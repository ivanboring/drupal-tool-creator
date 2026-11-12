---
name: tc-scenarios
description: Step 1 of the tool creator. Use to create or revise workspace/<module>/scenarios.yml — the tasks the module's tools must let an agent solve, each with several prompt phrasings and PHP checks that prove success — either by interviewing the user about what an agent should do with the module, or by turning a list the user wrote into the format. Ends with `tc scenarios check` passing.
---

# Step 1: scenarios

A scenario is one job an agent should be able to do on a site with this module, written so a
machine can tell whether it was done. The scenarios *are* the spec for the tools: step 2 designs
the tools from them, and steps 3–4 score every tool version against them. Weak scenarios produce
tools that look good and are not.

## 1. Learn the module first

Before proposing anything, read the module in the site (`drupal/web/modules/contrib/<m>/`):
`*.info.yml`, `*.permissions.yml`, `*.routing.yml`, its entity types (`src/Entity/`), its config
schema (`config/schema/`), and its services. Look at what an admin can do in the UI (routes,
forms). Check `workspace/<m>/NOTES.md` and `memory/LEARNINGS.md`. One paragraph to the user:
what the module manages and what an agent could plausibly be asked to do with it.

## 2. Get the list: two ways

**The user has a list.** Take it as given. Convert each item, ask only about what you cannot
check (which user does it, what exactly counts as done).

**Build it together.** Propose 4–8 scenarios that cover the module's real use, then let the user
cut, add and reword. Aim for a spread:

- the everyday create / update / delete of the module's main thing;
- a *read* question whose answer must come from the site ("which X are Y?") — checked with
  `answer_matches`;
- a task that needs a lookup first (an id, a machine name, a bundle) so the agent has to chain;
- a multi-step task (create then configure; find then change);
- a permission case: a user who may *not* do something, where success is the agent reporting it
  and nothing changing;
- one deliberately vague or colloquial request that a real editor would type.

Ask with `AskUserQuestion` (Claude) or a short numbered list (Codex/OpenCode) — do not guess
the user's priorities.

## 3. Prompts: several per scenario, one held out

Each scenario gets 3–5 prompts that ask for the same outcome in different ways: a precise one, a
terse one, a chatty/indirect one, one that uses the UI's vocabulary rather than the code's, and a
non-native-English one if relevant. Mark **one** prompt per scenario `holdout: true`. The
reiterate step never reads holdout transcripts, so the holdout score tells you whether tweaks
generalise or only fit the visible prompts.

Prompts must be solvable without asking back. If a real user would have to answer a question,
put the answer in the prompt (or add `followups:`, which only the `fc` backend uses).

## 4. Checks: prove the outcome, not the path

Each check is a PHP snippet run with full Drupal bootstrapped; it must `return` something truthy
on success. Check the *state of the site*, never which tool was used, so a better toolset with
different tools still passes.

- Be tolerant where the prompt is: if the prompt does not dictate a machine name, find the thing
  by label/title/property, not by an id you made up.
- Be strict where the prompt is: if it says "unpublished", check the status.
- For read scenarios, put the expected facts in `answer_matches` (a regex; `(?i)` for case),
  and create those facts in `setup:` so the answer is not guessable.
- For permission-denied scenarios, check that nothing changed and use `answer_matches` for the
  agent saying it could not.

`setup:` runs before every trial after the database is restored: create the content the scenario
needs there (with `php:` returning TRUE, or `drush: [...]`), never in the baseline.

`users:` defines who the agent acts as. Prefer a real role with the module's permissions over
`admin`: uid 1 bypasses access, so an admin-only suite can never catch an access bug. Permissions
the tool module will add (e.g. `use <m> tools`) can be listed here; users are created after the
tool module is installed. Until step 2 exists, `tc scenarios check` runs without them.

## 5. Write and verify

Write `workspace/<m>/scenarios.yml` in this format (`toolcreator/scenarios.py` has the full
reference):

```yaml
agent:
  max_turns: 15            # optional; system_prompt: optional override of the default
users:
  editor:
    permissions: [administer redirects]
scenarios:
  - id: add-redirect
    description: Create a 301 redirect from an old URL to an existing node.
    run_as: editor
    setup:
      - php: |
          $n = \Drupal\node\Entity\Node::create(['type' => 'page', 'title' => 'About us']);
          $n->save();
          return TRUE;
    prompts:
      - "Create a 301 redirect from /about-old to the 'About us' page."
      - {id: terse, text: "about-old should go to About us"}
      - {id: chatty, text: "We renamed our about page, people still hit /about-old, can you send them to the new About us page?"}
      - {id: indirect, text: "Make sure visitors of /about-old end up on About us permanently.", holdout: true}
    checks:
      - name: one redirect from about-old to the About us node
        php: |
          $nid = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('title', 'About us')->execute();
          $rs = \Drupal::entityTypeManager()->getStorage('redirect')->loadByProperties(['redirect_source__path' => 'about-old']);
          if (count($rs) !== 1) { return FALSE; }
          $r = reset($rs);
          return in_array($r->getRedirectUrl()->getRouteParameters()['node'] ?? NULL, $nid) && $r->getStatusCode() == 301;
```

Then run:

```bash
tc scenarios check <m>
```

It validates the file, runs each scenario's setup on a clean database, and runs the checks, which
must all **fail** at that point (a check that passes before the agent acts measures nothing). Fix
until it is green. If you can, also prove each check *can* pass: make the change by hand with
`cd drupal && drush php:eval '...'` after `tc scenarios check`, run
`tc scenarios checks <m> --scenario <id>` (checks only, against the site as it is), then
`tc site reset --module <m>`.

Show the user the final list (ids, one line each, prompt count, who runs it) and get a yes before
moving to step 2. Once evals exist, any change to `scenarios.yml` starts a new version (S2, S3, …):
the reports break the progress line there and compare scores only within one version. Record anything surprising about the module in `workspace/<m>/NOTES.md`.
