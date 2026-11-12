"""Every eval of every module, lined up so you can see whether the tools get better.

Two runs' scores only compare when both asked the same questions. So each run is tagged with the
version of scenarios.yml it ran against: S1, S2, ... per module, in order of first use, taken from
the copy every run keeps. A run is "partial" when it did not run all of that file (`--scenario`,
`--prompt-id` or no holdout). A change, a "best so far" and a "new best" are only computed between
complete runs on the same version. A change of scenarios is a break in the line, never a gain or
a loss.

Success rate comes first. Once an agent passes as often as it ever has on a version (its top
rate, usually 100%), what a tweak can still improve is cost: tokens and time per trial. So every
point also carries those, a change against the previous point that is tested for noise (paired by
prompt), and the fewest so far among the evals at the top rate. Among evals with the same rate,
the best is the one with the better holdout, then the fewest tokens, then the least time. Tokens
are a mean per trial; time is the median trial, because one trial that waited for a model to load
(about two minutes for a local model another one pushed out) would swamp a mean.

Used by `tc report` (text) and html.py (the workspace and module pages).
"""
from __future__ import annotations

import hashlib
import json
import math
import statistics
from dataclasses import dataclass, field
from functools import cached_property
from pathlib import Path

import yaml

from . import config


def _read_json(p: Path, default=None):
    try:
        return json.loads(p.read_text())
    except (OSError, json.JSONDecodeError):
        return default


def _canon(v) -> str:
    return json.dumps(v, sort_keys=True, ensure_ascii=False, default=str)


def _pairs(doc: dict) -> set[str]:
    """Every scenario/prompt key in a scenarios.yml, with prompt ids assigned as scenarios.py does."""
    out = set()
    for s in doc.get("scenarios") or []:
        for j, p in enumerate(s.get("prompts") or []):
            pid = f"p{j + 1}" if isinstance(p, str) else str((p or {}).get("id") or f"p{j + 1}")
            out.add(f"{s.get('id')}/{pid}")
    return out


def _changes(old: dict, new: dict) -> list[str]:
    """What changed between two scenarios.yml versions, one short line per item."""
    o = {str(s.get("id")): s for s in old.get("scenarios") or []}
    n = {str(s.get("id")): s for s in new.get("scenarios") or []}
    out = [f"+ {sid}" for sid in n if sid not in o] + [f"− {sid}" for sid in o if sid not in n]
    for sid in n:
        if sid in o and _canon(o[sid]) != _canon(n[sid]):
            keys = sorted(k for k in set(o[sid]) | set(n[sid]) if _canon(o[sid].get(k)) != _canon(n[sid].get(k)))
            out.append(f"~ {sid} ({', '.join(keys)})")
    for k in sorted((set(old) | set(new)) - {"scenarios"}):
        if _canon(old.get(k)) != _canon(new.get(k)):
            out.append(f"~ {k}")
    return out


@dataclass
class Version:
    number: int
    fingerprint: str
    doc: dict
    first_run: str
    changes: list[str] = field(default_factory=list)  # against the previous version

    @property
    def name(self) -> str:
        return f"S{self.number}"


