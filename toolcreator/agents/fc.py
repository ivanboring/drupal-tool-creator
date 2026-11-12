"""The built-in function-calling agent loop, for small models.

Talks to any OpenAI-compatible /chat/completions endpoint. For Ollama it uses the native /api/chat
instead, because that is the only way to set the context window (num_ctx): through the OpenAI
endpoint Ollama silently truncates to its default context, which cuts tool schemas off and makes a
model look worse than it is. Set TC_OLLAMA_NUM_CTX to change the window (default 32768).

A model's private reasoning (Ollama's `thinking`, an OpenAI-compatible `reasoning_content`) is kept
in transcript.json as "thinking" on the assistant message it came with, so a failure can be read
with the model's reasons. It is not sent back to the model: the history the model sees is as before.
"""
from __future__ import annotations

import json
import re
import time
import urllib.error
import urllib.request
import uuid

from .. import config
from ..tools import Runner, openai_functions
from . import Outcome, Trial


def _post(url: str, key: str, body: dict, timeout: int = 600) -> dict:
    data = json.dumps(body).encode()
    last = None
    for attempt in range(4):
        req = urllib.request.Request(url, data=data, method="POST", headers={
            "Content-Type": "application/json", "Authorization": f"Bearer {key}"})
        try:
            with urllib.request.urlopen(req, timeout=timeout) as r:
                return json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            last = f"HTTP {e.code}: {e.read().decode(errors='replace')[:800]}"
            if e.code not in (408, 429, 500, 502, 503, 504):
                break
        except (urllib.error.URLError, TimeoutError, ConnectionError) as e:
            last = str(e)
        time.sleep(2 ** attempt * 2)
    raise RuntimeError(last or "request failed")


_XML_FN = re.compile(r"<function=([^>\s]+)>(.*?)</function>", re.S)
_XML_PARAM = re.compile(r"<parameter=([^>\s]+)>\s*(.*?)\s*</parameter>", re.S)
_HERMES = re.compile(r"<tool_call>\s*(\{.*?\})\s*</tool_call>", re.S)


def _value(raw: str):
    try:
        return json.loads(raw)
    except (json.JSONDecodeError, ValueError):
        return raw


def text_tool_calls(text: str) -> list[tuple[str, dict]]:
    """Tool calls a model wrote as text instead of as structured calls.

    Some local models (qwen3-coder's XML format, hermes-style <tool_call> JSON) emit the call in the
    message body when they put a sentence before it, and the runtime's parser then misses it. That
    is a model/runtime quirk, not a tool problem, so the loop recovers the call and counts it.
    """
    calls = []
    for name, body in _XML_FN.findall(text or ""):
        calls.append((name.strip(), {k.strip(): _value(v) for k, v in _XML_PARAM.findall(body)}))
    if not calls:
        for raw in _HERMES.findall(text or ""):
            try:
                doc = json.loads(raw)
            except json.JSONDecodeError:
                continue
            args = doc.get("arguments") or doc.get("parameters") or {}
            if isinstance(args, str):
                args = _value(args)
            calls.append((str(doc.get("name", "")), args))
    return calls


