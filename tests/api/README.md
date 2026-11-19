# Google Reader API verification

Run against a configured FreshRSS 1.30.4 instance:

```sh
php tests/api/verify_greader.php
```

The Docker harness defaults should be `FRESHRSS_API_BASE=http://localhost:8088/api/greader.php`, `FRESHRSS_USER=admin`, and `FRESHRSS_PASSWORD=test-api-password`. Override any value with an environment variable:

| Variable | Default / purpose |
| --- | --- |
| `FRESHRSS_API_BASE` | `http://localhost:8088/api/greader.php` (base endpoint, no trailing route required) |
| `FRESHRSS_USER` | `admin` |
| `FRESHRSS_PASSWORD` | `test-api-password` |
| `FRESHRSS_TOPIC_LABEL` | `Topic: Video Games` |
| `FRESHRSS_TOPIC_STREAM` | `user/-/label/` plus the URL-encoded topic label |
| `FRESHRSS_EXPECTED_TITLE` | `Nintendo announces new Zelda game` |
| `FRESHRSS_ARTICLE_ID` | Optional exact Google Reader item ID; otherwise found by title in topic stream |
| `FRESHRSS_ORIGINAL_FEED_ID` | Feed ID used to build `feed/<id>` stream when stream is not given |
| `FRESHRSS_ORIGINAL_FEED_STREAM` | Original stream path, e.g. `feed/42`; takes precedence over feed ID |
| `FRESHRSS_ORIGINAL_FEED_TITLE` | `General Tech Seed Feed`, used in output |
| `FRESHRSS_HTTP_TIMEOUT` | `20` seconds |

The chosen fixture must be the Nintendo article seeded into the unrelated general-tech feed (not Finance). Supply its ID or ensure its title matches exactly. The script logs in with ClientLogin, verifies the label is a `type: tag`, checks topic and original-feed membership, reads the unread-count response, then toggles read/star via `edit-tag` and verifies persistence. It restores both state flags to their original values even when a toggle assertion fails, then confirms the label unread count is restored. It does not create or alter labels.

Only a completed run against the target instance establishes a pass. This script has not been run as part of writing it.