@dataclass
class Run:
    module: str
    dir: Path
    meta: dict
    summary: dict
    version: Version
    partial: bool

    @property
    def id(self) -> str:
        return self.meta.get("run_id", self.dir.name)

    @property
    def variant(self) -> str:
        return self.meta.get("variant", "?")

    @property
    def label(self) -> str:
        return self.meta.get("label") or ""

    @property
    def started(self) -> str:
        return (self.meta.get("started_at") or "").replace("T", " ").rstrip("Z")[:16]

    @cached_property
    def results(self) -> list[dict]:
        """Every trial, from results.jsonl."""
        out = []
        try:
            lines = (self.dir / "results.jsonl").read_text().splitlines()
        except OSError:
            return out
        for line in lines:
            try:
                out.append(json.loads(line))
            except json.JSONDecodeError:
                continue
        return out

    def scenario_counts(self) -> dict[str, dict[str, list[float]]]:
        """agent -> scenario -> [passed, trials, tokens, [seconds], reached], train and holdout
        together; tokens is a sum and seconds a list over the `reached` trials (setup did not fail)."""
        out: dict[str, dict[str, list]] = {}
        for r in self.results:
            c = out.setdefault(r.get("agent", "?"), {}).setdefault(r.get("scenario", "?"), [0, 0, 0, [], 0])
            c[0] += 1 if r.get("passed") else 0
            c[1] += 1
            if not r.get("setup_failed"):
                c[2] += _trial_cost(r, "tokens")
                c[3].append(_trial_cost(r, "seconds"))
                c[4] += 1
        return out


def fmt_tokens(n) -> str:
    """8543 -> 8.5k, 1234567 -> 1.2M."""
    if n is None:
        return "–"
    n = float(n)
    return f"{n / 1e6:.1f}M" if n >= 1e6 else f"{n / 1e3:.1f}k" if n >= 1e3 else f"{n:.0f}"


def fmt_secs(s) -> str:
    """3.7 -> 3.7 s, 42 -> 42 s, 135 -> 2m 15s, 7300 -> 2.0 h."""
    if s is None:
        return "–"
    s = float(s)
    if s < 10:
        return f"{s:.1f} s"
    if s < 60:
        return f"{s:.0f} s"
    if s < 3600:
        return f"{int(s // 60)}m {int(s % 60):02d}s"
    return f"{s / 3600:.1f} h"


METRICS = {"tokens": "tokens per trial", "seconds": "time per trial"}
MIN_EFFECT = 0.02  # a cost change under 2% is reported as within noise even when it is consistent


def _trial_cost(r: dict, metric: str) -> float:
    if metric == "tokens":
        return int(r.get("input_tokens") or 0) + int(r.get("output_tokens") or 0)
    return float(r.get("duration_s") or 0)


@dataclass
class CostChange:
    rel: float      # new / old - 1
    real: bool      # beyond noise: paired by prompt, more than twice its standard error, and >= 2%

    def text(self) -> str:
        v = abs(self.rel) * 100
        sign = "+" if self.rel > 0 else "−"
        return f"{sign}{v:.0f}%" if v >= 0.5 else "±0%"


def _paired(new: dict[str, float], old: dict[str, float]) -> CostChange | None:
    """Compare per-prompt means, so a change that holds on every prompt is not hidden by the large
    spread between prompts (a lookup-and-write task costs more than a read)."""
    keys = [k for k in new if k in old]
    if not keys:
        return None
    o = statistics.mean(old[k] for k in keys)
    if o <= 0:
        return None
    d = [new[k] - old[k] for k in keys]
    rel = statistics.mean(new[k] for k in keys) / o - 1
    if len(d) < 3 or abs(rel) < MIN_EFFECT:
        return CostChange(rel, False)
    se = statistics.stdev(d) / math.sqrt(len(d))
    return CostChange(rel, abs(statistics.mean(d)) > 2 * se)


