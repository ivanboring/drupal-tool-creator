"""Static HTML views of the workspace, written next to the Markdown and JSON.

    workspace/index.html                         the global report: every module's progress, every
                                                 eval of every module (filterable), the learnings
    workspace/<m>/index.html                     the module: progress per agent, every eval with its
                                                 change, pass rate by scenario over evals, variants,
                                                 scenarios, current tools, NOTES/ITERATIONS/REPORT
    workspace/<m>/runs/<id>/report.html          one eval: per agent, pass-rate matrix, every train
                                                 trial with its tool calls, the tools shown
    workspace/<m>/runs/<id>/holdout/index.html   the held-out trials (kept apart on purpose: the
                                                 reiterate loop never reads holdout/)

Every page is one self-contained file (inline CSS and JS, no external requests), so it opens from
disk (file://) as well as through ddev at <primary url>/tc/. Everything taken from runs (prompts,
answers, tool output) is escaped; the tooltip script only ever sets textContent.

Progress is only compared between complete evals on the same version of a module's scenarios
(see history.py): a change of scenarios is drawn as a break, never as a gain or a loss. Next to
the success rate, every progress chart can show tokens and time per trial: once an agent passes as
often as it can, those are what a tweak can still improve.
"""
from __future__ import annotations

import html
import json
import math
import os
import statistics
from collections import defaultdict
from pathlib import Path

from . import config, history

E = html.escape
SERIES_CAP = 4  # categorical slots that validate on adjacent pairs in both modes, direct-labelled

CSS = """
:root{color-scheme:light;
--page:#f9f9f7;--surface:#fcfcfb;--ink:#0b0b0b;--ink-2:#52514e;--muted:#898781;--grid:#e1e0d9;
--axis:#c3c2b7;--border:rgba(11,11,11,.10);--code:#f0efec;
--s1:#2a78d6;--s2:#eb6834;--s3:#1baf7a;--s4:#eda100;
--good:#0ca30c;--good-ink:#006300;--bad:#d03b3b;--bad-ink:#b42828;--warn:#fab219;
--h0:#cde2fb;--h1:#9ec5f4;--h2:#6da7ec;--h3:#3987e5;--h4:#256abf;--h5:#184f95;
--hi0:#0b0b0b;--hi1:#0b0b0b;--hi2:#0b0b0b;--hi3:#ffffff;--hi4:#ffffff;--hi5:#ffffff}
@media (prefers-color-scheme:dark){:root:where(:not([data-theme="light"])){color-scheme:dark;
--page:#0d0d0d;--surface:#1a1a19;--ink:#f0efec;--ink-2:#c3c2b7;--muted:#898781;--grid:#2c2c2a;
--axis:#383835;--border:rgba(255,255,255,.10);--code:#232321;
--s1:#3987e5;--s2:#d95926;--s3:#199e70;--s4:#c98500;--good-ink:#0ca30c;--bad-ink:#e66767;
--h0:#104281;--h1:#184f95;--h2:#1c5cab;--h3:#2a78d6;--h4:#5598e7;--h5:#86b6ef;
--hi0:#ffffff;--hi1:#ffffff;--hi2:#ffffff;--hi3:#ffffff;--hi4:#0b0b0b;--hi5:#0b0b0b}}
:root[data-theme="dark"]{color-scheme:dark;
--page:#0d0d0d;--surface:#1a1a19;--ink:#f0efec;--ink-2:#c3c2b7;--muted:#898781;--grid:#2c2c2a;
--axis:#383835;--border:rgba(255,255,255,.10);--code:#232321;
--s1:#3987e5;--s2:#d95926;--s3:#199e70;--s4:#c98500;--good-ink:#0ca30c;--bad-ink:#e66767;
--h0:#104281;--h1:#184f95;--h2:#1c5cab;--h3:#2a78d6;--h4:#5598e7;--h5:#86b6ef;
--hi0:#ffffff;--hi1:#ffffff;--hi2:#ffffff;--hi3:#ffffff;--hi4:#0b0b0b;--hi5:#0b0b0b}
*{box-sizing:border-box}
body{margin:0;background:var(--page);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
main{max-width:1180px;margin:0 auto;padding:24px 16px 64px}
a{color:var(--s1)}
h1{font-size:26px;margin:4px 0 2px;font-weight:650}
h2{font-size:19px;margin:40px 0 12px;font-weight:650}
h3{font-size:16px;margin:24px 0 8px;font-weight:650}
.crumbs{font-size:13px;color:var(--muted)}
.crumbs a{color:var(--ink-2)}
.sub{color:var(--ink-2);margin:0 0 8px}
.muted{color:var(--muted)}
.card{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:16px;overflow-x:auto}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:20px 0}
.tile{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:14px 16px}
.tile .label{font-size:13px;color:var(--ink-2)}
.tile .value{font-size:28px;font-weight:600;line-height:1.2;margin-top:4px}
.tile .note{font-size:13px;color:var(--muted)}
table{border-collapse:collapse;width:100%;font-size:14px}
th,td{text-align:left;padding:7px 10px;border-bottom:1px solid var(--grid);vertical-align:top}
th{font-weight:600;color:var(--ink-2);font-size:13px}
td.num,th.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.key{display:inline-block;width:14px;height:2px;border-radius:1px;vertical-align:middle;margin-right:8px}
.k1{background:var(--s1)}.k2{background:var(--s2)}.k3{background:var(--s3)}.k4{background:var(--s4)}.k0{background:var(--muted)}
.tag{display:inline-block;font-size:12px;padding:1px 7px;border-radius:9px;border:1px solid var(--border);color:var(--ink-2);margin-left:6px;white-space:nowrap}
.status{font-weight:600;white-space:nowrap}
.status.pass{color:var(--good-ink)}.status.fail{color:var(--bad-ink)}
.status .dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px;vertical-align:1px}
.status.pass .dot{background:var(--good)}.status.fail .dot{background:var(--bad)}
.heat td.cell{text-align:center;vertical-align:middle;font-variant-numeric:tabular-nums;font-weight:600;border:2px solid var(--surface);border-radius:6px;min-width:84px}
.h0{background:var(--h0);color:var(--hi0)}.h1{background:var(--h1);color:var(--hi1)}.h2{background:var(--h2);color:var(--hi2)}
.h3{background:var(--h3);color:var(--hi3)}.h4{background:var(--h4);color:var(--hi4)}.h5{background:var(--h5);color:var(--hi5)}
.cell:hover,.cell:focus{outline:2px solid var(--ink);outline-offset:-2px}
.meter{display:inline-block;width:64px;height:6px;border-radius:3px;background:var(--grid);vertical-align:middle;margin-right:8px;overflow:hidden}
.meter i{display:block;height:100%;background:var(--ink-2);border-radius:3px}
pre,code{font:12.5px/1.45 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
pre{background:var(--code);border-radius:6px;padding:10px 12px;margin:6px 0;white-space:pre-wrap;word-break:break-word;max-height:420px;overflow:auto}
code{background:var(--code);padding:1px 5px;border-radius:4px}
pre code{background:none;padding:0}
details.trial{background:var(--surface);border:1px solid var(--border);border-radius:8px;margin:6px 0}
details.trial>summary{cursor:pointer;padding:9px 12px;display:flex;gap:12px;align-items:baseline;flex-wrap:wrap;list-style:none}
details.trial>summary::-webkit-details-marker{display:none}
details.trial>summary::before{content:"▸";color:var(--muted);width:10px}
details.trial[open]>summary::before{content:"▾"}
details.trial .body{padding:4px 16px 14px 34px}
.why{color:var(--ink-2);font-size:13px}
.call{border-left:2px solid var(--grid);padding-left:12px;margin:12px 0}
.call .fn{font-weight:600;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13px}
.call.failed{border-left-color:var(--bad)}
.lbl{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;margin-top:10px}
.controls{display:flex;flex-wrap:wrap;gap:16px;align-items:center;margin:8px 0 12px;font-size:14px;color:var(--ink-2)}
.legend{display:flex;gap:18px;flex-wrap:wrap;font-size:13px;color:var(--ink-2);margin:0 0 8px}
.chart{position:relative}
.psub{font-size:13px;margin:-2px 0 6px}
.legend svg{vertical-align:middle;margin-right:6px}
.delta.up,.delta.good{color:var(--good)}.delta.down,.delta.bad{color:var(--bad)}
.heat.compact td.cell{min-width:58px;padding:6px 8px}
.heat th.vb,.heat td.vb{border-left:2px dashed var(--axis)}
td.na{text-align:center;vertical-align:middle;color:var(--muted)}
.versions{margin-top:12px}.versions ul{margin:4px 0 0;padding-left:18px;font-size:13px;color:var(--ink-2)}
select{font:inherit;font-size:14px;padding:3px 6px;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--ink)}
.controls label{display:flex;gap:8px;align-items:center}
td.agent{white-space:nowrap}
.multiples{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px 28px}
.ptitle{font-size:14px;font-weight:600;margin-bottom:4px}
.ptitle .muted{font-weight:400}
.chart svg{width:100%;height:auto;display:block;outline:none}
.chart svg:focus-visible{outline:2px solid var(--s1);outline-offset:4px;border-radius:4px}
.tip{position:absolute;pointer-events:none;background:var(--surface);border:1px solid var(--border);border-radius:8px;
padding:8px 10px;font-size:13px;box-shadow:0 4px 16px rgba(0,0,0,.12);display:none;min-width:150px;z-index:2}
.tip .t{font-weight:600;margin-bottom:4px}
.tip .r{display:flex;align-items:center;gap:8px;white-space:nowrap}
.tip .r b{font-variant-numeric:tabular-nums}
.tip .r span{color:var(--ink-2)}
.md table{width:auto}.md h1{font-size:20px}.md h2{font-size:17px;margin-top:20px}.md h3{font-size:15px}
.empty{color:var(--muted);font-style:italic}
footer{margin-top:48px;font-size:12px;color:var(--muted)}
"""

