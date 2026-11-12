---
name: tc-reiterate
description: Step 4 of the tool creator. Use to improve a module's tools by measurement — loop X times: eval the current best variant (which always resets the filesystem and database), read the train failures, make one small tweak to descriptions, input definitions, messages or tool shape, save it as a new variant, eval it, keep it only if the success rate improves (or, once the agent is at its top rate, if it holds the rate with fewer tokens or less time). Reports success rates, tokens and time per variant and ends with the best variant. "/tc-reiterate <module> [loops] [agent]".
---

# Step 4: reiterate

A loop of small, measured changes. Each loop tests **one hypothesis** about why the agent fails,
so you can tell which change helped. The eval is the judge, not your intuition.

Inputs (ask only if not given and not in `.env`/`ITERATIONS.md`):
- `loops` X: how many tweak loops (default 5);
- target agent(s): the small model(s) to optimise for (default `TC_DEFAULT_AGENT`); optionally a
  second "guard" agent, so a tweak does not help one model by hurting another;
- repeats: 2 by default, 3 when comparing close variants.

## Before the first loop

1. Read `workspace/<m>/ITERATIONS.md` (create it if missing, header below), `NOTES.md`, and
   `memory/LEARNINGS.md`. If loops already ran in an earlier session, continue from them.
2. Decide the **best variant** so far: `tc report <m>`. It lists every eval with its change against the
   previous eval of the same agent on the same scenarios, marks one-trial changes "within noise",
   gives tokens and time per trial with their change (`~` = within noise), stars the evals at the
   agent's top rate, and ends with the best per agent: the highest rate, then the better holdout,
   then the fewest tokens. If the current best has no eval with the
   target agent(s) and repeats, run one now; that is loop 0, the number to beat:
   ```bash
   tc eval <m> --variant <best> --agent <target> --repeats <n> --label loop0
   ```

## Each loop

1. **Read the evidence.** The latest run's `report.md`, then `summary.json` → `train_failures`,
   and the `trace.jsonl` + `result.json` of a few failing trials under `trials/`. Never open
   anything under `holdout/` (that includes `holdout/index.html`, which `report.html` links to):
   the holdout score is your check that a tweak generalises, and reading it would turn it into
   training data. The HTML pages are for the user; you work from `report.md` and `summary.json`.
2. **Pick one hypothesis.** Name the failure pattern (see the table in `tc-simulate-agent`) that
   costs the most trials, and the smallest change that should fix it. Write it down *before*
   editing: "small model passes `node_id` as a title in add-redirect; input description does not
   say it is numeric or where to get it".
3. **Install the best variant and edit it in the site.**
   ```bash
   tc variant install <m> <best>
   # edit drupal/web/modules/custom/<tool_module>/...
   cd drupal && drush cr && drush tool:info <id> --format=json
   ```
4. **Quick check** on one failing train prompt before paying for a full eval:
   `tc try <m> --agent <target> --scenario <id> --prompt-id <p>`. One pass proves nothing (noise),
   but a crash, a schema error or an unchanged failure saves you an eval.
5. **Save and eval.** The eval resets the filesystem and database and installs from the saved
   copy, so the edit must be saved first:
   ```bash
   tc variant save <m> --note "<hypothesis, one line>"
   tc eval <m> --variant <new> --agent <target> [--agent <guard>] --repeats <n> --label loop<k>
   ```
6. **Keep or drop.** Compare with the best on the same agent(s) and repeats:
   - keep if the success rate is higher, or equal with fewer calls or fewer failed calls, and the
     holdout rate did not drop;
   - at the top rate (see below), keep if the rate and the holdout held and tokens or time per
     trial fell beyond noise (`tc report` shows the change without `~`);
   - drop otherwise; the next loop starts from the old best again, not from the dropped variant;
   - a gain of a single trial is within noise; re-run both with `--repeats 3` before declaring a win.
7. **Log it** as one row in `ITERATIONS.md` and continue.

**At the top rate, tune for cost.** When the target passes every train and holdout trial in two
consecutive evals, the rate cannot rise any more; that is the agent's top rate, usually 100%. An
agent can also top out lower: no tweak has beaten its best in three loops. From then on, the
remaining loops aim at **tokens and time per trial** with the rate held:
- tokens: the whole catalog is sent on every turn, so shorter descriptions and input definitions
  cut input tokens on every call; so does one call fewer (a result that already holds the ids the
  next step needs, or no "call X first" when X is not needed);
- time: fewer turns, fewer failed calls that lead to retries, and faster tool code.

Tokens are counted as the backend reports them and are compared only within one agent. Time is
the median trial, with the model's load before the first trial left out; it depends on the
machine, so compare it only between evals run the same way (same agents, same machine). A
shorter catalog is not free: check the holdout, which is where a lost hint shows first.

Stop after three dropped loops in a row (you are at the model's ceiling for this design, or at
the cost it needs; say so), or when the loops run out.

## What a tweak may change

Allowed, in roughly this order of payoff:
- the tool **description**: when to use it, which tool to call first, what it returns;
- **input** names, descriptions with format and example, `enum`s, defaults, constraints,
  required/optional, refiners;
- **result and error messages**: say what happened, return the ids the next call needs, and on
  failure list the valid values or name the tool that finds them;
- **outputs** added for chaining;
- tool **shape**: merge two tools an agent confuses, split one that does too much, add a small
  `read`/`explain` lookup tool. A new tool goes through the generator and gets tests, per the
  `create-tool-plugins` skill.

Not allowed in this loop (stop and ask the user):
- `checkAccess()`, permissions, the `destructive` flag;
- `scenarios.yml`: its prompts, checks, setup or users. Moving the goalposts invalidates every
  earlier number;
- the agent system prompt in `scenarios.yml`: it is not part of the tools;
- the target module's own code.

Keep the generated tests passing; if you change a tool's contract, update its tests in the same
variant (`cd drupal && vendor/bin/phpunit -c web/core web/modules/custom/<tool_module>`).

## ITERATIONS.md

```markdown
# <module>: iterations

Target: fc:ollama/<model> (guard: claude:haiku), repeats 2.

| loop | variant | parent | hypothesis | target pass | train | holdout | calls | tokens | time | kept |
|---|---|---|---|---|---|---|---|---|---|---|
| 0 | v01 | – | baseline | 9/20 | 50% | 25% | 4.1 | 12.4k | 9.1 s | – |
| 1 | v02 | v01 | add-redirect: say node_id is numeric, name the lookup tool | 13/20 | 70% | 50% | 3.6 | 11.9k | 8.4 s | yes |
```

Tokens and time are per trial, as `tc report <m>` prints them.

## Report

At the end, give the user the table, the module page (`workspace/<m>/index.html`, which charts
success rate, tokens and time per trial over the evals per agent and renders `ITERATIONS.md`), the
best variant and its numbers per agent (rate, train, holdout, tokens and time per trial), what
kinds of changes moved the score or the cost, and what still fails and why. Add the generalisable lessons (what
description pattern fixed what small-model behaviour) to `memory/LEARNINGS.md`. Next is step 5,
`tc-finalize`, when the user is satisfied.

## Running it unattended

The user can run the loop without a session open, for example:

```bash
ddev claude -p "/tc-reiterate <m> 5 fc:ollama/qwen3-coder:30b" --permission-mode acceptEdits
ddev codex exec "Use the tc-reiterate skill for <m>: 5 loops, target fc:ollama/qwen3-coder:30b"
```

Unattended, never wait for an answer: anything that needs the user (access, scenarios) is noted in
`ITERATIONS.md` and skipped.
