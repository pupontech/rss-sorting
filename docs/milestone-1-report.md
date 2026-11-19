# Milestone 1 report — 2026-11-19

## Delivered

Standalone `xExtension-ArticleTopics`, targeting latest stable FreshRSS 1.30.4 (release commit `08f97bd497ace733c151ea1c709d85cd5b65cc53`). Six Luna 6 tasks contributed implementation, Docker fixtures, API verification, documentation, focused tests, and a source audit. See ADR-001 for the decision to author independently rather than copy LlmClassification code.

The configuration page supports enablement, provider URL/model/key, editable taxonomy JSON, content limit, and label prefix. A separately scheduled CLI worker uses a per-user lock, bounded HTTP requests, committed-ID discovery, and durable progress that stops before a failed entry. Ownership and article-label associations share a database transaction. Manual pairs are preserved, category collisions rejected, and blank key submissions preserve the saved key. No LLM calls run during refresh.

Enabling snapshots visible IDs as already handled. Repeated worker scans discover unseen IDs even if they commit below the original maximum; no unsupported post-commit hook is registered. This requires a growing processed-ID ledger.

## Files

- `xExtension-ArticleTopics/`: metadata, configuration page and handler, store, worker, CLI entrypoint.
- `docker-compose.test.yml`, `tests/docker/`, `tests/mock/`: isolated SQLite FreshRSS fixture, seeded general-tech feed and deterministic provider.
- `tests/api/verify_greader.php`, `tests/unit/`, `tests/run.php`: API and focused unit checks.
- `README.md`, `CHANGELOG.md`, `LICENSE`, `docs/`: installation, architecture, source audit, contract and phone checklist.
- `.gitignore`: excludes downloaded runtimes and upstream development checkouts.

## Measured verification

Portable PHP 8.3.36, Windows, SQLite, official FreshRSS 1.30.4 source. The disposable instance was installed under ignored `.upstream/FreshRSS`; no production instance was touched. Python fixture server supplied both RSS and OpenAI-compatible responses. A local PHP development server exposed the real Google Reader API.

Final unit command: `.tools/php/php.exe tests/run.php`:

```text
ArticleTopics stopped at entry 303: Provider request failed (HTTP 503).
PASS ClassificationTest.php
PASS OwnershipTest.php
PASS StoreTest.php
3 test file(s), 0 failure(s).
```

The HTTP 503 message is an expected failure fixture. Tests cover taxonomy validation, collisions, malformed/unknown/duplicate model values, delimiter injection, manual labels, ownership rollback, failed progress, and a lower-ID late commit after enablement. These use FreshRSS doubles and SQLite; they do not replace integration coverage for every database.

PHP lint passed all 12 PHP/template files. `git diff --check` found no whitespace errors. A real refresh with `cli/actualize-user.php --user=demo --feed-id=1` exited 0 and reported `FreshRSS actualized 1 feeds for demo (0 new articles)` after initial subscription imported the fixtures. The worker reported `ArticleTopics processed 2 article(s).`

Google Reader verification against that real local instance exited 0:

```text
PASS: Topic: Video Games is exposed as a user label (type=tag)
PASS: Topic: Video Games is present in tag/list
PASS: expected article is present in Topic: Video Games
PASS: finance fixture is absent from the Video Games stream
PASS: article title matches configured fixture (Nintendo announces new Zelda game)
PASS: article remains in its original feed stream (General Tech Seed Feed)
PASS: unread-count includes the topic label stream (count=1)
PASS: read toggle persists through the API
PASS: star toggle persists through the API
PASS: read state restored to its initial value
PASS: star state restored to its initial value
PASS: topic unread count is unchanged after restoring article state
API verification completed successfully.
```

The verifier's default feed display name differs from the seeded `General Tech Daily`; membership was checked using the actual numeric feed ID 1.

## FeedMe

Issue https://github.com/seazon/FeedMe/issues/275 was checked by the documentation task and remains open as of this report. API checks establish correct label representation, article retrieval, stream membership, and state mutations; they do not establish Android rendering or sync compatibility. Run `docs/feedme-manual-test.md` on the phone, including opening from a label, read sync, two-label membership, and original-feed access. No workaround or Android compatibility claim is made.

## Deployment and remaining verification

Follow README installation: copy the extension folder into FreshRSS `extensions/`, enable/configure for the user, and schedule `php extensions/xExtension-ArticleTopics/cli/classify.php --user=USERNAME`. Review the documented retention limitation before broad use. No deployment, commit, or push was performed.

Docker/Compose execution remains **unverified**: Docker and WSL are absent on this machine. The provided runner is `tests/docker/run.ps1`; it resets only its test stack. MySQL/MariaDB, PostgreSQL, the configuration form in a browser, process-kill recovery, and concurrent external label edits were not integration-tested. The local PHP install warned that recommended intl, zip and fileinfo modules were absent; required operations above succeeded.

Keywords/Hybrid, retry/backoff, cache, updated articles, owned-label expiration, backfill, richer UI, CI/PHPStan and release packaging are later milestones. Until expiration ships, topic labels can prevent normal purge when FreshRSS `keep_labels` is true. Ownership and processed-ID state can grow. Milestone 2 has not started; confirmation is required by PROMPT.md.
