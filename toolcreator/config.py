"""Paths and settings shared by every command."""
from __future__ import annotations

import os
import re
from pathlib import Path

ROOT = Path(os.environ.get("TC_ROOT") or Path(__file__).resolve().parent.parent)
SITE_HOST = os.environ.get("DDEV_HOSTNAME", "drupal-tool-creator.localhost").split(",")[0]
SITE_DIR = ROOT / "drupal"
DRUSH = SITE_DIR / "vendor" / "bin" / "drush"
IN_CONTAINER = os.environ.get("IS_DDEV_PROJECT") == "true"
BASELINES = ROOT / "baselines"
SITE_BASELINE = BASELINES / "site"
WORKSPACE = ROOT / "workspace"
MEMORY = ROOT / "memory"

MODULE_RE = re.compile(r"^[a-z][a-z0-9_]*$")


def _dotenv() -> dict[str, str]:
    """Values from the repo .env; the real environment wins over them."""
    out: dict[str, str] = {}
    f = ROOT / ".env"
    if f.is_file():
        for line in f.read_text().splitlines():
            line = line.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            k, v = line.split("=", 1)
            out[k.strip()] = v.strip().strip('"').strip("'")
    return out


_ENV = {**_dotenv(), **os.environ}


def env(key: str, default: str = "") -> str:
    return _ENV.get(key, default) or default


def endpoints() -> dict[str, dict[str, str]]:
    """OpenAI-compatible endpoints declared as TC_ENDPOINT_<NAME>_URL / _KEY."""
    eps: dict[str, dict[str, str]] = {}
    for k, v in _ENV.items():
        m = re.match(r"^TC_ENDPOINT_([A-Z0-9_]+)_URL$", k)
        if m and v:
            name = m.group(1).lower()
            eps[name] = {"url": v.rstrip("/"), "key": _ENV.get(f"TC_ENDPOINT_{m.group(1)}_KEY", "") or "none"}
    return eps


def module_dir(module: str) -> Path:
    if not MODULE_RE.match(module):
        raise SystemExit(f"not a module machine name: {module!r}")
    return WORKSPACE / module


def module_baseline(module: str) -> Path:
    return BASELINES / "modules" / module
