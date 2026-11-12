---
name: tc-simulate-agent
description: Step 3 of the tool creator. Use to find out how agents actually do with a module's tools — run the scenarios with a strong model (Claude/Codex) as the agent to get a ceiling, then with small models (local Ollama, OpenRouter, OpenCode) in eval loops, optionally walk a scenario yourself using only the tools, and diagnose whether failures are missing capability, a wrong scenario, or tool ergonomics.
---

# Step 3: simulate the agent

The question this step answers: **when an agent only has these tools, does it get the job done,
and if not, whose fault is it?** You learn that by putting different agents in the agent's seat.
Every agent sees the same thing a Drupal AI agent sees through `tool_ai_connector`: function name
= tool id with `:` → `__`, the tool description, the input JSON Schema, and the same
"Tool Plugin executed successfully: …" result text. It acts as the scenario's `run_as` user, so
access checks are real.

| Seat | Spec | What it tells you |
|---|---|---|
| Strong model | `claude:sonnet`, `claude:opus`, `codex:<model>` | The **ceiling**. If this fails, the tools cannot do the job or the scenario is wrong. |
| Mid model | `claude:haiku`, `opencode:<provider>/<model>` | Whether the toolset is merely usable. |
| Small model | `fc:ollama/<model>`, `fc:openrouter/<model>` | Whether the tools are *robust*. This is the number step 4 optimises. |
| You | walkthrough (below) | Qualitative friction you can explain in words. |

Claude, Codex and OpenCode get the tools over MCP and nothing else (no shell, no files); they run
in an empty temp directory so this repo's instructions do not leak in. The `fc` backend is the
harness's own function-calling loop and works with any OpenAI-compatible endpoint; with Ollama it
sets a 32k context (`TC_OLLAMA_NUM_CTX`) so tool schemas are not silently truncated.

Pick small models that the user would really deploy. `ollama list` on the host shows local ones;
ask the user which to target if `.env`'s `TC_DEFAULT_AGENT` does not say.

## 1. One trial each, watched

```bash
tc variant install <m> latest
tc try <m> --agent claude:sonnet --scenario <id>
tc try <m> --agent fc:ollama/<small-model> --scenario <id>
```

`tc try` prints every call with its arguments and result, the answer, and each check. Do this for
two or three scenarios before running anything big; it catches broken setup, broken checks and
broken tools in seconds.

## 2. The first eval

```bash
tc eval <m> --variant v01 --agent claude:sonnet --agent fc:ollama/<small-model> --repeats 2 --label baseline
```

This always resets the filesystem and database first, installs the variant, then runs every
scenario × prompt × agent × repeat from a restored database. Results go to
`workspace/<m>/runs/<run-id>/`: `report.md` (read this first), `summary.json`, and one directory
per trial with `result.json`, `trace.jsonl` (every tool call with arguments and result) and
`transcript.json`. Small models are noisy: use `--repeats 2` at least, 3 for decisions.
The same run is in `report.html` for the user (the path and URL are printed at the end).
Every agent's row also has tokens and time per trial. Note the target's in `NOTES.md` with the
diagnosis: they are the baseline step 4 tunes against once the rate is at the top.

## 3. Diagnose

Read `report.md`, then the trials behind each failure (`trials/<scenario>/<prompt>/<agent>/rN/`).
Sort every failing scenario into one of these, and write the table into `workspace/<m>/NOTES.md`:

| Pattern | Means | Goes to |
|---|---|---|
| The strong model fails too | A capability is missing, or a tool is buggy, or access denies the run_as user | step 2: add or fix a tool (with access approval) |
| Strong passes but the check fails anyway / passes wrongly | The check or the prompt is wrong | step 1: fix the scenario with the user; earlier numbers are void |
| Strong passes, small fails: calls the wrong tool | Descriptions overlap or do not say when to use which | step 4 |
| Small model invents ids / machine names | No lookup tool or no `enum`; description does not say to look up first | step 4 (or step 2 if a lookup tool is missing) |
| `invalid_input` errors | Input names, types or formats are unclear | step 4 |
| Calls the right tool, then stops or gives up | Result message does not say what happened or what to do next | step 4 |
| Loops / many calls | Outputs do not give what the next step needs | step 4 |
| `unknown_tool` | Model mangled a name; names too long or too similar | step 4 |

## 4. Walkthrough: be the agent (optional, useful for one stubborn scenario)

Act as the agent yourself, with the same restriction: read only `tc tools <m>` (what the agent
sees, not the source), and act only through the tools, as the scenario's user:

```bash
cd drupal
drush tool:run <id> --uid=<uid from `tc site status`> --input='{...}' --json
```

Narrate each decision in one line: what you looked for in the descriptions, what you could not
tell, what you had to guess. Every guess is a candidate tweak. `tc variant install <m> <v>` to
reset when done.

## Hand off

Tell the user: ceiling (strong model) and small-model success, train vs holdout, and the
diagnosis table. Give them the run's `report.html` path or URL to look through the trials. If anything went to step 1 or 2, do that first and re-run this step. Otherwise
continue with `tc-reiterate`.
