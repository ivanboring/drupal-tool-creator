# Drupal tool creator

A small DDEV lab for building [Tool API](https://www.drupal.org/project/tool) plugins for a Drupal
module, and then improving them by measuring how well AI agents, including small local models,
actually get work done with them.

You pick a module. The lab does five steps, each a skill a coding agent follows:

| # | Skill | What happens |
|---|---|---|
| 1 | `tc-scenarios` | Agree what the tools must let an agent do: scenarios, several prompt phrasings each (one held out), PHP checks that prove the outcome. Written with Claude/Codex/OpenCode, or from your own list. |
| 2 | `tc-generate-tools` | Generate the first version of the tools, following the tool module's own `create-tool-plugins` skill: the generator, fail-closed access you approve, unit + kernel tests. Saved as variant `v01`. |
| 3 | `tc-simulate-agent` | Put agents in the agent's seat: a strong model (Claude/Codex) for the ceiling, small models (Ollama, OpenRouter, OpenCode) for robustness, and optionally the coding agent walking a scenario itself. Diagnose why things fail. |
| 4 | `tc-reiterate` | Loop X times: eval, read the failures, make one small tweak (descriptions, inputs, messages, tool shape), save a new variant, eval again, keep it only if the success rate improves. Once the agent is at its top rate, keep tweaks that hold the rate with fewer tokens or less time. |
| 5 | `tc-finalize` | Confirm the best variant across agents, make tests/phpcs/phpstan pass, export it with a report. |

`tool-creator` is the skill for the whole process: it works out which step a module is at and
continues from there.

## Setup

Requires DDEV ≥ 1.24.10 and Docker.

```bash
ddev setup
```

This starts the project, builds Drupal 11 in `drupal/` with drush, `drupal/tool` (plus
`tool_explorer`), `tool_belt` (code only, as examples) and `core-dev`, and captures the clean
baseline into `baselines/site/`. The site is https://drupal-tool-creator.localhost (admin/admin).

The project uses the `.localhost` TLD, which resolves to loopback everywhere, so ddev never needs
sudo to edit `/etc/hosts`.

Coding agents run **inside the ddev web container**, installed by the
[`e0ipso/ddev-assistant-claude`](https://github.com/e0ipso/ddev-assistant-claude),
[`-codex`](https://github.com/e0ipso/ddev-assistant-codex) and
[`-opencode`](https://github.com/e0ipso/ddev-assistant-opencode) add-ons. They copy your host
logins and config into the container on every `ddev start`, so no extra login is needed.

Small-model endpoints go in `.env` (copied from `.env.example` by `ddev setup`). Ollama on the host
works out of the box at `host.docker.internal:11434`, as long as Ollama listens on all interfaces
(`OLLAMA_HOST=0.0.0.0`).

## Use

```bash
ddev claude          # or: ddev codex / ddev opencode, at the repo root inside the container
```
```
/tool-creator redirect
```

Or drive the harness yourself:

```bash
ddev tc module prepare redirect                 # reset, install the module, capture its baseline
ddev tc scenarios check redirect                # after writing workspace/redirect/scenarios.yml
ddev tc variant save redirect --note "..."      # after building tools in drupal/web/modules/custom/
ddev tc eval redirect --variant v01 --agent fc:ollama/qwen3-coder:30b --agent claude:haiku --repeats 2
ddev tc report redirect                         # every eval of the module, with its change and best so far
ddev tc report                                  # every module: first → best
ddev tc html redirect                           # rebuild the HTML pages (evals do it themselves)
```

`ddev tc help` lists every command.

## How an eval works

Every `tc eval` restores `drupal/` **and** the database to the module baseline, installs the
variant from `workspace/<module>/variants/`, creates the scenario users, and dumps that database.
Each trial then:

1. restores that dump and runs the scenario's `setup`,
2. gives the agent the prompt, a system prompt, and the exposed tools, acting as the scenario's user,
3. runs the scenario's PHP checks (and the optional `answer_matches` regex) against the site.

Agents see the tools the way a Drupal AI agent sees them through `tool_ai_connector`: function
name = tool id with `:` → `__`, the tool's description, its input JSON Schema, and the same
`Tool Plugin executed successfully: …` result text. Calls go through `drush tool:run --uid`, so
input validation and access checks are the Tool API's own.

| Agent spec | Runs as | Gets the tools via |
|---|---|---|
| `fc:<endpoint>/<model>` | built-in function-calling loop, any OpenAI-compatible endpoint (native `/api/chat` for Ollama, 32k context) | function calling |
| `claude:<model>` | `claude -p`, no built-in tools | stdio MCP bridge |
| `codex:<model>` | `codex exec`, shell, exec, patch, browser, apps and plugins disabled | stdio MCP bridge |
| `opencode:<provider>/<model>` | `opencode run`, every built-in tool off | stdio MCP bridge |

The CLI agents run in an empty temp dir with a throwaway config dir that holds only your
credentials, so neither this repo's instructions nor your global ones leak into the measurement.

Each run writes `workspace/<module>/runs/<run-id>/`: `report.md`, `summary.json` (success rate
overall, train vs holdout, per scenario and prompt, tool usage and errors), and one directory per
trial with the tool-call trace and transcript. Holdout prompts land under `holdout/`; the reiterate
skill never reads them, so the holdout rate shows whether tweaks generalise.

### HTML reports

Every eval also writes HTML, and `tc variant save`, `tc variant install`, `tc report` and
`tc html <module> [--runs]` refresh it:

| Page | Shows |
|---|---|
| `workspace/index.html` | The overview of everything: each module's progress over its evals (success rate, tokens or time per trial), every module's step and best result, and every eval of every module in one table (filter by module or agent) with its change against the previous one. |
| `workspace/<module>/index.html` | Progress over evals per agent (success rate, tokens or time per trial), every eval with its change, pass rate by scenario over evals (which scenario moved), the variants, the scenarios, the current tools, and `REPORT.md`, `ITERATIONS.md` and `NOTES.md` rendered. |
| `workspace/<module>/runs/<run-id>/report.html` | One eval: agents with tokens and time per trial and in total, a pass-rate matrix by scenario and prompt, each train trial with its tool calls, tool usage, and the catalog the agent saw. |
| `workspace/<module>/runs/<run-id>/holdout/index.html` | The holdout trials, kept on a separate page. |

Progress is only compared like for like. Each run keeps a copy of the `scenarios.yml` it ran;
every distinct version of it is S1, S2, … per module. A change and a "best so far" are only computed
between complete runs on the same version, so editing the scenarios shows as a break in the line,
not as a drop. A run of only some scenarios or prompts (`--scenario`, `--prompt-id`) is marked
partial and left out of the progress. A change of one trial or less is marked "within noise".

Once an agent passes as often as it ever has (its top rate, usually 100%), the rate can no longer
show progress, so every eval also measures cost per trial:
- **Tokens:** input + output, as the agent's backend reports them. Input is summed over every turn,
  so the catalog counts once per turn. Compare tokens within one agent only: tokenizers differ.
- **Time:** the agent's wall time for the median trial, tool calls included. The harness's checks are
  left out, and so is the model's load: a local model gets one uncounted warm-up call before its
  first trial, and the load time goes into `meta.json` as `warmup_s`.

A change in either is tested per prompt and shown in grey when it is within noise. The cost charts
fill the evals at the top rate and draw the fewest so far among them. At the same rate, the best
eval is the one with the better holdout, then the fewest tokens, then the least time.

The pages are self-contained (no external assets) and follow the system's light or dark theme.
Open them straight from disk (`file://`), or through the site at
`https://drupal-tool-creator.localhost/tc/` (for example `/tc/redirect/`), which
`.ddev/nginx/tc-reports.conf` serves from `workspace/` without `.cache/` or `autosave/`.

## Layout

| Path | What it is |
|---|---|
| `.agents/skills/` | The six skills (canonical); linked into `.claude/skills/`. The tool module's `create-tool-plugins` and `use-drupal-tools` skills are linked in from the installed module. `.agents/skills/AGENTS.md` explains how they work together. |
| `toolcreator/` | The `tc` harness (Python, stdlib + PyYAML): resets, variants, catalog, MCP bridge, agent backends, eval, reports. |
| `site/` | `provision.sh` and `baseline.sh` (capture/restore filesystem + database). |
| `drupal/` | The disposable Drupal site (not committed). |
| `baselines/` | Clean-site and per-module baselines (not committed). |
| `workspace/<module>/` | Source of truth per module: `module.yml`, `scenarios.yml`, `variants/`, `runs/`, `NOTES.md`, `ITERATIONS.md`, `REPORT.md`, `final/`. |
| `memory/LEARNINGS.md` | Lessons that carry across modules and sessions. |

## Not a security boundary

Unlike the security-finder lab this is built from, nothing here is locked down: agents have
internet access, git, and memory across sessions, and they run in the ordinary ddev web container.
Do not point it at a site or credentials you care about.
