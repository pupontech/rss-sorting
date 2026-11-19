# Local M2 integration runner

This harness is for the ignored, disposable `.upstream/FreshRSS` installation only. It refuses any other FreshRSS root and requires release 1.30.4. It prefers `.tools/php/php.exe` on Windows and otherwise uses `php` from `PATH`, so the same runner can be invoked with `pwsh` on Linux. It does not use Docker. Never point it at a live instance.

Run the read-only path check:

```powershell
./tests/integration/run.ps1 -Mode Preflight
```

After the disposable demo user has the mock feed and Article Topics configuration, run the refresh and worker:

```powershell
./tests/integration/run.ps1 -Mode Worker
```

Worker mode copies the extension into that disposable checkout, starts the local mock on `127.0.0.1:8081`, refreshes feeds, and runs the worker. It assumes FreshRSS has already been configured to use the matching local mock URL. The parent integration pass must inspect FreshRSS state and the Google Reader API for the scenario assertions; this runner does not print a pass merely because the worker exits successfully.

The mock provides `/__test__/fixtures` for fixture IDs/text/topics, `/__test__/article` to edit a fixture while retaining its GUID, `/__test__/responses` to queue provider statuses (for example `{"responses":[500]}` or `{"responses":[429]}`), `/__test__/delay` for a bounded provider delay used by the worker-kill recovery test, `/__test__/state` for call counts and queued status count, and `/__test__/reset` to clear request state. These unauthenticated endpoints are test-only. The PowerShell runner binds the mock to loopback and Compose publishes it only on `127.0.0.1`; do not expose the mock beyond loopback or the isolated Compose network.

The control surface and twelve prompt fixtures support scenarios for initial classification, committed content changes, retry after 500/429, cache reuse, manual-versus-owned label retention, and schema migration checks. State assertions and execution of the integration matrix are pending the implementation owner's final pass. No result is implied by fixture availability.

## Full assertions

Run `./tests/integration/run.ps1 -Mode Assertions` against the installed disposable checkout. This creates a fresh m2test-prefixed user and runs `assert_m2.php`, then `assert_backfill.php` when `lib/ArticleTopicsBackfill.php` and `cli/backfill.php` are installed, followed by refresh-isolation checks and the Google Reader API verifier. Backfill assertions cover dry-run isolation, page-resume across service instances, creation-time high-water bounds, unread/feed scopes, read/star preservation, and per-user lock contention. Ports 8081 and 8088 must be free. It stops its servers but retains the test user. The runner reports missing backfill implementation files rather than treating the M2 pass as complete M3/M4 validation.
