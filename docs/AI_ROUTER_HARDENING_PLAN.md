# AI Router Hardening Plan

## Goal

Make `ai_router` the stable default route for supported store tasks without removing the existing deterministic safety rails.

Success means:

- The majority of supported task requests resolve through `ai_router`.
- Deterministic rules win only for explicit override cases, hard safety guards, or AI unavailability.
- Clarification prompts are precise and tied to actually missing information.
- Write actions route correctly but still require preview or confirmation in backend runtime.
- Unsupported capability responses stop inventing routes and consistently stay inside the declared product surface.

## Current Contract

Primary runtime path today:

1. `YoRo_Parser_Engine::parse()`
2. `YoRo_AI_Command_Router::route()`
3. `YoRo_Command_Decision_Normalizer::normalize()`
4. Parser bridge hydration and clarification handling
5. `YoRo_Action_Planner::build_plan()`
6. `YoRo_Plan_Semantic_Validator::validate()`
7. `YoRo_Runtime_Guard::prepare()`
8. `YoRo_Plan_Executor::execute()`

The contract already exists, but the route is not yet cleanly dominant because parser flow still includes:

- workflow continuation shortcuts
- action composition before and after router
- conversational-only routing
- rule fallback on AI failure
- hardening guards and direct heuristics

That is acceptable, but each non-router path must be classified as either deterministic override, recovery fallback, or safety guard.

## Workstreams

### 1. Catalog Hardening

Files:

- `includes/actions/class-yoro-action-registry.php`
- `includes/actions/class-yoro-action-catalog-builder.php`
- `includes/actions/class-yoro-orders-actions.php`
- `includes/actions/class-yoro-workflow-actions.php`

Actions:

- Treat the action catalog as the router-facing API contract, not as optional metadata.
- Require every `ai_exposed` action to provide:
  - `ai_description`
  - `required_params`
  - `param_hints`
  - `clarification_hints`
  - `ai_examples`
- Reduce semantic overlap between nearby actions by tightening descriptions and examples.
- Audit actions that are currently infering `required_params` from builder defaults and move them closer to action registration where practical.
- Add a debug-only audit helper that reports incomplete AI metadata for `ai_exposed` actions.

Acceptance criteria:

- High-value actions no longer rely on generic descriptions only.
- Router-facing actions have explicit required-parameter metadata.
- Commonly confused surfaces such as briefing, summary, insights, compose, save draft, and send have distinct examples and descriptions.
- A developer can run one audit and immediately see which actions are unsafe to expose to the router.

### 2. Router Decision Contract Tightening

Files:

- `includes/ai/class-yoro-ai-command-router.php`
- `includes/ai/prompts/class-yoro-ai-command-router-prompt.php`
- `includes/ai/normalizers/class-yoro-command-decision-normalizer.php`

Actions:

- Keep prompt responsibilities high-level: mode selection, safety behavior, language behavior, and core semantic distinctions.
- Move action-specific facts into the catalog whenever possible.
- Review whether global `params` schema is causing avoidable cross-action ambiguity.
- Log and inspect params emitted by the model that get trimmed away during normalization.
- Preserve strict action whitelist behavior.
- Preserve strict non-invention behavior for unsupported and out-of-scope cases.

Acceptance criteria:

- Router does not select actions outside the catalog.
- Router does not regularly emit irrelevant params for the chosen action.
- Clarification responses are tied to declared missing params rather than vague low-confidence copy.
- Prompt changes become rarer because action semantics live mostly in catalog metadata.

### 3. Fallback Taxonomy and Parser Control Flow

Files:

- `includes/parsing/class-yoro-parser-engine.php`
- `includes/parsing/class-yoro-memory-command-parser.php`
- `includes/parsing/class-yoro-email-command-parser.php`
- `includes/execution/class-yoro-action-composition-planner.php`
- `includes/workflows/class-yoro-workflow-candidate-resolver.php`

Actions:

- Classify every non-router resolution path into one of:
  - `deterministic_override`
  - `recovery_fallback`
  - `safety_guard`
- Add a compact `resolution_path` field to parsed results and runtime logs.
- Make exact-match and active-workflow continuations explicit deterministic overrides.
- Keep rule fallback only for defined AI-unavailable or AI-invalid states.
- Prevent silent drift where a new heuristic becomes a shadow primary router.
- Review whether pre-router action composition should remain ahead of router or should become a deterministic post-router refinement only.

Acceptance criteria:

- Every successful parse can explain why it did not use `ai_router` directly.
- New heuristics cannot silently become primary routing behavior.
- AI-unavailable fallbacks are observable and measurable.
- The team can inspect a log row and know whether the result came from primary AI routing, deterministic override, or recovery fallback.

### 4. Evaluation Loop From Real Traffic

Files:

- `includes/core/class-yoro-logger.php`
- `includes/rest/class-yoro-rest-run-handler.php`
- `includes/rest/class-yoro-rest-run-log-helper.php`
- `includes/dev/class-yoro-parser-test-suite.php`

