"""Loading and validating workspace/<module>/scenarios.yml.

Format (see .agents/skills/tc-scenarios/SKILL.md for the full guide):

    agent:
      system_prompt: ...        # optional, replaces the default agent system prompt
      max_turns: 15             # optional
    users:                      # created on the site when a variant is installed
      editor:
        permissions: [administer redirects]
        roles: []               # existing roles to add as well
    scenarios:
      - id: add-redirect
        description: Create a 301 redirect from an old path.
        run_as: editor          # admin (uid 1), anonymous (uid 0) or a key under users:
        setup:                  # run before every trial, after the database is restored
          - php: "... return TRUE;"
          - drush: [config:set, system.site, name, Example, -y]
        prompts:
          - "Redirect /old-page to /node/1"
          - {id: terse, text: "old-page -> node 1, 301"}
          - {id: indirect, text: "...", holdout: true}
        checks:                 # every check must return truthy for the trial to pass
          - name: redirect exists
            php: "return ...;"
        answer_matches: "(?i)old-page"   # optional regex the final answer must match
        followups: ["Yes, go ahead."]    # optional scripted replies if the agent stops early (fc only)
"""
from __future__ import annotations

import re
from dataclasses import dataclass, field

import yaml

from . import config

DEFAULT_SYSTEM_PROMPT = """\
You are an assistant that works on a Drupal website on behalf of the current user.
You can only act on the site through the tools you have been given; you have no other access.
Use the tools to carry out the user's request. Look things up with the tools rather than guessing
ids or machine names. Do not ask the user to confirm a request that is already clear; just do it.
When you are done, reply with a short summary of what you did, or with the answer to the question."""


@dataclass
class Prompt:
    id: str
    text: str
    holdout: bool = False


@dataclass
class Scenario:
    id: str
    description: str
    run_as: str
    prompts: list[Prompt]
    checks: list[dict]
    setup: list[dict] = field(default_factory=list)
    answer_matches: str | None = None
    followups: list[str] = field(default_factory=list)


@dataclass
class Suite:
    module: str
    system_prompt: str
    max_turns: int
    users: dict[str, dict]
    scenarios: list[Scenario]


class ScenarioError(ValueError):
    pass


def load(module: str) -> Suite:
    f = config.module_dir(module) / "scenarios.yml"
    if not f.is_file():
        raise SystemExit(f"workspace/{module}/scenarios.yml not found (step 1: the tc-scenarios skill)")
    try:
        data = yaml.safe_load(f.read_text()) or {}
    except yaml.YAMLError as e:
        raise ScenarioError(f"scenarios.yml is not valid YAML: {e}") from e
    return parse(module, data)


def parse(module: str, data: dict) -> Suite:
    errors: list[str] = []
    agent = data.get("agent") or {}
    users = data.get("users") or {}
    for key, u in users.items():
        if key in ("admin", "anonymous"):
            errors.append(f"users.{key}: reserved name")
        if not re.match(r"^[a-z][a-z0-9_]*$", str(key)):
            errors.append(f"users.{key}: use a lowercase machine name")
        if u is not None and not isinstance(u, dict):
            errors.append(f"users.{key}: must be a mapping with permissions/roles")
    scenarios: list[Scenario] = []
    seen: set[str] = set()
    for i, s in enumerate(data.get("scenarios") or []):
        where = f"scenarios[{i}]"
        sid = str(s.get("id") or "")
        if not re.match(r"^[a-z0-9][a-z0-9_-]*$", sid):
            errors.append(f"{where}: id must be lowercase letters, digits, - or _")
        if sid in seen:
            errors.append(f"{where}: duplicate id {sid}")
        seen.add(sid)
        run_as = str(s.get("run_as") or "admin")
        if run_as not in ("admin", "anonymous") and run_as not in users:
            errors.append(f"{sid}: run_as '{run_as}' is not admin, anonymous, or a key under users:")
        prompts: list[Prompt] = []
        for j, p in enumerate(s.get("prompts") or []):
            if isinstance(p, str):
                prompts.append(Prompt(id=f"p{j + 1}", text=p))
            elif isinstance(p, dict) and p.get("text"):
                prompts.append(Prompt(id=str(p.get("id") or f"p{j + 1}"), text=str(p["text"]),
                                      holdout=bool(p.get("holdout"))))
            else:
                errors.append(f"{sid}: prompts[{j}] needs text")
        if not prompts:
            errors.append(f"{sid}: at least one prompt is required")
        if len({p.id for p in prompts}) != len(prompts):
            errors.append(f"{sid}: prompt ids must be unique")
        checks = s.get("checks") or []
        if not checks and not s.get("answer_matches"):
            errors.append(f"{sid}: needs checks and/or answer_matches, or nothing can fail")
        for k, c in enumerate(checks):
            if not isinstance(c, dict) or not c.get("php"):
                errors.append(f"{sid}: checks[{k}] needs a php: snippet that returns truthy on success")
            elif "return" not in c["php"]:
                errors.append(f"{sid}: checks[{k}] php must `return` its result")
        for k, st in enumerate(s.get("setup") or []):
            if not isinstance(st, dict) or not (("php" in st) ^ ("drush" in st)):
                errors.append(f"{sid}: setup[{k}] must be exactly one of php: or drush:")
        if s.get("answer_matches"):
            try:
                re.compile(s["answer_matches"])
            except re.error as e:
                errors.append(f"{sid}: answer_matches is not a valid regex: {e}")
        scenarios.append(Scenario(
            id=sid, description=str(s.get("description") or ""), run_as=run_as, prompts=prompts,
            checks=[{"name": c.get("name") or f"check {k + 1}", "php": c.get("php", "")}
                    for k, c in enumerate(checks) if isinstance(c, dict)],
            setup=list(s.get("setup") or []), answer_matches=s.get("answer_matches"),
            followups=[str(x) for x in (s.get("followups") or [])],
        ))
    if not scenarios:
        errors.append("no scenarios defined")
    if errors:
        raise ScenarioError("scenarios.yml has problems:\n  - " + "\n  - ".join(errors))
    return Suite(
        module=module,
        system_prompt=str(agent.get("system_prompt") or DEFAULT_SYSTEM_PROMPT),
        max_turns=int(agent.get("max_turns") or 15),
        users={k: (v or {}) for k, v in users.items()},
        scenarios=scenarios,
    )
