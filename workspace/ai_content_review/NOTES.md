# ai_content_review: notes

What was learned while building tools for `ai_content_review`. Append dated entries; newest last.

## 2026-11-02 · step 1 (scenarios)

**Install.** Only alpha releases exist: `tc module prepare ai_content_review --composer
'drupal/ai_content_review:^1.0@alpha'` (1.0.0-alpha3). Pulls in drupal/ai 1.5, ai_agents 1.3,
entity, key, field_validation and more. The site has **no AI provider** and **no content types**
in the baseline; scenario setup creates `article` and `page` with a `body` field
(`node_add_body_field()` fails on this 11.4 site, so the field is created explicitly).

**No live reviews in evals.** Running a criterion calls an AI Agent on a real provider. Without
one the record ends `failed`. Scenarios therefore never run a review synchronously. Queueing is
measured instead: `ai_content_review.batch_enqueuer->enqueueForRule($rule, $limit)` creates a
`pending` record plus a queue item (`ai_content_review_batch_review`) per subject per criterion,
with no AI call. It covers only published entities, keeps a resume cursor in state per rule, and
`ITEMS_PER_PASS` is 10.

**Data model (what the tools must get right).**
- `review_rule` (config, `ai_content_review.rule.*`): `label`, `entity_type`, `bundles` (empty =
  all), `status`, `schedule_enabled`/`schedule_interval` (>= 300), and `criteria[]`. Each criterion
  is `plugin_id` (`agent_based`), `label`, optional `short_description` (<= 28), `acronym` (<= 3),
  `badge_color`, `pass_threshold`, `warn_threshold`, and `configuration` = `agent_id`,
  `prompt_template` (the "Guidelines & rules"), `execution_mode` (direct|polling),
  `provider_config`, `example_groups`.
- `agent_id` must name an `ai_agent` whose tools include
  `ai_content_review:content_review_result` (the UI only offers those:
  `plugin.manager.ai_agents->getAgentsByTool()`). ai_agents ships three agents without it; the
  scenarios add "Editorial reviewer" and "SEO reviewer" with it.
- **A criterion's identity is its input hash**: sha256 of its sorted `configuration`
  (`ai_content_review.input_hasher`). `review_record.criterion_id` is just the plugin id
  (`agent_based`) for every criterion, so records are matched to criteria by `input_hash`.
  Editing anything inside `configuration` orphans the criterion's existing records. Label,
  acronym and thresholds are siblings of `configuration` and do not.
- Grade (`GradeEvaluator::grade`): score >= pass → pass; else score >= warn (if set) → warn; else
  fail; no pass threshold or no score → none. The summary builder calls these tiers good, warning,
  critical and none.
- Latest result per criterion = record with MAX(id) per `input_hash` for the subject
  (`ai_content_review.record_loader->loadLatestForSubject()`).
- `review_record` and `suggestion` are append-only for users: the access handler and
  `hook_entity_access` deny update and delete. Triage is written through the entity setters, as
  `SuggestionDecisionController` does: `setDecision('accepted'|'ignored'|'undecided', $uid)`,
  `setCheckedOff($uid)` / `clearCheckedOff()`.
- Applying a suggestion: `plugin.manager.review_record_type->createInstance('internal')
  ->applySuggestion($entity, $suggestion)` (writes the value **and saves the entity**), then
  `$suggestion->setApplied($uid)->save()`. The controller requires `update` access on the entity
  and refuses an already-applied suggestion.
- Enable/disable a rule: `$rule->enable()/disable()->save()`. Saving a rule marks the router for
  rebuild (the per-entity Review tab routes are dynamic).

