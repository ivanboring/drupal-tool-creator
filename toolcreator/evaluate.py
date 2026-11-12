"""Installing a variant and running the eval.

Every eval starts the same way, without exception:
  1. any unsaved tool-module code in the site is autosaved
  2. the site's filesystem and database are restored to the module baseline
  3. the variant is copied in and enabled, the scenario users are created
  4. that database is dumped; every trial starts from this dump
"""
from __future__ import annotations

import json
import re
import shutil
import statistics
import tempfile
import time
from collections import Counter, defaultdict
from pathlib import Path

from . import agents as agent_backends
from . import config, scenarios, site, tools, workspace
from .history import fmt_secs, fmt_tokens


# --- installing a variant -----------------------------------------------------------------------

_USER_PHP = r"""
$key = %(key)s; $perms = %(perms)s; $roles = %(roles)s;
$all = array_keys(\Drupal::service('user.permissions')->getPermissions());
$unknown = array_values(array_diff($perms, $all));
if ($unknown) { return ['error' => 'unknown permissions: ' . implode(', ', $unknown)]; }
$rid = 'tc_' . $key;
$role = \Drupal\user\Entity\Role::load($rid) ?: \Drupal\user\Entity\Role::create(['id' => $rid, 'label' => 'Tool creator: ' . $key]);
foreach ($perms as $p) { $role->grantPermission($p); }
$role->save();
$missing = array_values(array_filter($roles, fn ($r) => !\Drupal\user\Entity\Role::load($r)));
if ($missing) { return ['error' => 'unknown roles: ' . implode(', ', $missing)]; }
$u = user_load_by_name($key) ?: \Drupal\user\Entity\User::create(['name' => $key, 'mail' => $key . '@example.com', 'status' => 1, 'pass' => $key]);
$u->addRole($rid);
foreach ($roles as $r) { $u->addRole($r); }
$u->save();
return ['uid' => (int) $u->id()];
"""


def _cache_dir(module: str) -> Path:
    d = config.module_dir(module) / ".cache"
    d.mkdir(parents=True, exist_ok=True)
    return d


def install_variant(module: str, variant: str) -> dict:
    site.require_running()
    mod = workspace.load_module(module)
    suite = scenarios.load(module)
    variant = workspace.resolve_variant(module, variant)
    src = workspace.variant_path(module, variant)
    base = config.module_baseline(module)
    if not (base / "db.sql.gz").is_file():
        raise SystemExit(f"no module baseline for {module}. Run: tc module prepare {module}")

    t0 = time.time()
    print(f"[reset] restoring filesystem + database to the {module} baseline")
    workspace.autosave(module)
    site.baseline("restore", base)
    print(f"[install] {mod['tool_module']} @ {variant}")
    site.copy_tree(src, workspace.site_tool_module(module))
    site.drush("pm:enable", mod["tool_module"], "-y")
    site.drush("cache:rebuild")

    users = {"admin": 1, "anonymous": 0}
    for key, spec in suite.users.items():
        code = _USER_PHP % {"key": json.dumps(key), "perms": _php_list(spec.get("permissions")),
                            "roles": _php_list(spec.get("roles"))}
        ok, value, err = site.php(code)
        if err or not isinstance(value, dict) or value.get("error"):
            raise SystemExit(f"could not create user '{key}': {err or (value or {}).get('error')}")
        users[key] = value["uid"]

    catalog = tools.build_catalog(mod)
    if not catalog:
        print(f"[warn] no tools exposed: nothing from providers {mod['expose']['providers']} "
              f"or ids {mod['expose']['ids']}. Check module.yml expose: and `drush tool:list`.")
    cache = _cache_dir(module)
    snapshot = cache / f"{variant}.sql.gz"
    site.db_dump(snapshot)
    (cache / f"{variant}.catalog.json").write_text(json.dumps(catalog, indent=2))
    workspace.set_site_state(module=module, variant=variant, users=users, snapshot=str(snapshot),
                             catalog=str(cache / f"{variant}.catalog.json"))
    print(f"[install] done in {time.time() - t0:.0f}s: {len(catalog)} tool(s), users {users}")
    return workspace.site_state()


def _php_list(values) -> str:
    return "[" + ", ".join(json.dumps(str(v)) for v in (values or [])) + "]"


