# YoRo AI-First Engineering Guardrails

YoRo Chat is a fully AI-first, ChatGPT-like store assistant. The plugin must not become the conversation brain.

This file is the plugin-side source of truth for future development. When behavior feels wrong, do not add local phrase patches in the plugin. Fix the semantic contract, routing, memory, or response policy in Laravel unless the problem is purely rendering or execution.

## Current Product Model

- Laravel is the conversation brain: semantic understanding, multilingual intent, action planning, memory, pending workflow state, debug traces, response language/tone, capability boundaries, and routing.
- The WordPress plugin is the store-side runtime: chat UI, structured store context provider, action catalog provider, confirmation UI, action executor, direct UI controls, and action-result reporter.
- WooCommerce/store data must be exposed as structured context and structured action contracts. The plugin should provide facts and capabilities, not conclusions about what the user meant.
- Laravel may use scoped AI contracts for speed, but those contracts are still AI-first semantic contracts. Backend validation may ground, verify, and reject unsafe output; it must not become a phrase router.
- Fully AI-first does not mean "LLM can do anything." The action catalog, integration registry, live store settings, confirmation policy, and executor permissions are hard boundaries.

## Ownership

- Laravel owns conversation reasoning: intent, context continuity, memory, clarification, action planning, natural response copy, transcript, debug trace, and pending operation graph state.
- The plugin owns local execution: UI rendering, store context bridge, action catalog exposure, action execution, confirmation clicks, undo execution, permission checks, validation, rate limits, structured provider payloads, and local error handling.
- Renderers may format structured results, but they must not decide what the user meant.

## Before Any Fix

Every bug fix must identify the failing layer first:

- Understanding, tone, follow-up continuity, or action choice: fix Laravel prompt, orchestrator, context builder, memory, or action schema.
- Wrong or missing executable capability: fix the action catalog or executor contract.
- Correct plan but failed store change: fix plugin executor, validation, permission, or direct action handler.
- Correct result but poor UI: fix renderer only.
- Lost pending operation or memory: fix Laravel conversation state first, then plugin transport only if needed.

## Structured Provider Rules

- Store identity, WooCommerce settings, order statuses, product statuses, products, orders, reports, queues, integrations, and capabilities should be sent as structured data with stable keys and display labels.
- Labels for WooCommerce statuses/settings should come from WooCommerce/WordPress APIs whenever possible. Mapping is acceptable only as presentation fallback, not as authority.
- `router_hints` from the plugin are weak scope hints only. They may limit catalogs for latency, but they must not choose final actions, parse natural language, or override Laravel semantic decisions.
- `action_catalog` is the authority for executable capabilities. If an action is not in the catalog, the assistant must not claim it can execute it.
- `operation_graph` / `pending_operation_graph` from Laravel is canonical for multi-step work. The plugin may validate and execute it, but must not infer extra steps.
- User-authored content slots such as internal note text and customer email instruction must come from Laravel structured slots. The plugin executor should not recover note/email content from raw text.

## Do Not Add

- Phrase-specific chat rules such as "if the user says X, answer Y".
- Local mock AI responses for real chat behavior.
- Keyword-based intent routing for the main chat path.
- Plugin-side conversation memory that competes with Laravel.
- Generic suggestions generated outside the conversation brain.
- Fallback from the main chat endpoint to legacy command routing.
- Local natural-language parsers for report windows, order statuses, note bodies, email instructions, follow-up meaning, or response language.
- UI claims that YoRo can inspect/configure third-party plugins or live settings unless the integration registry and action catalog expose that capability.

## Allowed Plugin Logic

- Safety guardrails, permissions, nonce checks, and confirmation enforcement.
- Schema normalization for API compatibility.
- Store data validation before writes.
- Direct button actions from rendered cards.
- User-facing formatting cleanup that does not change intent or invent content.
- Technical fallback errors when the AI service is unavailable.
- UI-specific state such as active panel, selected conversation id, visible report tab, scroll position, and pending confirmation controls.
- Read-only structured data collection from WooCommerce/WordPress for Laravel context.

## UI/Executor Rules

- Chat copy should come from Laravel assistant payloads. The plugin may render cards, reports, queues, plans, tables, and status badges from structured payloads.
- Plan UI should show what will happen, what needs confirmation, and which operations are pending, without inventing explanations.
- Execution must be idempotent where possible and report action events back to Laravel with operation id, action, params, result, status, and trace id.
- A failed execution should be reported as an action event/debug signal, not silently converted into a new conversation answer.
- Direct UI buttons can call local executors, but natural-language follow-up after that still returns to Laravel.

## Review Checklist

Before merging a change, confirm:

- Main chat enters through `POST /chat/message`, which bridges to Laravel `/conversations/message`.
- `/run` is used only for approved pending operation execution.
- `/execute` is used only for direct local actions from UI controls.
- No new keyword router or phrase patch was added in the plugin.
- Any natural response change was made in Laravel AI context, not hardcoded in plugin UI.
- Regression tests cover behavior, not exact prose unless the prose is a safety notice.
- New plugin context fields are structured, compact, and label-safe.
- New executable behavior appears in `action_catalog` and has executor validation.
- New UI rendering handles malformed/missing structured fields without changing semantic meaning.