Actions:

- Build a labeled eval set from production-like logs and frequent commands.
- Track at minimum:
  - expected mode
  - expected action
  - expected minimum params
  - whether clarification is expected
  - whether preview or confirmation is expected
- Split evals into:
  - happy-path reads
  - concrete writes
  - incomplete writes
  - follow-up references
  - unsupported capability questions
  - out-of-scope chat
- Keep deterministic fixture tests, but add a smaller curated real-language eval pack that reflects live usage.

Acceptance criteria:

- Router quality is measured against a stable eval set, not just anecdotal smoke tests.
- The team can see whether a prompt or catalog change improved or degraded route quality.
- Top recurring clarifications and unsupported requests are visible in one place.

### 5. Telemetry and Observability

Files:

- `includes/core/class-yoro-logger.php`
- `includes/ai/class-yoro-ai-request-tracker.php`
- `includes/rest/class-yoro-rest-runtime-log.php`
- `includes/admin/class-yoro-admin-page.php`

Actions:

- Add telemetry fields for:
  - `resolution_path`
  - `router_mode`
  - `catalog_action_count`
  - `params_trimmed_count`
  - `clarify_reason`
  - `fallback_reason`
- Normalize `clarify_reason` into a small set such as:
  - `missing_target`
  - `missing_status`
  - `missing_note`
  - `missing_instruction`
  - `ambiguous_reference`
  - `low_confidence`
- Surface router health in admin telemetry view:
  - `ai_router` usage rate
  - fallback rate
  - confirmation rate for AI-routed writes
  - unsupported request frequency
  - top clarification reasons

Acceptance criteria:

- Router regressions can be spotted from telemetry without reading raw logs.
- The team can distinguish prompt problems from catalog problems from runtime safety escalations.
- Clarification load is measurable and reducible over time.

## Implementation Order

### Phase 1

- Catalog audit and metadata hardening
- Telemetry fields for route-path visibility
- Small fallback taxonomy refactor in parser engine

### Phase 2

- Real-traffic eval pack
- Router prompt and schema cleanup based on measured failures
- Param-trimming instrumentation and review

### Phase 3

- Reduce fallback surface where data shows router is already reliable
- Tighten deterministic override list
- Revisit action composition placement relative to router

## Definition Of Done

This hardening phase is complete when all of the following are true:

- `ai_router` is the dominant path for supported task requests.
- Deterministic rule ownership is narrow, documented, and observable.
- Clarification prompts are mostly caused by real missing inputs rather than route uncertainty.
- Unsupported requests consistently return product-surface-aware responses instead of weak guesses.
- Router changes are evaluated against a repeatable command set before rollout.
- Admin telemetry can answer why a route succeeded, clarified, fell back, previewed, or required confirmation.

## Implementation Checklist

### A. Action Catalog Contract

`includes/actions/class-yoro-action-registry.php`

- [ ] Add a debug-only registry audit helper for `ai_exposed` actions.
- [ ] Define the minimum AI metadata contract in one place so audits and builders use the same rules.
- [ ] Report missing `ai_description`, `required_params`, `ai_examples`, `param_hints`, and `clarification_hints`.

`includes/actions/class-yoro-action-catalog-builder.php`

- [ ] Add catalog-level audit output for incomplete action metadata.
- [ ] Add optional debug fields that expose whether `required_params` came from explicit config or builder inference.
- [ ] Review inferred required-param map and shrink it to legacy fallback only.

`includes/actions/class-yoro-orders-actions.php`

- [ ] Fill explicit AI metadata for high-traffic actions first:
  - `orders.search`
  - `orders.get_details`
  - `orders.update_status`
  - `orders.hold`
  - `orders.add_note`
  - `orders.compose_customer_email`
  - `orders.save_customer_email_draft`
  - `orders.send_customer_email`
  - `store.get_morning_briefing`
  - `store.get_daily_briefing`
  - `store.get_business_summary`
  - `operations.get_summary`
  - `operations.get_attention_queue`
  - `operations.get_fulfillment_queue`
- [ ] Add bilingual examples for each high-traffic action.
- [ ] Add clarification hints that name the missing business input instead of an internal field.

`includes/actions/class-yoro-workflow-actions.php`

- [ ] Add explicit AI metadata for preview workflow entry actions.
- [ ] Make queue-preview actions clearly distinguishable from reporting and summary actions.
- [ ] Add examples that teach the router when to ask the user to open a queue first.

### B. Router Decision Contract

`includes/ai/class-yoro-ai-command-router.php`

- [ ] Record normalized router mode and selected action in a debug-friendly structure before parser bridge hydration.
- [ ] Capture when router output is valid JSON but downgraded later for clarification.
- [ ] Capture when model params are trimmed away during normalization.

`includes/ai/normalizers/class-yoro-command-decision-normalizer.php`