def installed(module: str, variant: str | None = None) -> dict:
    st = workspace.site_state()
    if st.get("module") != module or (variant and st.get("variant") != workspace.resolve_variant(module, variant)):
        want = f"{module} @ {variant}" if variant else module
        raise SystemExit(f"the site does not hold {want} (it holds: {st.get('module')} @ {st.get('variant')}). "
                         f"Run: tc variant install {module} <variant>")
    return st


# --- one trial ------------------------------------------------------------------------------------

def run_setup(sc: scenarios.Scenario) -> str | None:
    for i, step in enumerate(sc.setup):
        if "php" in step:
            ok, _, err = site.php(step["php"])
            if err or not ok:
                return f"setup[{i}] php failed: {err or 'returned falsy'}"
        else:
            args = step["drush"] if isinstance(step["drush"], list) else str(step["drush"]).split()
            r = site.drush(*[str(a) for a in args], check=False)
            if r.returncode != 0:
                return f"setup[{i}] drush failed: {r.stderr.strip()[-500:]}"
    return None


def run_checks(sc: scenarios.Scenario) -> list[dict]:
    out = []
    for c in sc.checks:
        ok, value, err = site.php(c["php"])
        out.append({"name": c["name"], "ok": ok and not err, "value": value if not err else None,
                    "error": err or None})
    return out


def run_trial(state: dict, suite: scenarios.Suite, sc: scenarios.Scenario, prompt: scenarios.Prompt,
              agent_spec: str, agent, trial_dir: Path, catalog: list[dict]) -> dict:
    trial_dir.mkdir(parents=True, exist_ok=True)
    site.db_restore(Path(state["snapshot"]))
    res: dict = {"scenario": sc.id, "prompt": prompt.id, "holdout": prompt.holdout, "agent": agent_spec,
                 "variant": state["variant"], "run_as": sc.run_as, "passed": False}
    err = run_setup(sc)
    if err:
        res.update(error=err, setup_failed=True)
        (trial_dir / "result.json").write_text(json.dumps(res, indent=2))
        return res
    trace = trial_dir / "trace.jsonl"
    trace.write_text("")
    workdir = Path(tempfile.mkdtemp(prefix="tc-trial-"))
    check_s = [0.0]

    def done() -> bool:
        # The harness's own checks (before a followup) are not the agent's time.
        t = time.time()
        try:
            return all(c["ok"] for c in run_checks(sc)) if sc.checks else True
        finally:
            check_s[0] += time.time() - t

    t0 = time.time()
    trial = agent_backends.Trial(
        prompt=prompt.text, system_prompt=suite.system_prompt, catalog=catalog,
        uid=int(state["users"][sc.run_as]), trace=trace, workdir=workdir, max_turns=suite.max_turns,
        followups=sc.followups, done=done)
    outcome = agent.run(trial)
    duration = time.time() - t0 - check_s[0]
    for extra in ("agent-stdout.txt",):
        if (workdir / extra).is_file():
            shutil.copy(workdir / extra, trial_dir / extra)
    shutil.rmtree(workdir, ignore_errors=True)

    calls = [json.loads(l) for l in trace.read_text().splitlines() if l.strip()]
    checks = run_checks(sc)
    answer_ok = True
    if sc.answer_matches:
        answer_ok = bool(re.search(sc.answer_matches, outcome.answer or ""))
    passed = all(c["ok"] for c in checks) and answer_ok
    res.update(
        passed=passed, checks=checks, answer_ok=answer_ok if sc.answer_matches else None,
        answer=outcome.answer, error=outcome.error, turns=outcome.turns,
        tool_calls=len(calls), failed_calls=sum(1 for c in calls if not c.get("success")),
        unknown_tool_calls=sum(1 for c in calls if c.get("error_type") == "unknown_tool"),
        tools_used=[c.get("tool") or c.get("function") for c in calls],
        call_errors=[{"tool": c.get("tool") or c.get("function"), "error_type": c.get("error_type"),
                      "message": (c.get("message") or "")[:300]} for c in calls if not c.get("success")],
        text_tool_calls=outcome.text_tool_calls,
        # Cost: tokens as the backend reports them (input summed over every turn, so the catalog
        # counts once per turn), and wall time of the agent, of which tool_time_s ran in the tools.
        input_tokens=outcome.input_tokens, output_tokens=outcome.output_tokens, duration_s=round(duration, 1),
        tool_time_s=round(sum(c.get("ms") or 0 for c in calls) / 1000, 1),
        prompt_text=prompt.text,
    )
    (trial_dir / "result.json").write_text(json.dumps(res, indent=2, ensure_ascii=False))
    (trial_dir / "transcript.json").write_text(json.dumps(outcome.transcript, indent=2, ensure_ascii=False, default=str))
    return res


