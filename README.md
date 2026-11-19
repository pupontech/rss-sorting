# Article Topics for FreshRSS

Article Topics **0.2.0** classifies individual articles into FreshRSS user labels, independently of their feed. Target: **FreshRSS 1.30.4**. Articles stay in their original feed and All Articles; classification does not change read or starred state. Architecture: [ADR-001](docs/adr-001.md).

## Install and configure

Copy `xExtension-ArticleTopics` into your FreshRSS `extensions/` directory. For Docker/Compose, mount it read-only at `/var/www/FreshRSS/extensions/xExtension-ArticleTopics` and preserve the FreshRSS data volume. On bare metal, use the equivalent install directory. The PHP/web account needs read access to code and write access to the user's database and `data/users/<user>/extensions/ArticleTopics/`.

Enable **ArticleTopics** in FreshRSS extensions, open its configuration page, and select Keywords Only, Hybrid, or LLM Only. Configure an OpenAI-compatible base URL (without `/chat/completions`), model and optional key for Hybrid/LLM modes. Keys are stored in FreshRSS per-user configuration; a blank submitted key preserves the saved key. Health checks send a bounded explicit request to that endpoint. Never put keys in shared logs.

Edit topic names, descriptions, weighted phrases, negative phrases, thresholds and enabled state in the native UI. Add/delete rows as needed; existing exclusion rules are preserved. The advanced JSON view exposes the full taxonomy. Unknown model topics are dropped; malformed or partial results fail rather than clearing labels. Preview shows method, confidence and matched phrases without writing article labels or worker state.

**Content sent to the LLM:** configured topic names/descriptions and bounded article title, HTML-cleaned content/summary and feed-supplied tags. The endpoint receives this content under its own privacy terms. The extension never fetches article URLs or follows provider redirects. Keywords Only needs no provider. Hybrid avoids requests for clear keyword results and calls the provider for weak, conflicting or absent matches. Optional reasoning/think fields are removed only after an explicit provider rejection.

## Labels, retention, and privacy

Default label prefix: `Topic: ` (including the space). Feed categories and user labels share the Google Reader API namespace, so full-name collisions are rejected. These are user labels, not article tags or feed categories. An article can have multiple topics. Existing manual article-label pairs are not claimed; reclassification and expiration remove only recorded extension-owned pairs. Ownership and association changes share a user-database transaction.

FreshRSS `keep_labels=true` protects labelled entries from purge. Owned-label expiration defaults to the user's `archiving.keep_period`; if unset, it uses three months. Configure explicit retention days or disable expiration in the UI. Expired entries are not labelled again; manual/unrelated labels remain untouched and may still protect entries. Disabling/removing the extension does not delete labels or user data. Protect FreshRSS data/configuration and backups as credentials and reading-history data.

## Scheduling and backfill

Run both jobs separately from refresh and reader sync, under the same PHP/user permissions as FreshRSS:

```cron
*/5 * * * * cd /var/www/FreshRSS && php extensions/xExtension-ArticleTopics/cli/classify.php --user=alice
*/5 * * * * cd /var/www/FreshRSS && php extensions/xExtension-ArticleTopics/cli/backfill.php --user=alice --next
```

Docker example: `docker exec --user www-data freshrss php extensions/xExtension-ArticleTopics/cli/classify.php --user=alice`. Schedule the corresponding `backfill.php --user=alice --next` command too. Saved worker budgets default to 20 seconds/50 articles; `classify.php --limit=N` overrides its article budget. Bounded HTTP timeouts, exponential retries (default five attempts), and a nonblocking per-user lock keep outages and overlap independent of refresh. Deleted entries are skipped and their failures cleaned. Committed input changes are detected through bounded fingerprint scans.

Enabling snapshots visible IDs as already handled; live discovery catches later commits, including lower IDs. It does not classify existing articles automatically. Queue a backfill in the UI or create/run one with:

```sh
php extensions/xExtension-ArticleTopics/cli/backfill.php --user=alice --scope=all --dry-run --limit=50
php extensions/xExtension-ArticleTopics/cli/backfill.php --user=alice --job=JOB_ID --limit=50
```

Scopes: `unread`, `7d`, `30d`, `all`, `feed` with `--feed-id`, `category` with `--category-id`, `failed`, and `taxonomy` (explicitly reclassify everything after a taxonomy change). Jobs retain a keyset cursor and creation-time upper ID bound. Dry runs predict topics without changing labels or live worker state; they can still call the configured provider. Progress includes scanned/labelled/skipped/failed/articles and actual LLM requests, including failures. Diagnostics show counts, method share and recent failures with a retry action. A job at its retry cap finishes with failure records; inspect those before treating a job as fully successful.

## Validation and FeedMe

Run `composer install` and `composer check` with PHP 8.3, cURL, mbstring and SQLite. Production PHPStan runs at level 5 against FreshRSS 1.30.4; the API contract additionally runs at level 10. Upstream has separate first-party level 10 and third-party level 0 configs; our stronger third-party level 5 choice is explicit, not a claim of full level 10 implementation coverage.

Local SQLite validation: `tests/integration/run.ps1 -Mode Assertions` against the installed ignored development checkout; setup and prerequisites are in [the integration guide](tests/integration/README.md). Docker fixtures and CI are provided in `docker-compose.test.yml` and `.github/workflows/ci.yml`. Build a versioned ZIP with `php scripts/build-release.php` (requires PHP zip). No benchmark or real-feed quality number is inferred from mocks.

The Google Reader API verifier checks label `type: tag`, topic/feed membership, unread count, and read/star persistence/restoration. FeedMe issue [#275](https://github.com/seazon/FeedMe/issues/275) was open when checked on 2026-11-19. API checks do not establish Android rendering or sync compatibility. Run [the phone checklist](docs/feedme-manual-test.md); no workaround is claimed. About 50 user-labelled real articles are still needed for per-topic precision and recall.

## Update, troubleshoot, uninstall

Back up FreshRSS data before replacing extension files. Preserve per-user state; schema 1 file state migrates to schema 2. Review custom taxonomies after upgrading: legacy entries receive empty keyword/exclusion defaults. Check PHP/FreshRSS logs, endpoint URL/model, permissions, cron and recent failures when processing stops. Do not share secrets or article bodies in diagnostics.

To uninstall, disable the extension and remove its code directory. Applied labels, ownership tables and state remain. Explicit data removal is a separate administrative operation; never delete ownership state casually while its labels remain. Nothing here deploys to a live FreshRSS instance.

See [release notes](docs/release-notes.md), [security review](docs/security-review.md), [review ledger](docs/review-ledger.md), and [changelog](CHANGELOG.md).
