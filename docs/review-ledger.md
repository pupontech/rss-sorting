# Release review ledger

Requested model override: Luna 6 on the priority service tier, using the sol-loop review procedure. Reviewer usage was not exposed.

## Round 1 ג€” changes requested

- R1, failed-only retries below cursor: declined after reproduction. `run()` selects eligible `retry_ids` before calling cursor-based `nextIds()`. The scan cursor only controls discovering later failures, not retries. Real SQLite assertions now cover an HTTP 500 for the first failure, success on the later failure during cooldown, and resumed recovery of the earlier retry below the cursor. All pass; both worker failures are cleared.
- R2, provider failure prevents later scan progress: declined after reproduction. The per-entry catch calls `recordResult(..., 'failed', ...)`, which advances the scan cursor and independently persists a retry ID. That exact failure-and-later-ID scenario passes in integration. A process interruption before durable recording leaves the cursor before the article, so the idempotent ownership transaction can safely repeat it; existing resumed-page and process interruption checks cover adjacent recovery behavior.
- R3, taxonomy scope selects every article: clarified, not narrowed. PROMPT.md asks for "everything after a taxonomy change". Full reclassification is intentional. UI wording now explicitly says "Reclassify all after taxonomy change".
- R4: reviewer retracted its own claim; the enable transition is already gated by both old and submitted values.

The reproduction exposed an additional real issue: provider failures and optional-field retries were undercounted in LLM-call totals. The transport now counts actual requests, classification returns the request delta, and failed backfill/worker attempts include it. Integration asserts the failed request count.

## Round 2

APPROVE. Reviewer confirmed R1/R2 are resolved by the existing retry/cursor separation and passing reproductions, accepted the taxonomy intent, and found no bug in the request-count changes. No findings remain. Two rounds total; usage was not exposed.
