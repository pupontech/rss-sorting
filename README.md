# Article Topics for FreshRSS

Article Topics is a standalone FreshRSS user extension that applies topic labels to individual articles using weighted keywords and, when needed, an OpenAI-compatible chat-completions endpoint. The target is FreshRSS **1.30.4**. It does not move articles, create feeds, or change read or starred state. The API and checked-in upstream source identify 1.30.4 (`.upstream/FreshRSS/constants.php`, commit `08f97bd497ace733c151ea1c709d85cd5b65cc53`).

## Install and configure

Copy `xExtension-ArticleTopics` into FreshRSS's `extensions/` directory, beside `app/`. Preserve the folder name. The PHP/web-server account needs read access to the extension and write access to `DATA_PATH/users/<username>/extensions/ArticleTopics/`.

- **Docker:** mount it at `/var/www/FreshRSS/extensions/xExtension-ArticleTopics`; keep the FreshRSS data volume writable by the container account.
- **Compose:** add the equivalent bind mount to the existing FreshRSS service and retain the data volume and permissions.
- **Bare metal:** install under the actual FreshRSS `extensions/` directory and check the web/PHP account can write that user's extension data directory.

Enable **Article Topics** in FreshRSS extension management, then configure it for the user. Enter the OpenAI-compatible base URL (without `/chat/completions`), model, optional API key, and taxonomy JSON. The key is stored in that user's extension configuration; the password input is blank when rendered and leaving it blank keeps the saved key. Enable classification only after saving valid settings.

The default taxonomy contains editable topics. Each topic has a name, enabled flag, description, weighted positive phrases, negative phrases, a threshold, and optional exclusions. The configurable label prefix defaults to `Topic: `. FreshRSS exposes categories and user labels as `user/-/label/<name>` in the Google Reader API. Stream lookup resolves categories first, so the prefix prevents ambiguous names; saves reject collisions with existing categories.

## Article data and provider

In Hybrid mode the worker first matches keywords and calls the provider when those results are absent, weak, or conflicting. Keywords Only and LLM Only are also available. The request includes enabled topic names and descriptions, article title, plain-text article content within the configured character limit, and feed-supplied article tags. The client does not fetch article URLs. Requests go only to the configured endpoint. That endpoint receives article data and may retain or process it under its own terms. Choose an endpoint you trust and set the content limit to suit your privacy needs. API keys are not displayed or intentionally logged.

Article text is untrusted data in the model prompt. The server accepts only configured topic names, applies thresholds and exclusions, limits the number of labels, and treats malformed responses as failures. These controls do not guarantee that a model will classify every article correctly.

## Worker, scheduling, and retries

Classification runs only in a separate CLI worker; feed refresh and reader sync do not call the LLM. Run from the FreshRSS installation with its PHP runtime:

```sh
php extensions/xExtension-ArticleTopics/cli/classify.php --user=USERNAME [--limit=N]
```

Example host cron for Docker (adjust container, user, and log path):

```cron
*/5 * * * * docker exec --user www-data freshrss php ./extensions/xExtension-ArticleTopics/cli/classify.php --user=USERNAME >> /var/log/freshrss-article-topics.log 2>&1
```

The worker defaults to 50 articles and a 20-second budget, uses a nonblocking per-user lock, and bounds provider timeouts by remaining budget. It discovers committed articles through durable reconciliation because 1.30.4 has no suitable post-commit extension hook. Enabling snapshots existing article IDs as already handled; later committed IDs are eligible. Discovery handles late lower IDs. State and lock files live in the per-user extension data directory; ownership rows live in that user's FreshRSS database.

Provider failures and malformed output are persisted with attempt count, next retry time, and last error. Retries use capped exponential backoff (up to five attempts by default); exhausted entries remain failed and do not block later eligible work. Changed classifier inputs are revisited by a bounded scan. Reclassification removes only label associations recorded as extension-owned. If a matching association existed before the extension tried to add it, that pair remains manual.

## Retention and keep_labels

FreshRSS defaults `keep_labels` to true, which can exclude labeled entries from age-based cleanup. Article Topics removes its own expired topic-label associations so ordinary purging can proceed. By default the cutoff follows the user's `keep_period` (three months if unavailable); an optional retention-days setting overrides it, and retention can be disabled. Manual labels and associations are never removed by Article Topics. With `keep_labels` enabled, unrelated labels can still keep an article from purging after its topic label expires.

## Backfill, preview, and diagnostics

The current tree includes a backfill form/service, a preview action, and a provider health check, but these are **not release-ready or verified**. The UI's backfill scope values do not match the service's accepted scope values, and the only checked-in CLI command runs the live classifier rather than queued backfill jobs. Treat backfill as unavailable until those contracts are aligned and the worker path is implemented and verified. Do not assume that a queued job will run. The form displays job counters when jobs exist, but it does not provide the requested classifier diagnostics (for example, keyword-versus-LLM share and recent retry failures).

The preview action accepts a title/body and returns method, categories, confidence, and matched phrases without applying labels or changing read/star state. In LLM modes it sends the supplied text to the configured endpoint. It has a three-second budget in the reviewed code. This action has not been validated end to end; do not use it with sensitive text until verified.

Existing articles present when classification is enabled are skipped by the live worker. Do not rely on the current backfill UI to classify them. These are Milestone 3 implementation gaps, not promised release behavior.

## Reader API and FeedMe

Topic labels are FreshRSS user labels, separate from feed categories and article tags. FreshRSS's Google Reader API exposes labels through `tag/list` and label streams; articles with multiple topic labels can be present in each corresponding stream while remaining in the original feed. This documents API behavior only and makes **no FeedMe compatibility claim**. FeedMe issue [#275](https://github.com/seazon/FeedMe/issues/275) was reported open on 2026-11-19 for blank articles opened from tag folders. No Android test or workaround is claimed. Use the phone checklist in [docs/feedme-manual-test.md](docs/feedme-manual-test.md).

## Updating, troubleshooting, and uninstall

Back up FreshRSS data, replace the extension code with the new version while preserving per-user state, and check permissions. Continue using the separate worker schedule. Check the worker's generic output and FreshRSS/PHP logs, provider URL/model, endpoint availability, and data-directory write access. Do not paste keys or article content into shared logs or support reports. Disable classification before changing endpoint credentials or taxonomy if you want to pause processing.

To uninstall, disable the extension in FreshRSS and remove its code directory. This does not delete applied labels, ownership records, or state. Review topic labels in FreshRSS and remove them as desired. Explicit data cleanup is a separate administrative action; do not remove extension state as part of routine disable/uninstall.

## Release status

Version **1.30.4** is the target FreshRSS version, not an Article Topics release version. Milestones 1 and 2 implementation and the reported local checks are recorded in [docs/milestone-2-report.md](docs/milestone-2-report.md). Milestones 3 and 4 are not complete: backfill execution and admin contracts, complete diagnostics, release validation, and packaging remain outstanding. No evaluation on 50 real articles was supplied or performed, so per-topic precision and recall are absent. No release-readiness or production deployment claim is made. See [CHANGELOG.md](CHANGELOG.md), [release notes](docs/release-notes.md), [security review](docs/security-review.md), and [ADR-001](docs/adr-001.md).
