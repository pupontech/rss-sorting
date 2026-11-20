"""Deterministic local RSS and OpenAI-compatible test server (Python stdlib only).

The /__test__ endpoints are intentionally unauthenticated and must only be bound
to loopback or used inside the isolated test container.
"""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from email.utils import formatdate
from threading import Lock
import json
import os
import re
import xml.etree.ElementTree as ET


FIXTURES = [
    ("Nintendo announces new Zelda game", "Nintendo revealed its next Zelda game. A new adventure for Nintendo Switch launches next year.", ["Video Games"]),
    ("Best 10GbE network switches for NAS builds", "A guide to 10GbE network switches, NAS builds, storage and home servers.", ["Hardware", "Self-Hosting"]),
    ("Apple releases macOS update", "Apple released a macOS software update with new desktop features.", ["Technology"]),
    ("Apple farmers face drought", "Apple farmers face drought, crop losses and water shortages across the region.", []),
    ("Steam Deck performance benchmarks", "Benchmarks compare Steam Deck gaming performance, frame rates and hardware.", ["Video Games", "Hardware"]),
    ("New Steam turbine technology developed", "Engineers developed a more efficient steam turbine for power generation.", []),
    ("New AI coding assistant released", "A new AI coding assistant helps software developers write and review code.", ["Artificial Intelligence", "Software Development"]),
    ("Financial markets respond to inflation figures", "Financial markets and bond yields moved after new inflation figures.", ["Finance"]),
    ("Microsoft patches critical Windows vulnerability", "Microsoft patched a critical Windows security vulnerability exploited by attackers.", ["Cybersecurity", "Technology"]),
    ("Game developer announces layoffs", "A video game developer announced studio layoffs after a difficult year.", ["Video Games", "Business"]),
    ("New open-source NAS software released", "A new open-source NAS software project helps users self-host storage services.", ["Self-Hosting", "Software Development"]),
    ("New discovery in astrophysics", "Astronomers report a new astrophysics discovery about distant galaxies.", ["Science"]),
]
ARTICLES = [dict(id=f"m2-{i:03}", title=title, description=body,
                 category=["General"], link=f"https://example.invalid/m2/{i:03}",
                 guid=f"m2-{i:03}", pubDate=formatdate(usegmt=True), expected=topics)
           for i, (title, body, topics) in enumerate(FIXTURES, 1)]
STATE_LOCK = Lock()
STATE = {"responses": [], "calls": 0, "last_request": None, "delay_ms": 0}