@dataclass
class Point:
    """One agent's result in one run."""
    run: Run
    agent: str
    stats: dict
    prev: "Point | None" = None        # previous comparable point: same agent, same version, both complete
    best_before: float | None = None   # best comparable rate before this point
    earlier_versions: bool = False     # the agent has complete evals on another version before this
    top_rate: float | None = None      # the agent's best rate on this version, over all its complete evals
    fewest: dict = field(default_factory=dict)  # metric -> fewest so far among evals at the top rate

    @cached_property
    def _cost(self) -> dict[str, tuple[float | None, dict[str, float]]]:
        """metric -> (per trial, {scenario/prompt: per trial}), over trials that reached the agent:
        the mean for tokens, the median for time. Read from results.jsonl, so runs from before
        summary.json had these fields work too."""
        rs = [r for r in self.run.results if r.get("agent") == self.agent and not r.get("setup_failed")]
        out = {}
        for m in METRICS:
            if not rs:
                legacy = {"tokens": self.stats.get("mean_tokens"),
                          "seconds": self.stats.get("median_duration_s") or self.stats.get("mean_duration_s")}[m]
                out[m] = (legacy or None, {})
                continue
            agg = statistics.median if m == "seconds" else statistics.mean
            by: dict[str, list[float]] = {}
            for r in rs:
                by.setdefault(f"{r.get('scenario')}/{r.get('prompt')}", []).append(_trial_cost(r, m))
            v = agg([_trial_cost(r, m) for r in rs])
            # A backend that reports no usage gives zero tokens; that is unknown, not free.
            out[m] = (v if v > 0 else None, {k: agg(x) for k, x in by.items()})
        return out

    def cost(self, metric: str) -> float | None:
        return self._cost[metric][0]

    @property
    def tokens(self) -> float | None:
        return self.cost("tokens")

    @property
    def seconds(self) -> float | None:
        return self.cost("seconds")

    def cost_change(self, metric: str) -> CostChange | None:
        if self.prev is None or self.cost(metric) is None or self.prev.cost(metric) is None:
            return None
        return _paired(self._cost[metric][1], self.prev._cost[metric][1]) or \
            CostChange(self.cost(metric) / self.prev.cost(metric) - 1, False)

    @property
    def at_top(self) -> bool:
        return not self.run.partial and self.top_rate is not None and self.rate >= self.top_rate - 1e-9

    @property
    def rate(self) -> float:
        """Exact passed/trials: summary.json rounds, which would blur the one-trial noise test."""
        if self.trials:
            return int(self.stats.get("passed") or 0) / self.trials
        return self.stats.get("success_rate") or 0.0

    @property
    def trials(self) -> int:
        return int(self.stats.get("trials") or 0)

    @property
    def delta(self) -> float | None:
        return None if self.prev is None else self.rate - self.prev.rate

    @property
    def best_so_far(self) -> float | None:
        if self.run.partial:
            return None
        return self.rate if self.best_before is None else max(self.rate, self.best_before)

    @property
    def new_best(self) -> bool:
        return self.best_before is not None and self.rate > self.best_before + 1e-9

    @property
    def within_noise(self) -> bool:
        """A change of at most one trial, which proves nothing (the tc-reiterate rule)."""
        if self.prev is None or not self.delta:
            return False
        return abs(self.delta) <= 1 / (min(self.trials, self.prev.trials) or 1) + 1e-9

    @property
    def change(self) -> str:
        if self.run.partial:
            return "partial"
        if self.prev is None:
            return "new-version" if self.earlier_versions else "first"
        d = self.delta or 0
        return "up" if d > 1e-9 else "down" if d < -1e-9 else "same"

    def change_text(self) -> str:
        c = self.change
        if c == "partial":
            return "partial run"
        if c == "first":
            return "first eval"
        if c == "new-version":
            return f"first on {self.run.version.name}"
        if c == "same":
            return "= no change"
        out = f"{'▲' if c == 'up' else '▼'} {pts(self.delta)}"
        if self.within_noise:
            out += " (within noise)"
        elif self.new_best:
            out += " new best"
        return out


def pts(delta: float) -> str:
    """A change in percentage points, signed: +12 pts, −0.5 pts."""
    v = abs(delta) * 100
    return f"{'+' if delta > 0 else '−'}{v:.1f} pts" if v < 0.95 else f"{'+' if delta > 0 else '−'}{v:.0f} pts"


