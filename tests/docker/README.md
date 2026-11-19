# FreshRSS Docker test environment

This harness targets the locally verified FreshRSS **1.30.4** release and uses SQLite, one `demo` user, and a deterministic Python standard-library mock for RSS and OpenAI-compatible chat completions. It does not modify production configuration or data.

## Run

From the repository root, with Docker Desktop and Compose v2 available:

```powershell
./tests/docker/run.ps1
```

The runner builds the mock server, starts FreshRSS, automatically installs it, creates the `demo` user (web password `demo`, API password `demo-api`), enables ArticleTopics with a small Video Games/Finance taxonomy, subscribes it to the unrelated **General Tech Daily** fixture feed, runs the extension CLI worker, and then runs `tests/api/verify_greader.php` when supplied. The fixture includes a Nintendo Zelda story and a separate finance story. The OpenAI-compatible endpoint is `http://mock:8081/v1/chat/completions`; its outputs are fixed by article content and require no external service or secret.

FreshRSS is available at <http://localhost:8080>. The mock is mapped to `localhost:8081` for inspection. Test state persists in the named `freshrss-data` volume across runs. To reset the environment and lose its test data, run `docker compose -f docker-compose.test.yml down -v` from the repository root.

The compose file mounts the working `xExtension-ArticleTopics` source read-only into the official release image. The fixture setup uses FreshRSS 1.30.4 CLI bootstrapping and its feed controller; it never reaches out to real feeds or an LLM provider.
