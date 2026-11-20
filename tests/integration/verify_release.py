"""Install and exercise the release ZIP on a disposable FreshRSS instance."""
import argparse
from contextlib import closing, contextmanager
import json
import os
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import sys
import tempfile
import time
from urllib.request import urlopen
from zipfile import ZipFile

from playwright.sync_api import expect, sync_playwright


def free_port():
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        return listener.getsockname()[1]


@contextmanager
def server(command, url, log_path, env):
    with log_path.open("w", encoding="utf-8") as log:
        process = subprocess.Popen(command, env=env, stdout=log, stderr=log)
        try:
            for _ in range(80):
                if process.poll() is not None:
                    raise RuntimeError(f"Server exited. See {log_path}")
                try:
                    with urlopen(url, timeout=1):
                        break
                except OSError:
                    time.sleep(0.1)
            else:
                raise RuntimeError(f"Server failed to start. See {log_path}")
            yield
        finally:
            process.terminate()
            process.wait(timeout=10)


def run_php(php, args, cwd, env, exit_code=0, stdin=None):
    result = subprocess.run(
        [php, *map(str, args)], cwd=cwd, env=env, text=True, encoding="utf-8",
        errors="replace", capture_output=True, timeout=40, input=stdin,
    )
    assert result.returncode == exit_code, result.stdout + result.stderr
    return result


def login(page, base, username):
    page.goto(base)
    page.locator("#username").fill(username)
    page.locator("#passwordPlain").fill("fixture-local-only")
    page.locator("#loginButton").click()
    expect(page.locator("#loginButton")).to_have_count(0)


def open_settings(page, base):
    page.goto(base + "?c=extension")
    enable = page.locator('button[formaction*="a=enable"][formaction*="ArticleTopics"]')
    if enable.count():
        enable.click()
    with page.expect_response(lambda response: "a=configure" in response.url
                              and "e=ArticleTopics" in response.url) as request:
        page.locator('a.configure[href*="ArticleTopics"]').click()
    assert request.value.status == 200
    assert "no-store" in request.value.headers.get("cache-control", "")


