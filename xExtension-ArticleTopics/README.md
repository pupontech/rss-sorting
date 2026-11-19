# Article Topics 0.2.0

Standalone extension for FreshRSS 1.30.4. Copy this directory into FreshRSS extensions/, enable ArticleTopics for the user, then configure it in the native extension page. The PHP/web account needs write access to data/users/<user>/extensions/ArticleTopics/ and the user database.

Keywords Only runs locally; Hybrid uses weighted phrases first and calls the configured OpenAI-compatible endpoint for weak/conflicting/absent results. LLM Only always calls it. Requests contain configured topic names/descriptions and bounded article title/content/feed tags. Article URLs are never fetched. API keys are stored in FreshRSS per-user configuration and never shown in the form.

Topic: is the default label prefix (with a trailing space). User labels and feed categories share an API namespace; collisions are rejected. Labels do not move articles or change read/star states. Only extension-owned label associations are removed during reclassification or expiration; prior manual pairs remain manual.

Schedule both independent CLI jobs from the FreshRSS installation root:

```cron
*/5 * * * * cd /var/www/FreshRSS && php extensions/xExtension-ArticleTopics/cli/classify.php --user=alice
*/5 * * * * cd /var/www/FreshRSS && php extensions/xExtension-ArticleTopics/cli/backfill.php --user=alice --next
```

The classifier uses saved worker budgets (default 50 articles/20 seconds); --limit overrides the article budget. Provider failures retry with exponential backoff, default five attempts, without blocking refresh. One worker/backfill runs per user at a time. Live discovery excludes articles visible at enable time and catches later lower-ID commits. Explicit backfill handles older articles.

Backfill can be queued in the UI or started using --scope=unread|7d|30d|all|feed|category|failed|taxonomy, with --feed-id or --category-id where required. Add --dry-run to predict labels without changing article labels or live worker state. Resume using --job=ID. Jobs use persisted keyset cursors and snapshot upper bounds. The UI displays progress, diagnostics, read-only previews, and explicit provider health checks.

Owned-label expiration defaults to FreshRSS archiving.keep_period (three months if unset), or an explicit retention day count. This matters because keep_labels=true otherwise protects labelled articles from purge. Manual labels can still protect articles. Retention is configurable; expired entries are not re-labelled.

To update, back up data and replace code while preserving per-user state. Disabling/uninstalling does not delete labels or data. Removing extension tables/files is a separate explicit administrative task. FeedMe Android rendering must be checked manually; Google Reader API verification alone is not a compatibility claim.
