# AI Router Multilingual Architecture Roadmap

## Goal

Support store-operation commands across all languages by making:

- `ai_router` the only deep natural-language understanding layer
- backend routing logic language-agnostic
- fallback logic state-driven instead of phrase-driven

This roadmap does **not** replace [AI_ROUTER_HARDENING_PLAN.md](AI_ROUTER_HARDENING_PLAN.md). It builds on top of it.

The hardening plan already gave the system:

- route taxonomy
- telemetry
- eval loops
- prompt/catalog controls
- observable fallback behavior

Those remain necessary. This roadmap changes **semantic ownership**, not the usefulness of the hardening work.

## Problem Statement

Right now the system has two different kinds of intelligence:

### 1. Language-agnostic layers

These layers already scale well:

- action catalog
- planner
- semantic validator
- runtime guard
- executor
- telemetry schema

They operate on normalized actions and params, not on user phrasing.

### 2. Language-dependent layers

These layers still contain too much English-centric understanding:

- semantic fallback in [class-yoro-parser-engine.php](../includes/parsing/class-yoro-parser-engine.php)
- workflow continuation and follow-up parsing in [class-yoro-email-command-parser.php](../includes/parsing/class-yoro-email-command-parser.php)
- action-composition clarify flows in [class-yoro-action-composition-planner.php](../includes/execution/class-yoro-action-composition-planner.php)
- semantic examples in [class-yoro-ai-command-router-prompt.php](../includes/ai/prompts/class-yoro-ai-command-router-prompt.php)
- regression coverage in [class-yoro-parser-test-suite.php](../includes/dev/class-yoro-parser-test-suite.php)

That is why multilingual quality degrades exactly when the route leaves `ai_primary`.

## Architecture Target

The target architecture is:

- AI understands language.
- Backend understands contract.
- Fallback understands state.
- Telemetry understands quality.

This is the only sustainable model for all-language support.

## What Stays

The following parts of the current system remain valid and should be preserved:

### Route taxonomy

- `ai_primary`
- `deterministic_override`
- `recovery_fallback`
- `safety_guard`

### Router contract discipline

- strict action whitelist
- strict param normalization
- clarify reasons
- fallback reasons
- trimmed-param telemetry

### Observability

- router health telemetry
- eval pack framework
- resolution-path logging
- admin insight surfaces

These are foundation layers, not English-only features.

## What Must Change

### 1. Semantic ownership must move upward into `ai_router`

Parser fallback should stop acting like a multilingual intent classifier.

Instead:

- `ai_router` should own natural-language understanding across languages
- parser fallback should only recover from known protocol/state situations

### 2. Fallback must become state-first, not phrase-first

Current fallback still tries to infer intent from lexical phrasing.

That approach cannot scale to all languages.

Fallback should primarily handle:

- active workflow continuation
- confirmation / cancel / replay
- deterministic entity references
- missing-slot collection
- stale-context recovery

Fallback should **not** be the main place that interprets business-summary, briefing, queue, or write-intent semantics across arbitrary languages.

### 3. Semantic fallback should be minimized

Some semantic rescue paths may remain for resilience, but only as narrow, low-risk helpers.

They should:

- depend more on state/context than raw phrasing
- be easy to remove or demote later
- never become a shadow multilingual parser

### 4. Routing needs an intermediate language-agnostic representation

The system should introduce or strengthen an `intent_family` layer such as:

- `store_briefing`
- `business_summary`
- `operations_overview`
- `operations_attention`
- `fulfillment_review`
- `customer_service_review`
- `update_order_status`
- `add_note`
- `customer_email`

The model maps language -> `intent_family`.
The backend maps `intent_family` -> action/workflow/clarify path.

That is much more scalable than backend phrase parsing.

## Architecture Changes

### A. Introduce Intent-Family Routing Contract

Files:

- `includes/ai/class-yoro-ai-command-router.php`
- `includes/ai/normalizers/class-yoro-command-decision-normalizer.php`
- `includes/ai/prompts/class-yoro-ai-command-router-prompt.php`

Changes:

- Add a compact `intent_family` field to router decisions when applicable.
- Keep `action` as the executable route target.
- Use `intent_family` only as a semantic normalization layer for:
  - eval
  - telemetry
  - fallback compatibility
  - future prompt stability

Why:

- It separates user language from backend semantics.
- It gives the backend a stable meaning layer even when action surfaces evolve.

### B. Reduce Phrase-Driven Parser Understanding

Files:

- `includes/parsing/class-yoro-parser-engine.php`
- `includes/parsing/class-yoro-email-command-parser.php`
- `includes/execution/class-yoro-action-composition-planner.php`

Changes:

- Audit every heuristic and classify it:
  - `state_safe`
  - `protocol_safe`
  - `language_dependent`
- Keep only `state_safe` and `protocol_safe` branches as long-term parser responsibilities.
- Demote `language_dependent` semantic branches unless they are extremely narrow and low-risk.

Examples of branches that should remain:

- current workflow continuation
- short missing-value follow-up
- exact order/template/draft reference extraction
- confirmation/cancel/replay

Examples of branches that should shrink over time:

- business-summary phrase inference
- store-overview phrase inference
- queue intent inference from wording alone