def settings_and_backfill(page, base, root, extension, php, env, fixtures, linked):
    owner = root / "data/users/stagingowner"
    direct_settings = base + "?c=extension&a=configure&e=ArticleTopics"
    backfill = extension / "cli/backfill.php"
    classifier = extension / "cli/classify.php"
    user = "--user=stagingowner"
    login(page, base, "stagingowner")
    open_settings(page, base)
    rows = page.locator("#topic-rows tr")
    expect(rows.first).to_be_visible()
    initial_count = rows.count()
    page.get_by_role("button", name="Add topic", exact=True).click()
    expect(rows).to_have_count(initial_count + 1)
    rows.last.locator(".topic-name").fill("Release Test Topic")
    rows.last.locator(".topic-positive").fill("phrase absent from the fixture:3")
    page.locator("#enabled").check()
    page.locator("#mode").select_option("keywords")
    with page.expect_response(lambda response: response.request.method == "POST"):
        page.get_by_role("button", name="Save settings", exact=True).click()
    open_settings(page, base)
    expect(rows.last.locator(".topic-name")).to_have_value("Release Test Topic")
    expect(page.locator("#mode")).to_have_value("keywords")
    expect(page.locator("#enabled")).to_be_checked()
    print("PASS Extensions gear adds and saves a topic and retains the classification mode")

    page.goto(direct_settings)
    expect(rows.last.locator(".topic-name")).to_have_value("Release Test Topic")
    page.locator('[name="backfill_scope"]').select_option("feed")
    page.locator('[name="backfill_feed_id"]').fill("1")
    page.get_by_role("checkbox", name="Dry run").check()
    page.get_by_role("button", name="Queue backfill", exact=True).click()
    expect(page.get_by_text("Backfill queued:", exact=False)).to_be_visible()

    with closing(sqlite3.connect(owner / "db.sqlite")) as db:
        state_sql = "SELECT id,id_feed,is_read,is_favorite FROM entry ORDER BY id"
        labels_sql = """SELECT e.title, t.name FROM entry e
            JOIN entrytag et ON e.id=et.id_entry JOIN tag t ON t.id=et.id_tag
            ORDER BY e.title, t.name"""
        original_state = db.execute(state_sql).fetchall()
        original_labels = db.execute(labels_sql).fetchall()
        worker_state = owner / "extensions/ArticleTopics/state.json"
        original_worker_state = worker_state.read_bytes()
        assert len(original_state) == 12 and len(original_labels) == 2

        dry = json.loads(run_php(php, [backfill, user, "--next"], root, env).stdout)
        assert dry["status"] == "complete" and dry["dry_run"] is True
        assert dry["scanned"] == 12 and dry["failed"] == dry["llm_calls"] == 0
        assert db.execute(labels_sql).fetchall() == original_labels
        assert db.execute(state_sql).fetchall() == original_state
        assert worker_state.read_bytes() == original_worker_state

        cli = [backfill, user, "--scope=feed", "--feed-id=1"]
        real = json.loads(run_php(php, cli, root, env).stdout)
        assert real["status"] == "complete" and real["dry_run"] is False
        for key in ("scanned", "labelled", "skipped", "failed", "llm_calls"):
            assert real[key] == dry[key], key
        labels = db.execute(labels_sql).fetchall()
        expected = set(original_labels)
        expected.update((item["title"], "Topic: " + topic)
                        for item in fixtures for topic in item["topics"])
        # This mock fixture rules out a game label. The default keyword taxonomy
        # also explicitly matches its title phrase under Science.
        expected.add(("New Steam turbine technology developed", "Topic: Science"))
        assert set(labels) == expected, (set(labels) - expected, expected - set(labels))
        assert len(labels) == len(expected)
        assert db.execute(state_sql).fetchall() == original_state

        restarted = json.loads(run_php(php, cli, root, env).stdout)
        assert restarted["id"] != real["id"] and restarted["status"] == "complete"
        assert db.execute(labels_sql).fetchall() == labels
        assert db.execute(state_sql).fetchall() == original_state

    print(f"PASS real backfill matched dry run: {real['scanned']} scanned, "
          f"{real['labelled']} labelled, {real['skipped']} skipped, "
          f"{real['failed']} failed, {real['llm_calls']} provider calls")
    print(f"PASS expected labels on all 12 articles, {len(labels)} unique label pairs, "
          "manual labels, read/star states and feed membership preserved after rerun")

    page.goto(direct_settings)
    expect(page.get_by_role("columnheader", name="Would label")).to_be_visible()
    dry_row = page.get_by_role("row").filter(has_text=dry["id"]).get_by_role("cell")
    real_row = page.get_by_role("row").filter(has_text=real["id"]).get_by_role("cell")
    expect(dry_row.nth(4)).to_have_text("–")
    expect(dry_row.nth(5)).to_have_text(str(dry["labelled"]))
    expect(real_row.nth(4)).to_have_text(str(real["labelled"]))
    expect(real_row.nth(5)).to_have_text("–")
    page.goto(base)
    expect(page.get_by_text("Topic: Video Games", exact=True).first).to_be_visible()
    print("PASS dry-run and real counts appear in the correct columns, and the live label appears in FreshRSS")

    result = run_php(php, [classifier, user], root, env)
    assert "ArticleTopics processed 0 article(s)." in result.stdout
    if linked:
        override = {**env, "ARTICLETOPICS_FRESHRSS_ROOT": str(root)}
        real_cli = extension.resolve() / "cli"
        run_php(php, [real_cli / "classify.php", user], root, override)
        run_php(php, [real_cli / "backfill.php", user, "--next"], root, override)
        invalid = {**env, "ARTICLETOPICS_FRESHRSS_ROOT": str(root / "missing")}
        result = run_php(php, [classifier, user], root, invalid, 1)
        assert "FreshRSS root not found" in result.stderr
        print("PASS both CLI workers resolve the linked path automatically and the physical path via override")

    for args in ([classifier], [backfill, "--next"]):
        result = run_php(php, [*args, "--user=stagingguest"], root, env, 1)
        assert "unavailable for this account" in result.stderr