def _sort_key(p: Point) -> tuple:
    """Higher rate first; at the same rate, the better holdout (it generalises), then fewer tokens,
    then less time, then fewer bad calls."""
    inf = float("inf")
    return (p.rate, p.stats.get("holdout_rate") or 0,
            -(p.tokens if p.tokens is not None else inf), -(p.seconds if p.seconds is not None else inf),
            -(p.stats.get("failed_call_rate") or 0), -(p.stats.get("mean_tool_calls") or 0))


def fewest_text(h: "History", agent: str, metric: str, version: "Version | None" = None) -> str | None:
    """'8.7k at v03 → 7.7k at v13 (−12%)' over the agent's evals at its top rate, or None."""
    top = [p for p in h.at_top(agent, version) if p.cost(metric) is not None]
    lo = h.fewest(agent, metric, version)
    if not top or lo is None:
        return None
    fmt = fmt_tokens if metric == "tokens" else fmt_secs
    if len(top) == 1:
        return f"{fmt(lo.cost(metric))} at {lo.run.variant}"
    if lo is top[0]:
        return f"{fmt(lo.cost(metric))} at {lo.run.variant}, still the fewest"
    rel = lo.cost(metric) / top[0].cost(metric) - 1
    return f"{fmt(top[0].cost(metric))} at {top[0].run.variant} → {fmt(lo.cost(metric))} at {lo.run.variant} ({CostChange(rel, True).text()})"


def cost_text(p: Point, metric: str) -> str:
    """'7.5k −14%' style, for the text report; '(noise)' when the change proves nothing."""
    v = p.cost(metric)
    out = (fmt_tokens if metric == "tokens" else fmt_secs)(v)
    c = p.cost_change(metric)
    if c is not None:
        out += f" {c.text()}" + ("" if c.real else "~")
    return out


@dataclass
class History:
    module: str
    runs: list[Run]
    versions: list[Version]
    points: list[Point]
    current: Version | None      # the version the module's scenarios.yml is at now, if any run used it
    stale: bool                  # scenarios.yml changed since the last eval

    @property
    def complete(self) -> list[Run]:
        return [r for r in self.runs if not r.partial]

    @property
    def latest_version(self) -> Version | None:
        return self.current or (self.runs[-1].version if self.runs else None)

    def agents(self) -> list[str]:
        return _order([p.agent for p in self.points])

    def points_for(self, agent: str) -> list[Point]:
        return [p for p in self.points if p.agent == agent]

    def primary(self) -> str | None:
        """The agent the module is tuned for: the default agent if it was evaluated, else the one
        with the most complete evals."""
        counts: dict[str, int] = {}
        for p in self.points:
            if not p.run.partial:
                counts[p.agent] = counts.get(p.agent, 0) + 1
        if not counts:
            return None
        default = config.env("TC_DEFAULT_AGENT")
        if default in counts:
            return default
        order = self.agents()
        return max(counts, key=lambda a: (counts[a], -order.index(a)))

    def best(self, agent: str | None = None, version: Version | None = None) -> Point | None:
        v = version or self.latest_version
        pts = [p for p in self.points if not p.run.partial and p.run.version is v and (agent is None or p.agent == agent)]
        return max(pts, key=_sort_key, default=None)

    def first(self, agent: str, version: Version | None = None) -> Point | None:
        v = version or self.latest_version
        return next((p for p in self.points if not p.run.partial and p.run.version is v and p.agent == agent), None)

    def at_top(self, agent: str, version: Version | None = None) -> list[Point]:
        """The agent's evals that reached its top rate on the version, in order."""
        v = version or self.latest_version
        return [p for p in self.points if p.agent == agent and p.run.version is v and p.at_top]

    def fewest(self, agent: str, metric: str, version: Version | None = None) -> Point | None:
        pts = [p for p in self.at_top(agent, version) if p.cost(metric) is not None]
        return min(pts, key=lambda p: p.cost(metric), default=None)