**Permissions.** `configure reviews` (restricted, also the rule entity's admin permission),
`run reviews`, `view review records` (plus view access to the reviewed entity), `view review
history`, `run batch reviews` (restricted: spends provider budget), `view review queue`.

**Scenario users.** `sitebuilder`: configure + batch + view queue + run + view records + history +
`access content`. `editor`: run reviews, view records, history, `access content`, `edit any
article content` (needed to apply a suggestion). Neither is admin, so access bugs show up.

**The permission scenario is answer-only.** `editor-cannot-change-rules` has no state check:
`tc scenarios check` rejects any check that already passes before the agent acts, and "the rule
is unchanged" always does. If a tool let the editor change the rule, the agent would report
success, and `answer_matches` (a refusal) would fail.

## 2026-11-02 · Toolset ground rules (agreed with the user before step 2)

Every variant must keep to these. The reiterate loop may tune wording and shape, never these.

1. **No fabricated results.** No tool creates or edits a review record's status, score,
   severity, explanation or suggestions. Only the module's orchestrator and batch enqueuer make
   records; tools call those services, never `review_record`/`suggestion` storage `create()`.
2. **No synchronous review runs.** No tool calls the AI provider in the request
   (`orchestrator->runCriterion()`/`runForSubject()`). Reviews are started by queueing only
   (`batch_enqueuer`), gated by `run batch reviews`, because every queued review spends provider
   budget. Revisit only if the user asks for live runs on a site with a provider.
3. **Records and suggestions stay append-only.** No update or delete tools for them. Triage goes
   through the entity setters with the current user's uid (`setDecision()`, `setCheckedOff()`),
   as `SuggestionDecisionController` does. Applying goes through the record type plugin's
   `applySuggestion()` + `setApplied()`, needs `update` access on the reviewed entity, and
   refuses an already-applied suggestion.
4. **Say when an edit resets history.** Changing a criterion's `configuration` (agent,
   guidelines, examples, execution mode, provider) gives it a new input hash and orphans its
   records; label, acronym, short description, badge color and thresholds do not. Tools keep
   the two kinds of edit apart, and a result says plainly when existing results no longer count.
5. **Valid config only.** Rule writes run the config-schema validation
   (`getTypedData()->validate()`) before saving and return the violations as readable messages.
   A criterion's agent must carry `ai_content_review:content_review_result`; a tool lists the
   eligible agents so the agent id is looked up, not guessed.
6. **Access mirrors the module, fail-closed until the user approves it.** Rule writes need
   `configure reviews`; reads need `view review records` plus view access to each reviewed
   entity (entity queries with `accessCheck(TRUE)`); triage needs `run reviews`; applying also
   needs `update` on the entity; queueing needs `run batch reviews`.
7. **Do the module's math for the agent.** Read tools return the latest record per criterion
   (MAX id per input hash) with the grade already computed by `GradeEvaluator`
   (pass / warn / fail / not reviewed), so a small model never compares scores to thresholds
   itself.
8. **Human names in, ids out.** Tools accept what an editor says (rule label, criterion label,
   node title) or ids, and return both, so the agent can chain without guessing machine names.
9. **Smallest toolset that covers the scenarios.** No tools for things no scenario needs
   (deleting rules, schedules, example groups, external reviews) in v01; add them only with a
   scenario that measures them.

## 2026-11-02 · Step 2: toolset and access (approved by the user)

Ten task-shaped tools, ids `ai_content_review_tools_<name>`: list_review_rules, create_review_rule,
add_review_criterion, update_review_criterion, set_review_rule_status, list_review_results,
get_content_review, decide_suggestion, apply_suggestion, queue_reviews. Rule, criterion, agent and
content inputs take a name or an id; failed lookups list the valid values. The user considered
merging 2+3 and 8+9 and kept them separate.