def profile_upgrade(page, base, root, extension, php, env, release):
    owner = root / "data/users/stagingowner"
    profile_dir = owner / "extensions/ArticleTopics"
    profile_path = profile_dir / "keyword-profile.json"
    worker_path = profile_dir / "state.json"
    guest_profile = root / "data/users/stagingguest/extensions/ArticleTopics/keyword-profile.json"
    guest_profile.parent.mkdir(parents=True, exist_ok=True)
    guest_profile.write_text("invalid fixture", encoding="utf-8")

    def topic(name, keywords):
        return {"name": name, "enabled": True, "description": "", "positive_keywords": keywords}

    profile = {
        "topics": [topic("Farm News", {"drought": 1}), topic("Water Research", {"water shortages": 10}),
                   topic("Editor Picks", {}), topic("Developer Tools", {"coding assistant": 1})],
        "feeds": {}, "focused_feeds": [], "fallback": "Uncategorized",
    }

    def save_profile():
        profile_path.write_text(json.dumps(profile), encoding="utf-8")

    save_profile()
    before = {p: p.read_bytes() for p in (profile_path, worker_path, profile_dir / "jobs.json")}
    with ZipFile(release) as archive:
        archive.extractall(extension.parent)
    assert all(path.read_bytes() == content for path, content in before.items())

    page.goto(base + "?c=extension&a=configure&e=ArticleTopics")
    expect(page.get_by_text("Active keyword profile:", exact=False)).to_contain_text("Uncategorized")
    page.locator('[name="worker_limit"]').fill("2")
    page.get_by_role("button", name="Save settings", exact=True).click()
    page.locator('[name="backfill_scope"]').select_option("unread")
    page.get_by_role("button", name="Queue backfill", exact=True).click()
    expect(page.get_by_text("Backfill queued:", exact=False)).to_be_visible()

    cli = extension.resolve() / "cli"
    env = {**env, "ARTICLETOPICS_FRESHRSS_ROOT": str(root)}
    backfill = [cli / "backfill.php", "--user=stagingowner", "--next"]
    classify = [cli / "classify.php", "--user=stagingowner", "--limit=50"]
    with closing(sqlite3.connect(owner / "db.sqlite")) as db:
        state_sql = "SELECT id,id_feed,is_read,is_favorite FROM entry ORDER BY id"
        original_entries = db.execute(state_sql).fetchall()
        read_id, feed_id, *_ = next(row for row in original_entries if row[2])
        read_key = json.loads(worker_path.read_text())["fingerprints"][str(read_id)]

        def labels(title):
            return {name for (name,) in db.execute(
                """SELECT t.name FROM tag t JOIN entrytag et ON t.id=et.id_tag
                   JOIN entry e ON e.id=et.id_entry WHERE e.title=?""", (title,))}

        read_title = "Nintendo announces new Zelda game"
        read_labels = labels(read_title)
        partial = json.loads(run_php(php, backfill, root, env).stdout)
        assert partial["status"] == "pending" and partial["scanned"] == 2, partial
        done = json.loads(run_php(php, [*backfill, "--limit=50", "--seconds=30"], root, env).stdout)
        assert done["id"] == partial["id"] and done["status"] == "complete"
        assert done["labelled"] == 11 and done["failed"] == done["llm_calls"] == 0
        assert labels("Apple farmers face drought") == {"Topic: Farm News"}
        assert labels("New Steam turbine technology developed") == {"Topic: Uncategorized"}

        profile["feeds"] = {str(feed_id): "Editor Picks"}
        save_profile()
        run_php(php, classify, root, env)
        assert labels("Apple farmers face drought") == {"Topic: Farm News"}
        assert labels("New Steam turbine technology developed") == {"Topic: Editor Picks"}

        profile["focused_feeds"] = [feed_id]
        save_profile()
        run_php(php, classify, root, env)
        assert labels("New AI coding assistant released") == {"Topic: Editor Picks", "Topic: Developer Tools"}
        assert labels("New Steam turbine technology developed") == {"Topic: Editor Picks"}

        profile["feeds"] = {}
        profile["focused_feeds"] = []
        save_profile()
        run_php(php, classify, root, env)
        assert labels("New Steam turbine technology developed") == {"Topic: Uncategorized"}
        state = json.loads(worker_path.read_text())
        entry_ids = dict(db.execute("SELECT title,id FROM entry"))
        replay_ids = {int(read_id), int(entry_ids["New Steam turbine technology developed"]),
                      int(entry_ids["New AI coding assistant released"])}
        state["pending"] = sorted(replay_ids)
        state["processed_ids"] = [entry_id for entry_id in state["processed_ids"] if entry_id not in replay_ids]
        prior_hits = state["metrics"]["cache_hits"]
        worker_path.write_text(json.dumps(state), encoding="utf-8")
        run_php(php, classify, root, env)
        state = json.loads(worker_path.read_text())
        assert state["metrics"]["cache_hits"] == prior_hits + 2, state["metrics"]
        assert state["fingerprints"][str(read_id)] == read_key
        assert labels(read_title) == read_labels == {"Manual test label", "Topic: Video Games"}
        assert db.execute(state_sql).fetchall() == original_entries

    print("PASS in-place upgrade, per-user profile, saved and explicit batch budgets, feed priority, "
          "exclusive fallback, cached results, and preservation of read and manual labels")