# --- the eval -------------------------------------------------------------------------------------

def run_eval(module: str, variant: str, agent_specs: list[str], repeats: int = 1,
             only_scenarios: list[str] | None = None, only_prompts: list[str] | None = None,
             holdout: bool = True, label: str = "") -> Path:
    suite = scenarios.load(module)
    chosen = [s for s in suite.scenarios if not only_scenarios or s.id in only_scenarios]
    if not chosen:
        raise SystemExit(f"no scenarios match {only_scenarios}")
    backends = [(spec, agent_backends.get(spec)) for spec in agent_specs]  # fail fast on bad specs

    state = install_variant(module, variant)  # always: reset filesystem + database, then install
    catalog = json.loads(Path(state["catalog"]).read_text())
    run_id = workspace.stamp() + f"-{state['variant']}" + (f"-{re.sub(r'[^a-z0-9-]+', '-', label.lower())}" if label else "")
    run_dir = config.module_dir(module) / "runs" / run_id
    run_dir.mkdir(parents=True)
    shutil.copy(config.module_dir(module) / "scenarios.yml", run_dir / "scenarios.yml")
    (run_dir / "catalog.json").write_text(json.dumps(catalog, indent=2))
    meta = {"run_id": run_id, "module": module, "variant": state["variant"], "agents": agent_specs,
            "repeats": repeats, "scenarios": [s.id for s in chosen], "label": label,
            "started_at": workspace.now(), "tools": [t["id"] for t in catalog]}
    (run_dir / "meta.json").write_text(json.dumps(meta, indent=2))

    plan = [(spec, a, sc, p, r) for spec, a in backends for sc in chosen for p in sc.prompts
            if (not only_prompts or p.id in only_prompts) and (holdout or not p.holdout)
            for r in range(1, repeats + 1)]
    print(f"[eval] {len(plan)} trial(s) -> {run_dir.relative_to(config.ROOT)}")
    results = []
    warmed: set[str] = set()
    for i, (spec, a, sc, p, r) in enumerate(plan, 1):
        if spec not in warmed and hasattr(a, "warmup"):
            # The plan runs one agent's trials together, so one warm-up per agent is enough.
            warmed.add(spec)
            w = a.warmup()
            meta.setdefault("warmup_s", {})[spec] = round(w, 1)
            print(f"  [warmup] {spec}: model ready in {w:.0f}s (not counted in any trial)", flush=True)
        group = "holdout" if p.holdout else "trials"
        tdir = run_dir / group / sc.id / p.id / agent_backends.slug(spec) / f"r{r}"
        res = run_trial(state, suite, sc, p, spec, a, tdir, catalog)
        res["dir"] = str(tdir.relative_to(run_dir))
        results.append(res)
        mark = "PASS" if res["passed"] else "FAIL"
        why = "" if res["passed"] else "  " + _why(res)
        print(f"  [{i}/{len(plan)}] {mark} {sc.id}/{p.id}{' (holdout)' if p.holdout else ''} "
              f"{spec} r{r}: {res.get('tool_calls', 0)} calls, {res.get('duration_s', 0)}s{why}", flush=True)
        with (run_dir / "results.jsonl").open("a") as f:
            f.write(json.dumps(res, ensure_ascii=False) + "\n")

    site.db_restore(Path(state["snapshot"]))  # leave the site at the variant's clean state
    meta["finished_at"] = workspace.now()
    (run_dir / "meta.json").write_text(json.dumps(meta, indent=2))
    summary = summarize(results, meta)
    (run_dir / "summary.json").write_text(json.dumps(summary, indent=2))
    (run_dir / "report.md").write_text(render_report(summary, catalog))
    from . import html
    report_html = html.write_run(run_dir)
    html.write_all(module)
    print("\n" + render_headline(summary))
    print(f"\nreport: {(run_dir / 'report.md').relative_to(config.ROOT)}")
    if report_html:
        print(f"html:   {report_html.relative_to(config.ROOT)}  ({html.url_for(report_html)})")
        print(f"module: {(config.module_dir(module) / 'index.html').relative_to(config.ROOT)}")
    return run_dir


