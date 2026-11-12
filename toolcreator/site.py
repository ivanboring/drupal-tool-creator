"""Talking to the Drupal site from inside the web container: drush, PHP checks, DB snapshots, resets."""
from __future__ import annotations

import gzip
import json
import os
import shutil
import subprocess
from pathlib import Path

from . import config


class SiteError(RuntimeError):
    pass


def require_running() -> None:
    if not config.IN_CONTAINER:
        raise SiteError("tc runs inside the ddev web container: use `ddev tc ...` from the host")
    if not config.DRUSH.is_file():
        raise SiteError("no Drupal site in drupal/ yet: run `ddev setup` on the host")


def running() -> bool:
    return config.IN_CONTAINER and config.DRUSH.is_file()


def drush(*args: str, input: str | bytes | None = None, check: bool = True, timeout: int = 300,
          text: bool = True) -> subprocess.CompletedProcess:
    r = subprocess.run([str(config.DRUSH), *args], cwd=config.SITE_DIR, input=input,
                       capture_output=True, text=text, timeout=timeout)
    if check and r.returncode != 0:
        err = r.stderr if text else r.stderr.decode(errors="replace")
        raise SiteError(f"drush {' '.join(args)[:200]} failed ({r.returncode}):\n{err.strip()[-2000:]}")
    return r


def drush_json(*args: str) -> object:
    r = drush(*args)
    try:
        return json.loads(r.stdout or "null")
    except json.JSONDecodeError as e:
        raise SiteError(f"drush {' '.join(args)} did not return JSON: {r.stdout[:500]}") from e


def php(code: str, timeout: int = 120) -> tuple[bool, object, str]:
    """Run a PHP snippet that `return`s a value. Returns (ok, value, error).

    The snippet is the body of a closure, so it must `return` its result; `ok` is its truthiness.
    The code goes through a temp file, never the command line, so quoting cannot break it.
    """
    wrapped = (
        "<?php\n$__tc = (function () {\n" + code + "\n})();\n"
        "echo \"\\n__TC_RESULT__\" . json_encode(['ok' => (bool) $__tc, 'value' => $__tc], "
        "JSON_PARTIAL_OUTPUT_ON_ERROR);\n"
    )
    tmp = config.ROOT / "logs" / f".tc-php-{os.getpid()}.php"
    tmp.parent.mkdir(parents=True, exist_ok=True)
    tmp.write_text(wrapped)
    try:
        r = drush("php:script", str(tmp), check=False, timeout=timeout)
    finally:
        tmp.unlink(missing_ok=True)
    out = r.stdout
    if "__TC_RESULT__" not in out:
        return False, None, (r.stderr.strip() or out.strip() or f"exit {r.returncode}")[-2000:]
    payload = json.loads(out.rsplit("__TC_RESULT__", 1)[1].strip() or "{}")
    return bool(payload.get("ok")), payload.get("value"), ""


def db_dump(path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    r = drush("sql:dump", "--extra-dump=--no-tablespaces", text=False, timeout=600)
    with gzip.open(path, "wb", compresslevel=1) as f:
        f.write(r.stdout)


def db_restore(path: Path) -> None:
    """Drop every table and load a dump: a couple of seconds for a standard site."""
    drush("sql:drop", "-y")
    with gzip.open(path, "rb") as f:
        data = f.read()
    # Straight into the mysql client: drush sql:cli warns (and is slower) with large stdin.
    r = subprocess.run(["bash", "-c", _sql_connect()], input=data, capture_output=True, timeout=600)
    if r.returncode != 0:
        raise SiteError(f"database import failed: {r.stderr.decode(errors='replace')[-1000:]}")
    drush("cache:rebuild")


_CONNECT: str | None = None


def _sql_connect() -> str:
    global _CONNECT
    if _CONNECT is None:
        _CONNECT = drush("sql:connect").stdout.strip()
    return _CONNECT


def baseline(action: str, directory: Path, quiet: bool = True) -> None:
    env = {**os.environ, "TC_QUIET": "1" if quiet else "0"}
    r = subprocess.run(["bash", str(config.ROOT / "site" / "baseline.sh"), action, str(directory)], env=env)
    if r.returncode != 0:
        raise SiteError(f"baseline {action} {directory} failed (see logs/site.log)")


def composer(*args: str) -> None:
    r = subprocess.run(["composer", *args, "--no-interaction"], cwd=config.SITE_DIR)
    if r.returncode != 0:
        raise SiteError(f"composer {' '.join(args)} failed")


def copy_tree(src: Path, dst: Path) -> None:
    if dst.exists():
        shutil.rmtree(dst)
    shutil.copytree(src, dst, ignore=shutil.ignore_patterns(".git", "__pycache__", "*.orig", "*.rej"))
