# Article Topics 0.2.0 release notes

Target FreshRSS 1.30.4. This release adds scheduled article-level user labels, weighted keyword/Hybrid/LLM classification, transactional ownership, retry/backoff and cache, committed-update detection, owned-label expiration, resumable keyset backfill, native configuration/topic management, explicit provider health checks, read-only preview and diagnostics.

Backfill UI scope values are mapped to the service, and `cli/backfill.php --next` runs queued jobs separately from refresh. The classifier consumes saved worker budgets. Browser scripts and CSS use FreshRSS static asset hooks to respect CSP. Manual labels, original feed membership and read/star states are preserved.

## Verification observed locally

- 30 PHP/template lint checks; 9 unit files, zero failures.
- Production PHPStan level 5 and API contract level 10: no errors.
- Full FreshRSS 1.30.4/SQLite integration and Google Reader API verifier pass, including worker process-kill recovery, backfill dry run/resume, failed-only retries below the scan cursor, cache, retention, refresh isolation and read/star restoration.
- Headless Edge: authenticated configuration renders; health/preview, topic add/delete, save, blank-key preservation, prefix spacing and UI backfill enqueue pass; invalid CSRF returns 403. No extension JavaScript errors.
- Luna 6 priority review: two rounds; approved. See review-ledger.md.
- Versioned ZIP build with a 17-file allowlisted manifest. No runtime, development checkout, tests or API keys are packaged.

GitHub CI results are tracked on the release PR; local Docker execution was unavailable. PostgreSQL/MySQL, concurrent manual-label edits and FeedMe Android behavior remain unverified. The requested real-article evaluation set was not supplied; precision/recall are unmeasured. Fixture results are not real-feed accuracy estimates. No production deployment occurred.

See the root README for installation, scheduling, backfill, updating and uninstall; feedme-manual-test.md for phone checks. Historical milestone reports remain snapshots, not current release status.
