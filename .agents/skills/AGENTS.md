# AGENTS.md: how the skills work together

Eight skills build and tune Tool API plugins for one Drupal module. Six belong to the tool
creator: one orchestrator and five steps. Two come from the tool module itself. This file is the
map: what each skill is for, what it hands to the next, where the user has to answer, and how to
run them across sessions and coding agents. The ground rules in the root `AGENTS.md` apply to every
one of them.

## The skills

| Skill | Role | Use it when |
|---|---|---|
| `tool-creator` | Orchestrator | "Make / improve / continue the tools for `<m>`", or you do not know which step a module is at. It reads the workspace, picks the step and loads that step's skill. It does no step's work itself. |
| `tc-scenarios` | Step 1 | Writing or revising `scenarios.yml`: the jobs the tools must make possible, several prompts each, PHP checks. |
| `tc-generate-tools` | Step 2 | Designing the toolset from the scenarios and building the first version, v01. Also when step 3 finds a missing tool. |
| `tc-simulate-agent` | Step 3 | Measuring how a strong model and small models do with a variant, and sorting failures by cause. |
| `tc-reiterate` | Step 4 | X loops of one small, measured tweak each. The only step meant to run unattended. |
| `tc-finalize` | Step 5 | Confirming the winner across agents, making it pass tests, phpcs and phpstan, and exporting it with `REPORT.md`. |
| `create-tool-plugins` | From the tool module | How to build one tool: the generator, fail-closed access, mandatory tests. Step 2 follows it for every tool, and step 4 does too when a tweak adds a tool. |
| `use-drupal-tools` | From the tool module | Every `drush tool:*` call: step 2's smoke tests, step 3's walkthrough, step 4's checks of an edit. |

The two tool-module skills are symlinks into `drupal/web/modules/contrib/tool/.agents/skills/`, so
they always match the installed tool version. Never edit them here. Where they say `ddev drush`,
drop `ddev`: you are already in the container.

## The flow

```
  1 tc-scenarios ─▶ 2 tc-generate-tools ─▶ 3 tc-simulate-agent ─▶ 4 tc-reiterate ─▶ 5 tc-finalize
         ▲                   ▲                      │
         │                   └─ a tool is missing ──┤
         └───── a scenario or check is wrong ───────┘
```

Each step starts only once the previous one is done. If a step's gate is not met, go back rather
than work around it.

| Step | Starts when | Done when (hand-off to the next) |
|---|---|---|
| 1 | `tc module prepare <m>` has run, so `module.yml` exists | `tc scenarios check <m>` is green and the user said yes to the list |
| 2 | Step 1 is done | Variant v01 is saved, `tc variant install <m> v01` works, the tests pass, and each tool's access is approved (or left fail-closed and written down) |
| 3 | v01 (or any new variant from step 2) is saved | One eval with a strong model and the target small model exists, and the diagnosis table is in `NOTES.md` with nothing pointing back to step 1 or 2 |
| 4 | Step 3 left only ergonomic failures | X loops ran, or three loops in a row were dropped. Once the target is at its top rate (everything passed twice), the remaining loops cut tokens and time per trial instead. `ITERATIONS.md` names the best variant. |
| 5 | The user is satisfied with step 4 | `final/` and `REPORT.md` exist, and the module page shows the report |

Going back is normal; say which way and why:
- **3 → 2:** the strong model fails too, so a capability is missing or a tool is buggy. Add or fix
  the tool with `tc-generate-tools` (generator, tests, access approval), save a new variant, and
  run step 3 again.
- **3 → 1:** a check or prompt is wrong. Fix it with `tc-scenarios`, only with the user's
  agreement. Every earlier run is now incomparable; say so in `NOTES.md` and `ITERATIONS.md`, then
  run step 3 again from scratch. The reports do this on their own: the edited file is a new
  scenario version (S2, …), and progress restarts there instead of showing a drop.
- **Inside step 4:** a tweak may add a small lookup tool through `create-tool-plugins`, but its
  access needs the user like any new tool. Unattended, write it in `ITERATIONS.md` and skip it.

## Where the user has to answer

Steps 1, 2 and 5 are conversations. Step 3 rarely needs the user, and step 4 never waits.

| Step | Needs the user for |
|---|---|
| 1 | The scenario list (their list, or one built together) and the final yes |
| 2 | The toolset table, and `checkAccess()` for each tool: one question covering all tools if possible |
| 3 | Which small model(s) to target, if `TC_DEFAULT_AGENT` in `.env` does not say |
| 4 | Nothing. Anything that would need them (access, scenarios, the target module) goes in `ITERATIONS.md` and is skipped. |
| 5 | Confirming the winner; whether the tools should move into the target module |

Ask with `AskUserQuestion` in Claude Code, or as a short numbered list in Codex and OpenCode.

## The workspace is the hand-off

The skills never call each other's results directly. Each one reads what the earlier steps left in
`workspace/<m>/` and `memory/`, so any step can start in a fresh session, or with a different
coding agent.

