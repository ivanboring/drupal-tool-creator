---
name: tc-generate-tools
description: Step 2 of the tool creator. Use to produce the first version of a module's Tool API plugins from its scenarios.yml — design the smallest toolset that covers every scenario, create the companion module, then build each tool by following the tool module's own create-tool-plugins skill (drush generate plugin:tool, fail-closed access approved by the user, mandatory unit + kernel tests) and save the result as variant v01.
---

# Step 2: generate the first version of the tools

The tools are built **the tool module's way**. Its skill is the authority for how a single tool is
made; this skill adds only what is specific to the tool creator (designing a toolset from
scenarios, the companion module, saving the variant).

**Read the tool module's skill now, in full, before anything else:**

```
drupal/web/modules/contrib/tool/.agents/skills/create-tool-plugins/SKILL.md
```

(also linked as the `create-tool-plugins` skill) and the references it points to when its steps
call for them. Its hard requirements apply unchanged: generate with `drush generate plugin:tool`,
never hand-write a plugin; scan first; access is fail-closed until the user approves it; both
generated tests are mandatory; prefer a deriver over copy-paste. You already run inside the ddev
web container, so wherever it says `ddev drush ...` or `ddev exec ...`, drop `ddev`/`ddev exec`
and run the command from `drupal/` (`cd drupal && drush ...`, `vendor/bin/phpunit ...`).

## 1. Make sure the site holds the module and nothing else

```bash
tc site status            # should show your module, variant null
tc site reset --module <m>   # if not: filesystem + database back to the module baseline
```

## 2. Design the toolset from the scenarios (before generating anything)

Read `workspace/<m>/scenarios.yml`, `NOTES.md`, and `memory/LEARNINGS.md`. For each scenario,
write down the calls an agent would make, in order. Then merge those into a toolset and show it to
the user as a table: tool id, operation, one-line description, inputs, outputs, which scenarios
use it.

What makes a toolset work for agents, small ones especially:

- **Few, task-shaped tools.** One tool per thing a user wants done, not one per API method. A
  small model choosing between 4 tools does far better than between 15.
- **A way to look things up.** Whenever an input is an id, machine name, bundle or option, there is
  a `read`/`explain` tool that lists valid values, or the input carries an `enum`. Agents must
  never have to guess an id.
- **Scalar inputs over entity inputs.** `entity_type_id` + `entity_id` (or a label to look up)
  work from every invoker; entity-typed inputs do not pass through drush or MCP (the
  `use-drupal-tools` skill explains why).
- **Results that let the agent chain.** Return the id/uuid/url of what was created, and a message
  that says what happened in words.
- **Failures that say how to recover.** "No webform 'contact'. Existing webforms: contact_us,
  feedback" beats "Not found".
- **Descriptions written for the caller.** Say when to use the tool, not how it is implemented;
  mention the sibling tool to use first if one is needed. Input descriptions give the format and
  an example.

Use the generator's default plugin id, `<tool_module>_<label>` (e.g.
`redirect_tools_create_redirect`): it groups the tools by name, and the `drush generate plugin:tool`
in tool 1.0.0-beta12 rejects a `prefix:name` id as "not a correct machine name" even though the
attribute itself accepts one. Get the user's OK on the table.

## 3. Create the companion module

The module name is `tool_module` in `workspace/<m>/module.yml`. Generate it, do not hand-write it:

```bash
cd drupal
drush generate module --answer="<Module> tools" --answer="<tool_module>" \
  --answer="Tool API plugins for <Module>." --answer="Tools" --answer="<m>:<m>, tool:tool" \
  --answer="no" --answer="no" --answer="no"
```

The eight answers are: name, machine name, description, package, dependencies, then no `.module`,
no `.install`, no README. If the prompts differ in a later Drush, run it once with `--dry-run` and no
answers to see them. The `.info.yml` must depend on `tool:tool` and `<m>:<m>`. If the tools need their own permission (often a
`use <m> tools`-style permission is *not* needed; reusing the module's own permissions is better),
add `<tool_module>.permissions.yml`. Enable it: `drush en <tool_module> -y`.

## 4. Build each tool with the tool module's skill

For each tool in the agreed table, follow `create-tool-plugins` Steps 0–6 exactly: generate it with
`drush generate plugin:tool` (module answer = the companion module), fill in the contract and
`doExecute()`, **stop and get the user's approval for `checkAccess()`**, make both generated tests
real, verify.

Access is where the scenarios help: each scenario's `run_as` user and its `permissions` in
`scenarios.yml` say who is expected to be allowed. Present the permission(s) per tool together
with that, in one `AskUserQuestion` if you can, rather than one question per tool. If the user is
not available to approve, leave that tool fail-closed, say so, and know that every scenario that
needs it will fail in step 3 until it is approved.

Check the result as the agent will see it:

```bash
cd drupal && drush cr && drush tool:list --format=json
vendor/bin/phpunit -c web/core web/modules/custom/<tool_module>
```

Then smoke-test one real call per tool as a scenario user (see the `use-drupal-tools` skill):
`drush tool:run <id> --uid=<uid> --input='{...}' --json`.

## 5. Save it as v01

```bash
tc variant save <m> --note "first generated version: <n> tools"
tc variant install <m> v01     # proves it installs from the workspace and creates the scenario users
tc tools <m>                   # what the agent will see
tc scenarios check <m>         # now with the scenario users present
```

Append to `workspace/<m>/NOTES.md`: the toolset and why it has that shape, access decisions and
who approved them, anything about the module that surprised you. Next is step 3, `tc-simulate-agent`.
