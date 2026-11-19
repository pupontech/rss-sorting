# Milestone 1 implementation contract

Target is FreshRSS 1.30.4 (confirmed in `.upstream/FreshRSS/constants.php`). Extension folder and entrypoint are `xExtension-ArticleTopics` / `ArticleTopics`.

## Worker contract

Run `php cli/classify.php --user=USERNAME [--limit=N]` from the FreshRSS installation root (the extension-local script is at `extensions/xExtension-ArticleTopics/cli/classify.php`). Worker supports keyword, hybrid, and LLM modes. `--limit` caps articles; defaults are 50 entries and 20 seconds total. A per-user nonblocking lock skips overlapping runs. HTTP connect/total timeouts are bounded by remaining time.

State is isolated under FreshRSS's extension user path `DATA_PATH/users/<username>/extensions/ArticleTopics/`; this is `Minz_Extension::getExtensionUserPath()` for entrypoint `ArticleTopics`. Schema 2 `state.json` contains `start_id`, `cursor_id`, `pending`, `processed_ids`, `discovery_offset`, `failures`, `fingerprints`, and `cache`. Schema 1 migrates in place, preserving pending IDs, cursor, baseline, and processed IDs while adding empty failure, fingerprint, and cache maps. Discovery excludes already processed baseline IDs and IDs whose `failures[id].next_retry_at` is in the future. Enabling records visible IDs as processed and acquires the worker lock before writing state, so it does not overwrite a running worker's state. Fingerprints detect changed inputs for reclassification; cache keys cover effective classifier inputs and taxonomy. Failures store attempts, next retry time, and a bounded last error.

## Configuration

Per-user FreshRSS extension settings include provider credentials/URL/model, the existing prefix and content limit, `mode` (`keywords`, `hybrid`, `llm`; default `hybrid`), `max_topics` (default 2), `confidence_threshold` (default 0), retries (maximum 5, base delay 60 seconds), owned-label retention (enabled; blank day count resolves to FreshRSS `keep_period`), response format (`plain`, `json_schema`, `json_object`), optional reasoning effort, and optional `think=false`. Taxonomy items retain `name`, `enabled`, and `description` and add `positive_keywords` (phrase-to-positive-weight object), `negative_keywords` (phrase list), `threshold` (non-negative number, default 2), and `exclusions` (topic-name list). Legacy taxonomy fills empty keyword/exclusion defaults. LLM output is validated against enabled configured topics; malformed output is an error.

## Ownership and labels

Label lookup and creation use FreshRSS `FreshRSS_TagDAO`; association and ownership changes use the same active user PDO in one transaction. The extension table `_at_owned` is created before the transaction and is automatically user-prefixed by FreshRSS. An association is owned only when the worker inserts the `_entrytag` pair and ownership row together; pre-existing associations remain manual. Categories are compared against the final prefixed label before save and label creation.

## Test seams

Keep classifier transport and label/storage operations injectable or separable enough for tests to use a deterministic mock OpenAI endpoint and temporary per-user storage. Never call classification from refresh hooks; target 1.30.4 lacks a suitable post-commit hook. Config POST uses FreshRSS's guarded configure route and the form includes `FreshRSS_Auth::csrfToken()`.
