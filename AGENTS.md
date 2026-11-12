# AGENTS.md

This repo is the Drupal tool creator: a DDEV project whose coding agents build Tool API plugins for
a Drupal module and improve them against measured agent success. `CLAUDE.md` is `@AGENTS.md`.

## If you were asked to build or improve tools for a module

Use the `tool-creator` skill (`.agents/skills/tool-creator/SKILL.md`). It runs the five steps:
`tc-scenarios` → `tc-generate-tools` → `tc-simulate-agent` → `tc-reiterate` → `tc-finalize`.
Read `memory/LEARNINGS.md` first. How the skills fit together (what each step hands to the next,
where the user must answer, running steps across sessions and coding agents) is in
`.agents/skills/AGENTS.md`.

You normally run inside the ddev web container (`IS_DDEV_PROJECT=true`), at the repo root:
- `tc ...` is the harness (`tc help`). From the host it is `ddev tc ...`.
- Drush and composer are local: `cd drupal && drush ...`. Do not call `ddev` from inside.
- Ollama on the host is `http://host.docker.internal:11434`.

Ground rules:
- `drupal/` and the database are disposable; every eval resets both. `workspace/` is the source of
  truth; save tool code with `tc variant save` before anything resets.
- Never edit the target module; tools go in the companion module from `workspace/<m>/module.yml`.
- Never edit a scenario or its checks to raise a score; never touch `checkAccess()` or permissions
  without the user's approval.
- Record module facts in `workspace/<m>/NOTES.md`, cross-module lessons in `memory/LEARNINGS.md`.

## If you are working on the lab itself

| Path | What it is |
|---|---|
| `.ddev/config.yaml` | The project: repo root, Drupal in `drupal/` (`composer_root`), python3 + PyYAML in the web image. |
| `.ddev/*assistant*`, `.ddev/web-build/Dockerfile.assistant-*` | The e0ipso ddev-assistant add-ons (Claude Code, Codex, OpenCode). Managed by `ddev add-on get`; do not hand-edit. |
| `.ddev/web-build/Dockerfile.tool-creator` | Puts `tc` on PATH in the web container. |
| `.ddev/commands/host/setup` | `ddev setup`: start, provision, capture the clean baseline. |
| `.ddev/commands/web/{tc,claude,codex,opencode}` | `ddev tc`, `ddev claude`, … at the repo root in the web container. |
| `.ddev/scripts/link-skills.sh` | Links `.agents/skills/*` into `.claude/skills/`, and the tool module's two skills into both. |
| `site/` | `provision.sh` (builds `drupal/`), `baseline.sh` (capture/restore FS + DB), `lib.sh`. |
| `toolcreator/` | The harness. `site.py` (drush, PHP checks, DB dump/restore), `workspace.py` (module.yml, variants, autosave), `scenarios.py` (scenarios.yml schema), `tools.py` (catalog + runner mirroring tool_ai_connector), `mcp_server.py` (stdio MCP bridge), `agents/` (fc, claude, codex, opencode), `evaluate.py` (install, trials, scoring, reports), `history.py` (every eval of a module, tagged by scenario version, with change and best so far, and tokens and time per trial with a noise test: `tc report` and the overview pages), `html.py` (the HTML pages), `cli.py`. |
| `.ddev/nginx/tc-reports.conf` | Serves `workspace/` at `/tc/` on the site (HTML reports), minus `.cache/` and `autosave/`. |

- Edit skills under `.agents/skills/`; `.claude/skills/` holds symlinks only. Keep
  `.agents/skills/AGENTS.md` in step with them: it is the contract between the steps.
- Keep the agent-facing tool view identical to `tool_ai_connector` (`tools.py`): names, schema and
  result text are what the eval measures.
- Keep the CLI agents isolated in evals (`agents/cli.py`): empty temp cwd, throwaway config dir
  with credentials only, Drupal tools over MCP and nothing that can change the site.
- After changing `site/baseline.sh`, test both `tc site reset` and a full `tc eval`.
- Python is stdlib + PyYAML (python3-markdown optional, for rendering notes in the HTML); keep it that way.
- The HTML pages are self-contained: inline CSS/JS, no external assets, so they open from `file://`.
