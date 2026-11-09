# YoRo AI-First Engineering Guardrails

The primary logical operation path is `ChatGPT/Codex -> YoRo API -> YoRo Core -> WordPress/WooCommerce`. API owns canonical semantic/control authority; this plugin owns connector-local enforcement, execution, structured results and WordPress rendering. AI hosts and retained Apps access API only; no direct connector bypass is permitted. The plugin must not become the conversation brain.

This file is the plugin-side technical guidance, aligned with the source-pinned [Master](https://github.com/YoOhw-com/yoro-workspace/blob/9f839541a455727ccfc09bf88970417da8b8c662/docs/strategy/master-architecture-roadmap.md) and [ownership boundaries](https://github.com/YoOhw-com/yoro-workspace/blob/9f839541a455727ccfc09bf88970417da8b8c662/docs/architecture/ownership-boundaries.md). Those references give strategic context, not execution permission. Retained Apps/native chat and their shared conversation/provider dependencies remain supported for compatibility. When retained chat behavior feels wrong, fix API semantic contracts, routing, memory or response policy rather than adding local phrase patches; fix connector-local enforcement, rendering or execution in Core.

## Current Product Model

- Laravel API owns canonical operational semantics, action/capability identity, authentication/authorization, grants/entitlements and actor binding, business and Safety policy, confirmation, durable lifecycle, orchestration/delivery, audit and verification/recovery contracts. Retained chat/App consumers also use its semantic understanding, multilingual intent, memory, pending workflow state, debug and response services.
- The WordPress plugin is the store-side runtime: structured context and capabilities, local permission/safety checks, worker coordination, idempotency/concurrency/journal, execution, result verification and bounded recovery. Retained chat UI, confirmation UI and direct UI controls remain supported.
- WooCommerce/store data must be exposed as structured context and structured action contracts. The plugin should provide facts and capabilities, not conclusions about what the user meant.
- Retained natural-language chat may use scoped API AI contracts for speed; validation may ground, verify and reject unsafe output but must not become a phrase router. Already-structured MCP operations may use deterministic API/Core paths without a second inference merely to reinterpret the request. Shared policy, operation storage, audit and lifecycle dependencies remain intact; this guidance does not prove all runtime coupling removed.
- Fully AI-first does not mean "LLM can do anything." The action catalog, integration registry, live store settings, confirmation policy, and executor permissions are hard boundaries.

## Ownership

- API owns canonical semantic/control authority. For retained chat it also owns conversation reasoning: intent, context continuity, memory, clarification, action planning, natural response copy, transcript, debug trace, and pending operation graph state.
- The plugin owns local execution: UI rendering, store context bridge, action catalog exposure, action execution, confirmation clicks, undo execution, permission checks, validation, rate limits, structured provider payloads, and local error handling.
- Renderers may format structured results, but they must not decide what the user meant.

Finite Human-approved exceptions remain exact: [YR-0217 Chat Support](https://github.com/YoOhw-com/yoro-workspace/issues/571) and [YR-0222 billing](https://github.com/YoOhw-com/yoro-workspace/issues/578) retain their own scope, criteria, rights, budgets, deadlines, UNKNOWNs and immutable history. This alignment does not migrate them, promote those features globally or wait for their completion or companion Workspace source merge.

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

- Retained chat copy should come from Laravel assistant payloads. The plugin may render cards, reports, queues, plans, tables, and status badges from structured payloads.
- Plan UI should show what will happen, what needs confirmation, and which operations are pending, without inventing explanations.
- Execution must be idempotent where possible and report action events back to Laravel with operation id, action, params, result, status, and trace id.
- A failed execution should be reported as an action event/debug signal, not silently converted into a new conversation answer.
- Direct UI buttons can call local executors, but natural-language follow-up after that still returns to Laravel.

## Review Checklist

For the affected behavior, preserve the following boundaries and existing required checks. The endpoint rules in the first three bullets apply to retained native chat/UI paths, not as a required inference route for structured MCP:

- Main chat enters through `POST /chat/message`, which bridges to Laravel `/conversations/message`.
- `/run` is used only for approved pending operation execution.
- `/execute` is used only for direct local actions from UI controls.
- No new keyword router or phrase patch was added in the plugin.
- Any natural response change was made in Laravel AI context, not hardcoded in plugin UI.
- Regression tests cover behavior, not exact prose unless the prose is a safety notice.
- New plugin context fields are structured, compact, and label-safe.
- New executable behavior appears in `action_catalog` and has executor validation.
- New UI rendering handles malformed/missing structured fields without changing semantic meaning.

## Verification Navigation

Choose the smallest affected set using the existing test entry points: standalone PHP contracts in [`tests/unit/`](tests/unit/) (for example `php tests/unit/api-endpoint-resolver-test.php`) and standalone renderer contracts such as `node tests/smoke/result-renderer-confirmation-contract.js`. The existing [smoke workflow](.github/workflows/smoke.yml) defines the disposable WordPress and WordPress/WooCommerce installations, PHP `wp eval-file` smoke population and Node smoke population; do not point that setup at a retained Local Sites installation or database.

Preserve applicable required hosted checks and exact API/Core pair proof through the existing [API pairing guidance](https://github.com/YoOhw-com/yoro-ai-api/blob/5da7303a8a2d0b9ab59a1946b4be1df81508fd0a/docs/ci-api-core-pairing.md) and [verification workflow](https://github.com/YoOhw-com/yoro-ai-api/blob/5da7303a8a2d0b9ab59a1946b4be1df81508fd0a/docs/development-testing-workflow.md). These are baseline navigation references, not current candidate/pair certificates or operational authority. Select platform concerns from the approved outcome and actual dependencies; preserve matching WordPress/WooCommerce/MySQL semantics for local SQL/locking/concurrency and use separately authorized exact host/deployed evidence for runtime claims. Standalone tests, fakes and control-plane observations do not attest that runtime. Reuse unaffected proof only with its complete relevant input key, freshness and cleanup intact, reporting reuse truthfully. Documentation-only link/scope/consistency checks add no runtime proof and waive no applicable mandatory check.