JS = r"""
(function(){
  const tip=document.createElement('div');tip.className='tip';document.body.appendChild(tip);
  function place(x,y){tip.style.display='block';const w=tip.offsetWidth;
    let left=x+14;if(left+w>window.scrollX+document.documentElement.clientWidth-8){left=x-w-14}
    tip.style.left=left+'px';tip.style.top=(y+14)+'px'}
  function hide(){tip.style.display='none'}
  function row(color,value,name){const r=document.createElement('div');r.className='r';
    const k=document.createElement('i');k.className='key';k.style.background=color;
    const b=document.createElement('b');b.textContent=value;const s=document.createElement('span');s.textContent=name;
    r.append(k,b,s);return r}
  // Heat cells and other marks: data-tip-title / data-tip lines.
  document.querySelectorAll('[data-tip]').forEach(el=>{
    const show=(x,y)=>{tip.replaceChildren();const t=document.createElement('div');t.className='t';
      t.textContent=el.dataset.tipTitle||'';tip.append(t);
      el.dataset.tip.split('\n').forEach(l=>{const d=document.createElement('div');d.textContent=l;tip.append(d)});place(x,y)};
    el.addEventListener('pointermove',e=>show(e.pageX,e.pageY));el.addEventListener('pointerleave',hide);
    el.addEventListener('focus',()=>{const r=el.getBoundingClientRect();show(r.right+window.scrollX,r.top+window.scrollY)});
    el.addEventListener('blur',hide)});
  // Line chart: crosshair snaps to the nearest variant, one readout lists every agent.
  document.querySelectorAll('.chart[data-chart]').forEach(box=>{
    const data=JSON.parse(document.getElementById(box.dataset.chart).textContent);
    const svg=box.querySelector('svg'),hair=svg.querySelector('.hair');let idx=-1;
    const css=getComputedStyle(document.documentElement);
    function show(i,px,py){idx=i;hair.setAttribute('x1',data.xs[i]);hair.setAttribute('x2',data.xs[i]);hair.style.display='';
      tip.replaceChildren();const t=document.createElement('div');t.className='t';t.textContent=data.labels[i];tip.append(t);
      data.series.forEach(s=>{const p=s.points[i];const c=css.getPropertyValue(s.color).trim();
        tip.append(row(c,p?p.text:'not evaluated',s.name))});place(px,py)}
    svg.addEventListener('pointermove',e=>{const pt=svg.createSVGPoint();pt.x=e.clientX;pt.y=e.clientY;
      const x=pt.matrixTransform(svg.getScreenCTM().inverse()).x;let best=0;
      data.xs.forEach((v,i)=>{if(Math.abs(v-x)<Math.abs(data.xs[best]-x))best=i});show(best,e.pageX,e.pageY)});
    svg.addEventListener('pointerleave',()=>{hair.style.display='none';hide()});
    svg.addEventListener('keydown',e=>{if(e.key!=='ArrowRight'&&e.key!=='ArrowLeft')return;e.preventDefault();
      const i=Math.max(0,Math.min(data.xs.length-1,(idx<0?0:idx+(e.key==='ArrowRight'?1:-1))));
      const r=svg.getBoundingClientRect();show(i,r.left+window.scrollX+r.width*data.xs[i]/data.w,r.top+window.scrollY+20)});
    svg.addEventListener('blur',()=>{hair.style.display='none';hide()})});
  // Agent switch: show one [data-switch-group] at a time (without JS they all show, each with a heading).
  document.querySelectorAll('select[data-switch]').forEach(sel=>{
    const f=()=>document.querySelectorAll('[data-switch-group="'+sel.dataset.switch+'"]').forEach(g=>{
      g.style.display=g.dataset.value===sel.value?'':'none'});sel.addEventListener('change',f);f()});
  // Table filters: every select for a table must match a row's data-* value (or be "All").
  document.querySelectorAll('select[data-filter-table]').forEach(sel=>sel.addEventListener('change',()=>{
    const id=sel.dataset.filterTable,sels=[...document.querySelectorAll('select[data-filter-table="'+id+'"]')];
    document.querySelectorAll('#'+id+' tr[data-row]').forEach(tr=>{
      tr.style.display=sels.every(s=>!s.value||tr.dataset[s.dataset.key]===s.value)?'':'none'})}));
  // "Failures only" filter for trial lists.
  document.querySelectorAll('input[data-filter]').forEach(cb=>cb.addEventListener('change',()=>{
    document.querySelectorAll(cb.dataset.filter).forEach(el=>{el.style.display=cb.checked?'none':''})}));
})();
"""


# --- helpers --------------------------------------------------------------------------------------

def _pct(x) -> str:
    return "–" if x is None else f"{x * 100:.0f}%"


def _heat(rate) -> str:
    return "" if rate is None else f"h{max(0, min(5, round(rate * 5)))}"


def _heat_top(rate: float) -> str:
    """Steps for tuning, where 11/12 against 12/12 is the difference that matters: 100% is the
    darkest, then 90%, 75%, 50%, anything passed, nothing passed."""
    return "h5" if rate >= 1 else "h4" if rate >= .9 else "h3" if rate >= .75 else "h2" if rate >= .5 else \
        "h1" if rate > 0 else "h0"


MEASURES = [("rate", "Success rate"), ("tokens", "Tokens per trial"), ("seconds", "Time per trial")]


def _fmt(metric: str):
    return history.fmt_tokens if metric == "tokens" else history.fmt_secs


def _axis_label(v: float, metric: str) -> str:
    if metric == "tokens":
        return f"{v / 1e6:g}M" if v >= 1e6 else f"{v / 1e3:g}k" if v >= 1e3 else f"{v:g}"
    return f"{v:g} s" if v < 60 else f"{v / 60:g} min"


_STEPS = (1, 1.2, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10)


def _nice(x: float) -> float:
    """A round number at or above x, so the scale's gridlines are round numbers too."""
    if x <= 0:
        return 1.0
    k = 10 ** math.floor(math.log10(x))
    return next(m * k for m in _STEPS if m * k >= x - 1e-12)


def _nice_floor(x: float) -> float:
    if x <= 0:
        return 0.0
    k = 10 ** math.floor(math.log10(x))
    return max(m * k for m in _STEPS if m * k <= x + 1e-12)


def _cost_html(p: history.Point, metric: str) -> str:
    """'7.7k ▼ −11%': the value per trial and its change against the previous eval; a change
    within noise is grey and has no arrow."""
    out = E(_fmt(metric)(p.cost(metric)))
    c = p.cost_change(metric)
    if c is None:
        return out
    if not c.real:
        return out + f' <span class="muted" title="within noise">{E(c.text())}</span>'
    return (out + f' <span class="delta {"good" if c.rel < 0 else "bad"}" aria-hidden="true">{"▼" if c.rel < 0 else "▲"}</span> '
            + E(c.text()))


def _status(passed: bool) -> str:
    return f'<span class="status {"pass" if passed else "fail"}">{"✓ Pass" if passed else "✗ Fail"}</span>'


def _json(v) -> str:
    return json.dumps(v, indent=2, ensure_ascii=False, default=str)


def _md(text: str) -> str:
    try:
        import markdown  # python3-markdown in the web image
        return markdown.markdown(text, extensions=["tables", "fenced_code"], output_format="html")
    except Exception:
        return f"<pre>{E(text)}</pre>"


def _read_json(p: Path, default=None):
    try:
        return json.loads(p.read_text())
    except (OSError, json.JSONDecodeError):
        return default


def _page(title: str, crumbs: list[tuple[str, str | None]], body: str) -> str:
    nav = " / ".join(f'<a href="{E(h)}">{E(t)}</a>' if h else E(t) for t, h in crumbs)
    return (f'<!doctype html><html lang="en"><head><meta charset="utf-8">'
            f'<meta name="viewport" content="width=device-width,initial-scale=1">'
            f'<title>{E(title)}</title><style>{CSS}</style></head><body><main>'
            f'<div class="crumbs">{nav}</div>{body}'
            f'<footer>Drupal tool creator · generated {E(_now())} · regenerate with <code>tc html</code></footer>'
            f'</main><script>{JS}</script></body></html>')


def _now() -> str:
    from .workspace import now
    return now()


def url_for(path: Path) -> str:
    base = os.environ.get("DDEV_PRIMARY_URL", f"https://{config.SITE_HOST}").rstrip("/")
    return f"{base}/tc/{path.relative_to(config.WORKSPACE)}"


def _agent_order(module: str) -> list[str]:
    """Every agent ever evaluated for the module: the default agent first, then by first appearance.

    Colour follows the agent, not its position on a page: every page of a module takes slots from
    this one order, so an agent keeps its colour across runs, the dashboard and the chart. The
    workspace page uses the same rule across all modules (history.agent_order()).
    """
    return history.agent_order(module)


