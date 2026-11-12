"""`tc` harness commands (run inside the agent container)."""
from __future__ import annotations

import argparse
import json
import subprocess
import sys
from pathlib import Path

from . import config, evaluate, scenarios, site, workspace

HELP = """\
tc module prepare <module> [--composer SPEC|none] [--enable a,b] [--tool-module NAME]
      Reset the site, install the module, and capture its baseline (FS + DB). Writes module.yml.
tc module show <module>

tc site reset [--module M]       Restore filesystem + database to the clean site (or the module baseline).
tc site status                   What the site holds now.
tc site login [--uid N]          One-time login link.

tc variant save <module> [--note TEXT]   Copy the tool module out of the site into variants/vNN.
tc variant list <module>
tc variant install <module> <vNN|latest> Reset FS + DB, install the variant, create scenario users.
tc variant diff <module> <vA> <vB>

tc tools <module> [--json]       The installed tools exactly as the agent sees them.
tc scenarios check <module>      Validate scenarios.yml; run setup + checks on a clean install.
tc scenarios checks <module> --scenario ID   Run one scenario's checks against the site as it is now.

tc eval <module> --variant V [--agent SPEC]... [--repeats N] [--scenario ID]... [--prompt ID]...
        [--no-holdout] [--label TEXT]
      Reset FS + DB, install V, run every scenario x prompt x agent x repeat. Writes runs/<id>/.
tc try <module> --agent SPEC [--as USER] (--prompt TEXT | --scenario ID [--prompt-id P])
      One trial against the installed variant, printed in full. No run directory.
tc report [<module>]             Every eval of the module with its change and best so far; without a module,
                                 every module's progress (first → best). Also refreshes the HTML.
tc html [<module>] [--runs]      Regenerate the HTML pages: workspace/index.html, workspace/<m>/index.html,
                                 and with --runs every runs/<id>/report.html too.

Agent SPEC: fc:<endpoint>/<model> | claude:<model> | codex:<model> | opencode:<provider>/<model>
Default agent: $TC_DEFAULT_AGENT
"""


def _default_agents(given: list[str] | None) -> list[str]:
    if given:
        return given
    d = config.env("TC_DEFAULT_AGENT")
    if not d:
        raise SystemExit("no --agent given and TC_DEFAULT_AGENT is not set in .env")
    return [d]


def cmd_module(a) -> int:
    if a.action == "show":
        print(yaml_dump(workspace.load_module(a.module)))
        return 0
    module = a.module
    d = config.module_dir(module)
    d.mkdir(parents=True, exist_ok=True)
    existing = {}
    if (d / "module.yml").is_file():
        existing = workspace.load_module(module)
    composer = a.composer if a.composer is not None else existing.get("composer", f"drupal/{module}")
    if composer in ("none", "core", ""):
        composer = None
    enable = a.enable.split(",") if a.enable else existing.get("enable", [module])
    tool_module = a.tool_module or existing.get("tool_module", f"{module}_tools")
    site.require_running()
    print(f"[reset] restoring the clean site baseline")
    workspace.autosave(module)
    site.baseline("restore", config.SITE_BASELINE)
    if composer:
        print(f"[composer] require {composer}")
        site.composer("require", "-W", composer)
    print(f"[enable] {', '.join(enable)}")
    site.drush("pm:enable", *enable, "-y", timeout=900)
    print(f"[baseline] capturing {module} baseline")
    site.baseline("capture", config.module_baseline(module))
    data = {**existing, "module": module, "composer": composer, "enable": enable, "tool_module": tool_module,
            "expose": existing.get("expose") or {"providers": [tool_module], "ids": []},
            "prepared_at": workspace.now()}
    workspace.save_module(module, data)
    notes = d / "NOTES.md"
    if not notes.exists():
        notes.write_text(f"# {module}: notes\n\nWhat was learned while building tools for `{module}`. "
                         "Append dated entries; newest last.\n")
    workspace.set_site_state(module=module, variant=None, users={"admin": 1, "anonymous": 0})
    _refresh_html(module)
    print(f"[done] workspace/{module}/module.yml written; site holds {module} with no tool module")
    return 0


def yaml_dump(data) -> str:
    import yaml
    return yaml.safe_dump(data, sort_keys=False)


