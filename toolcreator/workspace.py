"""Per-module workspace: module.yml, variants, the module baseline, and what the site holds now.

    workspace/<module>/
      module.yml          how to install the module, the tool module's name, which tools to expose
      scenarios.yml       what the tools must let an agent do (step 1)
      NOTES.md            what was learned about this module, kept across sessions
      variants/vNN/       one directory per saved version of the tool module, plus meta.json
      runs/<run-id>/      one directory per eval
      autosave/<ts>/      tool-module code found in the site that no variant held, saved before a reset
"""
from __future__ import annotations

import datetime as dt
import hashlib
import json
import shutil
from pathlib import Path

import yaml

from . import config, site

STATE_FILE = config.ROOT / "logs" / "site-state.json"


def now() -> str:
    return dt.datetime.now(dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def stamp() -> str:
    return dt.datetime.now().strftime("%Y%m%d-%H%M%S")


# --- module.yml ---------------------------------------------------------------------------------

def load_module(module: str) -> dict:
    f = config.module_dir(module) / "module.yml"
    if not f.is_file():
        raise SystemExit(f"workspace/{module}/module.yml not found. Run: tc module prepare {module}")
    data = yaml.safe_load(f.read_text()) or {}
    data.setdefault("module", module)
    data.setdefault("tool_module", f"{module}_tools")
    data.setdefault("enable", [module])
    exp = data.setdefault("expose", {})
    exp.setdefault("providers", [data["tool_module"]])
    exp.setdefault("ids", [])
    return data


def save_module(module: str, data: dict) -> None:
    d = config.module_dir(module)
    d.mkdir(parents=True, exist_ok=True)
    header = (
        "# How the tool creator installs this module and which tools it shows the agent.\n"
        "# composer: the package to require (null = already in the site, e.g. a core module)\n"
        "# enable:   modules enabled in the module baseline\n"
        "# tool_module: the companion module the generated tools live in (workspace/<m>/variants/)\n"
        "# expose:   tools the agent sees in an eval: every tool from these providers, plus these ids;\n"
        "#           optional order: ids shown first, in that order (the rest follow, sorted by id)\n"
    )
    (d / "module.yml").write_text(header + yaml.safe_dump(data, sort_keys=False))


# --- site state -----------------------------------------------------------------------------------

def site_state() -> dict:
    try:
        return json.loads(STATE_FILE.read_text())
    except (OSError, json.JSONDecodeError):
        return {}


def set_site_state(**state) -> None:
    STATE_FILE.parent.mkdir(parents=True, exist_ok=True)
    STATE_FILE.write_text(json.dumps({**state, "at": now()}, indent=2))


# --- variants -------------------------------------------------------------------------------------

def tree_hash(path: Path) -> str:
    h = hashlib.sha256()
    for f in sorted(p for p in path.rglob("*") if p.is_file() and ".git" not in p.parts):
        h.update(str(f.relative_to(path)).encode())
        h.update(b"\0")
        h.update(f.read_bytes())
    return h.hexdigest()[:16]


def variants_dir(module: str) -> Path:
    return config.module_dir(module) / "variants"


def list_variants(module: str) -> list[dict]:
    out = []
    for d in sorted(variants_dir(module).glob("v*")):
        meta_f = d / "meta.json"
        meta = json.loads(meta_f.read_text()) if meta_f.is_file() else {"id": d.name}
        out.append(meta)
    return out


def variant_path(module: str, variant: str) -> Path:
    mod = load_module(module)
    if variant in ("latest", "last"):
        vs = list_variants(module)
        if not vs:
            raise SystemExit(f"no variants saved for {module} yet")
        variant = vs[-1]["id"]
    p = variants_dir(module) / variant / mod["tool_module"]
    if not p.is_dir():
        raise SystemExit(f"variant {variant} not found at {p}")
    return p


def resolve_variant(module: str, variant: str) -> str:
    return variant_path(module, variant).parent.name


def site_tool_module(module: str) -> Path:
    return config.SITE_DIR / "web" / "modules" / "custom" / load_module(module)["tool_module"]


def save_variant(module: str, note: str = "", parent: str | None = None) -> str:
    src = site_tool_module(module)
    if not src.is_dir():
        raise SystemExit(f"nothing to save: {src} does not exist")
    h = tree_hash(src)
    for v in list_variants(module):
        if v.get("hash") == h:
            print(f"identical to existing variant {v['id']}; not saved again")
            return v["id"]
    existing = [int(v["id"][1:]) for v in list_variants(module) if v["id"][1:].isdigit()]
    vid = f"v{(max(existing) + 1) if existing else 1:02d}"
    state = site_state()
    if parent is None and state.get("module") == module:
        parent = state.get("variant")
    dst = variants_dir(module) / vid
    site.copy_tree(src, dst / src.name)
    meta = {"id": vid, "parent": parent, "note": note, "created_at": now(), "hash": h}
    (dst / "meta.json").write_text(json.dumps(meta, indent=2))
    print(f"saved {module} variant {vid}" + (f" (parent {parent})" if parent else ""))
    return vid


def autosave(module: str | None = None) -> None:
    """Before a reset wipes web/modules/custom, keep any tool-module code no variant holds."""
    custom = config.SITE_DIR / "web" / "modules" / "custom"
    if not custom.is_dir():
        return
    modules = [module] if module else [p.name for p in config.WORKSPACE.iterdir() if (p / "module.yml").is_file()] \
        if config.WORKSPACE.is_dir() else []
    for m in modules:
        try:
            tm = load_module(m)["tool_module"]
        except SystemExit:
            continue
        src = custom / tm
        if not src.is_dir():
            continue
        h = tree_hash(src)
        if any(v.get("hash") == h for v in list_variants(m)):
            continue
        dst = config.module_dir(m) / "autosave" / stamp() / tm
        site.copy_tree(src, dst)
        print(f"[autosave] unsaved {tm} code found in the site; kept at {dst.relative_to(config.ROOT)}")