def _agent_keys(order: list[str]) -> dict[str, int]:
    """Agent -> categorical slot 1..4 in that fixed order; 0 (muted, table only) past the cap."""
    return {s: (i + 1 if i < SERIES_CAP else 0) for i, s in enumerate(order)}


# --- tools catalog --------------------------------------------------------------------------------

def _catalog(catalog: list[dict]) -> str:
    if not catalog:
        return '<p class="empty">No tools were exposed.</p>'
    out = []
    for t in catalog:
        schema = t.get("input_schema") or {}
        props, req = schema.get("properties") or {}, set(schema.get("required") or [])
        rows = []
        for name, p in props.items():
            typ = p.get("type", "")
            typ = " | ".join(map(str, typ)) if isinstance(typ, list) else str(typ)
            extra = []
            if "enum" in p:
                extra.append("one of: " + ", ".join(str(v) for v in p["enum"] if v is not None))
            if "default" in p:
                extra.append(f"default: {p['default']}")
            rows.append(f"<tr><td><code>{E(name)}</code>{'<span class=tag>required</span>' if name in req else ''}</td>"
                        f"<td>{E(typ)}</td><td>{E(p.get('description', ''))}"
                        f"{'<div class=muted>' + E('; '.join(extra)) + '</div>' if extra else ''}</td></tr>")
        inputs = ("<table><tr><th>Input</th><th>Type</th><th>Description</th></tr>" + "".join(rows) + "</table>"
                  if rows else '<p class="muted">No inputs.</p>')
        flags = [t.get("operation") or ""] + (["destructive"] if t.get("destructive") else [])
        out.append(f'<div class="card" style="margin:10px 0"><div><code>{E(t["name"])}</code>'
                   + "".join(f"<span class=tag>{E(f)}</span>" for f in flags if f)
                   + f'<span class="tag">permission: {E(str(t.get("permission")))}</span></div>'
                   f'<p style="margin:8px 0">{E(t.get("description", ""))}</p>{inputs}</div>')
    return "".join(out)


# --- run report -----------------------------------------------------------------------------------

def _trial(r: dict, run_dir: Path, link_prefix: str = "") -> str:
    tdir = run_dir / r.get("dir", "")
    calls = []
    trace = tdir / "trace.jsonl"
    if trace.is_file():
        for line in trace.read_text().splitlines():
            try:
                calls.append(json.loads(line))
            except json.JSONDecodeError:
                pass
    why = ""
    if not r.get("passed"):
        parts = [f"check “{c['name']}” failed" for c in r.get("checks", []) if not c.get("ok")]
        if r.get("answer_ok") is False:
            parts.append("answer did not match")
        if r.get("error"):
            parts.append(str(r["error"])[:160])
        why = "; ".join(parts)
    head = (f'<summary>{_status(r.get("passed", False))}<span><b>{E(r["scenario"])}</b> / {E(r["prompt"])}</span>'
            f'<span class="muted">{E(r["agent"])} · {E(r.get("dir", "").rsplit("/", 1)[-1])}</span>'
            f'<span class="muted">{r.get("tool_calls", 0)} call(s) · {E(history.fmt_secs(r.get("duration_s", 0)))}'
            + (f' · {E(history.fmt_tokens(tok))} tokens' if (tok := int(r.get("input_tokens") or 0) + int(r.get("output_tokens") or 0)) else "")
            + '</span>'
            f'{"<span class=why>" + E(why) + "</span>" if why else ""}</summary>')
    body = [f'<div class="lbl">Prompt (as {E(r.get("run_as", ""))})</div><pre>{E(r.get("prompt_text", ""))}</pre>']
    if r.get("setup_failed"):
        body.append(f'<div class="lbl">Setup failed</div><pre>{E(str(r.get("error")))}</pre>')
    for i, c in enumerate(calls, 1):
        ok = c.get("success")
        body.append(f'<div class="call{"" if ok else " failed"}"><div class="fn">{i}. {E(str(c.get("function")))}'
                    f'{"" if ok else "<span class=tag>" + E(str(c.get("error_type"))) + "</span>"}'
                    f'<span class="muted" style="font-weight:400"> · {c.get("ms", 0)} ms</span></div>'
                    f'<pre>{E(_json(c.get("args")))}</pre><pre>{E(str(c.get("result", ""))[:6000])}</pre></div>')
    if not calls and not r.get("setup_failed"):
        body.append('<p class="muted">No tool calls.</p>')
    body.append(f'<div class="lbl">Answer</div><pre>{E(r.get("answer") or "(none)")}</pre>')
    if r.get("checks"):
        items = "".join(f'<li>{_status(c.get("ok"))} {E(c["name"])}'
                        + (f'<pre>{E(c["error"][:2000])}</pre>' if c.get("error") else "") + "</li>"
                        for c in r["checks"])
        body.append(f'<div class="lbl">Checks</div><ul style="list-style:none;padding:0">{items}</ul>')
    if r.get("answer_ok") is not None:
        body.append(f'<div class="lbl">Answer check</div><p>{_status(r["answer_ok"])}</p>')
    if r.get("error") and not r.get("setup_failed"):
        body.append(f'<div class="lbl">Agent error</div><pre>{E(str(r["error"]))}</pre>')
    files = [f for f in ("result.json", "trace.jsonl", "transcript.json", "agent-stdout.txt") if (tdir / f).is_file()]
    if files:
        body.append('<div class="lbl">Files</div><p>' + " · ".join(
            f'<a href="{E(link_prefix + r.get("dir", ""))}/{f}">{f}</a>' for f in files) + "</p>")
    cls = "pass" if r.get("passed") else "fail"
    return f'<details class="trial {cls}">{head}<div class="body">{"".join(body)}</div></details>'


def _trial_list(results: list[dict], run_dir: Path, link_prefix: str = "") -> str:
    by_sc: dict[str, list[dict]] = defaultdict(list)
    for r in results:
        by_sc[r["scenario"]].append(r)
    out = ['<label class="controls"><input type="checkbox" data-filter="details.trial.pass"> Show failures only</label>']
    for sc, rs in by_sc.items():
        n_pass = sum(1 for r in rs if r.get("passed"))
        out.append(f'<h3>{E(sc)} <span class="tag">{n_pass}/{len(rs)} passed</span></h3>')
        for r in sorted(rs, key=lambda r: (r["prompt"], r["agent"], r.get("dir", ""))):
            out.append(_trial(r, run_dir, link_prefix))
    return "".join(out)