def cmd_site(a) -> int:
    if a.action == "status":
        st = workspace.site_state()
        print(json.dumps({"site": f"https://{config.SITE_HOST}", "ready": site.running(), **st}, indent=2))
        return 0
    if a.action == "login":
        site.require_running()
        r = site.drush("user:login", f"--uid={a.uid}", f"--uri=https://{config.SITE_HOST}")
        print(r.stdout.strip())
        return 0
    if a.action == "reset":
        site.require_running()
        workspace.autosave(a.module)
        target = config.module_baseline(a.module) if a.module else config.SITE_BASELINE
        print(f"[reset] filesystem + database -> {target.relative_to(config.ROOT)}")
        site.baseline("restore", target, quiet=False)
        workspace.set_site_state(module=a.module, variant=None, users={"admin": 1, "anonymous": 0})
        return 0
    return 1


def cmd_variant(a) -> int:
    if a.action == "save":
        workspace.save_variant(a.module, note=a.note or "")
        _refresh_html(a.module)
    elif a.action == "list":
        st = workspace.site_state()
        for v in workspace.list_variants(a.module):
            here = "  <- installed" if st.get("module") == a.module and st.get("variant") == v["id"] else ""
            print(f"{v['id']:5} parent={v.get('parent') or '-':5} {v.get('created_at', '')}  {v.get('note', '')}{here}")
    elif a.action == "install":
        evaluate.install_variant(a.module, a.variant)
        _refresh_html(a.module)
    elif a.action == "diff":
        pa, pb = workspace.variant_path(a.module, a.a), workspace.variant_path(a.module, a.b)
        subprocess.run(["diff", "-ru", str(pa), str(pb)])
    return 0


def cmd_tools(a) -> int:
    st = evaluate.installed(a.module)
    catalog = json.loads(Path(st["catalog"]).read_text()) if st.get("catalog") else []
    if not catalog:
        print("no catalog: install a variant first (tc variant install <module> <v>)")
        return 1
    if a.json:
        print(json.dumps(catalog, indent=2))
        return 0
    print(f"{len(catalog)} tool(s) the agent sees for {a.module} @ {st.get('variant')}:\n")
    for t in catalog:
        props = t["input_schema"].get("properties") or {}
        req = set(t["input_schema"].get("required") or [])
        print(f"■ {t['name']}  [{t.get('operation')}{', destructive' if t.get('destructive') else ''}]"
              f"  permission: {t.get('permission')}")
        print(f"  {t['description']}")
        for name, p in props.items():
            extra = []
            if "enum" in p:
                extra.append("enum=" + ",".join(map(str, p["enum"]))[:80])
            if "default" in p:
                extra.append(f"default={p['default']}")
            ptype = p.get("type", "?")
            ptype = "|".join(map(str, ptype)) if isinstance(ptype, list) else ptype
            print(f"    - {name}{'*' if name in req else ''} ({ptype}): {p.get('description', '')}"
                  + (f"  [{'; '.join(extra)}]" if extra else ""))
        print()
    return 0


