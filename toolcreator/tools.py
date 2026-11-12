"""The tools an agent sees, and running them.

Mirrors what an AI agent gets through tool_ai_connector, so the eval measures the real thing:
  - function name  = tool id with ':' replaced by '__'
  - description    = the tool's description
  - parameters     = the tool's input JSON Schema (drush tool:info)
  - result text    = "Tool Plugin executed successfully: <message>" + "Output for <key>: <value>" lines

Execution goes through `drush tool:run --uid=<run_as> --json`, i.e. the Tool API's own runner with
its input validation and access checks.
"""
from __future__ import annotations

import json
import time
from pathlib import Path

from . import site


def function_name(tool_id: str) -> str:
    return tool_id.replace(":", "__")


def build_catalog(module_cfg: dict) -> list[dict]:
    """Every tool the agent should see for this module, with its schema."""
    providers = set(module_cfg["expose"].get("providers") or [])
    ids = set(module_cfg["expose"].get("ids") or [])
    listing = site.drush_json("tool:list", "--format=json") or []
    picked = [t for t in listing if t.get("provider") in providers or t.get("id") in ids]
    # The order the agent sees: by id, unless expose.order lists ids to put first (a real agent
    # sees its configured tool list in the order it was saved, so order is a deployment choice).
    rank = {tid: n for n, tid in enumerate(module_cfg["expose"].get("order") or [])}
    catalog = []
    for t in sorted(picked, key=lambda t: (rank.get(t["id"], len(rank)), t["id"])):
        info = site.drush_json("tool:info", t["id"], "--format=json")
        schema = info.get("input_schema") or {"type": "object", "properties": {}}
        schema.setdefault("type", "object")
        schema.setdefault("properties", {})
        if isinstance(schema.get("properties"), list):  # PHP encodes an empty map as []
            schema["properties"] = {}
        catalog.append({
            "id": t["id"],
            "name": function_name(t["id"]),
            "label": info.get("label") or t.get("label"),
            "description": info.get("description") or t.get("description") or "",
            "operation": info.get("operation"),
            "destructive": info.get("destructive"),
            "permission": info.get("permission"),
            "requirements": info.get("requirements"),
            "input_schema": schema,
        })
    return catalog


def openai_functions(catalog: list[dict]) -> list[dict]:
    return [{"type": "function", "function": {
        "name": t["name"], "description": t["description"], "parameters": t["input_schema"]}}
        for t in catalog]


def _stringify(v) -> str:
    if v is None:
        return "NULL"
    if isinstance(v, bool):
        return "true" if v else "false"
    if isinstance(v, (int, float, str)):
        return str(v)
    return json.dumps(v, ensure_ascii=False)


class Runner:
    """Executes tool calls for one trial and records each one to a trace file."""

    def __init__(self, catalog: list[dict], uid: int, trace: Path | None = None):
        self.by_name = {t["name"]: t for t in catalog}
        self.by_name.update({t["id"]: t for t in catalog})
        self.uid = uid
        self.trace = trace
        self.calls: list[dict] = []

    def call(self, name: str, args) -> str:
        t0 = time.time()
        rec: dict = {"function": name, "args": args}
        tool = self.by_name.get(name)
        if tool is None:
            text = (f"Error: there is no tool named '{name}'. Available tools: "
                    + ", ".join(sorted({t['name'] for t in self.by_name.values()})))
            rec.update(tool=None, success=False, error_type="unknown_tool", message=text)
        elif not isinstance(args, dict):
            text = "Tool Plugin executed unsuccessfully: the arguments must be a JSON object."
            rec.update(tool=tool["id"], success=False, error_type="invalid_arguments", message=text)
        else:
            rec["tool"] = tool["id"]
            r = site.drush("tool:run", tool["id"], f"--uid={self.uid}", "--input=" + json.dumps(args),
                           "--json", check=False, timeout=300)
            doc = None
            for line in reversed(r.stdout.strip().splitlines()):
                try:
                    doc = json.loads(line)
                    break
                except json.JSONDecodeError:
                    continue
            if not isinstance(doc, dict):
                text = "Tool Plugin executed unsuccessfully: " + (r.stderr.strip() or r.stdout.strip() or "no output")[-1500:]
                rec.update(success=False, error_type="crash", message=text)
            elif doc.get("success"):
                text = "Tool Plugin executed successfully: " + str(doc.get("message") or "")
                for k, v in (doc.get("outputs") or {}).items():
                    text += f"\nOutput for {k}: {_stringify(v)}"
                rec.update(success=True, error_type=None, message=str(doc.get("message") or ""))
            else:
                msg = str(doc.get("message") or doc.get("error") or "unknown failure")
                text = "Tool Plugin executed unsuccessfully: " + msg
                if doc.get("error_type") == "invalid_input":
                    text += "\nThe tool input schema is: " + json.dumps(tool["input_schema"])
                rec.update(success=False, error_type=doc.get("error_type") or "failed", message=msg)
        rec["ms"] = int((time.time() - t0) * 1000)
        rec["result"] = text
        self.calls.append(rec)
        if self.trace:
            with self.trace.open("a") as f:
                f.write(json.dumps(rec, ensure_ascii=False) + "\n")
        return text