| File | Written by | Read by |
|---|---|---|
| `memory/LEARNINGS.md` | Steps 4 and 5, and any step that learns something reusable | Every skill, before it starts |
| `module.yml` | `tc module prepare` | `tc`, all steps |
| `scenarios.yml` | Step 1 only | Steps 2–5 and `tc` |
| `NOTES.md` | Every step appends: module facts, toolset rationale, access approvals and who gave them, the diagnosis table | Every step |
| `variants/vNN/` | `tc variant save` in steps 2, 4 and 5 | `tc variant install`, `tc eval` |
| `runs/<id>/report.md`, `summary.json`, `trials/` | `tc eval` in steps 3, 4 and 5 | Steps 3, 4 and 5 |
| `runs/<id>/holdout/` | `tc eval` | **No skill.** Only its score counts, so tweaks are checked on prompts nobody tuned for. |
| `ITERATIONS.md` | Step 4, one row per loop | Steps 4 and 5, and `tool-creator` when resuming |
| `REPORT.md`, `final/` | Step 5 | The user |
| `workspace/index.html`, `index.html`, `runs/<id>/report.html` | `tc` (evals, variant saves, `tc report`, `tc html`) | The user: the overview of every module and eval, the module page, one eval. Skills read `tc report` and the `.md` and `.json` files instead. |

`drupal/` and the database are disposable: every eval and every `tc variant install` resets both.
Work in the companion module that has not been saved with `tc variant save` is lost on the next
reset, except for the `autosave/` safety net.

## Two different agents

The coding agent that loads these skills (you) is not the **agent under test**. The agent under
test, in `tc try` and `tc eval`, runs in an empty temporary directory with only the Drupal tools.
It never sees this file, the skills, the repo or `NOTES.md`. Help for it can only travel through
the tools: descriptions, input schemas, result and error messages. That is exactly what step 4
tunes.

## Ways to run it

**One session, start to finish.** Run `ddev claude` (or `ddev codex`, `ddev opencode`) from the
repo root, then `/tool-creator <m>`, or just ask to make tools for `<m>`. The orchestrator
prepares the module if needed and moves through the steps, stopping at each user gate.

**One step at a time.** Call a step skill directly to do or redo just that step: `/tc-scenarios <m>`
to revise scenarios, `/tc-simulate-agent <m>` to measure a new model. In Codex or OpenCode, ask for
it by name: "Use the tc-scenarios skill for `<m>`".

**Interactive, then unattended, then interactive.** Steps 1–3 with the user present, step 4 in the
background, step 5 together again:

```bash
ddev claude -p "/tc-reiterate <m> 5 fc:ollama/qwen3-coder:30b" --permission-mode acceptEdits
ddev codex exec "Use the tc-reiterate skill for <m>: 5 loops, target fc:ollama/qwen3-coder:30b"
```

Afterwards, `/tool-creator <m>` in a new session reads `ITERATIONS.md`, reports where the loop
ended, and offers to keep tuning or finalize.

**Mixing coding agents.** Claude Code finds the skills in `.claude/skills/` (symlinks). Codex and
OpenCode find them in `.agents/skills/`. Because the hand-off is the workspace, step 1 can run in
Claude Code and step 4 in Codex.

## A typical module

The numbers are made up; the order of the steps is what matters.

```
/tool-creator webform
  tc module prepare webform              site reset, webform installed, baseline captured
  tc-scenarios       6 scenarios agreed, `tc scenarios check` green
  tc-generate-tools  4 tools agreed, access approved, tests green, saved as v01
  tc-simulate-agent  claude:sonnet 100%, qwen3-coder 55%; one lookup tool missing
  tc-generate-tools  add the lookup tool, saved as v02
  tc-simulate-agent  sonnet 100%, qwen3-coder 70%; the rest is ergonomics
(unattended) /tc-reiterate webform 5 fc:ollama/qwen3-coder:30b   best v05, 90% train, 83% holdout, 9.8k tokens/trial
/tool-creator webform
  tc-finalize        fresh eval across agents, phpcs and phpstan clean, final/ + REPORT.md
```

Point the user at `workspace/<m>/index.html` (or `https://drupal-tool-creator.localhost/tc/<m>/`)
after every eval. It charts each agent's progress over evals: success rate, and tokens and time per
trial, which are what improves once the rate is at the top. `workspace/index.html` (`/tc/`) is the
overview across modules: each module's progress and every eval in one table. `tc report` prints the
same in text.

## Changing the skills

- Edit the canonical files in `.agents/skills/<name>/SKILL.md`. `.claude/skills/` holds only
  symlinks, created by `.ddev/scripts/link-skills.sh`, which runs on `ddev start`. Run it by hand
  after adding a skill.
- The tables above are the contract between steps. If you change what a step writes, or where,
  update the step that reads it, the `tool-creator` table and this file in the same change.
- A new step skill goes into the `tool-creator` table, its "Starting or resuming" logic, and this
  file.