def _order(agents: list[str]) -> list[str]:
    """Default agent first, then by first appearance. Colour follows this order, so an agent keeps
    its colour on every chart of a page."""
    order: list[str] = []
    for a in agents:
        if a not in order:
            order.append(a)
    default = config.env("TC_DEFAULT_AGENT")
    if default in order:
        order.remove(default)
        order.insert(0, default)
    return order


def modules() -> list[str]:
    if not config.WORKSPACE.is_dir():
        return []
    return sorted(p.name for p in config.WORKSPACE.iterdir() if (p / "module.yml").is_file())


def load(module: str) -> History:
    versions: dict[str, Version] = {}
    runs: list[Run] = []
    for d in sorted((config.module_dir(module) / "runs").glob("*/")):
        meta, summary = _read_json(d / "meta.json"), _read_json(d / "summary.json")
        if not meta or not summary:
            continue  # still running, or failed before it finished
        try:
            doc = yaml.safe_load((d / "scenarios.yml").read_text()) or {}
        except (OSError, yaml.YAMLError):
            doc = {}
        fp = hashlib.sha1(_canon(doc).encode()).hexdigest()[:12]
        if fp not in versions:
            prev = list(versions.values())[-1] if versions else None
            versions[fp] = Version(len(versions) + 1, fp, doc, meta.get("run_id", d.name),
                                   _changes(prev.doc, doc) if prev else [])
        ran: set[str] = set()
        for a in summary.get("agents", {}).values():
            ran |= set((a.get("by_prompt") or {}).keys())
        pairs = _pairs(doc)
        runs.append(Run(module, d, meta, summary, versions[fp], partial=not pairs or ran != pairs))

    points: list[Point] = []
    last: dict[tuple[str, int], Point] = {}
    best: dict[tuple[str, int], float] = {}
    seen_complete: set[str] = set()
    for r in runs:
        for agent, stats in r.summary.get("agents", {}).items():
            p = Point(r, agent, stats)
            if not r.partial:
                key = (agent, r.version.number)
                p.prev, p.best_before = last.get(key), best.get(key)
                p.earlier_versions = p.prev is None and agent in seen_complete
                last[key] = p
                best[key] = max(best.get(key, 0.0), p.rate)
                seen_complete.add(agent)
            points.append(p)

    # `best` now holds each agent's top rate per version; walk again for the fewest so far at it.
    low: dict[tuple[str, int, str], float] = {}
    for p in points:
        if p.run.partial:
            continue
        k = (p.agent, p.run.version.number)
        p.top_rate = best[k]
        for m in METRICS:
            if p.at_top and p.cost(m) is not None:
                low[k + (m,)] = min(low.get(k + (m,), math.inf), p.cost(m))
            if k + (m,) in low:
                p.fewest[m] = low[k + (m,)]

    current, stale = None, False
    try:
        now = yaml.safe_load((config.module_dir(module) / "scenarios.yml").read_text()) or {}
        fp = hashlib.sha1(_canon(now).encode()).hexdigest()[:12]
        current = versions.get(fp)
        stale = bool(runs) and current is None
    except (OSError, yaml.YAMLError):
        pass
    return History(module, runs, list(versions.values()), points, current, stale)


def agent_order(module: str | None = None) -> list[str]:
    """Every agent evaluated, for one module or (None) across the workspace, in colour order."""
    agents: list[tuple[str, str]] = []
    for m in [module] if module else modules():
        for d in sorted((config.module_dir(m) / "runs").glob("*/")):
            s = _read_json(d / "summary.json") or {}
            agents += [(d.name, a) for a in s.get("agents", {})]
    return _order([a for _, a in sorted(agents)])


# --- text, for `tc report` (agents read this; the HTML is for people) ---------------------------

def _pct(x) -> str:
    return "–" if x is None else f"{x * 100:.0f}%"


