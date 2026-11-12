"""Coding-agent CLIs as the agent under test: Claude Code, Codex, OpenCode.

Each one gets the Drupal tools over the stdio MCP bridge (toolcreator.mcp_server) and nothing
else that can change the site. Each runs headless in an empty temporary directory outside the repo,
so no AGENTS.md, CLAUDE.md or skill from this project leaks into what is being measured.

Tool calls are counted from the bridge's trace, not parsed from each CLI's output, so every backend
is measured the same way.
"""
from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
from pathlib import Path

from .. import config
from . import Outcome, Trial

TIMEOUT = int(config.env("TC_AGENT_TIMEOUT", "900"))


def _bridge_cmd(t: Trial) -> list[str]:
    catalog = t.workdir / "catalog.json"
    catalog.write_text(json.dumps(t.catalog))
    return [sys.executable, "-I", "-c",
            "import sys; sys.path.insert(0, sys.argv[1]); from toolcreator.mcp_server import main; "
            "sys.exit(main(sys.argv[2:]))",
            str(config.ROOT), "--catalog", str(catalog), "--uid", str(t.uid), "--trace", str(t.trace)]


def _isolated_home(t: Trial, name: str, files: list[str]) -> Path:
    """A throwaway config dir holding only the credentials from the real one.

    The ddev assistant add-ons copy the user's whole host config (global CLAUDE.md, skills, hooks,
    AGENTS.md) into the container. None of that may reach the agent under test, so each trial
    gets a fresh config dir with nothing in it but the login.
    """
    d = t.workdir / name
    d.mkdir(parents=True, exist_ok=True)
    for f in files:
        src = Path(os.path.expanduser(f))
        if src.is_file():
            shutil.copy(src, d / src.name)
    return d


def _run(cmd: list[str], t: Trial, env: dict | None = None) -> tuple[str, str, int]:
    try:
        r = subprocess.run(cmd, cwd=t.workdir, capture_output=True, text=True, timeout=TIMEOUT,
                           env={**os.environ, **(env or {})}, stdin=subprocess.DEVNULL)
        return r.stdout, r.stderr, r.returncode
    except subprocess.TimeoutExpired as e:
        return (e.stdout or b"").decode(errors="replace") if isinstance(e.stdout, bytes) else (e.stdout or ""), \
            f"timed out after {TIMEOUT}s", 124


def _jsonl(text: str) -> list[dict]:
    out = []
    for line in text.splitlines():
        line = line.strip()
        if line.startswith("{"):
            try:
                out.append(json.loads(line))
            except json.JSONDecodeError:
                pass
    return out


class ClaudeAgent:
    def __init__(self, model: str):
        self.model = model

    def run(self, t: Trial) -> Outcome:
        mcp = t.workdir / "mcp.json"
        bridge = _bridge_cmd(t)
        mcp.write_text(json.dumps({"mcpServers": {"drupal": {"type": "stdio", "command": bridge[0], "args": bridge[1:]}}}))
        cmd = ["claude", "-p", t.prompt, "--model", self.model, "--system-prompt", t.system_prompt,
               "--mcp-config", str(mcp), "--strict-mcp-config",
               "--tools", "",                      # no built-in tools: no shell, no file access
               "--allowedTools", "mcp__drupal",    # every drupal tool, pre-approved
               "--max-turns", str(t.max_turns), "--output-format", "json",
               "--no-session-persistence"]
        home = _isolated_home(t, "claude-config", ["~/.claude/.credentials.json"])
        stdout, stderr, code = _run(cmd, t, env={"CLAUDE_CONFIG_DIR": str(home)})
        (t.workdir / "agent-stdout.txt").write_text(stdout + "\n--- stderr ---\n" + stderr)
        out = Outcome(transcript=[{"stdout": stdout[-20000:], "stderr": stderr[-4000:]}])
        try:
            doc = json.loads(stdout.strip().splitlines()[-1])
            out.answer = doc.get("result") or ""
            out.turns = int(doc.get("num_turns") or 0)
            u = doc.get("usage") or {}
            out.input_tokens = int(u.get("input_tokens", 0)) + int(u.get("cache_read_input_tokens", 0)) \
                + int(u.get("cache_creation_input_tokens", 0))
            out.output_tokens = int(u.get("output_tokens", 0))
            if doc.get("is_error") or doc.get("subtype") not in (None, "success"):
                out.error = f"claude: {doc.get('subtype')}"
        except (json.JSONDecodeError, IndexError):
            out.error = f"claude exited {code}: {(stderr or stdout)[-800:]}"
        return out