def cmd_scenarios(a) -> int:
    if a.action == "checks":
        suite = scenarios.load(a.module)
        sc = next((s for s in suite.scenarios if s.id == a.scenario), None)
        if not sc:
            raise SystemExit(f"give --scenario, one of: {', '.join(s.id for s in suite.scenarios)}")
        results = evaluate.run_checks(sc)
        for c in results:
            print(f"{'✓' if c['ok'] else '✗'} {c['name']}" + (f": {c['error'][:500]}" if c.get("error") else
                                                              f"  (returned {json.dumps(c['value'])[:200]})"))
        return 0 if all(c["ok"] for c in results) else 1
    try:
        suite = scenarios.load(a.module)
    except scenarios.ScenarioError as e:
        print(e)
        return 1
    n_prompts = sum(len(s.prompts) for s in suite.scenarios)
    n_hold = sum(1 for s in suite.scenarios for p in s.prompts if p.holdout)
    print(f"scenarios.yml OK: {len(suite.scenarios)} scenario(s), {n_prompts} prompt(s), {n_hold} holdout")
    if n_hold == 0:
        print("  note: no holdout prompts; mark 1 per scenario `holdout: true` so tweaks can't just overfit")
    st = workspace.site_state()
    if st.get("module") == a.module and st.get("snapshot"):
        print(f"checking against the installed variant {st['variant']} (database restored before each scenario)")
        restore = lambda: site.db_restore(Path(st["snapshot"]))
        users = st.get("users", {})
    else:
        print("no variant installed: checking against the module baseline (users from users: are not created)")
        base = config.module_baseline(a.module) / "db.sql.gz"
        if not base.is_file():
            raise SystemExit(f"no module baseline; run tc module prepare {a.module}")
        restore = lambda: site.db_restore(base)
        users = {"admin": 1, "anonymous": 0}
    bad = 0
    for sc in suite.scenarios:
        restore()
        err = evaluate.run_setup(sc)
        if err:
            print(f"  ✗ {sc.id}: {err}")
            bad += 1
            continue
        if sc.run_as not in users:
            print(f"  ! {sc.id}: run_as '{sc.run_as}' is created only when a variant is installed")
        results = evaluate.run_checks(sc)
        errors = [c for c in results if c["error"]]
        passing = [c for c in results if c["ok"]]
        if errors:
            bad += 1
            for c in errors:
                print(f"  ✗ {sc.id}: check '{c['name']}' errored: {c['error'][:300]}")
        elif passing:
            bad += 1
            print(f"  ✗ {sc.id}: already passes before the agent does anything: "
                  + ", ".join(c["name"] for c in passing))
        elif not results:
            print(f"  ✓ {sc.id}: setup ran; answer-only scenario (judged by answer_matches)")
        else:
            print(f"  ✓ {sc.id}: setup ran, {len(results)} check(s) fail before the agent acts (as they should)")
    restore()
    return 1 if bad else 0


def cmd_eval(a) -> int:
    evaluate.run_eval(a.module, a.variant, _default_agents(a.agent), repeats=a.repeats,
                      only_scenarios=a.scenario, only_prompts=a.prompt, holdout=not a.no_holdout,
                      label=a.label or "")
    return 0


def cmd_try(a) -> int:
    from . import agents as agent_backends
    import tempfile
    st = evaluate.installed(a.module)
    suite = scenarios.load(a.module)
    catalog = json.loads(Path(st["catalog"]).read_text())
    spec = _default_agents([a.agent] if a.agent else None)[0]
    agent = agent_backends.get(spec)
    if a.scenario:
        sc = next((s for s in suite.scenarios if s.id == a.scenario), None)
        if not sc:
            raise SystemExit(f"no scenario {a.scenario}")
        prompt = next((p for p in sc.prompts if p.id == a.prompt_id), sc.prompts[0]) if not a.prompt else \
            scenarios.Prompt(id="adhoc", text=a.prompt)
        if a.as_user:
            sc.run_as = a.as_user
    else:
        if not a.prompt:
            raise SystemExit("give --prompt TEXT or --scenario ID")
        sc = scenarios.Scenario(id="adhoc", description="", run_as=a.as_user or "admin",
                                prompts=[], checks=[])
        prompt = scenarios.Prompt(id="adhoc", text=a.prompt)
    if sc.run_as not in st["users"]:
        raise SystemExit(f"unknown user {sc.run_as}; have {list(st['users'])}")
    tdir = Path(tempfile.mkdtemp(prefix="tc-try-"))
    res = evaluate.run_trial(st, suite, sc, prompt, spec, agent, tdir, catalog)
    print(f"\nprompt: {prompt.text}\nagent:  {spec} as {sc.run_as}\n")
    for line in (tdir / "trace.jsonl").read_text().splitlines():
        c = json.loads(line)
        print(f"→ {c.get('function')}({json.dumps(c.get('args'), ensure_ascii=False)[:400]})")
        print("  " + c.get("result", "").replace("\n", "\n  ")[:1200])
    print(f"\nanswer: {res.get('answer')}")
    if res.get("error"):
        print(f"error: {res['error']}")
    for c in res.get("checks", []):
        print(f"check {'✓' if c['ok'] else '✗'} {c['name']}" + (f": {c['error'][:200]}" if c.get("error") else ""))
    print(f"\n{'PASS' if res['passed'] else 'FAIL'}  ({res.get('tool_calls', 0)} calls, {res.get('duration_s')}s)"
          f"  files: {tdir}")
    print("the site still holds this trial's state; `tc variant install` or another trial resets it")
    return 0 if res["passed"] else 1