def write_run(run_dir: Path) -> Path | None:
    summary = _read_json(run_dir / "summary.json")
    meta = _read_json(run_dir / "meta.json", {})
    if not summary:
        return None
    results = []
    rf = run_dir / "results.jsonl"
    if rf.is_file():
        results = [json.loads(l) for l in rf.read_text().splitlines() if l.strip()]
    catalog = _read_json(run_dir / "catalog.json", [])
    module, variant = meta.get("module", ""), meta.get("variant", "")
    order = _agent_order(module) if module else []
    agents = sorted(summary["agents"], key=lambda a: order.index(a) if a in order else len(order))
    keys = _agent_keys(order + [a for a in agents if a not in order])

    # Cost from the trials themselves, so runs from before summary.json had these fields show it too.
    cost: dict[str, dict] = {}
    for spec in agents:
        rs = [r for r in results if r.get("agent") == spec and not r.get("setup_failed")]
        n = len(rs) or 1
        tin = sum(int(r.get("input_tokens") or 0) for r in rs)
        tout = sum(int(r.get("output_tokens") or 0) for r in rs)
        secs = sum(float(r.get("duration_s") or 0) for r in rs)
        tool = sum(float(r.get("tool_time_s") or 0) for r in rs)
        med = statistics.median([float(r.get("duration_s") or 0) for r in rs]) if rs else 0
        cost[spec] = {"in": tin / n, "out": tout / n, "secs": secs / n, "median": med, "tool": tool / n,
                      "has_tool": any("tool_time_s" in r for r in rs), "tokens": tin + tout, "total_secs": secs}
    rows = []
    for spec in agents:
        a, c = summary["agents"][spec], cost[spec]
        rate = a.get("success_rate") or 0
        tok_tip = (f'{c["in"]:,.0f} in + {c["out"]:,.0f} out per trial, as the backend reports them; '
                   f'{history.fmt_tokens(c["tokens"])} in all, in {history.fmt_secs(c["total_secs"])} of agent time')
        time_tip = (f'median trial; mean {history.fmt_secs(c["secs"])}'
                    + (f', of which {history.fmt_secs(c["tool"])} in the tools' if c["has_tool"] else "")
                    + (f'; model load before the first trial {history.fmt_secs(w)}, not counted'
                       if (w := (meta.get("warmup_s") or {}).get(spec)) else ""))
        rows.append(
            f'<tr><td class="agent"><i class="key k{keys[spec]}"></i>{E(spec)}</td>'
            f'<td class="num"><b>{_pct(rate)}</b> <span class="muted">{a["passed"]}/{a["trials"]}</span></td>'
            f'<td class="num">{_pct(a.get("train_rate"))}</td><td class="num">{_pct(a.get("holdout_rate"))}</td>'
            f'<td class="num">{a.get("mean_tool_calls")}</td><td class="num">{_pct(a.get("failed_call_rate"))}</td>'
            f'<td class="num">{a.get("unknown_tool_calls", 0)}</td><td class="num">{a.get("text_tool_calls", 0)}</td>'
            f'<td class="num" title="{E(tok_tip)}">{E(history.fmt_tokens(c["in"] + c["out"]) if c["tokens"] else "–")}</td>'
            f'<td class="num" title="{E(time_tip)}">{E(history.fmt_secs(c["median"]))}</td></tr>')
    agent_table = ('<div class="card"><table><tr><th>Agent</th><th class="num">Passed</th><th class="num">Train</th>'
                   '<th class="num">Holdout</th><th class="num">Calls / trial</th><th class="num">Failed calls</th>'
                   '<th class="num">Unknown tools</th><th class="num">Text calls</th><th class="num">Tokens / trial</th>'
                   '<th class="num">Time / trial</th></tr>' + "".join(rows) + "</table>"
                   '<p class="muted" style="font-size:13px;margin:10px 0 0">Tokens: input + output as each backend reports them, '
                   'input summed over every turn; compare them within one agent. Time: the agent\'s wall time for the median trial, '
                   'tool calls included, the harness\'s checks and the model\'s load left out. Hover a value for the split.</p></div>')

    # scenario/prompt x agent matrix
    cells: dict[tuple[str, str, str], list[bool]] = defaultdict(list)
    prompts: dict[tuple[str, str], dict] = {}
    for r in results:
        cells[(r["scenario"], r["prompt"], r["agent"])].append(bool(r.get("passed")))
        prompts.setdefault((r["scenario"], r["prompt"]), {"holdout": r.get("holdout"), "text": r.get("prompt_text", "")})
    mrows = []
    for (sc, p), info in prompts.items():
        tds = []
        for spec in agents:
            v = cells.get((sc, p, spec), [])
            if not v:
                tds.append('<td class="cell muted">–</td>')
                continue
            rate = sum(v) / len(v)
            tip = f"{sum(v)} of {len(v)} passed ({rate * 100:.0f}%)\n{spec}"
            tds.append(f'<td class="cell {_heat(rate)}" tabindex="0" data-tip-title="{E(sc)} / {E(p)}" '
                       f'data-tip="{E(tip)}">{sum(v)}/{len(v)}</td>')
        mrows.append(f'<tr><td><b>{E(sc)}</b> / {E(p)}{"<span class=tag>holdout</span>" if info["holdout"] else ""}'
                     f'<div class="muted" style="font-size:13px">{E(info["text"][:160])}</div></td>{"".join(tds)}</tr>')
    matrix = ('<div class="card"><table class="heat"><tr><th>Scenario / prompt</th>'
              + "".join(f'<th style="text-align:center"><i class="key k{keys[s]}"></i>{E(s)}</th>' for s in agents)
              + "</tr>" + "".join(mrows) + "</table>"
              '<p class="muted" style="font-size:13px;margin:10px 0 0">Cells: trials passed of trials run; darker is a higher pass rate.</p></div>')

    usage = []
    for spec in agents:
        a = summary["agents"][spec]
        tu, te = a.get("tool_usage") or {}, a.get("tool_errors") or {}
        never = [t["id"] for t in catalog if t["id"] not in tu]
        lines = "".join(f'<tr><td><code>{E(t)}</code></td><td class="num">{n}</td><td class="num">{te.get(t, 0)}</td></tr>'
                        for t, n in tu.items())
        usage.append(f'<h3><i class="key k{keys[spec]}"></i>{E(spec)}</h3><div class="card"><table>'
                     f'<tr><th>Tool</th><th class="num">Calls</th><th class="num">Failed</th></tr>{lines}</table>'
                     + (f'<p class="muted">Never called: {", ".join(E(t) for t in never)}</p>' if never else "")
                     + (f'<p class="muted">Error types: {E(", ".join(f"{k} ×{v}" for k, v in (a.get("error_types") or {}).items()))}</p>'
                        if a.get("error_types") else "") + "</div>")

    train = [r for r in results if not r.get("holdout")]
    hold = [r for r in results if r.get("holdout")]
    hold_link = ""
    if hold:
        hp = run_dir / "holdout" / "index.html"
        hp.parent.mkdir(exist_ok=True)
        hp.write_text(_page(
            f"Holdout {module} {variant}",
            [("Workspace", "../../../../index.html"), (module, "../../../index.html"),
             (meta.get("run_id", ""), "../report.html"), ("holdout", None)],
            f'<h1>Holdout trials</h1><p class="sub">{E(module)} @ {E(variant)} · run {E(meta.get("run_id", ""))}. '
            'Kept on a separate page on purpose: the reiterate loop must not read these, so the holdout '
            'score stays an honest check that tweaks generalise.</p>'
            + _trial_list(hold, run_dir, link_prefix="../")))
        n_pass = sum(1 for r in hold if r.get("passed"))
        hold_link = (f'<h2>Holdout trials</h2><p>{n_pass} of {len(hold)} passed. '
                     '<a href="holdout/index.html">Open the holdout trials</a> '
                     '<span class="muted">(a separate page: the reiterate loop never reads it)</span></p>')

    dur = ""
    if meta.get("started_at") and meta.get("finished_at"):
        import datetime as dt
        f = "%Y-%m-%dT%H:%M:%SZ"
        secs = (dt.datetime.strptime(meta["finished_at"], f) - dt.datetime.strptime(meta["started_at"], f)).seconds
        dur = f" · took {secs // 60}m {secs % 60}s"
    tot_tok, tot_secs = sum(c["tokens"] for c in cost.values()), sum(c["total_secs"] for c in cost.values())
    if tot_tok or tot_secs:
        dur += (f" · {history.fmt_tokens(tot_tok)} tokens" if tot_tok else "") + f" · {history.fmt_secs(tot_secs)} of agent time"
    body = (f'<h1>{E(module)} @ {E(variant)}</h1>'
            f'<p class="sub">Run <code>{E(meta.get("run_id", ""))}</code>'
            f'{" · " + E(meta["label"]) if meta.get("label") else ""} · {meta.get("repeats")} repeat(s) · '
            f'started {E(meta.get("started_at", ""))}{dur}</p>'
            f'<h2>Agents</h2>{agent_table}'
            f'<h2>Pass rate by scenario and prompt</h2>{matrix}'
            f'<h2>Train trials</h2>{_trial_list(train, run_dir) if train else "<p class=empty>None.</p>"}'
            f'{hold_link}'
            f'<h2>Tool usage</h2>{"".join(usage)}'
            f'<h2>Tools shown to the agent</h2>{_catalog(catalog)}')
    out = run_dir / "report.html"
    out.write_text(_page(f"Eval {module} {variant}",
                         [("Workspace", "../../../index.html"), (module, "../../index.html"),
                          (meta.get("run_id", ""), None)], body))
    return out


# --- progress over evals (shared by the module pages and the workspace page) ---------------------

def _change_html(p: history.Point) -> str:
    c = p.change
    if c not in ("up", "down"):
        return f'<span class="muted">{E(p.change_text())}</span>'
    out = (f'<span style="white-space:nowrap"><span class="delta {c}" aria-hidden="true">{"▲" if c == "up" else "▼"}</span> '
           f'{E(history.pts(p.delta))}</span>')
    if p.within_noise:
        out += ' <span class="muted" style="white-space:nowrap">within noise</span>'
    elif p.new_best:
        out += '<span class="tag">new best</span>'
    return out


def _eval_cell(r: history.Run, prefix: str) -> str:
    return (f'<a href="{E(prefix + "runs/" + r.dir.name + "/report.html")}" title="{E(r.id)}">{E(r.variant)}</a>'
            + (f' <span class="muted">{E(r.label)}</span>' if r.label else "")
            + ('<span class="tag">partial</span>' if r.partial else ""))


def _legend(versions: bool, lo: float = 0.0) -> str:
    return ('<div class="legend">'
            '<span><svg width="14" height="14" aria-hidden="true"><circle cx="7" cy="7" r="4" fill="var(--ink-2)"/></svg>each eval</span>'
            '<span><i class="key" style="background:var(--ink-2)"></i>best so far</span>'
            + ('<span><svg width="14" height="14" aria-hidden="true"><line x1="7" x2="7" y1="0" y2="14" stroke="var(--muted)" '
               'stroke-width="1.5" stroke-dasharray="3 3"/></svg>scenarios changed</span>' if versions else "")
            + (f'<span class="muted">scale {round(lo * 100)}–100%, the same in every panel</span>' if lo > 0 else "")
            + '</div>')


def _first_best(h: history.History, agent: str) -> str:
    v = h.latest_version
    pre = f"{v.name} · " if v and len(h.versions) > 1 else ""
    b, f = h.best(agent), h.first(agent)
    if not b:
        return f"no complete eval on {v.name}" if v else "no complete eval"
    n = sum(1 for p in h.points_for(agent) if not p.run.partial and p.run.version is v)
    if n == 1:
        return pre + f"one eval: {_pct(b.rate)} at {b.run.variant}"
    return pre + f"first {_pct(f.rate)} at {f.run.variant} → best {_pct(b.rate)} at {b.run.variant} · {n} evals"


