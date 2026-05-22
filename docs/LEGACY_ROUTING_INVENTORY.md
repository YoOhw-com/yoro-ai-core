# YoRo Legacy Routing Inventory

This inventory tracks routing code that still behaves like deterministic rule/parser logic around the AI-first command router. Use it to remove legacy behavior in small batches without losing safety behavior.

## Final Cleanup Status

The AI-first routing cleanup is complete for the normal command path.

- Normal supported commands route through the AI command router, then `YoRo_Router_Decision_Validator`.
- AI-unavailable recovery uses `CommandShape` where practical instead of case-specific keyword parsers.
- Remaining deterministic paths are classified as safety, context, workflow, conversation, or final clarification guardrails.
- Explicit order writes for add-note, status update, and hold are now shape-backed.
- Refund and verification workflow guards now expose `CommandShape.workflow_intent`, so trace review can explain them without reading raw JSON.

## Current Routing Order

1. `YoRo_Active_Workflow_Continue_Router`
   - Purpose: active workflow control.
   - Keep as guard. This is context safety, not legacy command parsing.

2. `YoRo_AI_Pre_Router_Guard_Result_Router`
   - Purpose: deterministic safety before AI for current email delivery, explicit refund review, explicit verification, guided follow-ups, contextual writes, follow-up references, and composition hints.
   - Keep only high-confidence safety/context guards here.
   - Do not move broad explicit write parsing here unless eval proves AI-first behavior remains intact.

3. `YoRo_AI_Command_Router_Result_Router`
   - Purpose: AI primary route, normalized through `YoRo_Router_Decision_Validator`.
   - Target canonical path for normal command routing.

4. `YoRo_AI_Post_Command_Router_Result_Router`
   - Purpose: semantic shape fallback when AI is unavailable or cannot produce a valid route.
   - Keep for shape-level recovery, especially query-and-apply.

5. `YoRo_AI_Conversation_Result_Router`
   - Purpose: non-action conversation handling.
   - Keep separate from task routing.

6. `YoRo_Parser_Tail_Router`
   - Purpose: final safety/recovery fallback.
   - This is the main legacy cleanup area.

## Tail Router Branches

### `semantic_read_only_fallback`

- Class: `YoRo_AI_Recovery_Fallback_Result_Router::maybe_build_semantic_read_only_fallback`.
- Keep for now.
- Reason: it is a recovery layer when AI routing is unavailable, not a first-choice parser.
- Cleanup target: ensure it is only used when AI returned no parseable result.

### `hardening_guard`

- Class: `YoRo_AI_Hardening_Guard_Result_Router`.
- Keep as a final safety guard.
- Safety items to keep:
  - grounded last orders references
  - explicit capability gap responses
  - email template list safety
- Legacy candidates to migrate or remove:
  - none in the normal command path.
- Removed:
  - `guard.operations_attention.aged_pending_orders`; aged pending order questions now recover through `CommandShape` order search.
  - `guard.operations_overview.*`; direct operations overview phrases now recover through semantic read-only fallback when AI routing is unavailable.
  - `guard.orders.add_note.explicit_order`; explicit add-note commands now recover through `CommandShape` explicit order write fallback.
  - `guard.orders.update_status.explicit_order` and `guard.orders.hold.explicit_order`; explicit status/hold commands now recover through `CommandShape` explicit order write fallback.
  - `explicit_order_write_guard`; the duplicated hardening-tail explicit refund/write branch has been removed.

## Shape-Backed Workflow Guardrails

These guards remain deterministic because they protect multi-step workflows, not because they are the primary command parser:

- `explicit_refund_guard`
  - `orders.refund_preview`
  - `orders.update_status` for explicit multi-order refund writes
  - `workflow.refund_review`
  - Shape: `workflow_intent.kind=refund_review`
- `explicit_verification_guard`
  - `workflow.verify_customer_before_fulfillment`
  - Shape: `workflow_intent.kind=verify_customer_before_fulfillment`

Do not migrate these further unless eval coverage proves AI decisions preserve preview/draft/send semantics and confirmation behavior.

### `ambiguous_command`

- Class: `YoRo_Parser_Safety_Fallback_Router::maybe_build_ambiguous_response`.
- Keep.
- Reason: final clarification for one-word ambiguous inputs.

### `latest_orders_fallback`

- Class: `YoRo_Parser_Safety_Fallback_Router::maybe_build_latest_orders_fallback`.
- Removed.
- Replacement: `YoRo_AI_Recovery_Fallback_Result_Router::maybe_build_command_shape_order_search_fallback`.
- Reason: `latest 5` / `recent 3` shorthand is now represented as an order-list `CommandShape` and recovered through the semantic-shape path when AI routing is unavailable.

### `incomplete_intent`

- Class: `YoRo_Parser_Safety_Fallback_Router::maybe_build_incomplete_intent_response`.
- Removed.
- Replacement: `YoRo_AI_Recovery_Fallback_Result_Router::maybe_build_command_shape_write_missing_target_fallback`.
- Reason: missing-target write commands such as `Hold orders`, `Add note to order`, and `Add note to this order` are now represented by `CommandShape` and normalized by `YoRo_Router_Decision_Validator`.

### `generic_clarification`

- Keep.
- Reason: final safe response when no router path can handle the command.

## Cleanup Rules

- Do not delete a branch until at least one eval case proves the intended behavior.
- Prefer moving behavior into `CommandShape` + `YoRo_Router_Decision_Validator`.
- Keep safety guards that prevent destructive writes, resolve active workflow references, or clarify missing targets.
- Remove pattern-specific read routing once AI route + shape-level validator can cover it.
- After every cleanup batch, run Real Command Eval Pack and full suite.

## Future Cleanup Candidate

Target: telemetry and naming cleanup only.

Reason:
- Normal routing no longer depends on full legacy rule fallback.
- Remaining non-AI paths are intentional guardrails.
- Future work should improve observability and eval coverage before removing more deterministic context handling.