class Handler(BaseHTTPRequestHandler):
    def respond(self, status, body, content_type="application/json"):
        data = body.encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        if self.path == "/health":
            return self.respond(200, "ok", "text/plain")
        if self.path in ("/models", "/protected/models", "/anonymous/models"):
            expected = {"/models": "Bearer fixture-token", "/protected/models": "Bearer replacement-token",
                        "/anonymous/models": None}[self.path]
            if self.headers.get("Authorization") != expected:
                return self.respond(401, "{}")
            ids = ["public-model"] if expected is None else ["fixture-10", "fixture-2", "fixture-2"]
            return self.respond(200, json.dumps({"data": [{"id": id_} for id_ in ids]}))
        if self.path in ("/feed.xml", "/general-tech.xml"):
            channel = ET.Element("channel")
            ET.SubElement(channel, "title").text = "General Tech Daily"
            ET.SubElement(channel, "link").text = "http://mock:8081/"
            ET.SubElement(channel, "description").text = "General interest fixture feed"
            for item in ARTICLES:
                node = ET.SubElement(channel, "item")
                for key in ("title", "description", "link", "guid", "pubDate"):
                    ET.SubElement(node, key).text = item[key]
                for category in item["category"]:
                    ET.SubElement(node, "category").text = category
            return self.respond(200, self.rss(channel), "application/rss+xml; charset=utf-8")
        if self.path == "/__test__/state":
            with STATE_LOCK:
                return self.respond(200, json.dumps({"calls": STATE["calls"], "last_request": STATE["last_request"], "remaining_responses": len(STATE["responses"])}))
        if self.path == "/__test__/fixtures":
            return self.respond(200, json.dumps([{"id": a["id"], "title": a["title"], "content": a["description"], "topics": a["expected"]} for a in ARTICLES]))
        return self.respond(404, "not found", "text/plain")

    @staticmethod
    def rss(channel):
        root = ET.Element("rss", version="2.0")
        root.append(channel)
        return '<?xml version="1.0"?>' + ET.tostring(root, encoding="unicode")

    def do_POST(self):
        length = min(int(self.headers.get("Content-Length", "0")), 1_000_000)
        try:
            payload = json.loads(self.rfile.read(length) or b"{}")
        except (json.JSONDecodeError, UnicodeDecodeError):
            return self.respond(400, json.dumps({"error": "invalid JSON"}))
        if self.path == "/__test__/reset":
            with STATE_LOCK:
                STATE.update(responses=[], calls=0, last_request=None, delay_ms=0)
            return self.respond(200, json.dumps({"ok": True}))
        if self.path == "/__test__/responses":
            responses = payload.get("responses")
            if not isinstance(responses, list) or any(not isinstance(x, int) or x not in (200, 429, 500, 503) for x in responses):
                return self.respond(400, json.dumps({"error": "responses must be a list of 200, 429, 500, or 503 statuses"}))
            with STATE_LOCK:
                STATE["responses"] = list(responses)
            return self.respond(200, json.dumps({"queued": len(responses)}))
        if self.path == "/__test__/delay":
            delay = payload.get("delay_ms")
            if not isinstance(delay, int) or delay < 0 or delay > 30000:
                return self.respond(400, json.dumps({"error": "delay_ms must be an integer from 0 to 30000"}))
            with STATE_LOCK:
                STATE["delay_ms"] = delay
            return self.respond(200, json.dumps({"delay_ms": delay}))
        if self.path == "/__test__/article":
            article_id = payload.get("id")
            article = next((x for x in ARTICLES if x["id"] == article_id), None)
            if article is None:
                return self.respond(404, json.dumps({"error": "unknown fixture id"}))
            if "title" in payload:
                if not isinstance(payload["title"], str): return self.respond(400, json.dumps({"error": "title must be text"}))
                article["title"] = payload["title"]
            if "content" in payload:
                if not isinstance(payload["content"], str): return self.respond(400, json.dumps({"error": "content must be text"}))
                article["description"] = payload["content"]
            if "topics" in payload:
                if not isinstance(payload["topics"], list) or any(not isinstance(x, str) for x in payload["topics"]):
                    return self.respond(400, json.dumps({"error": "topics must be a list of names"}))
                article["expected"] = payload["topics"]
            return self.respond(200, json.dumps({"updated": article_id}))
        if self.path not in ("/v1/chat/completions", "/chat/completions"):
            return self.respond(404, "not found", "text/plain")
        with STATE_LOCK:
            STATE["calls"] += 1
            STATE["last_request"] = payload
            status = STATE["responses"].pop(0) if STATE["responses"] else 200
            delay_ms = STATE["delay_ms"]
        if delay_ms:
            import time
            time.sleep(delay_ms / 1000)
        if status != 200:
            return self.respond(status, json.dumps({"error": {"message": "scripted test outage", "type": "mock_outage"}}))
        prompt = " ".join(str(m.get("content", "")) for m in payload.get("messages", []) if isinstance(m, dict))
        # Match only explicit article title/body data, never the taxonomy descriptions.
        match = re.search(r'"title"\s*:\s*"((?:[^"\\]|\\.)*)"', prompt)
        title = json.loads('"' + match.group(1) + '"') if match else ""
        topics = []
        for article in ARTICLES:
            if article["title"].casefold() == title.casefold():
                topics = article["expected"]
                break
        response = {"id": "chatcmpl-test", "object": "chat.completion", "created": 0,
                    "model": payload.get("model", "mock-model"),
                    "choices": [{"index": 0, "message": {"role": "assistant", "content": json.dumps({"categories": [{"name": t, "confidence": 0.99} for t in topics]})}, "finish_reason": "stop"}]}
        return self.respond(200, json.dumps(response))

    def log_message(self, fmt, *args):
        print("mock: " + fmt % args)


ThreadingHTTPServer((os.environ.get("HOST", "0.0.0.0"), int(os.environ.get("PORT", "8081"))), Handler).serve_forever()