- [ ] Add optional debug metadata for dropped params and unknown raw fields before filtering.
- [ ] Add a helper that returns `params_trimmed_count`.
- [ ] Keep action whitelist failure as a hard error.
- [ ] Review whether action-scoped param validation can be introduced without changing the external runtime contract.

`includes/ai/prompts/class-yoro-ai-command-router-prompt.php`

- [ ] Reduce action-specific policy text if the same information now exists in catalog metadata.
- [ ] Keep only stable routing policy in prompt:
  - mode semantics
  - safety behavior
  - language behavior
  - briefing vs summary vs insights distinctions
- [ ] Avoid moving more action facts into prompt unless they truly apply platform-wide.

### C. Parser Control Flow And Fallback Taxonomy

`includes/parsing/class-yoro-parser-engine.php`

- [ ] Add `resolution_path` to all major parsed return shapes.
- [ ] Add a small internal taxonomy:
  - `ai_primary`
  - `deterministic_override`
  - `recovery_fallback`
  - `safety_guard`
- [ ] Mark current workflow continuation as deterministic override.
- [ ] Mark AI-unavailable rule route as recovery fallback.
- [ ] Mark ambiguity and hardening clarifications as safety guard.
- [ ] Review whether pre-router action composition should remain ahead of router or move behind it.
- [ ] Refactor route-order comments to match the taxonomy so future edits do not create silent shadow routers.

`includes/execution/class-yoro-action-composition-planner.php`

- [ ] Decide which composition flows are deterministic enough to remain before router.
- [ ] For AI-assisted composition, expose whether the result came from AI decomposition, rule merge, or workflow continuation.
- [ ] Emit a machine-readable reason when composition returns clarification for `note` or `instruction`.

`includes/parsing/class-yoro-email-command-parser.php`

- [ ] Add explicit clarify reasons for missing email instruction, subject, or body.
- [ ] Ensure workflow continuation paths preserve the same `resolution_path` vocabulary.

`includes/workflows/class-yoro-workflow-candidate-resolver.php`

- [ ] Tag workflow promotion results so logs can distinguish direct router action selection from post-router workflow promotion.

### D. Runtime Logging And Telemetry

`includes/rest/class-yoro-rest-run-handler.php`

- [ ] Include `resolution_path`, `router_mode`, and `params_trimmed_count` in runtime log context when available.
- [ ] Add a normalized `clarify_reason` field to clarify responses.
- [ ] Add a normalized `fallback_reason` field when parser leaves `ai_primary`.

`includes/rest/class-yoro-rest-runtime-log.php`

- [ ] Extend runtime context builder to preserve new router telemetry fields.
- [ ] Keep field names stable and short so they are easy to aggregate later.

`includes/core/class-yoro-logger.php`

- [ ] Persist `resolution_path`, `router_mode`, `clarify_reason`, `fallback_reason`, and `params_trimmed_count` inside telemetry.
- [ ] Surface these fields even when execution does not reach final success.
- [ ] Keep null-safe behavior for older rows and mixed-schema environments.

`includes/ai/class-yoro-ai-request-tracker.php`

- [ ] Optionally record router sub-operations separately from downstream AI operations such as decomposition or compose-email.
- [ ] Preserve a compact summary so admin telemetry stays readable.

`includes/admin/class-yoro-admin-page.php`

- [ ] Add router health summary cards:
  - AI primary route rate
  - fallback rate
  - clarification rate
  - unsupported request count
  - top clarify reasons
- [ ] Add a small table for top fallback reasons and top clarify reasons.

### E. Evaluation And Regression Control

`includes/dev/class-yoro-parser-test-suite.php`

- [ ] Add a dedicated router hardening test group separate from generic parser fixtures.
- [ ] Add coverage buckets:
  - happy-path reads
  - concrete writes
  - incomplete writes
  - follow-up references
  - unsupported requests
  - out-of-scope requests
- [ ] Add assertions for `resolution_path` where behavior is intentional.
- [ ] Add assertions for `clarify_reason` in known missing-param cases.

`docs/AI_ROUTER_MIGRATION.md`

- [ ] Keep only the migration contract and current-state notes here.
- [ ] Link to this hardening plan for implementation detail.

`docs/AI_ROUTER_HARDENING_PLAN.md`

- [ ] Update progress by phase instead of expanding ad hoc notes elsewhere.
- [ ] Keep the checklist ordered by file ownership so implementation remains incremental.

### Suggested First Slice

If the team wants the smallest high-leverage implementation slice, do this first:

- [ ] Add `resolution_path` in `class-yoro-parser-engine.php`.
- [ ] Add telemetry persistence for `resolution_path` in `class-yoro-logger.php` and runtime context builders.
- [ ] Add a registry audit helper for incomplete AI metadata.
- [ ] Enrich AI metadata for the top 10-15 high-traffic actions.
- [ ] Add 1 router hardening test group that asserts route-path and clarify-reason behavior.

That slice should make the next prompt or catalog iteration measurable instead of subjective.
