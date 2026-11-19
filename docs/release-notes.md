# Release notes

## FreshRSS target: 1.30.4

This project currently targets FreshRSS 1.30.4. That number identifies the FreshRSS target; a separately numbered Article Topics release artifact has not been established here.

### Current documented scope

- Standalone FreshRSS extension with a configurable taxonomy and label prefix.
- Separate, scheduled CLI classification worker; keyword, Hybrid, and LLM modes; persisted retry state; per-user lock and data; and pair-level label ownership.
- Configurable retention of extension-owned topic labels, defaulting to the user's FreshRSS `keep_period` to account for `keep_labels` behavior.
- Local M1/M2 fixture, integration, and Google Reader API results as reported in `docs/milestone-2-report.md`. These are historical recorded results; this documentation change does not rerun or extend them.

### Milestone 3 work present but not release-ready

- **Backfill:** a UI form and durable job service are present. The form sends scopes such as `last_7_days` and `taxonomy_changed`, while the service accepts `7d` and `taxonomy`. The checked-in `cli/classify.php` does not execute backfill jobs. Therefore queued jobs are not an operational backfill feature yet. Do not rely on it to classify existing articles.
- **Preview and health check:** UI actions and bounded service methods are present. They have not been validated end to end; preview sends supplied text to the configured provider in LLM modes.
- **Diagnostics:** the backfill form can show stored per-job counters, but no complete diagnostics view for classifier share/recent failures is established. The requested diagnostic behavior remains incomplete.

### Not established in this scope

- **50-article evaluation:** no real article set was supplied. Precision and recall by topic are absent, not estimated from fixtures.
- **FeedMe:** FreshRSS API representation is documented, but there is no Android verification and no compatibility claim. See [manual checklist](feedme-manual-test.md) and [issue #275](https://github.com/seazon/FeedMe/issues/275).
- **Milestone 4:** CI, PHPStan, a completed release security gate, and versioned release zip are not evidenced as complete. Do not treat these notes as release approval or a production deployment record.

The code and artifact work for future milestones is outside this documentation record. See [ADR-001](adr-001.md), [security review](security-review.md), and [changelog](../CHANGELOG.md).