def cmd_report(a) -> int:
    from . import history, html
    print(history.text_report(a.module) if a.module else history.text_overview())
    pages = html.write_all(a.module)
    page = pages[0] if a.module else pages[-1]
    print(f"\nhtml: {page.relative_to(config.ROOT)}  ({html.url_for(page)})")
    return 0


def cmd_html(a) -> int:
    from . import html
    for p in html.write_all(a.module, runs=a.runs):
        print(f"{p.relative_to(config.ROOT)}  {html.url_for(p)}")
    return 0


def _refresh_html(module: str) -> None:
    """Keep the module page current after a change; never fail the command over it."""
    try:
        from . import html
        html.write_all(module)
    except Exception as e:  # noqa: BLE001
        print(f"[html] could not refresh the module page: {e}", file=sys.stderr)


def main(argv: list[str]) -> int:
    if not argv or argv[0] in ("help", "-h", "--help"):
        print(HELP)
        return 0
    if argv[0] == "mcp":
        from .mcp_server import main as mcp_main
        return mcp_main(argv[1:])
    ap = argparse.ArgumentParser(prog="tc", add_help=True)
    sub = ap.add_subparsers(dest="cmd", required=True)

    p = sub.add_parser("module")
    p.add_argument("action", choices=["prepare", "show"])
    p.add_argument("module")
    p.add_argument("--composer")
    p.add_argument("--enable")
    p.add_argument("--tool-module")
    p.set_defaults(fn=cmd_module)

    p = sub.add_parser("site")
    p.add_argument("action", choices=["reset", "status", "login"])
    p.add_argument("--module")
    p.add_argument("--uid", type=int, default=1)
    p.set_defaults(fn=cmd_site)

    p = sub.add_parser("variant")
    p.add_argument("action", choices=["save", "list", "install", "diff"])
    p.add_argument("module")
    p.add_argument("a", nargs="?")
    p.add_argument("b", nargs="?")
    p.add_argument("--note")
    p.set_defaults(fn=lambda a: cmd_variant(_variant_args(a)))

    p = sub.add_parser("tools")
    p.add_argument("module")
    p.add_argument("--json", action="store_true")
    p.set_defaults(fn=cmd_tools)

    p = sub.add_parser("scenarios")
    p.add_argument("action", choices=["check", "checks"])
    p.add_argument("module")
    p.add_argument("--scenario")
    p.set_defaults(fn=cmd_scenarios)

    p = sub.add_parser("eval")
    p.add_argument("module")
    p.add_argument("--variant", required=True)
    p.add_argument("--agent", action="append")
    p.add_argument("--repeats", type=int, default=int(config.env("TC_DEFAULT_REPEATS", "2")))
    p.add_argument("--scenario", action="append")
    p.add_argument("--prompt", action="append")
    p.add_argument("--no-holdout", action="store_true")
    p.add_argument("--label")
    p.set_defaults(fn=cmd_eval)

    p = sub.add_parser("try")
    p.add_argument("module")
    p.add_argument("--agent")
    p.add_argument("--as", dest="as_user")
    p.add_argument("--prompt")
    p.add_argument("--scenario")
    p.add_argument("--prompt-id")
    p.set_defaults(fn=cmd_try)

    p = sub.add_parser("report")
    p.add_argument("module", nargs="?")
    p.set_defaults(fn=cmd_report)

    p = sub.add_parser("html")
    p.add_argument("module", nargs="?")
    p.add_argument("--runs", action="store_true")
    p.set_defaults(fn=cmd_html)

    a = ap.parse_args(argv)
    try:
        return a.fn(a) or 0
    except (site.SiteError, scenarios.ScenarioError) as e:
        print(f"error: {e}", file=sys.stderr)
        return 1


def _variant_args(a):
    if a.action == "install":
        if not a.a:
            raise SystemExit("usage: tc variant install <module> <variant>")
        a.variant = a.a
    if a.action == "diff" and not (a.a and a.b):
        raise SystemExit("usage: tc variant diff <module> <vA> <vB>")
    return a
