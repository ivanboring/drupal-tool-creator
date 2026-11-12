"""A minimal stdio MCP server exposing one catalog of Drupal tools.

Claude Code, Codex and OpenCode all speak MCP, so this one bridge lets each of them act as the
agent under test with exactly the same tool names, descriptions and schemas the small models get
through the function-calling harness. Every call is appended to the trace file.

    python3 -m toolcreator.mcp_server --catalog catalog.json --uid 2 --trace trace.jsonl
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

PROTOCOL = "2025-06-18"


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--catalog", required=True)
    ap.add_argument("--uid", type=int, required=True)
    ap.add_argument("--trace")
    a = ap.parse_args(argv)

    from .tools import Runner  # imported late so a broken site never stops the handshake
    catalog = json.loads(Path(a.catalog).read_text())
    runner = Runner(catalog, a.uid, Path(a.trace) if a.trace else None)

    def reply(mid, result=None, error=None):
        msg = {"jsonrpc": "2.0", "id": mid}
        if error is not None:
            msg["error"] = error
        else:
            msg["result"] = result
        sys.stdout.write(json.dumps(msg) + "\n")
        sys.stdout.flush()

    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue
        try:
            req = json.loads(line)
        except json.JSONDecodeError:
            continue
        method, mid = req.get("method"), req.get("id")
        if mid is None:  # notification
            continue
        if method == "initialize":
            ver = (req.get("params") or {}).get("protocolVersion") or PROTOCOL
            reply(mid, {"protocolVersion": ver, "capabilities": {"tools": {"listChanged": False}},
                        "serverInfo": {"name": "drupal", "version": "1.0"}})
        elif method == "tools/list":
            reply(mid, {"tools": [{"name": t["name"], "description": t["description"],
                                   "inputSchema": t["input_schema"]} for t in catalog]})
        elif method == "tools/call":
            p = req.get("params") or {}
            try:
                text = runner.call(p.get("name", ""), p.get("arguments") or {})
                failed = not text.startswith("Tool Plugin executed successfully")
            except Exception as e:  # keep the session alive; the agent sees the error
                text, failed = f"Error running tool: {e}", True
            reply(mid, {"content": [{"type": "text", "text": text}], "isError": failed})
        elif method == "ping":
            reply(mid, {})
        else:
            reply(mid, error={"code": -32601, "message": f"method not found: {method}"})
    return 0


if __name__ == "__main__":
    sys.exit(main())
