# Article Topics for FreshRSS

Automatically sort individual FreshRSS articles into topic labels (`Topic: AI`, `Topic: Gaming`, `Topic: Security & Privacy`, etc.) using fast local keywords first and an optional AI fallback when keywords aren't sure.

## Why I created this

FreshRSS lets you organize *feeds* into folders, which works great when a feed only writes about one thing. In practice, a lot of the best feeds don't work like that — personal blogs, indie-web aggregators, and newsletters jump between software, hardware, books, photography, and everyday life in the same stream.

I wanted to browse my reading list **by what an article is actually about**, without:
- moving articles out of their original feeds or losing read/starred status,
- manually tagging dozens of posts a day, or
- paying to send every single article to an LLM when simple keywords can already tell what it's about.

Article Topics solves this by adding FreshRSS user labels (prefixed with `Topic: `) to each article in the background. If you remove an automatic label, Article Topics respects your choice and won't re-add it.

## How it works

You pick one of three modes:
- **Keywords Only** — 100% local, free, and private. Scores articles against your weighted keyword rules.
- **Hybrid (recommended)** — Runs keywords locally first. Only calls your AI provider when no topic matches, two topics tie, or rules conflict.
- **AI Only** — Always asks your configured OpenAI-compatible provider (OpenAI, OpenRouter, Ollama, LM Studio, etc.) to pick the topic.

Your articles stay in their original feeds, read/starred states are never touched, and manual labels you add yourself are never removed.

---

## What you need to do to use this plugin

### 1. Install the extension

