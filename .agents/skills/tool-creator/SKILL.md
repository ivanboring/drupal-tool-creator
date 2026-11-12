---
name: tool-creator
description: Use when asked to build, improve, or evaluate Drupal Tool API plugins (drupal/tool) for a module end to end — "make tools for <module>", "/tool-creator <module>", "continue the tools for <module>". Drives the five steps (scenarios → generate → simulate the agent → reiterate → finalize), works out which step a module is at, and hands off to the step skills tc-scenarios, tc-generate-tools, tc-simulate-agent, tc-reiterate and tc-finalize.
---

# Tool creator: the whole process

You build Tool API plugins for one Drupal module so that an AI agent, including a *small* model,
can reliably do real work on a site with them. You do not stop at "the tools run": you measure how
often agents succeed with them and improve the tools until the numbers stop going up.

Everything runs inside the ddev web container (`$IS_DDEV_PROJECT` is `true`), at the repo root.
If it is not, stop and tell the user to start you with `ddev claude` (or `ddev codex` /
`ddev opencode`) from the repo root, after a one-time `ddev setup`.

## The five steps

| Step | Skill | Produces | Done when |
|---|---|---|---|
| 1. Scenarios | `tc-scenarios` | `workspace/<m>/scenarios.yml` | `tc scenarios check <m>` passes and the user agreed the list |
| 2. Generate | `tc-generate-tools` | `workspace/<m>/variants/v01/` | the tools are generated with the tool module's own skill, access approved, tests green, saved as v01 |
| 3. Simulate the agent | `tc-simulate-agent` | first eval run + a diagnosis | a strong model and at least one small model have run the scenarios, and you know whether failures are *missing capability* or *ergonomics* |
| 4. Reiterate | `tc-reiterate` | `variants/vNN`, `ITERATIONS.md` | X loops done, or the small model is at its ceiling for two evals in a row |
| 5. Finalize | `tc-finalize` | `workspace/<m>/final/`, `REPORT.md` | the best variant passes tests and coding standards and is exported |

Step 3 can send you back to 2 (a tool is missing) or to 1 (a scenario or check is wrong). That is
normal; say which and why. `.agents/skills/AGENTS.md` has the full map: each step's entry gate and
hand-off, which files each one reads and writes, and where the user has to answer.

## Starting or resuming

1. Read `memory/LEARNINGS.md` (lessons across all modules) and, if it exists,
   `workspace/<m>/NOTES.md` and `workspace/<m>/ITERATIONS.md`. These are your memory between
   sessions; they are why sessions here are allowed to remember.
2. Work out where the module is:
   - no `workspace/<m>/module.yml` → prepare it: `tc module prepare <m>` (add
     `--composer 'drupal/<project>:^2.0@beta'` for a non-default constraint, `--composer none` for a
     core module, `--enable a,b` when submodules are needed). Then step 1.
   - no `scenarios.yml`, or `tc scenarios check <m>` fails → step 1.
   - no variants (`tc variant list <m>`) → step 2.
   - variants but no runs (`tc report <m>`) → step 3.
   - runs exist → step 4. If `ITERATIONS.md` shows a finished loop, give the best variant and its
     numbers and ask whether to keep tuning or go to step 5.
3. Tell the user in two lines where the module is and what you will do next, then load that
   step's skill and follow it.

## The harness, in one screen

```
tc module prepare <m>                 reset site, install module, capture its baseline
tc site status                        which module/variant the site holds
tc variant save <m> --note "..."      copy web/modules/custom/<tool_module> into variants/vNN
tc variant install <m> <vNN>          reset FS + DB, install that variant, create scenario users
tc tools <m>                          the tools exactly as the agent sees them
tc scenarios check <m>                validate scenarios.yml, prove checks fail before the agent acts
tc try <m> --agent SPEC --scenario ID one trial, printed in full (fast feedback)
tc eval <m> --variant vNN --agent SPEC --repeats N    the full, reset-first eval
tc report <m>                         every eval with its change, tokens and time; best per agent
tc report                             every module: first → best on its current scenarios
tc html <m> [--runs]                  rebuild the HTML pages (evals and variant saves do it already)
```

Show the user the HTML: `workspace/index.html` (the overview: every module's progress and every
eval), `workspace/<m>/index.html` (the module page: progress per agent, evals, pass rate by
scenario over evals, variants, scenarios, tools) and `runs/<run-id>/report.html` per eval. They open from
disk or at `https://drupal-tool-creator.localhost/tc/<m>/`; `tc eval` prints both. You read
`report.md` and `summary.json`; the HTML is for the user.

Agent specs: `fc:ollama/<model>` (small local models, the default target), `fc:openrouter/<model>`,
`claude:haiku`, `claude:sonnet`, `codex:<model>`, `opencode:<provider>/<model>`. Endpoints come
from `.env` (`TC_ENDPOINT_<NAME>_URL`). `ollama list` on the host shows local models; the
container reaches the host's Ollama at `host.docker.internal:11434`.

Drush and composer are local: `cd drupal && drush ...` / `composer ...` (never `ddev ...`; you are
already in the container). The site is https://drupal-tool-creator.localhost, admin/admin;
`tc site login` prints a login link.

## Rules that hold across every step

- **The site is disposable; `workspace/` is the source of truth.** Every eval and every
  `variant install` wipes `web/modules/custom/` and the database. Save work with
  `tc variant save` before anything resets. (`tc` autosaves unsaved tool-module code into
  `workspace/<m>/autosave/` as a safety net, but do not rely on it.)
- **Never edit the target module itself.** Tools live in the companion module named in
  `module.yml` (`tool_module`, default `<module>_tools`).
- **Never change a scenario or its checks to make a number go up.** If a scenario is wrong, say
  so, fix it with the user's agreement, and note that results before and after are not comparable.
- **Access stays fail-closed until the user approves it** (the tool module's rule; see step 2).
  The reiterate loop never touches `checkAccess()` or permissions.
- **Write down what you learn.** Module-specific facts go in `workspace/<m>/NOTES.md`; anything
  that would help with the *next* module (how small models misread a pattern, a description
  shape that works) goes in `memory/LEARNINGS.md`. Dated, short, newest last.