def _why(res: dict) -> str:
    if res.get("setup_failed"):
        return res["error"]
    parts = [f"check '{c['name']}' failed" + (f" ({c['error'][:120]})" if c.get("error") else "")
             for c in res.get("checks", []) if not c["ok"]]
    if res.get("answer_ok") is False:
        parts.append("answer did not match")
    if res.get("error"):
        parts.append(res["error"][:160])
    return "; ".join(parts)


# --- scoring --------------------------------------------------------------------------------------

def _rate(rs: list[dict]) -> float | None:
    return round(sum(1 for r in rs if r["passed"]) / len(rs), 3) if rs else None


def _mean(xs: list, digits: int):
    if not xs:
        return None
    v = round(statistics.mean(xs), digits)
    return int(v) if digits == 0 else v


def _tokens(r: dict) -> int:
    return int(r.get("input_tokens") or 0) + int(r.get("output_tokens") or 0)


def _cost(rs: list[dict]) -> dict:
    return {
        "mean_tokens": _mean([_tokens(r) for r in rs], 0) or 0,
        "mean_input_tokens": _mean([r.get("input_tokens", 0) for r in rs], 0) or 0,
        "mean_output_tokens": _mean([r.get("output_tokens", 0) for r in rs], 0) or 0,
        "total_tokens": sum(_tokens(r) for r in rs),
        "mean_duration_s": _mean([r.get("duration_s", 0) for r in rs], 1) or 0,
        # The median is the figure reports use: one trial that waited on a model load or the
        # network moves the mean of a 100-trial eval by a second.
        "median_duration_s": round(statistics.median([r.get("duration_s", 0) for r in rs]), 1) if rs else 0,
        "mean_tool_time_s": _mean([r.get("tool_time_s", 0) for r in rs], 1) or 0,
        "total_duration_s": round(sum(r.get("duration_s", 0) for r in rs), 1),
    }


def summarize(results: list[dict], meta: dict) -> dict:
    by_agent = defaultdict(list)
    for r in results:
        by_agent[r["agent"]].append(r)
    agents_out = {}
    for spec, rs in by_agent.items():
        train = [r for r in rs if not r["holdout"]]
        hold = [r for r in rs if r["holdout"]]
        scen = defaultdict(list)
        prom = defaultdict(list)
        for r in rs:
            scen[r["scenario"]].append(r)
            prom[f"{r['scenario']}/{r['prompt']}"].append(r)
        calls = [r.get("tool_calls", 0) for r in rs if not r.get("setup_failed")]
        total_calls = sum(calls)
        tool_use = Counter(t for r in rs for t in r.get("tools_used", []))
        tool_err = Counter(e["tool"] for r in rs for e in r.get("call_errors", []))
        err_types = Counter(e["error_type"] for r in rs for e in r.get("call_errors", []))
        agents_out[spec] = {
            "trials": len(rs), "passed": sum(1 for r in rs if r["passed"]),
            "success_rate": _rate(rs), "train_rate": _rate(train), "holdout_rate": _rate(hold),
            "mean_tool_calls": round(statistics.mean(calls), 2) if calls else 0,
            "mean_tool_calls_passed": round(statistics.mean([r["tool_calls"] for r in rs if r["passed"]]), 2)
            if any(r["passed"] for r in rs) else None,
            "failed_call_rate": round(sum(r.get("failed_calls", 0) for r in rs) / total_calls, 3) if total_calls else 0,
            "unknown_tool_calls": sum(r.get("unknown_tool_calls", 0) for r in rs),
            "text_tool_calls": sum(r.get("text_tool_calls", 0) for r in rs),
            # Cost per trial. Once an agent passes as often as it can, these are what a tweak can
            # still improve. Setup failures never reached the agent, so they are left out.
            **_cost([r for r in rs if not r.get("setup_failed")]),
            "mean_tokens_passed": _mean([_tokens(r) for r in rs if r["passed"]], 0),
            "mean_duration_s_passed": _mean([r.get("duration_s", 0) for r in rs if r["passed"]], 1),
            "setup_failures": sum(1 for r in rs if r.get("setup_failed")),
            "agent_errors": sum(1 for r in rs if r.get("error") and not r.get("setup_failed")),
            "by_scenario": {k: _rate(v) for k, v in scen.items()},
            "by_prompt": {k: {"rate": _rate(v), "holdout": v[0]["holdout"]} for k, v in prom.items()},
            "tool_usage": dict(tool_use.most_common()),
            "tool_errors": dict(tool_err.most_common()),
            "error_types": dict(err_types.most_common()),
            # Failures the tweak step may read. Holdout failures are deliberately left out.
            "train_failures": [{"scenario": r["scenario"], "prompt": r["prompt"], "dir": r.get("dir"),
                                "why": _why(r), "tools_used": r.get("tools_used", [])}
                               for r in train if not r["passed"]],
        }
    return {"meta": meta, "agents": agents_out}


