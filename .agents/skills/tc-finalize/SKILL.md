---
name: tc-finalize
description: Step 5 of the tool creator. Use when the reiterate loop is done and the user wants to keep the result — confirm the best variant with a fresh eval across agents, make its tests, coding standards and static analysis pass, and export it to workspace/<module>/final/ with a REPORT.md of what was built and how well agents do with it.
---

# Step 5: finalize

1. **Pick the winner.** `tc report <m>` and `ITERATIONS.md`. The best variant is the highest
   success rate for the target agent with holdout not worse than the alternatives; ties go to fewer
   tokens per trial, then less time (`tc report <m>` ranks them this way).
2. **Confirm with a fresh eval** across every agent class, so the report is not one lucky run:
   ```bash
   tc eval <m> --variant <best> --agent <target small> --agent claude:haiku --agent claude:sonnet --repeats 3 --label final
   ```
3. **Make it shippable.** Install it (`tc variant install <m> <best>`) and in
   `drupal/`:
   ```bash
   vendor/bin/phpunit -c web/core web/modules/custom/<tool_module>
   vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom/<tool_module>
   vendor/bin/phpstan analyse --level=5 web/modules/custom/<tool_module>
   ```
   Fix what fails without changing tool behaviour: descriptions, inputs and messages are what was
   measured. If a fix does change behaviour, save a new variant and re-run step 2's eval.
   Any tool still fail-closed is listed in the report as not usable yet.
4. **Save and export.**
   ```bash
   tc variant save <m> --note "final: tests, phpcs, phpstan clean"
   rm -rf workspace/<m>/final && cp -r workspace/<m>/variants/<final>/<tool_module> workspace/<m>/final
   ```
   If the user maintains the target module and wants the tools inside it rather than as a
   companion module, offer to move them into a submodule of it, but do not change the target
   module unasked.
5. **Write `workspace/<m>/REPORT.md`:** the scenarios (one line each); the toolset (id,
   operation, description, permission); the final eval table per agent with train, holdout,
   tokens and time per trial;
   the iteration history from `ITERATIONS.md` in short; what still fails and why; access decisions
   and who approved them. Link the final run's `report.md` and `report.html`. `tc html <m>` then
   renders `REPORT.md` at the top of the module page, `workspace/<m>/index.html`, which is what
   you hand the user.
6. **Remember.** Close out `NOTES.md` and add anything reusable to `memory/LEARNINGS.md`.
