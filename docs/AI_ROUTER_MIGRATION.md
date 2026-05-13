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
- Router mock decisions are allowed only in debug/test mode via `yoro_ai_command_router_mock_decision`.

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

Legacy AI intent parser, example retriever, and semantic intent router files have been removed after dependency checks. Workflow planner files remain because multi-step workflows may still use them in a future phase.

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

- Expand action metadata for all high-value actions.
- Extend deterministic router fixtures from parser tests into pipeline, preview, execute, and undo groups.
- Keep a small live AI smoke suite for prompt/catalog quality checks.