def verify_new_arrivals_trial(page, base, root, extension, php, env, mock):
    owner = root / "data/users/stagingowner"
    state_path = owner / "extensions/ArticleTopics/state.json"
    settings = base + "?c=extension&a=configure&e=ArticleTopics"
    with closing(sqlite3.connect(owner / "db.sqlite")) as db:
        entry, tag = db.execute(
            "SELECT o.id_entry,o.id_tag FROM at_owned o JOIN entry e ON e.id=o.id_entry WHERE e.is_read=0 LIMIT 1"
        ).fetchone()
        state = json.loads(state_path.read_text())
        state["failures"] = {str(entry): {
            "reason": "quota", "attempts": 1, "next_retry_at": int(time.time()) + 600,
            "first_at": int(time.time()), "last_error": "Fixture quota refusal", "exhausted": False,
        }}
        state_path.write_text(json.dumps(state))
        page.goto(base + "?get=a")
        notice = page.locator("#nav_menu_hooks")
        expect(notice.get_by_text("Affected articles: 1.", exact=False)).to_be_visible()
        notice.get_by_role("link", name="Settings and retry").click()
        expect(page).to_have_url(settings + "#article-topics-retry")
        csrf = page.locator("#article-topics-form input[name=_csrf]").input_value()
        result = page.request.post(base + "?c=tag&a=tagEntry&ajax=1", data={
            "_csrf": csrf, "id_tag": tag, "id_entry": str(entry), "checked": False, "ajax": 1,
        })
        assert result.ok, result.text()
        assert db.execute("SELECT 1 FROM at_override WHERE id_entry=?", (entry,)).fetchone()
        page.get_by_role("button", name="Retry failed classifications").click()
        expect(page.get_by_text("Queued 1 article(s) for the next classifier run.")).to_be_visible()
        run_php(php, [extension / "cli/classify.php", "--user=stagingowner"], root, env)
        assert not db.execute("SELECT 1 FROM entrytag WHERE id_entry=? AND id_tag=?", (entry, tag)).fetchone()
        page.goto(base + "?get=a&fixture=resolved")
        expect(page.locator("#nav_menu_hooks")).to_have_count(0)

        baseline = db.execute("SELECT id_entry,id_tag FROM entrytag ORDER BY id_entry,id_tag").fetchall()
        start = extension / "cli/start-trial.php"
        started = run_php(php, [start, "--user=stagingowner", "--api-url=" + mock, "--model=fixture"],
                          root, env, stdin="fixture-token\n")
        assert "Protected 12 existing articles" in started.stdout
        state = json.loads(state_path.read_text())
        assert state["fingerprints"] == [] and state["pending"] == [] and len(state["processed_ids"]) == 12
        page.goto(settings)
        expect(page.locator("#mode")).to_have_value("hybrid")
        expect(page.locator('[name="max_topics"]')).to_have_value("1")
        expect(page.get_by_text("New-arrivals trial started", exact=False)).to_be_visible()

        # Delayed feed commits can carry an ID lower than the activation high-water mark.
        delayed = min(state["processed_ids"]) - 1
        db.execute("""INSERT INTO entry(id,guid,title,link,is_read,is_favorite,id_feed,date,content)
                    VALUES(?,?,?,?,0,0,1,?,?)""",
                   (delayed, "delayed-trial", "Home server and homelab", "https://example.invalid/trial",
                    int(time.time()), "Running services on a spare machine."))
        db.commit()
        classified = run_php(php, [extension / "cli/classify.php", "--user=stagingowner"], root, env)
        assert "processed 1 article(s)" in classified.stdout
        assert db.execute("SELECT id_entry,id_tag FROM entrytag WHERE id_entry<>? ORDER BY id_entry,id_tag",
                          (delayed,)).fetchall() == baseline
        assert db.execute("""SELECT t.name FROM entrytag e JOIN tag t ON t.id=e.id_tag WHERE e.id_entry=?""",
                          (delayed,)).fetchall() == [("Topic: Self-Hosting & Systems",)]
        assert json.loads(state_path.read_text())["trial_articles"] == [delayed]
    print("PASS reading notice, native tag removal, retry, and Hybrid trial scoped to new arrivals")