class FunctionCallingAgent:
    def __init__(self, spec: str):
        endpoint, _, model = spec.partition("/")
        eps = config.endpoints()
        if endpoint not in eps:
            raise SystemExit(f"no endpoint '{endpoint}'. Set TC_ENDPOINT_{endpoint.upper()}_URL in .env "
                             f"(known: {', '.join(sorted(eps)) or 'none'})")
        if not model:
            raise SystemExit(f"fc agent spec needs a model: fc:{endpoint}/<model>")
        self.endpoint, self.model = endpoint, model
        self.url, self.key = eps[endpoint]["url"], eps[endpoint]["key"]
        self.native_ollama = endpoint == "ollama" or ":11434" in self.url
        t = config.env("TC_AGENT_TEMPERATURE")
        self.temperature = float(t) if t else None
        self.last_thinking = ""

    @property
    def _ollama_base(self) -> str:
        return self.url[:-3] if self.url.endswith("/v1") else self.url

    def _ollama_options(self) -> dict:
        return {"num_ctx": int(config.env("TC_OLLAMA_NUM_CTX", "32768"))}

    def warmup(self) -> float:
        """Load the model before the agent's first trial and return how long that took. A local
        model that another one pushed out of memory takes a minute or two to load, which is not the
        agent's time with the tools. Same options as a trial: Ollama reloads when num_ctx differs."""
        t0 = time.time()
        try:
            if self.native_ollama:
                _post(self._ollama_base + "/api/chat", self.key,
                      {"model": self.model, "messages": [], "stream": False, "options": self._ollama_options()})
            else:
                _post(self.url + "/chat/completions", self.key,
                      {"model": self.model, "max_tokens": 1, "messages": [{"role": "user", "content": "ok"}]})
        except Exception:
            pass  # a real problem shows up in the first trial, with its error
        return time.time() - t0

    # One model turn. Returns (assistant_message_for_history, tool_calls[(id, name, args)], text, usage)
    def _turn(self, messages: list, functions: list) -> tuple[dict, list, str, tuple[int, int]]:
        if self.native_ollama:
            base = self._ollama_base
            body = {"model": self.model, "messages": messages, "tools": functions, "stream": False,
                    "options": self._ollama_options()}
            if self.temperature is not None:
                body["options"]["temperature"] = self.temperature
            r = _post(base + "/api/chat", self.key, body)
            msg = r.get("message") or {}
            calls = []
            for c in msg.get("tool_calls") or []:
                fn = c.get("function") or {}
                args = fn.get("arguments")
                if isinstance(args, str):
                    try:
                        args = json.loads(args)
                    except json.JSONDecodeError:
                        pass
                calls.append((uuid.uuid4().hex[:12], fn.get("name", ""), args))
            hist = {"role": "assistant", "content": msg.get("content") or "",
                    "tool_calls": msg.get("tool_calls") or []}
            self.last_thinking = msg.get("thinking") or ""
            return hist, calls, msg.get("content") or "", (r.get("prompt_eval_count", 0), r.get("eval_count", 0))

        body = {"model": self.model, "messages": messages, "tools": functions, "tool_choice": "auto"}
        if self.temperature is not None:
            body["temperature"] = self.temperature
        r = _post(self.url + "/chat/completions", self.key, body)
        choice = (r.get("choices") or [{}])[0]
        msg = choice.get("message") or {}
        calls = []
        for c in msg.get("tool_calls") or []:
            fn = c.get("function") or {}
            raw = fn.get("arguments") or "{}"
            try:
                args = json.loads(raw) if isinstance(raw, str) else raw
            except json.JSONDecodeError:
                args = raw  # the runner reports it as invalid arguments
            calls.append((c.get("id") or uuid.uuid4().hex[:12], fn.get("name", ""), args))
        hist = {"role": "assistant", "content": msg.get("content")}
        self.last_thinking = msg.get("reasoning_content") or msg.get("reasoning") or ""
        if msg.get("tool_calls"):
            hist["tool_calls"] = msg["tool_calls"]
        u = r.get("usage") or {}
        return hist, calls, msg.get("content") or "", (u.get("prompt_tokens", 0), u.get("completion_tokens", 0))

    def run(self, t: Trial) -> Outcome:
        runner = Runner(t.catalog, t.uid, t.trace)
        functions = openai_functions(t.catalog)
        messages: list = [{"role": "system", "content": t.system_prompt}, {"role": "user", "content": t.prompt}]
        out = Outcome()
        followups = list(t.followups)
        thinking: dict[int, str] = {}  # index in messages -> that turn's reasoning (transcript only)
        try:
            while out.turns < t.max_turns:
                out.turns += 1
                hist, calls, text, (tin, tout) = self._turn(messages, functions)
                out.input_tokens += tin or 0
                out.output_tokens += tout or 0
                if not calls and (recovered := text_tool_calls(text)):
                    out.text_tool_calls += len(recovered)
                    calls = [(uuid.uuid4().hex[:12], n, a) for n, a in recovered]
                    # Put them back in history as proper calls, so the next turn sees the right format.
                    hist["content"] = ""
                    hist["tool_calls"] = [
                        {"function": {"name": n, "arguments": a}} if self.native_ollama else
                        {"id": cid, "type": "function", "function": {"name": n, "arguments": json.dumps(a)}}
                        for cid, n, a in calls]
                messages.append(hist)
                if self.last_thinking:
                    thinking[len(messages) - 1] = self.last_thinking
                if not calls:
                    out.answer = text
                    if followups and t.done is not None and not t.done():
                        messages.append({"role": "user", "content": followups.pop(0)})
                        continue
                    break
                for cid, name, args in calls:
                    result = runner.call(name, args)
                    if self.native_ollama:
                        messages.append({"role": "tool", "content": result, "tool_name": name})
                    else:
                        messages.append({"role": "tool", "tool_call_id": cid, "content": result})
            else:
                out.error = f"max_turns ({t.max_turns}) reached"
        except Exception as e:  # network, model errors: the trial fails, the run continues
            out.error = f"{type(e).__name__}: {e}"
        out.transcript = [dict(m, thinking=thinking[i]) if i in thinking else m for i, m in enumerate(messages)]
        return out
