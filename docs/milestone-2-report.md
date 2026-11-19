# Milestone 2 report — 2026-11-19

## Delivered and architecture

FreshRSS 1.30.4 remains the pinned target. Five Luna 6 implementation tasks and a sixth independent review used the priority service tier. The standalone extension now has weighted phrase scoring, negatives and exclusions, Keywords Only / Hybrid / LLM Only modes, top-N resolution, per-user classification cache, durable exponential retries with an attempt cap, committed-input fingerprint scans, and ownership-only expiration. Classification, retries and expiration run exclusively in the separately scheduled worker under its per-user lock.

Ownership SQL tables remain on schema 1; per-user file state migrates from schema 1 to schema 2, preserving progress and adding failures, fingerprints and cache. Default retention follows the user's ISO `keep_period`, with a three-month fallback when unset; explicit days and disabling are configurable. Expired articles are not classified again. Input hashes include bounded title, content, feed-supplied tags, taxonomy, prompt revision and configuration. State is local to the user; ownership remains transactionally coupled to article-label mutations in FreshRSS's active user database.

The review findings were fixed: both explicitly rejected optional provider fields can be removed sequentially, exclusion references are case-folded consistently, and cached names must belong to the enabled taxonomy. Integration additionally aligned UI/worker retry settings, added JSON-object response support, corrected the think=false toggle, and added contextual Windows/NAS keyword defaults. LLM-only top-N ranking uses confidence; malformed or partial classification items fail rather than clearing labels.

## Files added or changed

- `lib/ArticleTopicsClassifier.php`, `lib/ArticleTopicsLlmClient.php`, `lib/ArticleTopicsRetention.php` under the extension.
- Existing extension store, worker, configuration handler/template and metadata.
- `tests/unit/PipelineTest.php`, `ReliabilityTest.php`, `RetentionTest.php`, `StateV2Test.php`; existing test bootstrap and regression tests.
- `tests/integration/run.ps1`, `assert_m2.php`, `README.md`; expanded deterministic `tests/mock/server.py`.
- Root README, CHANGELOG, architecture/contract documentation and `docs/milestone-2-review.md`.

## Tests actually run

Windows, portable PHP 8.3.36, Python 3.12, official FreshRSS 1.30.4 checkout and SQLite. Production was not used. Final commands:

```powershell
./.tools/php/php.exe tests/run.php
./tests/integration/run.ps1 -Mode Assertions
```

Unit output:

```text
ArticleTopics recorded failure for entry 303 (attempt 1).
ArticleTopics processed 0 article(s).
PASS ClassificationTest.php
PASS OwnershipTest.php
PASS PipelineTest.php
PASS ReliabilityTest.php
PASS RetentionTest.php
PASS StateV2Test.php
PASS StoreTest.php
7 test file(s), 0 failure(s).
```

All 20 PHP/template files passed syntax checks; `git diff --check` passed. Unit checks include v1-to-v2 migration, capped retry scheduling, malformed JSON, taxonomy filtering, prompt delimiter injection, rollback of ownership writes, case-insensitive exclusions, confidence ranking, input/taxonomy/options cache invalidation, and sequential rejection of reasoning_effort and think.

The Assertions runner exited 0 on a newly created `m2test20261119155034` disposable user. It imported twelve mock feed articles and checked Hybrid output against the prompt fixtures, plus exact Keywords Only results for the ten positive fixtures. The two ambiguous negative cases were checked for forbidden labels in Hybrid mode; dedicated keyword negative/boundary unit checks also pass. No real-article precision/recall numbers are inferred from these fixtures.

Selected real FreshRSS/SQLite integration output:

```text
PASS: rolled-back update does not relabel
PASS: committed update replaces only owned labels
PASS: read and starred states unchanged
PASS: 429 and 500 failures are durable while later entries continue
PASS: eligible retries succeed and clear failures
PASS: unchanged articles require no extra LLM calls
PASS: cached identical inputs are reapplied without an LLM request
PASS: deleted entries have capped failure records cleaned
PASS: retention removes old owned labels and keeps manual labels
Milestone 2 integration assertions completed.
FreshRSS actualized 1 feeds for m2test20261119155034 (0 new articles)
PASS: refresh succeeds without LLM calls during provider outage
```

The retry test makes stored retry timestamps eligible to avoid waiting; backoff arithmetic/caps are unit-tested separately. The outage refresh test queues HTTP 503, runs the standard CLI refresh, and verifies the mock LLM call count did not increase. Transaction rollback is tested; a separate process-kill recovery test remains outstanding.

## Google Reader API and FeedMe

The same runner started a local PHP API server and reran `tests/api/verify_greader.php`; it exited 0. Steam Deck was the expected gaming fixture because the update test changed the original Nintendo entry. Checks passed for tag/list type=tag, topic membership, finance exclusion, original feed membership, unread count, read/star mutations and state restoration:

```text
PASS: Topic: Video Games is exposed as a user label (type=tag)
PASS: article title matches configured fixture (Steam Deck performance benchmarks)
PASS: article remains in its original feed stream (General Tech Seed Feed)
PASS: unread-count includes the topic label stream (count=2)
PASS: read toggle persists through the API
PASS: star toggle persists through the API
PASS: read state restored to its initial value
PASS: star state restored to its initial value
PASS: topic unread count is unchanged after restoring article state
API verification completed successfully.
```

The generic verifier display name says General Tech Seed Feed; membership uses the actual seeded feed ID 1 (General Tech Daily). FeedMe issue #275 was checked earlier in this session and remains reported open. Android rendering and sync are unverified; run `docs/feedme-manual-test.md`. API success is not a FeedMe compatibility claim.

## Updating and remaining limits

Back up FreshRSS data, replace `extensions/xExtension-ArticleTopics`, review the editable keyword taxonomy and settings, then run the same separate CLI cron command documented in README. Existing custom taxonomy entries migrate to empty keyword lists; add phrases or retain LLM mode as needed. No production deployment, commit or push was performed.

Docker/Compose, MySQL/MariaDB and PostgreSQL remain unrun here. Browser form behavior, process-kill recovery, and concurrent manual label edits are not integration-tested. The local runtime lacks recommended intl, zip and fileinfo modules; executed operations succeeded. File state and processed-ID history grow; cache is capped at 1,000 entries, but this is not a load benchmark. The local Assertions runner requires the already installed ignored checkout/runtime and free ports 8081/8088; it does not provision a new machine. It retains test users and stops its fixture/API servers on exit.

About 50 user-labeled real articles are still needed for per-topic precision/recall; none were supplied and no evaluation is fabricated. Backfill and the richer admin UI belong to Milestone 3; CI, PHPStan and release packaging belong to Milestone 4. Neither milestone was started. Stop here pending the user's next confirmation, as required by PROMPT.md.
