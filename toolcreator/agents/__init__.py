"""Agent backends: what plays the Drupal agent in a trial.

Agent spec strings:
  fc:<endpoint>/<model>      built-in function-calling loop against an OpenAI-compatible endpoint
                             (endpoints come from TC_ENDPOINT_<NAME>_URL/_KEY in .env)
                             e.g. fc:ollama/qwen3-coder:30b, fc:openrouter/qwen/qwen3-30b-a3b
  claude:<model>             Claude Code headless, tools over MCP only, e.g. claude:haiku
  codex:<model>              Codex exec, tools over MCP, no shell or patch tools, e.g. codex:gpt-5.1-codex-mini
  opencode:<provider/model>  OpenCode run, tools over MCP only; providers are the TC endpoints,
                             e.g. opencode:ollama/qwen3-coder:30b
"""
from __future__ import annotations

import re
from dataclasses import dataclass, field
from pathlib import Path


@dataclass
class Trial:
    prompt: str
    system_prompt: str
    catalog: list[dict]
    uid: int
    trace: Path
    workdir: Path
    max_turns: int
    followups: list[str] = field(default_factory=list)
    # Called after each agent stop to decide whether to send a followup (fc only).
    done: object = None


@dataclass
class Outcome:
    answer: str = ""
    turns: int = 0
    input_tokens: int = 0
    output_tokens: int = 0
    error: str | None = None
    text_tool_calls: int = 0      # calls recovered from message text (fc only), see agents/fc.py
    transcript: list = field(default_factory=list)


def slug(spec: str) -> str:
    return re.sub(r"[^A-Za-z0-9._-]+", "_", spec).strip("_")


def get(spec: str):
    kind, _, rest = spec.partition(":")
    if not rest:
        raise SystemExit(f"agent spec '{spec}' must look like fc:<endpoint>/<model>, claude:<model>, "
                         "codex:<model> or opencode:<provider>/<model>")
    if kind == "fc":
        from .fc import FunctionCallingAgent
        return FunctionCallingAgent(rest)
    if kind == "claude":
        from .cli import ClaudeAgent
        return ClaudeAgent(rest)
    if kind == "codex":
        from .cli import CodexAgent
        return CodexAgent(rest)
    if kind == "opencode":
        from .cli import OpenCodeAgent
        return OpenCodeAgent(rest)
    raise SystemExit(f"unknown agent kind '{kind}' in '{spec}'")
