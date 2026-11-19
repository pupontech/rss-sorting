# Backfill contract (Milestone 3)

The UI can create and inspect jobs through `ArticleTopicsBackfill`:

- Construct with the FreshRSS username and that user's ArticleTopics config.
- `create($options)` returns a durable job record containing its generated `id`.
- `run($jobId, $limit = 50, $seconds = 20)` advances one job and returns its updated record. The scheduled CLI is the only caller that runs classification; the UI only creates jobs and reads progress.
- `jobs()` returns this user's saved job records.

The CLI creates and immediately advances a job with `php extensions/xExtension-ArticleTopics/cli/backfill.php --user=alice --scope=all [--dry-run]`. Resume it with `--job=<id>`; each invocation is bounded by `--limit=50` and `--seconds=20` by default. Feed and category jobs also require `--feed-id=N` or `--category-id=N`.

Options are `scope` (`unread`, `7d`, `30d`, `all`, `feed`, `category`, `failed`, or `taxonomy`), optional positive integer `feed_id` / `category_id` for the corresponding scope, and boolean `dry_run`. A job captures its scope and maximum visible entry ID when created. Its numeric keyset cursor advances only after each entry result or failure is saved, so a later run resumes safely. Newer entries are outside that snapshot.

Each record exposes `status`, `cursor_id`, `high_water_id`, and cumulative `scanned`, `labelled`, `skipped`, `failed`, and `llm_calls` counters. Failures retain attempt count and last error in the job and are eligible for another run. Dry runs report predicted labels and progress without writing labels, cache entries, worker failures, or worker state. Neither mode changes read or starred status.

A worker and backfill share the per-user nonblocking `worker.lock`; overlapping runs skip rather than race. Job records live in that user's `extensions/ArticleTopics/jobs.json`, updated atomically under a separate jobs-file lock.

The current Worker exposes ownership-aware label application only as a private method. To remove the backfill engine's narrow reflection call in the parent integration, expose `ArticleTopicsWorker::applyLabels(int $entryId, array $topicNames, string $prefix): void` as a public wrapper around that existing operation. This lets backfill reuse the same atomic ownership checks without duplicating label logic.