Why:

- Backend fallback should degrade safely, not imitate multilingual NLU.

### C. Make Follow-Up Parsing State-Driven

Files:

- `includes/parsing/class-yoro-email-command-parser.php`
- `includes/core/class-yoro-conversation-memory.php`
- `includes/parsing/class-yoro-parser-engine.php`

Changes:

- Treat pending workflow state as the primary signal.
- If the workflow is waiting for `status`, treat the next short message as a status candidate before trying to interpret it as a new command.
- If the workflow is waiting for `note`, treat the next short message as note content.
- If the workflow is waiting for `instruction`, treat the next short message as email intent content.

Why:

- State-driven parsing scales better across languages than verb-trigger lists.
- A short reply can be valid even if it contains no English action verbs.

### D. Shift Prompt From English Examples to Policy

Files:

- `includes/ai/prompts/class-yoro-ai-command-router-prompt.php`

Changes:

- Reduce dependence on English-only example phrases.
- Keep prompt language around:
  - semantic distinctions
  - route safety
  - copy behavior
  - context resolution
- Explicitly instruct the model to map any supported language to the same internal action and intent-family contracts.

Why:

- Prompt should teach invariants, not carry the multilingual dictionary of the product.

### E. Add Language-Bucket Telemetry

Files:

- `includes/core/class-yoro-logger.php`
- `includes/rest/class-yoro-rest-run-handler.php`
- `includes/admin/class-yoro-admin-page.php`

Changes:

- Persist a lightweight `detected_language` hint when available.
- Add breakdowns for:
  - `ai_primary` rate by language
  - clarify rate by language
  - fallback rate by language
  - generic clarification rate by language

Why:

- “Supports all languages” is not a binary claim.
- It must be measurable as skew across language buckets.

## What To Remove

These should be reduced or phased out over time:

### 1. Broad phrase-family heuristics as core routing logic

Examples:

- large English regex lists for briefing/summary/queue surfaces
- phrase-based semantic rescue as primary fallback ownership

These can remain temporarily as narrow guards, but should not be the long-term multilingual strategy.

### 2. English-only workflow command triggers as hidden dependencies

Examples:

- `save draft`
- `update status`
- `same order`
- `this order`

These should be replaced by state-aware logic wherever practical.

### 3. The assumption that parser tests in English prove global quality

English pass rate should no longer be treated as a proxy for multilingual readiness.

## What To Add

### 1. `intent_family` in router outputs

Use it as a stable semantic layer across prompt, telemetry, and eval.

### 2. A dedicated multilingual eval strategy

Not a dictionary of all languages, but a layered evaluation model:

- language-agnostic contract tests
- representative multilingual smoke/eval packs
- production telemetry by language bucket

### 3. State-first follow-up handling

This is the key feature that avoids requiring parser-level language understanding for every workflow continuation.

### 4. Language-quality telemetry

Without this, multilingual support will remain anecdotal.

## Migration Order

### Phase 1. Contract First

- Add `intent_family` to router decision outputs.
- Keep current action outputs intact.
- Extend telemetry and eval snapshots to store `intent_family` when present.

Success criteria:

- No behavior regression.
- Contract fields are visible in logs/admin/debug paths.

### Phase 2. State-Driven Follow-Ups

- Refactor pending-workflow continuation to favor slot filling over English trigger phrases.
- Keep deterministic safety rails intact.

Success criteria:

- Short follow-up messages work regardless of whether they contain English control verbs.

### Phase 3. Demote Semantic Parser Heuristics

- Review parser-engine semantic read-only fallback.
- Keep only minimal rescue paths.
- Ensure they are clearly marked as `recovery_fallback`, not shadow primary routes.

Success criteria:

- Fallback is narrower and safer.
- Multilingual success depends less on parser phrase lists.

### Phase 4. Prompt and Catalog Alignment

- Update prompt wording so multilingual behavior is stated as routing policy, not English phrase lore.
- Use catalog metadata and `intent_family` more heavily.

Success criteria:

- Prompt becomes more stable.
- Action semantics live mostly in catalog + contract.

### Phase 5. Language Telemetry and Representative Eval

- Add representative multilingual eval packs.
- Add telemetry by language bucket.
- Track route quality skew.

Success criteria:

- The team can identify which languages are degrading and why.

## Acceptance Criteria

This roadmap is complete when all of the following are true:

- `ai_router` is the primary multilingual understanding layer for supported tasks.
- Backend fallback no longer depends heavily on English semantic phrase parsing.
- Workflow continuation succeeds from state context more often than from English trigger phrases.
- Route quality can be measured by language bucket.
- Multilingual support is validated by contract-level eval plus representative language coverage, not by English-only smoke tests.
- The system degrades safely when AI is unavailable, without pretending parser heuristics fully understand arbitrary languages.

## Practical Interpretation

“Support all languages” should **not** mean:

- parser fallback understands every language equally through regex and heuristics

It should mean:

- if the model can understand the language, the system can route it through `ai_router`
- backend layers remain stable because they operate on normalized contracts
- follow-up flows remain usable because they rely on state
- telemetry reveals where language quality is weak

That is the only version of multilingual support that is technically maintainable.