def _floor(rates: list[float]) -> float:
    """Bottom of the progress scale: tuning happens near the top, and on a 0-100% scale a gain from
    96% to 100% is a flat line. Lines need no zero baseline; every panel on a page shares it."""
    return max(0.0, int((min(rates, default=0.0) - 0.05) * 10) / 10)


def _x_axis(runs: list[history.Run], xs: list[float], L: float, T: float, ph: float, H: float, multi: bool) -> list[str]:
    """Variant ticks (thinned to about 7), a dashed break and an S# label where the scenarios
    changed, and the hover crosshair."""
    parts = []
    step = max(1, -(-len(runs) // 7))
    n = len(runs)
    for i, r in enumerate(runs):
        if i == n - 1 or (i % step == 0 and n - 1 - i >= step):
            parts.append(f'<text x="{xs[i]:.1f}" y="{H - 8}" text-anchor="middle" font-size="12" fill="var(--muted)">{E(r.variant)}</text>')
        new_version = i > 0 and r.version is not runs[i - 1].version
        if new_version:
            bx = (xs[i - 1] + xs[i]) / 2
            parts.append(f'<line x1="{bx:.1f}" x2="{bx:.1f}" y1="{T - 16}" y2="{T + ph}" stroke="var(--muted)" '
                         'stroke-width="1.5" stroke-dasharray="3 3"/>')
        if multi and (i == 0 or new_version):
            lx = L if i == 0 else (xs[i - 1] + xs[i]) / 2 + 5
            parts.append(f'<text x="{lx:.1f}" y="{T - 9}" font-size="11" fill="var(--muted)">{E(r.version.name)}</text>')
    parts.append(f'<line class="hair" x1="0" x2="0" y1="{T}" y2="{T + ph}" stroke="var(--axis)" stroke-width="1" style="display:none"/>')
    return parts


def _progress_panel(pid: str, name: str, title_html: str, sub: str, runs: list[history.Run],
                    pts: dict[str, history.Point], slot: int, lo: float = 0.0) -> str:
    """One agent's complete evals in order: a dot per eval, a step line for the best so far on each
    version of the scenarios, a dashed break where the scenarios changed. Dots under the line are
    evals that did not beat the best (dropped tweaks, noise)."""
    W, H, L, R, T, B = 340, 166, 38, 44, 24, 26
    pw, ph = W - L - R, H - T - B
    xs = [L + pw * (i + 0.5) / len(runs) for i in range(len(runs))]
    y = lambda rate: T + ph * (1 - (rate - lo) / (1 - lo))
    var = f"--s{slot}" if slot else "--muted"
    multi = len({r.version.number for r in runs}) > 1
    parts = [f'<svg viewBox="0 0 {W} {H}" tabindex="0" role="img" '
             f'aria-label="{E(name)}: success rate of each eval and the best so far; values in the table view">']
    for g in (lo, (lo + 1) / 2, 1):
        parts.append(f'<line x1="{L}" x2="{L + pw}" y1="{y(g):.1f}" y2="{y(g):.1f}" '
                     f'stroke="var({"--axis" if g == 0 else "--grid"})" stroke-width="1"/>'
                     f'<text x="{L - 8}" y="{y(g) + 4:.1f}" text-anchor="end" font-size="12" fill="var(--muted)" '
                     f'style="font-variant-numeric:tabular-nums">{round(g * 100)}%</text>')
    parts += _x_axis(runs, xs, L, T, ph, H, multi)
    segs: list[list[tuple[float, float]]] = [[]]
    for i, r in enumerate(runs):
        if i and r.version is not runs[i - 1].version:
            segs.append([])
        p = pts.get(r.id)
        if p is not None and p.best_so_far is not None:
            segs[-1].append((xs[i], y(p.best_so_far)))
    for seg in segs:
        if len(seg) > 1:
            d = f"M{seg[0][0]:.1f},{seg[0][1]:.1f}" + "".join(f" H{x:.1f} V{yy:.1f}" for x, yy in seg[1:])
            parts.append(f'<path d="{d}" fill="none" stroke="var({var})" stroke-width="2" stroke-linejoin="round"/>')
    for i, r in enumerate(runs):
        p = pts.get(r.id)
        if p is not None:
            parts.append(f'<circle cx="{xs[i]:.1f}" cy="{y(p.rate):.1f}" r="4" fill="var({var})" '
                         'stroke="var(--surface)" stroke-width="2"/>')
    idx = [i for i, r in enumerate(runs) if r.id in pts]
    if idx:  # label the end of the line only
        last = pts[runs[idx[-1]].id]
        parts.append(f'<text x="{xs[idx[-1]] + 9:.1f}" y="{y(last.best_so_far) + 4:.1f}" font-size="12" '
                     f'font-weight="600" fill="var(--ink-2)">{_pct(last.best_so_far)}</text>')
    parts.append("</svg>")
    ps = [pts.get(r.id) for r in runs]
    data = {"w": W, "xs": [round(x, 1) for x in xs],
            "labels": [f"{r.variant}{' · ' + r.label if r.label else ''}{' · ' + r.version.name if multi else ''}" for r in runs],
            "series": [
                {"name": "this eval", "color": var, "points": [
                    None if p is None else {"text": f"{_pct(p.rate)} · {p.stats.get('passed', 0)}/{p.trials} · {p.change_text()}"}
                    for p in ps]},
                {"name": "best so far", "color": var, "points": [
                    None if p is None else {"text": _pct(p.best_so_far)} for p in ps]}]}
    payload = json.dumps(data).replace("</", "<\\/")
    return (f'<div><div class="ptitle"><i class="key k{slot}"></i>{title_html}</div><div class="psub muted">{E(sub)}</div>'
            f'<div class="chart" data-chart="{pid}">{"".join(parts)}</div>'
            f'<script type="application/json" id="{pid}">{payload}</script></div>')


def _cost_panel(pid: str, name: str, title_html: str, sub: str, runs: list[history.Run],
                pts: dict[str, history.Point], slot: int, metric: str) -> str:
    """One agent's tokens or time per trial over its complete evals, on a fitted scale. Filled
    dots reached the agent's top rate on that version of the scenarios, hollow ones did not (their
    cost is not comparable: a failed trial can be short or long). The step line is the fewest so
    far at the top rate, which is what tuning at the top tries to bring down."""
    W, H, L, R, T, B = 340, 166, 46, 44, 24, 26
    pw, ph = W - L - R, H - T - B
    fmt = _fmt(metric)
    head = f'<div class="ptitle"><i class="key k{slot}"></i>{title_html}</div><div class="psub muted">{E(sub)}</div>'
    vals = [p.cost(metric) for p in pts.values() if p.cost(metric) is not None]
    if not vals:
        return (f'<div>{head}<p class="muted" style="font-size:13px">This agent\'s backend reports no '
                f'{"token counts" if metric == "tokens" else "times"}.</p></div>')
    # Fitted to the panel's values: tuning moves cost by 5-20%, which a scale from zero flattens.
    hi, lo = _nice(max(vals) * 1.05), _nice_floor(min(vals) * 0.85)
    if hi - lo < 1e-9:
        lo = 0.0
    xs = [L + pw * (i + 0.5) / len(runs) for i in range(len(runs))]
    y = lambda v: T + ph * (1 - (v - lo) / (hi - lo))
    var = f"--s{slot}" if slot else "--muted"
    multi = len({r.version.number for r in runs}) > 1
    parts = [f'<svg viewBox="0 0 {W} {H}" tabindex="0" role="img" aria-label="{E(name)}: {E(history.METRICS[metric])} '
             f'of each eval and the fewest so far at the top rate; values in the table view">']
    for g in (lo, (lo + hi) / 2, hi):
        parts.append(f'<line x1="{L}" x2="{L + pw}" y1="{y(g):.1f}" y2="{y(g):.1f}" '
                     f'stroke="var({"--axis" if g == 0 else "--grid"})" stroke-width="1"/>'
                     f'<text x="{L - 8}" y="{y(g) + 4:.1f}" text-anchor="end" font-size="12" fill="var(--muted)" '
                     f'style="font-variant-numeric:tabular-nums">{E(_axis_label(round(g, 6), metric))}</text>')
    parts += _x_axis(runs, xs, L, T, ph, H, multi)
    segs: list[list[tuple[float, float]]] = [[]]
    for i, r in enumerate(runs):
        if i and r.version is not runs[i - 1].version:
            segs.append([])
        p = pts.get(r.id)
        if p is not None and metric in p.fewest:
            segs[-1].append((xs[i], y(p.fewest[metric])))
    for seg in segs:
        if len(seg) > 1:
            d = f"M{seg[0][0]:.1f},{seg[0][1]:.1f}" + "".join(f" H{x:.1f} V{yy:.1f}" for x, yy in seg[1:])
            parts.append(f'<path d="{d}" fill="none" stroke="var({var})" stroke-width="2" stroke-linejoin="round"/>')
    for i, r in enumerate(runs):
        p = pts.get(r.id)
        if p is None or p.cost(metric) is None:
            continue
        if p.at_top:
            parts.append(f'<circle cx="{xs[i]:.1f}" cy="{y(p.cost(metric)):.1f}" r="4" fill="var({var})" '
                         'stroke="var(--surface)" stroke-width="2"/>')
        else:
            parts.append(f'<circle cx="{xs[i]:.1f}" cy="{y(p.cost(metric)):.1f}" r="3.5" fill="var(--surface)" '
                         f'stroke="var({var})" stroke-width="2"/>')
    idx = [i for i, r in enumerate(runs) if r.id in pts and metric in pts[r.id].fewest]
    if idx:
        last = pts[runs[idx[-1]].id].fewest[metric]
        parts.append(f'<text x="{xs[idx[-1]] + 9:.1f}" y="{y(last) + 4:.1f}" font-size="12" '
                     f'font-weight="600" fill="var(--ink-2)">{E(_axis_label(round(last, -2) if metric == "tokens" else round(last, 1), metric))}</text>')
    parts.append("</svg>")

    def text(p: history.Point) -> str:
        v = p.cost(metric)
        if v is None:
            return "not reported"
        out = f"{fmt(v)} · {_pct(p.rate)}" + ("" if p.at_top else ", below the top rate")
        c = p.cost_change(metric)
        return out + (f" · {c.text()}{'' if c.real else ' (within noise)'}" if c else "")

    ps = [pts.get(r.id) for r in runs]
    data = {"w": W, "xs": [round(x, 1) for x in xs],
            "labels": [f"{r.variant}{' · ' + r.label if r.label else ''}{' · ' + r.version.name if multi else ''}" for r in runs],
            "series": [
                {"name": "this eval", "color": var, "points": [None if p is None else {"text": text(p)} for p in ps]},
                {"name": "fewest at the top rate", "color": var, "points": [
                    None if p is None else {"text": fmt(p.fewest[metric]) if metric in p.fewest else "none yet"} for p in ps]}]}
    payload = json.dumps(data).replace("</", "<\\/")
    return (f'<div>{head}<div class="chart" data-chart="{pid}">{"".join(parts)}</div>'
            f'<script type="application/json" id="{pid}">{payload}</script></div>')


def _cost_legend(versions: bool, metric: str) -> str:
    what = ("mean of input + output tokens, as each backend counts them" if metric == "tokens"
            else "the median trial's wall time, tool calls included")
    return ('<div class="legend">'
            '<span><svg width="14" height="14" aria-hidden="true"><circle cx="7" cy="7" r="4" fill="var(--ink-2)"/></svg>'
            'eval at the agent\'s top rate</span>'
            '<span><svg width="14" height="14" aria-hidden="true"><circle cx="7" cy="7" r="3.5" fill="none" stroke="var(--ink-2)" '
            'stroke-width="2"/></svg>below it</span>'
            '<span><i class="key" style="background:var(--ink-2)"></i>fewest so far at the top rate</span>'
            + ('<span><svg width="14" height="14" aria-hidden="true"><line x1="7" x2="7" y1="0" y2="14" stroke="var(--muted)" '
               'stroke-width="1.5" stroke-dasharray="3 3"/></svg>scenarios changed</span>' if versions else "")
            + f'<span class="muted">{E(what)}; scale fitted per panel</span></div>')


def _fewest_sub(h: history.History, agent: str, metric: str) -> str:
    v = h.latest_version
    pre = f"{v.name} · " if v and len(h.versions) > 1 else ""
    b = h.best(agent)
    if not b:
        return f"no complete eval on {v.name}" if v else "no complete eval"
    txt = history.fewest_text(h, agent, metric)
    if txt is None:
        return pre + "not reported by this agent"
    n = len(h.at_top(agent))
    return pre + f"at {_pct(b.rate)}: {txt}" + (f" · {n} evals" if n > 1 else "")


def _switch(sid: str, groups: dict[str, str]) -> str:
    """A Measure select over one group per measure. Without JS every group shows, each under its
    own label."""
    opts = "".join(f'<option value="{k}">{E(l)}</option>' for k, l in MEASURES if k in groups)
    return (f'<div class="controls"><label>Measure <select data-switch="{E(sid)}">{opts}</select></label></div>'
            + "".join(f'<div data-switch-group="{E(sid)}" data-value="{k}"><div class="lbl" style="margin:0 0 6px">'
                      f'{E(dict(MEASURES)[k])}</div>{groups[k]}</div>' for k, _ in MEASURES if k in groups))


def _versions_html(h: history.History, prefix: str = "") -> str:
    if len(h.versions) < 2 and not h.stale:
        return ""
    items = "".join(
        f'<li><b>{E(v.name)}</b> from <a href="{E(prefix + "runs/" + v.first_run + "/report.html")}">{E(v.first_run)}</a>'
        + (f': {E("; ".join(v.changes))}' if v.changes else "") + "</li>" for v in h.versions)
    if h.stale:
        items += f'<li>scenarios.yml has changed since the last eval: the next eval starts S{len(h.versions) + 1}.</li>'
    return (f'<div class="versions"><div class="lbl">Scenario versions · scores only compare within one</div>'
            f'<ul>{items}</ul></div>')


def _progress(h: history.History, chart_id: str) -> str:
    if not h.points:
        return '<p class="empty">No evals yet. Run <code>tc eval</code> to see progress here.</p>'
    runs, order = h.complete, h.agents()
    slots = _agent_keys(order)
    partial = len(h.runs) - len(runs)
    notes = (f'<p class="muted" style="font-size:13px">{partial} partial eval(s), which ran only some scenarios or '
             f'prompts, are left out here; they are listed under Evals.</p>' if partial else "")
    head = "".join(f'<th class="num"><i class="key k{slots[a]}"></i>{E(a)}</th>' for a in order)
    rows = []
    for r in runs:
        by = {p.agent: p for p in h.points if p.run is r}
        rows.append(f'<tr><td>{_eval_cell(r, "")}</td><td>{E(r.version.name)}</td>' + "".join(
            (f'<td class="num">{_pct(by[a].rate)} · {_change_html(by[a])}<div class="muted" style="font-size:13px">'
             f'{_cost_html(by[a], "tokens")} tokens · {_cost_html(by[a], "seconds")}</div></td>') if a in by else '<td class="num">–</td>'
            for a in order) + "</tr>")
    table = f'<table><tr><th>Eval</th><th>Scenarios</th>{head}</tr>{"".join(rows)}</table>'
    if len(runs) < 2:
        return (f'<p class="muted" style="margin-top:0">The chart appears once there are two complete evals.</p>'
                f'{table}{_versions_html(h)}{notes}')
    shown = [(a, {p.run.id: p for p in h.points_for(a) if not p.run.partial}) for a in order[:SERIES_CAP]]
    shown = [(a, pts) for a, pts in shown if pts]
    lo = _floor([p.rate for _, pts in shown for p in pts.values()])
    versions = len({r.version.number for r in runs}) > 1
    groups = {"rate": _legend(versions, lo) + '<div class="multiples">' + "".join(
        _progress_panel(f"{chart_id}-{slots[a]}", a, E(a), _first_best(h, a), runs, pts, slots[a], lo)
        for a, pts in shown) + '</div>'}
    for m in history.METRICS:
        groups[m] = _cost_legend(versions, m) + '<div class="multiples">' + "".join(
            _cost_panel(f"{chart_id}-{m}-{slots[a]}", a, E(a), _fewest_sub(h, a, m), runs, pts, slots[a], m)
            for a, pts in shown) + '</div>'
    folded = order[SERIES_CAP:]
    if folded:
        notes += f'<p class="muted" style="font-size:13px">Also evaluated, in the table only: {E(", ".join(folded))}</p>'
    return (_switch(f"{chart_id}-m", groups) + _versions_html(h) + notes
            + f'<details style="margin-top:8px"><summary class="muted">Table view</summary><div style="margin-top:8px">{table}</div></details>')


def _evals_table(points: list[history.Point], slots: dict[str, int], table_id: str, module_prefix: bool,
                 compact: bool = False, calls: bool = True) -> str:
    """Every eval, newest first, one row per agent. With module_prefix the rows carry the module
    (the workspace page) and every row repeats its run, so the filters can hide any of them."""
    # A version tag on the eval only for modules whose scenarios have more than one version.
    seen: dict[str, set[int]] = {}
    for p in points:
        seen.setdefault(p.run.module, set()).add(p.run.version.number)
    multi = {m for m, vs in seen.items() if len(vs) > 1}
    rows, last = [], None
    for p in reversed(points):
        r, a = p.run, p.stats
        first = r is not last or not compact
        last = r
        prefix = f"{r.module}/" if module_prefix else ""
        rows.append(
            f'<tr data-row data-module="{E(r.module)}" data-agent="{E(p.agent)}">'
            + (f'<td class="muted" style="white-space:nowrap">{E(r.started) if first else ""}</td>')
            + (f'<td><a href="{E(r.module)}/index.html">{E(r.module)}</a></td>' if module_prefix else "")
            + f'<td>{(_eval_cell(r, prefix) + (f"<span class=tag>{E(r.version.name)}</span>" if r.module in multi else "")) if first else ""}</td>'
            f'<td class="agent"><i class="key k{slots.get(p.agent, 0)}"></i>{E(p.agent)}</td>'
            f'<td class="num"><b>{_pct(p.rate)}</b> <span class="muted">{a.get("passed", 0)}/{a.get("trials", 0)}</span></td>'
            f'<td>{_change_html(p)}</td><td class="num">{_pct(a.get("train_rate"))} · {_pct(a.get("holdout_rate"))}</td>'
            f'<td class="num">{_cost_html(p, "tokens")}</td><td class="num">{_cost_html(p, "seconds")}</td>'
            + (f'<td class="num">{a.get("mean_tool_calls", "–")} · {_pct(a.get("failed_call_rate"))}</td>'
               if calls else "") + '</tr>')
    head = ('<tr><th>Started (UTC)</th>' + ('<th>Module</th>' if module_prefix else "") + '<th>Eval</th><th>Agent</th>'
            + '<th class="num">Rate</th><th>Change</th><th class="num">Train · holdout</th>'
            '<th class="num">Tokens / trial</th><th class="num">Time / trial</th>'
            + ('<th class="num">Calls / trial · failed</th>' if calls else "") + '</tr>')
    return f'<table id="{E(table_id)}">{head}{"".join(rows)}</table>'


EVALS_CAPTION = ('<p class="muted" style="font-size:13px">Change: against the previous complete eval of the same agent '
                 'on the same version of the scenarios (S1, S2: tagged on the eval when a module has more than one); '
                 'within noise means one trial or less, which proves nothing. '
                 'Tokens (mean) and time (median trial) per trial: a coloured arrow is a change beyond noise (tested per '
                 'prompt), grey is within noise. Lower is better, and it counts most at the agent\'s top rate, where the rate cannot rise. '
                 'Tokens are as each backend counts them, so compare them within one agent.</p>')


def _scenario_matrix(h: history.History, mid: str) -> str:
    """Per agent: rows are scenarios, columns are evals in order, cells are passed/trials. Shows which
    scenario moved when the overall rate moved, and that unchanged scenarios held up when the
    scenarios changed."""
    if not h.runs:
        return '<p class="empty">No evals yet.</p>'
    ids: list[str] = []
    for v in ([h.latest_version] if h.latest_version else []) + h.versions:
        for s in v.doc.get("scenarios") or []:
            if str(s.get("id")) not in ids:
                ids.append(str(s.get("id")))
    counts = {r.id: r.scenario_counts() for r in h.runs}
    order = h.agents()
    slots = _agent_keys(order)
    groups = []
    for a in order:
        runs = [r for r in h.runs if a in r.summary.get("agents", {})]
        vb = lambda i: ' vb' if i and runs[i].version is not runs[i - 1].version else ""
        head = "".join(
            f'<th class="num{vb(i)}"><a href="{E("runs/" + r.dir.name + "/report.html")}" title="{E(r.id)}">{E(r.variant)}</a>'
            f'<div class="muted" style="font-weight:400">{E(r.label or r.version.name)}</div>'
            + ('<span class="tag">partial</span>' if r.partial else "") + '</th>' for i, r in enumerate(runs))
        body = []
        for sid in ids:
            cells = []
            for i, r in enumerate(runs):
                c = counts[r.id].get(a, {}).get(sid)
                if not c:
                    cells.append(f'<td class="na{vb(i)}">·</td>')
                    continue
                cost = (f'&#10;{E(history.fmt_tokens(c[2] / c[4]))} tokens · {E(history.fmt_secs(statistics.median(c[3])))} per trial'
                        if c[4] else "")
                cells.append(f'<td class="cell {_heat_top(c[0] / c[1])}{vb(i)}" tabindex="0" '
                             f'data-tip-title="{E(sid)}" data-tip="{E(f"{c[0]}/{c[1]} passed · {r.variant} {r.label}".strip())}'
                             f'{cost}&#10;{E(r.version.name)} · {E(r.id)}">{c[0]}/{c[1]}</td>')
            body.append(f'<tr><td>{E(sid)}</td>{"".join(cells)}</tr>')
        groups.append(f'<div data-switch-group="{E(mid)}" data-value="{E(a)}"><h3><i class="key k{slots[a]}"></i>{E(a)}</h3>'
                      f'<div style="overflow-x:auto"><table class="heat compact"><tr><th>Scenario</th>{head}</tr>{"".join(body)}</table></div></div>')
    pick = ""
    if len(order) > 1:
        opts = "".join(f'<option value="{E(a)}">{E(a)}</option>' for a in order)
        pick = f'<div class="controls"><label>Agent <select data-switch="{E(mid)}">{opts}</select></label></div>'
    return (pick + "".join(groups) + '<p class="muted" style="font-size:13px;margin-bottom:0">Cells: trials passed '
            'of trials run, train and holdout together. Darkest: every trial passed; then 90% or more, 75%, 50%, some, none. A dashed edge marks a new version of the scenarios; '
            '· means the scenario was not run.</p>')


# --- module dashboard -----------------------------------------------------------------------------

def write_module(module: str) -> Path | None:
    from . import scenarios, workspace
    d = config.module_dir(module)
    if not (d / "module.yml").is_file():
        return None
    mod = workspace.load_module(module)
    variants = workspace.list_variants(module)
    h = history.load(module)
    for r in h.runs:
        if not (r.dir / "report.html").is_file():
            write_run(r.dir)
    order = h.agents()
    slots = _agent_keys(order)

    best = h.best()
    v = h.latest_version
    multi = len(h.versions) > 1
    try:
        suite, suite_err = scenarios.load(module), None
    except (SystemExit, Exception) as e:  # missing or invalid scenarios.yml
        suite, suite_err = None, str(e)
    n_prompts = sum(len(s.prompts) for s in suite.scenarios) if suite else 0
    tiles = [
        ("Best result" + (f" on {v.name}" if multi else ""), _pct(best.rate) if best else "–",
         f"{best.run.variant} · {best.agent}" if best else "no complete evals yet"),
        ("Tokens per trial", history.fmt_tokens(best.tokens) if best else "–",
         f"{history.fmt_secs(best.seconds)} per trial · best eval, {best.run.variant}" if best else "no complete evals yet"),
        ("Variants", str(len(variants)), f"latest {variants[-1]['id']}" if variants else "none saved"),
        ("Evals", str(len(h.runs)), h.runs[-1].id if h.runs else "none"),
        ("Scenarios", str(len(suite.scenarios)) if suite else "–",
         (f"{n_prompts} prompts" + (f" · version {h.current.name}" if h.current and multi else "")
          + (" · changed since the last eval" if h.stale else "")) if suite else "scenarios.yml missing or invalid"),
    ]
    tiles_html = '<div class="tiles">' + "".join(
        f'<div class="tile"><div class="label">{E(l)}</div><div class="value">{E(val)}</div><div class="note">{E(n)}</div></div>'
        for l, val, n in tiles) + "</div>"

    runs_html = (f'<div class="card">{_evals_table(h.points, slots, "evals-" + module, False, compact=True)}</div>'
                 + EVALS_CAPTION if h.points else '<p class="empty">No evals yet.</p>')

    st = workspace.site_state()
    var_best: dict[str, history.Point] = {}
    for p in h.points:
        if not p.run.partial and (p.run.variant not in var_best or history._sort_key(p) > history._sort_key(var_best[p.run.variant])):
            var_best[p.run.variant] = p
    def _vb(vid: str) -> str:
        p = var_best.get(vid)
        if not p:
            return "–"
        return f'{_pct(p.rate)} · {E(p.agent)}' + (f'<span class="tag">{E(p.run.version.name)}</span>' if multi else "")
    var_rows = "".join(
        f'<tr><td><code>{E(x["id"])}</code>{"<span class=tag>installed</span>" if st.get("module") == module and st.get("variant") == x["id"] else ""}</td>'
        f'<td>{E(x.get("parent") or "–")}</td><td>{E(x.get("note") or "")}</td><td class="muted">{E(x.get("created_at", ""))}</td>'
        f'<td class="num">{_vb(x["id"])}</td></tr>'
        for x in reversed(variants))
    variants_html = ('<div class="card"><table><tr><th>Variant</th><th>Parent</th><th>Note</th><th>Saved</th>'
                     '<th class="num">Best result</th></tr>' + var_rows + "</table></div>") if variants else \
        '<p class="empty">No variants saved yet (step 2).</p>'

    if suite:
        sc_html = []
        for sc in suite.scenarios:
            ps = "".join(f'<li>{E(p.text)}{"<span class=tag>holdout</span>" if p.holdout else ""} '
                         f'<span class="muted">({E(p.id)})</span></li>' for p in sc.prompts)
            checks = "".join(f'<div class="lbl">{E(c["name"])}</div><pre>{E(c["php"])}</pre>' for c in sc.checks)
            if sc.answer_matches:
                checks += f'<div class="lbl">Answer must match</div><pre>{E(sc.answer_matches)}</pre>'
            setup = "".join(f'<pre>{E(_json(s))}</pre>' for s in sc.setup)
            sc_html.append(f'<div class="card" style="margin:10px 0"><b>{E(sc.id)}</b><span class="tag">as {E(sc.run_as)}</span>'
                           f'<p style="margin:6px 0">{E(sc.description)}</p><ul>{ps}</ul>'
                           f'<details><summary class="muted">Setup and checks</summary>'
                           f'{"<div class=lbl>Setup</div>" + setup if setup else ""}{checks}</details></div>')
        scenarios_html = "".join(sc_html)
    else:
        scenarios_html = f'<p class="empty">{E(suite_err or "")}</p>'

    catalog = []
    if h.runs:
        catalog = _read_json(h.runs[-1].dir / "catalog.json", [])
    elif st.get("module") == module and st.get("catalog"):
        catalog = _read_json(Path(st["catalog"]), [])

    docs = []
    for name in ("REPORT.md", "ITERATIONS.md", "NOTES.md"):
        f = d / name
        if f.is_file() and f.read_text().strip():
            docs.append(f'<h2>{E(name)}</h2><div class="card md">{_md(f.read_text())}</div>')

    body = (f'<h1>{E(module)}</h1><p class="sub">Tools in <code>{E(mod["tool_module"])}</code> · '
            f'composer <code>{E(str(mod.get("composer")))}</code> · exposed providers '
            f'{E(", ".join(mod["expose"].get("providers") or []))}</p>{tiles_html}'
            + (docs.pop(0) if docs and docs[0].startswith("<h2>REPORT.md") else "")
            + f'<h2>Progress over evals, per agent</h2><div class="card">{_progress(h, "chart-" + module)}</div>'
            f'<h2>Evals</h2>{runs_html}'
            f'<h2>By scenario over evals</h2><div class="card">{_scenario_matrix(h, "sm-" + module)}</div>'
            f'<h2>Variants</h2>{variants_html}'
            f'<h2>Scenarios</h2>{scenarios_html}'
            f'<h2>Current tools</h2>{_catalog(catalog) if catalog else "<p class=empty>Install or eval a variant to see its tools.</p>"}'
            + "".join(docs))
    out = d / "index.html"
    out.write_text(_page(f"Tool creator: {module}", [("Workspace", "../index.html"), (module, None)], body))
    return out


# --- workspace overview ---------------------------------------------------------------------------

def write_root() -> Path:
    """The global report: every module's progress, every eval of every module, the learnings."""
    hs = [history.load(m) for m in history.modules()]
    gorder = history.agent_order()
    gslots = _agent_keys(gorder)
    points = sorted((p for h in hs for p in h.points), key=lambda p: (p.run.meta.get("started_at") or "", p.run.id))

    reached = [t for h in hs for r in h.runs for t in r.results if not t.get("setup_failed")]
    tot_tok = sum(history._trial_cost(t, "tokens") for t in reached)
    tot_secs = sum(history._trial_cost(t, "seconds") for t in reached)
    tiles = [("Modules", str(len(hs)), ", ".join(h.module for h in hs) or "none yet"),
             ("Evals", str(sum(len(h.runs) for h in hs)), points[-1].run.id if points else "none yet"),
             ("Trials run", f"{sum(p.trials for p in points):,}",
              f"{history.fmt_tokens(tot_tok)} tokens · {history.fmt_secs(tot_secs)} of agent time"),
             ("Agents", str(len(gorder)), gorder[0] if gorder else "none yet")]
    tiles_html = '<div class="tiles">' + "".join(
        f'<div class="tile"><div class="label">{E(l)}</div><div class="value">{E(v)}</div><div class="note">{E(n)}</div></div>'
        for l, v, n in tiles) + "</div>"

    main = []
    for h in hs:
        agent = h.primary()
        if agent:
            pts = {p.run.id: p for p in h.points_for(agent) if not p.run.partial}
            main.append((h, agent, pts, [r for r in h.complete if r.id in pts]))
    lo = _floor([p.rate for _, _, pts, runs in main if len(runs) > 1 for p in pts.values()])
    panels: dict[str, list[str]] = {k: [] for k, _ in MEASURES}
    versions_shown = False
    for h, agent, pts, runs in main:
        title = f'<a href="{E(h.module)}/index.html">{E(h.module)}</a><span class="muted" style="font-weight:400"> · {E(agent)}</span>'
        slot = gslots.get(agent, 0)
        if len(runs) < 2:
            p = pts[runs[0].id] if runs else None
            so_far = {"rate": _pct(p.rate) if p else "", "tokens": history.fmt_tokens(p.tokens) if p else "",
                      "seconds": history.fmt_secs(p.seconds) if p else ""}
            for k, _ in MEASURES:
                panels[k].append(f'<div><div class="ptitle"><i class="key k{slot}"></i>{title}</div>'
                                 f'<div class="psub muted">{E(_first_best(h, agent) if k == "rate" else _fewest_sub(h, agent, k))}</div>'
                                 f'<p class="muted" style="font-size:13px">The chart appears after a second complete eval'
                                 f'{" (" + E(so_far[k]) + " at " + E(p.run.variant) + " so far)" if p else ""}.</p></div>')
            continue
        versions_shown |= len({r.version.number for r in runs}) > 1
        panels["rate"].append(_progress_panel(f"prog-{h.module}", f"{h.module} · {agent}", title, _first_best(h, agent),
                                              runs, pts, slot, lo))
        for m in history.METRICS:
            panels[m].append(_cost_panel(f"prog-{h.module}-{m}", f"{h.module} · {agent}", title, _fewest_sub(h, agent, m),
                                         runs, pts, slot, m))
    groups = {"rate": _legend(versions_shown, lo) + f'<div class="multiples">{"".join(panels["rate"])}</div>'}
    for m in history.METRICS:
        groups[m] = _cost_legend(versions_shown, m) + f'<div class="multiples">{"".join(panels[m])}</div>'
    progress = (_switch("prog-m", groups)
                + '<p class="muted" style="font-size:13px;margin-bottom:0">Each module with its main agent (the default agent if it '
                'was evaluated). Every agent, and what changed in the scenarios, is on the module page.</p>') if main else \
        '<p class="empty">No evals yet.</p>'

    rows = []
    for h in hs:
        d = config.module_dir(h.module)
        n_var = len(list((d / "variants").glob("v*"))) if (d / "variants").is_dir() else 0
        step = ("5 · finalized" if (d / "REPORT.md").is_file() else "4 · reiterating" if len(h.runs) > 1 else
                "3 · first eval" if h.runs else "2 · generate" if (d / "scenarios.yml").is_file() and not n_var else
                "3 · simulate" if n_var else "1 · scenarios")
        b = h.best()
        v = h.latest_version
        rows.append(f'<tr><td><a href="{E(h.module)}/index.html"><b>{E(h.module)}</b></a></td><td>{E(step)}</td>'
                    f'<td class="num">{n_var}</td><td class="num">{len(h.runs)}</td>'
                    f'<td>{E(v.name) if v else "–"}{" <span class=tag>changed since</span>" if h.stale else ""}</td>'
                    f'<td>{(_pct(b.rate) + " · " + E(b.run.variant) + " · " + E(b.agent)) if b else "–"}</td>'
                    f'<td class="num">{E(history.fmt_tokens(b.tokens)) if b else "–"}</td>'
                    f'<td class="num">{E(history.fmt_secs(b.seconds)) if b else "–"}</td>'
                    f'<td class="muted">{E(h.runs[-1].started) if h.runs else "–"}</td></tr>')
    modules_html = ('<div class="card"><table><tr><th>Module</th><th>Step</th><th class="num">Variants</th><th class="num">Evals</th>'
                    '<th>Scenarios</th><th>Best on current scenarios</th><th class="num">Tokens / trial</th>'
                    '<th class="num">Time / trial</th><th>Last eval</th></tr>' + "".join(rows) + "</table>"
                    '<p class="muted" style="font-size:13px;margin:10px 0 0">Best: the highest rate, then the better holdout, '
                    'then the fewest tokens; tokens and time are that eval\'s, per trial.</p></div>') \
        if rows else ('<p class="empty">No modules yet. Start with <code>tc module prepare &lt;module&gt;</code> or '
                      '<code>/tool-creator &lt;module&gt;</code>.</p>')

    evals_html = '<p class="empty">No evals yet.</p>'
    if points:
        sel = lambda key, label, vals: (f'<label>{label} <select data-filter-table="evals-all" data-key="{key}">'
                                        f'<option value="">All</option>' + "".join(f'<option value="{E(x)}">{E(x)}</option>' for x in vals)
                                        + '</select></label>')
        evals_html = (f'<div class="controls">{sel("module", "Module", [h.module for h in hs if h.runs])}'
                      f'{sel("agent", "Agent", gorder)}</div>'
                      f'<div class="card">{_evals_table(points, gslots, "evals-all", True, calls=False)}</div>'
                      + EVALS_CAPTION)

    learn = config.MEMORY / "LEARNINGS.md"
    learn_html = f'<h2>Learnings across modules</h2><div class="card md">{_md(learn.read_text())}</div>' \
        if learn.is_file() else ""
    body = ('<h1>Drupal tool creator</h1><p class="sub">Tool API plugins per module, scored by how often agents '
            'succeed with them. Every eval of every module, newest last on the charts and first in the table.</p>'
            + tiles_html + '<h2>Progress by module</h2><div class="card">' + progress + '</div>'
            + '<h2>Modules</h2>' + modules_html + '<h2>All evals</h2>' + evals_html + learn_html)
    out = config.WORKSPACE / "index.html"
    config.WORKSPACE.mkdir(parents=True, exist_ok=True)
    out.write_text(_page("Drupal tool creator", [("Workspace", None)], body))
    return out


def write_all(module: str | None = None, runs: bool = False) -> list[Path]:
    """Regenerate the module page(s) and the workspace page; with runs=True every run report too."""
    written = []
    for m in [module] if module else history.modules():
        if runs:
            for rd in sorted((config.module_dir(m) / "runs").glob("*/")):
                p = write_run(rd)
                if p:
                    written.append(p)
        p = write_module(m)
        if p:
            written.append(p)
    written.append(write_root())
    return written
