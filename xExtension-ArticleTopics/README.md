# Article Topics 0.2.2-rc.6

Automatically label individual FreshRSS articles by topic (`Topic: AI`, `Topic: Gaming`, `Topic: Security & Privacy`, etc.) using local weighted keywords first and an optional OpenAI-compatible AI provider when keywords aren't certain.

Articles stay in their original feeds, read/starred states are never changed, and any manual labels you add or remove are respected.

## Quick setup

1. **Install**: Copy `xExtension-ArticleTopics` into your FreshRSS `extensions/` folder (`/config/www/freshrss/extensions/` on LinuxServer Docker). Ensure the FreshRSS PHP user can write to `data/users/<user>/extensions/ArticleTopics/` and the user database.
2. **Optional allowlist (shared servers)**: Add to system `data/config.php`:
   ```php
   'extensions' => [
       'ArticleTopics' => [
           'allowed_users' => ['alice'],
       ],
   ],
   ```
3. **Configure**: Open **Configuration → Extensions**, enable **ArticleTopics**, and click its gear icon.
   - Choose **Keywords Only** (100% local), **Hybrid** (keywords first, AI fallback), or **AI Only**.
   - For Hybrid/AI Only, enter your provider base URL (without `/chat/completions`), optional API key, click **Load models**, pick a model, click **Save settings**, and verify with **Check provider**.
4. **Schedule the background workers** (replace `alice` with your username):
   ```cron
   # Bare metal / standard Docker
   2-59/5 * * * * cd /var/www/FreshRSS && php extensions/xExtension-ArticleTopics/cli/classify.php --user=alice
   3-59/5 * * * * cd /var/www/FreshRSS && php extensions/xExtension-ArticleTopics/cli/backfill.php --user=alice --next

   # LinuxServer Docker (abc crontab)
   2-59/5 * * * * ARTICLETOPICS_FRESHRSS_ROOT=/app/www /usr/bin/php /app/www/extensions/xExtension-ArticleTopics/cli/classify.php --user=alice
   3-59/5 * * * * ARTICLETOPICS_FRESHRSS_ROOT=/app/www /usr/bin/php /app/www/extensions/xExtension-ArticleTopics/cli/backfill.php --user=alice --next
   ```

## Backfill, profiles & retention

- **Older articles**: Enabling only classifies new arrivals. Queue a backfill (with optional **Dry run**) in the settings panel or via `cli/backfill.php --user=alice --scope=unread --dry-run`.
- **Per-user keyword profile**: Put `keyword-profile.json` in `data/users/<user>/extensions/ArticleTopics/` to override the UI rules with a richer profile and optional per-feed defaults. See `profiles/one-topic.json` for a 10-topic starter.
- **Manual overrides**: Removing an automatic `Topic:` label keeps that article from being re-labeled automatically.
- **Retention**: Extension-owned labels expire based on FreshRSS `archiving.keep_period` (3 months if unset) or your custom retention setting under **Advanced settings**, so `keep_labels` doesn't grow your database forever. Manual labels are never removed.
- **Privacy**: In Hybrid/AI Only mode, only topic names/descriptions and bounded article text (title, cleaned body, feed tags) are sent to your configured endpoint. Article URLs are never fetched, and API keys are never shown back in the UI.
