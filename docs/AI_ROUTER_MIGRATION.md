# AI Router Migration

## Direction

YoRo command handling is moving to this flow:

1. User gives a natural-language command in any supported language.
2. AI command router classifies the command.
3. Router returns a strict Command Decision.
4. Parser bridge converts the decision into an existing registered action.
5. Runtime guard, planner, confirmation, and executor enforce safety.
6. User remains the final approval authority for store-changing actions.

## Current Runtime Contract

- Primary route source: `ai_router`.
- Action selection must come from `YoRo_Action_Catalog_Builder`.
- Router output must pass `YoRo_Command_Decision_Normalizer`.
- Write actions may be routed by AI, but backend confirmation remains mandatory.
- Deterministic rules remain fallback or safety guard, not the primary intelligence layer.
- Full legacy rule parsing has been removed from the AI-router runtime path. AI-unavailable handling now uses explicit recovery or safety guardrails only.
- `CommandShape` is the canonical backend semantic shape for recovery, validator constraints, and trace review.
- Shape-backed recovery now covers order search, missing write targets, explicit order writes, query-and-apply selections, status/age/money/date/customer/payment/shipping filters, and refund/verification workflow intent metadata.
- Contextual "prepare next follow-up/review" commands are handled by an explicit pre-router guided follow-up guard, not by the full legacy rule parser.
- Contextual follow-up references such as "open the first one", "do the next recommendation", and "continue the plan" are handled by a pre-router follow-up reference guard with `guard.follow_up.*` metadata.
- Contextual order write references such as "hold the first one" and "add note to the first one" are handled by a pre-router contextual order write guard with `guard.*` metadata.
- Single-order commands with an explicit order ID but an uncertain local-language surface use the `order_ambiguous` catalog profile so the AI router can choose read or write actions across languages instead of being forced into read-only details.
- Combined status-plus-note requests are scoped to the status profile so `orders.bulk_update_status_with_note` remains available to the AI router.
- Briefing-style weekly commands canonicalize to the `store_briefing` family and rolling `last_7_days` window at the router-decision validation layer.
- Multi-action order commands are handled by the action-composition guard/action-decomposition path (`guard.action_composition` or `ai.action_decomposition`), not by the full legacy rule parser.
- Shape-based dynamic selections such as "Hold latest 5 orders" are handled by recovery fallback using `CommandShape`, not by legacy rule parsing.
- Explicit order writes such as add note, update status, and hold use `shape.explicit_order_write` when AI routing is unavailable or cannot produce a valid route.
- Explicit refund and verification workflow guards are retained as safety/context guardrails, but they now attach `command_shape.workflow_intent` so debug surfaces can explain them semantically.
- Workflow template promotion uses guard metadata (`guard.workflow_template.*`) unless the AI router directly selected a supported workflow action.
- Safety clarifications and contextual read-only refresh fallbacks report guard source metadata instead of rule metadata.
- Router mock decisions are allowed only in debug/test mode via `yoro_ai_command_router_mock_decision`.
- Deterministic test fixtures may simulate AI unavailability only to validate recovery and safety guardrails.

## Active Files

- `includes/actions/class-yoro-action-registry.php`
- `includes/actions/class-yoro-action-catalog-builder.php`
- `includes/ai/class-yoro-ai-command-router.php`
- `includes/ai/prompts/class-yoro-ai-command-router-prompt.php`
- `includes/ai/normalizers/class-yoro-command-decision-normalizer.php`
- `includes/parsing/class-yoro-parser-engine.php`
- `includes/rest/class-yoro-rest-run-handler.php`

## Legacy Routes Removed From Runtime

- AI intent fallback.
- AI workflow fallback.
- Retrieval candidate router.
- Semantic intent router.
- Full legacy rule fallback router.

Legacy AI intent parser, example retriever, semantic intent router, and full legacy rule fallback files have been removed after dependency checks. Workflow planner files remain because multi-step workflows may still use them in a future phase.

## Live Smoke Commands

Use these before broader manual QA:

- `Show 10 orders needing attention`
- `How is our business doing this month?`
- `How is the store doing this week?`
- `Update order #123 to on hold with note: Customer needs verification`
- `Add note to order #123`
- `Xem 5 đơn mới nhất`
- `Bạn có hỗ trợ tạo shipping label không?`

Expected behavior:

- Read commands route to read actions.
- Incomplete write commands clarify.
- Concrete write commands route correctly but require confirmation before data mutation.
- Unsupported capabilities return assistant messages, not invented actions.

## Next Work

- Continue targeted live AI smoke tests for prompt/catalog quality.
- Add new real user commands to the Real Command Eval Pack before changing routing behavior.
- Use Trace Review for every future routing bug report before adding backend constraints.

## Test Fixture Status

- Full suite currently passes 428/428.
- Parser group: deterministic AI router fixtures pass 160/160.
- Pipeline group: deterministic AI router fixtures pass 29/29.
- Real Command Eval Pack passes 43/43.
- Evaluation group currently passes 72/72.
- Hardening group currently passes 37/37.
- Planner group currently passes 19/19.
- Preview group: deterministic AI router fixtures pass 13/13.
- Execute group: deterministic AI router fixtures pass 52/52.
- Undo group: deterministic AI router fixtures pass 3/3.
- Pipeline fixtures include recursive `recommended_sequence` command overrides so follow-up tests do not call live AI.
- Preview fixtures run under a test admin user in WP-CLI so action preview permission checks match the admin UI.
- Execute fixtures run under a test admin user in WP-CLI and still exercise real read/workflow execution paths.
- Undo fixtures run under a test admin user in WP-CLI and execute real rollback plans against temporary test orders.