def _versions_text(h: History) -> list[str]:
    if len(h.versions) < 2 and not h.stale:
        return []
    out = ["scenario versions (scores only compare within one):"]
    for v in h.versions:
        out.append(f"  {v.name} from {v.first_run}" + (f": {'; '.join(v.changes)}" if v.changes else ""))
    if h.stale:
        out.append(f"  scenarios.yml changed since the last eval: the next eval starts S{len(h.versions) + 1}")
    return out


def text_report(module: str) -> str:
    h = load(module)
    if not h.runs:
        return f"no completed evals for {module} yet"
    lines = [f"{module}: {len(h.runs)} eval(s), {len(h.versions)} version(s) of the scenarios", ""]
    lines += _versions_text(h) + ([""] if len(h.versions) > 1 or h.stale else [])
    lines.append(f"{'run':38} {'ver':3} {'variant':7} {'agent':32} {'pass':>9} {'rate':>5}  {'change':30} "
                 f"{'train':>5} {'hold':>5} {'calls':>5}  {'tokens/trial':13} {'time/trial':12} top")
    for p in h.points:
        a = p.stats
        lines.append(f"{p.run.id[:38]:38} {p.run.version.name:3} {p.run.variant:7} {p.agent[:32]:32} "
                     f"{a.get('passed', 0):>3}/{a.get('trials', 0):<5} {_pct(p.rate):>5}  {p.change_text()[:30]:30} "
                     f"{_pct(a.get('train_rate')):>5} {_pct(a.get('holdout_rate')):>5} {a.get('mean_tool_calls', ''):>5}  "
                     f"{cost_text(p, 'tokens'):13} {cost_text(p, 'seconds'):12} {'*' if p.at_top else ''}")
    lines.append("tokens/trial and time/trial: change against the previous eval of the agent; ~ = within noise "
                 "(paired by prompt). top * = the agent's top rate on these scenarios.")
    v = h.latest_version
    lines += ["", f"best per agent on {v.name if v else '-'}" + (" (the current scenarios)" if h.current else "")
              + ": highest rate, then holdout, then fewest tokens, then least time"]
    for agent in h.agents():
        b, f = h.best(agent), h.first(agent)
        if not b:
            continue
        lines.append(f"  {agent:32} {_pct(b.rate):>5} at {b.run.variant} ({b.run.id}), "
                     f"{fmt_tokens(b.tokens)} tokens and {fmt_secs(b.seconds)} per trial;   first {_pct(f.rate)} at {f.run.variant}")
        top = h.at_top(agent)
        if len(top) > 1:
            parts = [f"{'time' if m == 'seconds' else m} {txt}" for m in METRICS if (txt := fewest_text(h, agent, m))]
            lines.append(f"  {'':32} {len(top)} evals at {_pct(b.rate)}, first → fewest: " + "; ".join(parts))
    return "\n".join(lines)


def text_overview() -> str:
    hs = [h for h in (load(m) for m in modules())]
    if not hs:
        return "no modules yet: tc module prepare <module>"
    lines = [f"{'module':20} {'evals':>5} {'ver':>3}  {'agent':32} {'first':>5} → {'best':>5}  {'best variant':12} "
             f"{'tokens/trial':>12} {'time/trial':>10}  last eval"]
    for h in hs:
        agent = h.primary()
        b, f = (h.best(agent), h.first(agent)) if agent else (None, None)
        v = h.latest_version
        lines.append(f"{h.module[:20]:20} {len(h.runs):>5} {v.name if v else '-':>3}  {(agent or '-')[:32]:32} "
                     f"{_pct(f.rate) if f else '–':>5} → {_pct(b.rate) if b else '–':>5}  "
                     f"{b.run.variant if b else '–':12} {fmt_tokens(b.tokens) if b else '–':>12} "
                     f"{fmt_secs(b.seconds) if b else '–':>10}  {h.runs[-1].id if h.runs else '–'}")
    lines.append("\nfirst → best: the module's main agent, on its current scenarios; tokens and time are the best "
                 "eval's. `tc report <module>` for every eval.")
    return "\n".join(lines)