def _pct(x) -> str:
    return "–" if x is None else f"{x * 100:.0f}%"


def render_headline(summary: dict) -> str:
    m = summary["meta"]
    lines = [f"{m['module']} @ {m['variant']}  ({m['run_id']})",
             f"{'agent':40} {'pass':>9} {'train':>6} {'holdout':>8} {'calls':>6} {'bad calls':>9} "
             f"{'tokens':>7} {'time':>7}  (per trial)"]
    for spec, a in summary["agents"].items():
        lines.append(f"{spec:40} {a['passed']:>3}/{a['trials']:<5} {_pct(a['train_rate']):>6} "
                     f"{_pct(a['holdout_rate']):>8} {a['mean_tool_calls']:>6} {_pct(a['failed_call_rate']):>9} "
                     f"{fmt_tokens(a.get('mean_tokens')):>7} {fmt_secs(a.get('median_duration_s')):>7}")
    return "\n".join(lines)


def render_report(summary: dict, catalog: list[dict]) -> str:
    m = summary["meta"]
    out = [f"# Eval: {m['module']} @ {m['variant']}", "",
           f"Run `{m['run_id']}`, {m['repeats']} repeat(s), started {m['started_at']}.", "",
           "| agent | passed | train | holdout | mean calls | failed-call rate | unknown tools "
           "| tokens / trial (in + out) | time / trial (median) | total tokens | total agent time |",
           "|---|---|---|---|---|---|---|---|---|---|---|"]
    for spec, a in summary["agents"].items():
        out.append(f"| `{spec}` | {a['passed']}/{a['trials']} | {_pct(a['train_rate'])} | {_pct(a['holdout_rate'])} "
                   f"| {a['mean_tool_calls']} | {_pct(a['failed_call_rate'])} | {a['unknown_tool_calls']} "
                   f"| {a.get('mean_tokens', 0)} ({a.get('mean_input_tokens', 0)} + {a.get('mean_output_tokens', 0)}) "
                   f"| {fmt_secs(a.get('median_duration_s'))} | {fmt_tokens(a.get('total_tokens'))} "
                   f"| {fmt_secs(a.get('total_duration_s'))} |")
    out += ["", "Tokens are as the agent's backend reports them, input summed over every turn; compare "
            "them within one agent only. Time is the agent's wall time per trial (the median trial), tool "
            "calls included; the harness's checks and the model's load before the first trial are left out."]
    for spec, a in summary["agents"].items():
        out += ["", f"## `{spec}`", "", "| scenario / prompt | success |", "|---|---|"]
        for k, v in a["by_prompt"].items():
            out.append(f"| {k}{' *(holdout)*' if v['holdout'] else ''} | {_pct(v['rate'])} |")
        if a["tool_usage"]:
            out += ["", "Tool calls: " + ", ".join(f"`{t}` ×{n}" + (f" ({a['tool_errors'][t]} failed)" if t in a['tool_errors'] else "")
                                                   for t, n in a["tool_usage"].items())]
        if a["error_types"]:
            out.append("Call error types: " + ", ".join(f"{k} ×{v}" for k, v in a["error_types"].items()))
        if a.get("text_tool_calls"):
            out.append(f"Tool calls written as text and recovered by the harness: {a['text_tool_calls']} "
                       "(a model/runtime quirk; a stricter runtime would have lost these)")
        unused = [t["id"] for t in catalog if t["id"] not in a["tool_usage"]]
        if unused:
            out.append("Never called: " + ", ".join(f"`{t}`" for t in unused))
        if a["train_failures"]:
            out += ["", "### Train failures", ""]
            for f in a["train_failures"]:
                out.append(f"- **{f['scenario']}/{f['prompt']}** — {f['why'] or 'failed'} — tools: "
                           f"{', '.join(f['tools_used']) or 'none'} — `{f['dir']}`")
    out += ["", "## Tools shown to the agent", ""]
    for t in catalog:
        out.append(f"- `{t['name']}` ({t.get('operation')}): {t['description']}")
    return "\n".join(out) + "\n"


def leaderboard(module: str) -> str:
    from . import history
    return history.text_report(module)