class CodexAgent:
    def __init__(self, model: str):
        self.model = model

    def run(self, t: Trial) -> Outcome:
        bridge = _bridge_cmd(t)
        last = t.workdir / "last-message.txt"
        prompt = f"{t.system_prompt}\n\nThe user's request:\n{t.prompt}"
        # Codex runs MCP servers inside its own sandbox, and a read-only sandbox blocks the bridge's
        # database connection. So the sandbox is off and every built-in tool that could reach the
        # site another way is disabled instead: no shell, no exec, no apply_patch, no browser,
        # apps or plugins. What remains is the Drupal MCP tools.
        off = ["shell_tool", "unified_exec", "apps", "plugins", "browser_use", "browser_use_external",
               "in_app_browser", "computer_use"]
        cmd = ["codex", "exec", "--skip-git-repo-check", "--json", "-m", self.model,
               "-s", "danger-full-access", "-c", "include_apply_patch_tool=false",
               "-c", "web_search=\"disabled\"",
               *[x for f in off for x in ("--disable", f)],
               "-C", str(t.workdir), "-o", str(last),
               "-c", f"mcp_servers.drupal.command={json.dumps(bridge[0])}",
               "-c", f"mcp_servers.drupal.args={json.dumps(bridge[1:])}",
               "-c", "mcp_servers.drupal.tool_timeout_sec=300",
               # Codex gives MCP servers a stripped environment; Drupal's settings.php only loads
               # the ddev database settings when IS_DDEV_PROJECT is set, so pass the ddev env on.
               "-c", "mcp_servers.drupal.env={" + ", ".join(
                   f"{k}={json.dumps(v)}" for k, v in os.environ.items()
                   if k.startswith(("DDEV_", "IS_DDEV", "TC_")) or k in ("PATH", "HOME", "LANG")) + "}",
               # exec cannot ask, so pre-approve the Drupal tools (and only them).
               "-c", "mcp_servers.drupal.default_tools_approval_mode=\"approve\"",
               prompt]
        home = _isolated_home(t, "codex-home", ["~/.codex/auth.json"])
        stdout, stderr, code = _run(cmd, t, env={"CODEX_HOME": str(home)})
        (t.workdir / "agent-stdout.txt").write_text(stdout + "\n--- stderr ---\n" + stderr)
        out = Outcome(transcript=_jsonl(stdout)[-400:])
        out.answer = last.read_text() if last.is_file() else ""
        for ev in out.transcript:
            if ev.get("type") == "turn.completed":
                u = ev.get("usage") or {}
                out.input_tokens += int(u.get("input_tokens", 0))
                out.output_tokens += int(u.get("output_tokens", 0))
                out.turns += 1
            if ev.get("type") == "item.completed" and (ev.get("item") or {}).get("type") == "command_execution":
                # Recorded so a reviewer can see the agent tried to work around the tools.
                out.transcript.append({"note": "codex ran a shell command (read-only sandbox)"})
        if code != 0 and not out.answer:
            out.error = f"codex exited {code}: {stderr[-800:]}"
        return out


class OpenCodeAgent:
    """opencode:<provider>/<model>. A provider that matches a TC endpoint is declared from .env;
    any other name is passed through to OpenCode's own providers (anthropic, openai, ...)."""

    OFF = {k: False for k in ("bash", "edit", "write", "read", "grep", "glob", "list", "patch",
                              "todowrite", "todoread", "webfetch", "websearch", "task", "skill", "codesearch")}

    def __init__(self, spec: str):
        self.provider, _, self.model = spec.partition("/")
        if not self.model:
            raise SystemExit("opencode agent spec must be opencode:<provider>/<model>")

    def run(self, t: Trial) -> Outcome:
        bridge = _bridge_cmd(t)
        cfg: dict = {
            "$schema": "https://opencode.ai/config.json",
            "model": f"{self.provider}/{self.model}",
            "mcp": {"drupal": {"type": "local", "command": bridge, "enabled": True, "timeout": 300000}},
            "tools": dict(self.OFF),
            "agent": {"drupal": {"mode": "primary", "prompt": t.system_prompt,
                                 "tools": {**self.OFF, "drupal_*": True}}},
            "permission": {"bash": "deny", "edit": "deny", "webfetch": "deny"},
            "share": "disabled", "autoupdate": False,
        }
        ep = config.endpoints().get(self.provider)
        if ep:
            cfg["provider"] = {self.provider: {
                "npm": "@ai-sdk/openai-compatible", "name": self.provider,
                "options": {"baseURL": ep["url"], "apiKey": ep["key"]},
                "models": {self.model: {"name": self.model, "tool_call": True}}}}
        cfg_file = t.workdir / "opencode.json"
        cfg_file.write_text(json.dumps(cfg, indent=2))
        cmd = ["opencode", "run", "--agent", "drupal", "-m", f"{self.provider}/{self.model}",
               "--format", "json", t.prompt]
        # A fresh XDG config dir keeps the user's global opencode.json and AGENTS.md out; the data
        # dir (where `opencode auth login` keeps provider keys) stays, for non-TC providers.
        xdg = t.workdir / "xdg-config"
        xdg.mkdir(exist_ok=True)
        stdout, stderr, code = _run(cmd, t, env={"OPENCODE_CONFIG": str(cfg_file), "XDG_CONFIG_HOME": str(xdg),
                                                 "OPENCODE_DISABLE_PROJECT_CONFIG": "1"})
        (t.workdir / "agent-stdout.txt").write_text(stdout + "\n--- stderr ---\n" + stderr)
        events = _jsonl(stdout)
        out = Outcome(transcript=events[-400:])
        texts = []
        for ev in events:
            part = ev.get("part") or {}
            if ev.get("type") == "text" and part.get("text"):
                texts.append(part["text"])
            if ev.get("type") in ("step_finish", "step-finish"):
                out.turns += 1
                tok = part.get("tokens") or {}
                out.input_tokens += int(tok.get("input", 0))
                out.output_tokens += int(tok.get("output", 0))
        out.answer = texts[-1] if texts else ""
        if code != 0 and not out.answer:
            out.error = f"opencode exited {code}: {(stderr or stdout)[-800:]}"
        return out
