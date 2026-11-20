# Article Topics 0.2.2-rc.2

Copy this directory into `extensions/` on FreshRSS 1.29.1 or later. Enable ArticleTopics for the FreshRSS user, then open its gear in Configuration → Extensions. The PHP/web account needs write access to `data/users/<user>/extensions/ArticleTopics/` and the user database.

On a shared install, an administrator can limit Article Topics to selected accounts in the system `data/config.php`. Merge this entry into the existing `extensions` list, or add that list if it is absent.

```php
'extensions' => [
    'ArticleTopics' => [
        'allowed_users' => ['alice'],
    ],
],
```

The list applies to the settings, preview, job queue, and both CLI entry points. Omit `allowed_users` to use FreshRSS's regular per-user configuration. An empty list disables Article Topics for every account. Keep each scheduled job's `--user` set to the intended FreshRSS account.

Keywords Only runs locally; Hybrid uses weighted phrases first and calls the configured OpenAI-compatible endpoint for weak/conflicting/absent results. LLM Only always calls it. Requests contain configured topic names/descriptions and bounded article title/content/feed tags. Article URLs are never fetched. API keys are stored in FreshRSS per-user configuration and never shown in the form.

Topic: is the default label prefix (with a trailing space). User labels and feed categories share an API namespace; collisions are rejected. Labels do not move articles or change read/star states. Only extension-owned label associations are removed during reclassification or expiration; prior manual pairs remain manual.

Schedule both independent CLI jobs from the FreshRSS installation root:

```cron
2-59/5 * * * * cd /var/www/FreshRSS && php extensions/xExtension-ArticleTopics/cli/classify.php --user=alice
3-59/5 * * * * cd /var/www/FreshRSS && php extensions/xExtension-ArticleTopics/cli/backfill.php --user=alice --next
```

### LinuxServer Docker

Install the extension in `/config/www/freshrss/extensions` inside the container. The FreshRSS application root is `/app/www` and the system config is `/config/www/freshrss/data/config.php`. Set `ARTICLETOPICS_FRESHRSS_ROOT` when invoking either worker from the persistent path or through a symlink. [LinuxServer install documentation](https://github.com/linuxserver/docker-freshrss#application-setup)

Keep the current `abc` crontab. If `/config/crontabs/abc` does not exist, use the output of `crontab -l -u abc` inside the container as its starting contents. Append both lines and replace `alice` with your FreshRSS account.

```cron
2-59/5 * * * * ARTICLETOPICS_FRESHRSS_ROOT=/app/www /usr/bin/php /app/www/extensions/xExtension-ArticleTopics/cli/classify.php --user=alice
3-59/5 * * * * ARTICLETOPICS_FRESHRSS_ROOT=/app/www /usr/bin/php /app/www/extensions/xExtension-ArticleTopics/cli/backfill.php --user=alice --next
```

For images that import the persistent crontab only when `/defaults/crontabs/abc` exists, bind-mount the completed crontab file to `/defaults/crontabs/abc:ro` if the image does not already supply one. Keep the spool file under `/etc/crontabs` writable by the container. Restart the container and use `crontab -l -u abc` to confirm that the existing feed-refresh job and both Article Topics jobs are present. [LinuxServer cron initialization](https://github.com/linuxserver/docker-baseimage-alpine/blob/3.24/root/etc/s6-overlay/s6-rc.d/init-crontab-config/run)

### Worker and backfill behavior

The classifier uses saved worker budgets (default 50 articles/20 seconds); --limit overrides the article budget. Provider failures retry with exponential backoff, default five attempts, without blocking refresh. One worker/backfill runs per user at a time. Live discovery excludes articles visible at enable time and catches later lower-ID commits. Explicit backfill handles older articles.

Backfill can be queued in the UI or started using --scope=unread|7d|30d|all|feed|category|failed|taxonomy, with --feed-id or --category-id where required. Add --dry-run to predict labels without changing article labels or live worker state. Resume using --job=ID. Jobs use persisted keyset cursors and snapshot upper bounds. The UI displays progress, diagnostics, read-only previews, and explicit provider health checks.

The scheduler's `--next` uses the saved worker article and time budgets. Pass `--limit` or `--seconds` to override the corresponding setting for that invocation.

New configurations default to Hybrid and one automatic topic. With a one-topic limit, Hybrid asks the provider to choose the main subject when keyword matches are weak, conflicting, missing, or point to multiple topics. AI Only asks the provider for every eligible article. Keywords Only runs locally. The Enable setting pauses the worker and retains its queue. Existing configurations retain their saved topic limit.

Removing an automatic topic keeps the article out of future classification. SQLite records the removal immediately, including when the same tag is manually added again. On other supported databases the worker detects a missing owned association on its next check. The reading menu links to retry controls when the provider rejects access, reports exhausted credits, or continues to fail after retries.

### Per-user keyword profile

Set up a profile in `data/users/<user>/extensions/ArticleTopics/keyword-profile.json`. Its `topics` list uses the same schema as the settings taxonomy. Set `feeds` to a map from numeric feed IDs to enabled topic names, `focused_feeds` to a list of numeric IDs from that map, and `fallback` to a distinct label name such as `Uncategorized`. Use an empty map and list if you have no feed defaults. Settings displays the active topics. Removing the profile restores the settings taxonomy.

The bundled [one-topic profile](profiles/one-topic.json) provides ten browsing topics and tie-breaking guidance for the provider. Use it with a one-topic limit.

To start the OpenAI new-arrivals trial, first back up the user database, config, and the extension's profile and state. From the FreshRSS installation root, run the following as the FreshRSS service account with a protected key file.

```sh
php extensions/xExtension-ArticleTopics/cli/start-trial.php --user=alice < /path/to/openai-key
```

The command sets Hybrid and one topic, installs the bundled vocabulary, and uses the current article IDs as the trial baseline. It protects existing labels from automatic reclassification and expiration during the trial. It also records the first 50 arrivals for review. The settings screen shows the start date and number of arrivals recorded.

In Keywords Only mode, title and feed-tag matches have twice the weight of body matches and take priority when they reach a topic threshold. A focused feed always gets its default topic, with additional qualifying title or feed-tag matches up to the topic limit. Other feeds use their default when no topic qualifies. The fallback applies by itself when neither the article nor the feed yields a topic. The regular worker skips previously classified read articles when a profile is active. To revisit the archive, explicitly choose a backfill scope that includes it.

Owned-label expiration defaults to FreshRSS archiving.keep_period (three months if unset), or an explicit retention day count. This matters because keep_labels=true otherwise protects labelled articles from purge. Manual labels can still protect articles. Retention is configurable; expired entries are not re-labelled.

To update, back up data and replace code while preserving per-user state. Disabling/uninstalling does not delete labels or data. Removing extension tables/files is a separate explicit administrative task. FeedMe Android rendering must be checked manually; Google Reader API verification alone is not a compatibility claim.