Download the latest release ZIP from [Releases](https://github.com/pupontech/rss-sorting/releases) (or copy `xExtension-ArticleTopics/` from this repo) into your FreshRSS `extensions/` directory:

- **Standard / bare-metal FreshRSS**: copy `xExtension-ArticleTopics` into `/var/www/FreshRSS/extensions/`.
- **LinuxServer Docker (`lscr.io/linuxserver/freshrss`)**: copy `xExtension-ArticleTopics` into `/config/www/freshrss/extensions/`.

Make sure the FreshRSS PHP user (`www-data` or `abc` in LinuxServer Docker) can read the extension files and write to `data/users/<your-user>/extensions/ArticleTopics/` and your user SQLite/DB.

*(Optional — shared installs)* If multiple people share one FreshRSS server and you only want specific accounts to use Article Topics, add an allowlist to FreshRSS's system `data/config.php`:

```php
'extensions' => [
    'ArticleTopics' => [
        'allowed_users' => ['alice'],
    ],
],
```

### 2. Enable and configure it in FreshRSS

1. Sign in to FreshRSS, open **Configuration (gear icon) → Extensions**.
2. Enable **ArticleTopics**, then click the **gear icon** next to it to open the settings panel.
3. Turn on **Enable automatic topic labels** and pick your mode (**Hybrid**, **Keywords Only**, or **AI Only**).
4. If using **Hybrid** or **AI Only**:
   - Enter your **Provider base URL** (for example `https://api.openai.com/v1` or your local Ollama URL, without `/chat/completions`).
   - Enter your **API key** (leave blank for local providers without auth). Keys are stored per user and never displayed back in the form.
   - Click **Load models** to pick a model from your provider, or type a model ID manually.
5. Click **Save settings**, then click **Check provider** to confirm the connection works right inside the panel.
6. *(Optional)* Use **Test a classification** at the bottom of the panel to test sample text without touching any real articles.

### 3. Schedule the background workers

Article Topics runs outside FreshRSS's web requests so a slow AI provider never blocks feed refreshes or page loads. Add two cron jobs under the same user that runs FreshRSS (replace `alice` with your FreshRSS username):

**Bare metal / standard Docker:**
```cron
2-59/5 * * * * cd /var/www/FreshRSS && php extensions/xExtension-ArticleTopics/cli/classify.php --user=alice
3-59/5 * * * * cd /var/www/FreshRSS && php extensions/xExtension-ArticleTopics/cli/backfill.php --user=alice --next
```

**LinuxServer Docker (`abc` crontab inside the container):**
```cron
2-59/5 * * * * ARTICLETOPICS_FRESHRSS_ROOT=/app/www /usr/bin/php /app/www/extensions/xExtension-ArticleTopics/cli/classify.php --user=alice
3-59/5 * * * * ARTICLETOPICS_FRESHRSS_ROOT=/app/www /usr/bin/php /app/www/extensions/xExtension-ArticleTopics/cli/backfill.php --user=alice --next
```

- `classify.php` runs every 5 minutes (2 minutes past the mark) to label newly arrived articles. When nothing is new, it exits cleanly without rewriting state.
- `backfill.php --next` runs every 5 minutes to process any backfill jobs you queue in the UI.

### 4. Label older articles (optional)

Enabling Article Topics only classifies **new** articles going forward so it doesn't unexpectedly burn API credits on your entire archive. Whenever you want to label older articles:

1. Open **Extensions → ArticleTopics (gear)** and scroll to **Backfill existing articles**.
2. Pick a scope (`Unread`, `Last 7 days`, `Last 30 days`, `Single feed`, `All`, etc.).
3. Check **Dry run** first if you want to see how many articles would be labeled without making changes, then click **Queue backfill**.

Or run it directly from the CLI:
```sh
php extensions/xExtension-ArticleTopics/cli/backfill.php --user=alice --scope=unread --dry-run --limit=50
```

---

## Customizing topics & reducing AI calls

- **In the UI**: Expand **Topics** in the Article Topics settings panel to add/edit topics, weighted positive phrases (`phrase:2`), negative phrases, and thresholds.
- **With a per-user profile file**: Place a `keyword-profile.json` file in `data/users/<user>/extensions/ArticleTopics/` (see [xExtension-ArticleTopics/profiles/one-topic.json](xExtension-ArticleTopics/profiles/one-topic.json) for a ready-to-use 10-topic starter). When a profile is active, title and feed-tag matches get 2× weight, and you can also map specific feeds to default topics.
- **Tips to keep AI calls low in Hybrid mode**:
  - Give unmistakable topic-defining terms a weight of `2` (matching your threshold of `2`) so they resolve locally on the first hit.
  - Keep general supporting terms at weight `1` so two of them together (or one in the title) resolve locally.
  - In single-topic mode (`Max topics = 1`), clear winners and dominant headline matches stay local; only genuine ties or unmatched articles go to the AI.

## Privacy, labels & retention

- **What gets sent to the AI**: Only when an article actually escalates to the provider in Hybrid/AI Only mode, Article Topics sends your topic names/descriptions and the article's title, plain-text content (capped by your content limit), and feed tags. It never fetches article URLs or follows redirects.
- **Label prefix**: Defaults to `Topic: ` (with a space). FreshRSS and the Google Reader API share a namespace between feed categories and user labels, so collisions with existing category names are blocked automatically.
- **Retention**: FreshRSS's `keep_labels` setting keeps labeled articles from being purged. To avoid infinite database growth, Article Topics automatically expires its own labels after FreshRSS's `archiving.keep_period` (or 3 months if unset). You can adjust or disable this under **Advanced settings → Retention**. Manual labels are never touched.
- **Uninstalling**: Disable the extension and remove `xExtension-ArticleTopics/`. Your existing labels and articles stay intact.

## Development & verification

- Run unit tests and PHP linting: `php scripts/lint.php && php tests/run.php`
- Run static analysis: `phpstan analyse -c phpstan.neon` and `phpstan analyse -c phpstan-contract.neon`
- Build a release ZIP: `php scripts/build-release.php`
- Full FreshRSS integration + Playwright test: see [tests/integration/README.md](tests/integration/README.md)

More technical details: [Architecture (ADR-001)](docs/adr-001.md), [Security Review](docs/security-review.md), [FeedMe Checklist](docs/feedme-manual-test.md), and [Changelog](CHANGELOG.md).
