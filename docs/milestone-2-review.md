# Milestone 2 code review

Scope: read-only review of `xExtension-ArticleTopics/lib/*.php` and extension configuration against the vendored FreshRSS 1.30.4 source. Existing report says the PHP unit suite passes 7 tests; I did not rerun it.

## Ranked findings

### 1. [P1] Retry optional provider-field fallback until remaining optional fields are accepted

**Files:** `xExtension-ArticleTopics/lib/ArticleTopicsLlmClient.php:30-36,75-82`

When a provider rejects both `reasoning_effort` and `think`, the client detects and removes only the first field, retries once, then treats a second 400/422 as a terminal request failure. This causes an otherwise usable provider to fail classification whenever both configured optional fields are unsupported. Retry by removing one explicitly rejected optional field at a time while there are eligible fields left and the deadline permits; stop on unrelated validation errors and avoid retry loops.

### 2. [P1] Resolve taxonomy exclusions case-insensitively, consistent with validation

**Files:** `xExtension-ArticleTopics/lib/ArticleTopicsStore.php:50-54`; `xExtension-ArticleTopics/lib/ArticleTopicsClassifier.php:144-160`

Validation accepts exclusion references case-insensitively (`$seen` is keyed by lowercase names), but `resolveNames()` later checks the reference with strict, case-sensitive `in_array`. For example, an exclusion `video games` validates against topic `Video Games`, but does not suppress the conflicting candidate. Normalize topic names and exclusions to the same case-folded keys during classification. `hasConflict()` already case-folds its check, so the hybrid path can call the LLM unnecessarily while final resolution still fails to enforce the exclusion.

### 3. [P2] Validate cached classification records before applying their names

**File:** `xExtension-ArticleTopics/lib/ArticleTopicsWorker.php:136-145`

A cache hit is accepted if `names` and `method` are merely set. `names` is then cast to an array and passed directly to `apply()`, which can create labels that are not in the enabled taxonomy (or fail on malformed non-string values). Validate the cache record shape, method, and every name against the enabled taxonomy; discard and recompute invalid entries. This also keeps persisted state from bypassing the classifier's allowed-name check.

## API/configuration checks

FreshRSS 1.30.4 provides the used entry APIs (`FreshRSS_Entry::title()`, `content()`, `tags()`, `date(true)`), `FreshRSS_EntryDAO::searchById()`, and the tag DAO methods used here. `Minz_ModelPdo` exposes the transaction methods called by the extension. I found no blocker in those API calls. The ownership-table operations in `ArticleTopicsRetention::expireOwned()` select from `_at_owned` and delete only those owned pairs inside a PDO transaction; the classifier’s removal path likewise checks ownership before deleting an association.

## Integration resolution

All three findings above were fixed during integration. Sequential explicit optional-field rejection and case-folded exclusions have regression tests; cached names and method are validated against the enabled taxonomy before application. See milestone-2-report.md for final measured checks.