def verify_model_picker(page, base, mock):
    open_settings(page, base)
    form = page.locator("#article-topics-form")
    provider = form.locator("#api_url")
    key = form.locator("#api_key")
    model = form.locator("#model")
    choices = form.get_by_role("combobox", name="Available models")
    load = form.get_by_role("button", name="Load models")
    status = form.locator("#article-topics-model-status")

    provider.fill(mock + "/chat/completions/")
    load.click()
    expect(choices).to_be_visible()
    assert choices.locator("option").all_text_contents() == ["Choose a model", "fixture-2", "fixture-10"]
    choices.select_option("fixture-10")
    expect(model).to_have_value("fixture-10")
    form.get_by_role("button", name="Save settings", exact=True).click()
    open_settings(page, base)
    expect(model).to_have_value("fixture-10")

    provider.fill(mock + "/protected")
    expect(choices).to_be_hidden()
    load.click()
    expect(status).to_contain_text("denied access")
    key.fill("replacement-token")
    load.click()
    expect(choices).to_be_visible()
    expect(key).to_have_value("replacement-token")
    key.fill("")
    provider.fill(mock + "/anonymous")
    load.click()
    expect(choices).to_be_visible()
    choices.select_option("public-model")
    expect(model).to_have_value("public-model")

    provider.fill(mock + "/unlisted")
    load.click()
    expect(status).to_contain_text("Enter a model ID.")
    model.fill("manual-model")
    form.get_by_role("button", name="Save settings", exact=True).click()
    open_settings(page, base)
    expect(model).to_have_value("manual-model")
    expect(form.get_by_text("Leave blank for providers without authentication.")).to_be_visible()
    print("PASS model discovery in the Extensions slider, saved and entered keys, provider isolation, and manual entry")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--freshrss", type=Path, required=True)
    parser.add_argument("--release", type=Path, required=True)
    parser.add_argument("--previous", type=Path, help="Install this ZIP first and validate a profile upgrade")
    parser.add_argument("--php", type=Path, required=True)
    parser.add_argument("--chromium", type=Path, required=True)
    parser.add_argument("--linked", action="store_true")
    args = parser.parse_args()

    repo = Path(__file__).resolve().parents[2]
    scratch = Path(tempfile.mkdtemp(prefix="article-topics-release-test-", dir=repo.parent))
    shutil.unpack_archive(args.freshrss, scratch)
    root, = scratch.glob("FreshRSS-*")
    print(f"Testing {root.name} in {scratch}", flush=True)
    env = dict(os.environ)
    env.pop("ARTICLETOPICS_FRESHRSS_ROOT", None)
    php = args.php.resolve()
    web_port = free_port()
    origin = f"http://127.0.0.1:{web_port}"
    base = origin + "/i/"
    run_php(php, [root / "cli/do-install.php", "--default-user=stagingowner",
                  f"--base-url={origin}", "--language=en", "--db-type=sqlite", "--auth-type=form"], root, env)
    for user in ("stagingowner", "stagingguest"):
        run_php(php, [root / "cli/create-user.php", f"--user={user}", "--no-default-feeds",
                      "--password=fixture-local-only"], root, env)

    destination = root / "extensions"
    if args.linked:
        destination.rename(scratch / "extensions-original")
        destination = scratch / "persistent/extensions"
        destination.mkdir(parents=True)
        if os.name == "nt":
            subprocess.run(["cmd.exe", "/d", "/c", "mklink", "/J",
                            str(root / "extensions"), str(destination)], check=True)
        else:
            (root / "extensions").symlink_to(destination, target_is_directory=True)
    with ZipFile(args.previous or args.release) as archive:
        archive.extractall(destination)
    extension = root / "extensions/xExtension-ArticleTopics"
    mock_port = free_port()
    mock = f"http://127.0.0.1:{mock_port}/"
    mock_server = [sys.executable, str(repo / "tests/mock/server.py")]
    web_server = [php, "-S", f"127.0.0.1:{web_port}", "-t", root / "p"]
    with server(mock_server, mock + "health", scratch / "mock.log",
                {**env, "HOST": "127.0.0.1", "PORT": str(mock_port)}):
        run_php(php, [repo / "tests/integration/seed_release.php", root, mock + "feed.xml"], root, env)
        with urlopen(mock + "__test__/fixtures") as response:
            fixtures = json.load(response)
        with server(web_server, base, scratch / "web.log", env), sync_playwright() as playwright:
            browser = playwright.chromium.launch(executable_path=str(args.chromium))
            owner_page = browser.new_page()
            owner_page.set_default_navigation_timeout(12000)
            settings_and_backfill(owner_page, base, root, extension, php, env, fixtures, args.linked)
            guest_page = browser.new_page()
            guest_page.set_default_navigation_timeout(12000)
            login(guest_page, base, "stagingguest")
            open_settings(guest_page, base)
            expect(guest_page.get_by_text("Article Topics is unavailable for this account.")).to_be_visible()
            expect(guest_page.locator("#article-topics-form")).to_have_count(0)
            assert not (root / "data/users/stagingguest/extensions/ArticleTopics/jobs.json").exists()
            print("PASS unlisted account cannot open the settings or run either CLI worker")
            if args.previous:
                profile_upgrade(owner_page, base, root, extension, php, env, args.release)
                verify_new_arrivals_trial(owner_page, base, root, extension, php, env, mock.rstrip("/"))
                verify_model_picker(owner_page, base, mock.rstrip("/"))


if __name__ == "__main__":
    main()