Access, approved by the user on 2026-11-02 (only existing module permissions):
- rule writes (create/add/update/status): `configure reviews`
- list_review_rules: `configure reviews` OR `view review records`
- list_review_results, get_content_review: `view review records`, and only content the user can view
- decide_suggestion, apply_suggestion: `run reviews` + update access on the reviewed entity's default
  revision, on its latest record only (the module's `canApplySuggestions()`)
- queue_reviews: `run batch reviews`

Module quirk: `text_replace` apply sets the whole target field to `suggested_value`, even when
`original_value` is one sentence of a longer body. The tools use the module's apply unchanged.

## 2026-11-02 · Step 2 done: v01

- **Shape.** Ten generated tools plus one hand-written service, `ReviewToolsHelper`
  (`ai_content_review_tools.helper`). The helper does every lookup by name or id: exact match,
  then a unique substring match; a failed lookup lists the valid values. It also builds and
  validates criteria the way `ReviewRuleForm` stores them (so input hashes match UI-made
  criteria), and computes graded results. Permission-only tools declare `permission:` in
  `#[Tool]` and do not override `checkAccess()`. Decide and apply override it to require
  `update` on the reviewed entity, as approved.
- **Walkthrough.** Every scenario passes when its tool calls are made by hand as the scenario
  user (driver: the scratchpad's `walk.py`, not kept). The editor's attempt to change a threshold
  is refused with "The 'configure reviews' permission is required."
- **Tests.** 75 unit and kernel tests are green, and phpcs (Drupal, DrupalPractice) is clean.
  phpstan has not run yet (step 5). Kernel tests extend `tests/src/Kernel/ReviewToolsKernelTestBase`;
  unit tests use `ToolAccessTestTrait`, which reads the real `#[Tool]` definition.
- **Scenario fix before any eval.** `edit any article content` moved out of `users.editor` and
  into the `&site` setup (granted to role `tc_editor`): the harness creates users before setup has
  created the article type, so the permission did not exist yet. No results are affected.
- **Agent-visible text.** Tool messages use `@` placeholders, which HTML-escape quotes and `&`.
  Both drush and tool_ai_connector pass messages through `PlainTextOutput::renderFromHtml()`, so
  agents see plain text. Kernel tests assert on that rendering too (the `message()` helper).

## Step 3: simulate the agent (2026-11-02)

Run `20261102-180008-v01-baseline-ollama`: v01, `fc:ollama/qwen3-coder:30b` only (the user chose
to run only on local Ollama, so there is no strong-model ceiling run), 2 repeats.
**67/70: train 94%, holdout 100%**, 1.7 calls per trial on average.

| Failure | What happened | Diagnosis | Goes to |
|---|---|---|---|
| triage-suggestions/terse r2 (1 of 2 failed) | "accept the headline suggestion" -> called `apply_suggestion`, so the title changed | Wrong tool: the apply description does not say it is not the way to accept | step 4 |
| triage-suggestions/chatty (2 of 2 failed) | "The new headline idea is good, I'll take it later" was left undecided on purpose; the model said "you wanted to consider it later" | Prompt is ambiguous: "I'll take it later" also reads as "decide later" | step 1, with the user's agreement |

Other observations (not failures):
- disable-rule/chatty: guessed "Legal review" first; the failure message listed the existing
  rules and the model picked "Legal compliance" on the next call. Recovery messages work.
- The model sometimes sends booleans and integers as strings (`"False"`, `"75"`). Both
  `drush tool:run` and the harness coerce them correctly (a probe with "False", "false", "0" all
  disabled the rule).
- 32 tool calls were written as text and recovered by the harness: a qwen3-coder/Ollama quirk. A
  stricter runtime would have lost them; nothing in the tools causes it.
- editor-cannot-change-rules: denied by access in every trial; the model reports it correctly.

## Step 4: reiterate (2026-11-02)

The target was qwen3-coder:30b on local Ollama, at repeats 3, for 3 loops; see ITERATIONS.md. The
best variant is v04 (105/105). v02 to v04 change only agent-facing text: two descriptions, one
input description, the next-step line in get_content_review's result, and decide_suggestion's
"already X; nothing changed" message. Access, the destructive flags, scenarios.yml and tool shapes
are unchanged. There are still 75 tests (778 assertions); phpcs is clean.

## Loops 4-6 (2026-11-03)

These loops targeted gemma4:e2b, with qwen3-coder:30b as the guard; see ITERATIONS.md. The best
variant is **v05**: v04 plus closest-match hints on failed rule and content lookups (helper
`closestNames()`; two new kernel tests, 77 tests in total). v06 (a more literal retry hint) was
dropped at eval, and loop 6 (look up the rule first) was dropped at the quick check. The site
holds v05.

## Loops 7-9 (2026-11-03)

Best variant is now **v08** (gemma4:e2b 101/105, qwen3-coder:30b 105/105); the site holds v08.
- v07 (kept): `findContent()` takes `$accept_contained_title`; only get_content_review sets it. A
  request with exactly one viewable title inside it ("Summer sale review") shows that item, and
  the header says "the closest match to ... (no content has that exact title)".
- v08 (kept): `findRule()` retries without "review(s)"/"rule(s)" when nothing matches, still
  unique-only, so "Legal review" disables "Legal compliance" and "Article review" with two
  "Article ..." rules still fails. This applies to all four tools that take a rule.
- v09 (dropped): plural content types ("articles"). Harmless but not measurable.
- gemma's answer still names the rule the way the user did ("disabled the 'Legal review' rule"),
  although the tool's result names "Legal compliance".

## Loops 10-12 (2026-11-03)

All three were dropped; v08 stays the best and is on the site. Paraphrase probes (27 of my own
requests, `tc try --prompt`) passed 24/27 on v08; the misses were triage (two) and a create-rule
request where gemma left out content_types (a rule for every type).
- Making content_types required (v10) made the UI-worded holdout fail 3/3: whatever that prompt
  says, gemma fills the field badly when forced.
- Content types written with extra words ("Article content type", "node:article", "Articles")
  are rejected by v08's lookup; v11 accepted them, but that did not move the holdout.
- New pattern seen once (v11 run, loosen-threshold/terse): the prompt names only the criterion
  ("tone & voice pass mark -> 75") and gemma passed "Tone & voice" as the *rule*. If it recurs: when
  no rule matches but exactly one rule has a criterion of that name, use that rule.
- Harness: `tc try` reads the catalog cached at install. To quick-check a description or schema
  edit, rebuild the catalog from the site first (a script for that was used in loop 11).

## Loops 13-16 (2026-11-04): cost and input design

- The holdout create-rule/ui-words is the prompt most sensitive to catalog wording: it failed
  2/3, 2/3 and 3/3 with shorter descriptions (v13, v14), even when only the *other* tools were
  shortened. Loops 11 and 12 also moved it. Treat a change to any description as able to move it.
  Correction (2026-11-04, loop 21 prep): loop 12's catalog was identical to v08's (code-only), and
  on v08's catalog the prompt fails about 1 in 3 in every eval (11 of 33 trials over 11 evals).
  Small catalog edits (loops 13, 16) passed it 3/3; only large rewrites (loops 14, 15, 17, 18)
  pushed it to 0-1/3. In every eval each trial makes one create_review_rule call; the failing
  ones are a tool failure ("failed", not invalid input) with no retry (from the aggregate counts).
- With no rule in the prompt ("set tone & voice pass score to 70", "tone & voice pass mark -> 75"),
  gemma4:e2b either looks the rule up (because list_review_rules says "Call it first"), asks the
  user (when that line is gone), or sends the criterion name as the rule and stops (about 1 in 12
  evals). v15 handles the last two in update_review_criterion; it is in variants/ if that failure
  ever becomes common.
- Cost per task on v08: gemma about 6.7k input tokens and 1.44 calls, qwen 8.6k and 1.65; about
  2.5k tokens per gemma turn are the fixed system prompt + catalog.
- qwen3-coder writes 38-49 tool calls per 105 trials as text (recovered by the fc loop, not by a
  real Drupal AI agent). In loop 13 two of its triage trials lost a call this way (a text call next
  to a native one is not recovered). This is the model/runtime, not the tools.

## Loops 17-20 (2026-11-04): order, names, ideas needing access

- Tool order: the catalog is sorted by id in the harness; a real agent sees its configured list
  in the order it was saved. A grouped order (rule tools with their lookup first, decide before
  apply) cost gemma 4 trials and qwen 2 against alphabetical. Keep the default order, or re-tune
  if a site lists the tools differently.
- Ideas that need the user's access approval (not run): one call for several decisions
  (`accepted: [ids]`, `ignored: [ids]`), apply by title + field without a lookup, one merged
  suggestion tool. decide_suggestion's and apply_suggestion's checkAccess() load the suggestion
  from `suggestion_id` and require update access to its content; each idea needs that check
  rewritten for a list or a title. The same rule per suggestion would keep access unchanged.
- Site settings for the final report: ai_agents can force and hide an input per agent
  (`tool_usage_limits` force_value + hide_property, e.g. entity_type = node) and override tool and
  input descriptions per agent (`tool_settings`).


## Loops 21-23 (2026-11-04): module terms, admin UI names, leaner catalog

- An accepted suggestion goes on the content's **improvement plan** (`ImprovementPlanInterface`):
  the checklist of accepted suggestions the editor works through later on the edit form; ticking
  one off marks it done. Describing accepting that way ("puts it on the improvement plan, the
  list of changes the editor makes later") is accurate and is what moved triage/chatty on gemma.
- The admin UI's words for a rule (ReviewRuleForm, AgentBasedCriterion): Name, Reviews (site
  content), Entity type ("Content" = node), Entity subtypes (= content types), Criterion type
  ("Agent-based criterion"), AI agent, Guidelines & rules, Passing score, Warning score, Short
  description, Acronym. Both the rule and the criterion are called "Name".
- create_review_rule failures an admin's wording can cause, found by direct calls (loop 22, not
  kept): ["Content", "Article"], "Content: Article", "Article (Content)", entity_type "Content",
  "Editorial reviewer agent", "AI agent: Editorial reviewer". Loop 22's code (v19) accepts them all,
  unique only, with tests; it did not fix the ui-words holdout, so it was dropped, but the code is
  a sound robustness change to take into step 5 if wanted (it costs no catalog text when the two
  description lines are left out). Correction 2026-11-05: loop 25 measured exactly that (v21, code
  only, catalog identical to v20) and ui-words passed 1/3; with the code 1 of 6 over v19 and v21,
  without it 3 of 6 over v18 and v20. Too few to say it hurts, but no sign it helps, so it is not
  a default for step 5 any more; ask the user.
- Unknown inputs (e.g. "criterion_type") make `drush tool:run` print a plain error, which the
  harness types "crash", not "failed".

## Loops 24-26 (2026-11-05)

- The module's own UI never shows an editor the word "undecided": the suggestion review counts
  open suggestions as "@count to review" (ai-review-suggestion-review.html.twig); "undecided" is
  only the API value (SuggestionInterface::DECISION_UNDECIDED, also the POST value that resets a
  decision).
- What the agent reads from get_content_review is the message plus every output as JSON
  ("Output for criteria: [...]", as tool_ai_connector renders it): about 2.4k characters on the
  Summer sale article, of which the JSON repeats the message. Changing a word in the message
  (loop 24 A) leaves `"decision": "undecided"` in the JSON, three times.
- triage/chatty on v20 passes about 60% of the time (9/15 in a `tc try` screen). The failures: gemma
  reads "I'll take it later" as "leave it undecided" (4 of 6), or makes one decide call and claims
  both (2 of 6); in 5 of 6 it decided the imperative one ("please dismiss that one") first and
  then stopped. Neither the read result (an "open" status, an improvement plan line) nor the write
  result ("call again for each other suggestion") raised it in screens of 15.
- A one-off seen in loop 25 (loosen-threshold/vague, 47 of 48 evals 100% before): gemma passed the
  user's "Tone checker" as `rule` to update_review_criterion, with the right criterion "Tone &
  voice"; the rule lookup failed and it claimed success without retrying. The criterion alone was
  unique (findCriterionInAnyRule), so a write that resolves a unique criterion when the rule name
  does not match would have passed. Not tried: it occurred once, and writes are kept strict.

## Diagnosis with gemma's reasoning (2026-11-05)

The harness now keeps a model's private reasoning in each trial's transcript.json ("thinking" on the
assistant message; `toolcreator/agents/fc.py`). gemma4:e2b reasons before every turn because Ollama
turns thinking on by default for it; the user wants evals in this base mode (temperature and
thinking as the model ships), so nothing about the model's settings changed, only the recording.

triage/chatty on v20, 15 trials with reasoning: 9/15 again. The 6 failures:
- 3 misread "I'll take it later" as "don't touch it now" (r2, r3) or even "ignore it" (r15).
- 3 had the right reading and planned the call, then never sent it (r4, r12, r14). r14's last
  reasoning quotes decide_suggestion's description ("Accepting puts it on the improvement plan,
  the list of changes the editor makes later ... also when they say it is good and they will use
  it later") and ends "I will now call ai_content_review_tools_decide_suggestion for suggestion ID
  1 with the decision accepted"; the turn then returns a text answer claiming it, with no call.
- Calls per turn decide it: 8 of the 9 passes sent both decide calls in the same turn; of the 5
  trials that sent one decision and waited for its result, 1 passed. After a decide result, gemma
  tends to write its summary next, whatever its reasoning planned.

claude:sonnet on the same prompt and tools: 10/10, always "accepted" for the headline and
"ignored" for the passive voice. The prompt is readable as intended; earlier notes calling it
"genuinely ambiguous" overstated that (qwen3-coder misread it once in 15 evals).

## Loop 28 (2026-11-05): batch decide, access approved, not kept

- **Access, approved by the user on 2026-11-05** for a decide_suggestion that takes several
  suggestions: "run reviews" plus `update` access on the reviewed content of every suggestion in
  the call; one suggestion on content the user may not edit denies the whole call. Unknown ids are
  left to doExecute() as before. Built and tested in v22 (`decisions: [{suggestion_id, decision}]`,
  all or nothing: an unknown or superseded id fails the call with nothing changed). v22 was not
  kept, so v20's one-suggestion contract and access stay the default.
- The module's UI labels the button "Ignore" (templates/ai-review-suggestion-card.html.twig), so
  "ignored" stays the decision word even though gemma reads it as "leave alone".
- What gemma does with a batch shape, from 180 screen trials of the list-of-objects shape: it
  sends one decide call (176 of 180), it gives
  every suggestion a value, and "leave the rest" becomes "ignored" (terse 1/3 in the v22 eval,
  6/10 in the screen); "don't touch the other suggestion" (chatty) sometimes did the same.
