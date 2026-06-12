# Changelog

## Unreleased

### Changed
- Main YoRo Chat now enters through the Laravel conversation endpoint and uses Laravel for reasoning, memory, pending operation graphs, debug traces, and natural assistant copy.
- The WordPress plugin is scoped to UI, store context, action catalog exposure, local execution, confirmation, undo, and structured result rendering.
- Quick-search secondary actions are labeled as direct UI actions instead of fallback AI routing.
- Plugin API status now exposes `conversation` as the YoRo Chat capability without legacy router aliases.
- Pending conversation approvals now execute through the structured `/execute` endpoint instead of the legacy `/run` shim.
- Plugin API client URL builders no longer rewrite obsolete `/ai/route` URLs into conversation/action endpoints.
- Chat requests now use a `chat` rate-limit scope instead of the removed `run` scope.
- The YoRo API request timeout filter is now `yoro_ai_api_request_timeout`.
- Settings cleanup now removes obsolete local parser/router thresholds and plugin-side memory/workflow options.
- Action catalog builder now only exposes the structured catalog and Laravel routing hints used by YoRo Chat.
- REST API status now assumes the single YoRo AI API provider boundary loaded by the plugin bootstrap.
- YoRo AI API client/settings no longer carry provider-optional class guards from older local-AI paths.
- AI email drafting now uses a dedicated email composer entrypoint instead of the old parser-named bridge.
- Bootstrap no longer carries an empty legacy services include step or duplicate provider constant definitions.
- Internal YoRo class guards were removed where bootstrap already guarantees the executor, formatter, cache, settings, and conversation coordinator are loaded.
- Admin chat localization no longer includes the unused confirmation prompt key from the old run flow.
- Admin chat click handling was tidied so approve, cancel, undo, insert, email, and order UI delegation remain easier to audit.

### Added
- AI-first engineering guardrails for keeping conversation intelligence in Laravel.
- Laravel conversation bridge support for `/conversations/message`, conversation hydration, pending action confirmation, and action-event reporting.
- Structured action catalog and compact context bridge for Laravel routing/planning.

### Removed
- Local third-party AI direct support from plugin settings and runtime.
- Plugin-side command router, command decision normalizers, semantic frame normalizer, workflow result normalizer, and route-result builders.
- Plugin-side generic assistant follow-up generation and stale `assistant_followup` rendering hooks.
- Stale `/parse` API URL compatibility path.
- Legacy `/run` REST route and run-specific log helper.
- Unused action registry schema/audit helpers from the old local parser path.
- Unused REST helper methods left behind by the removed `/run` flow.
- Legacy logger helpers for local parser trace review and real-query eval packs.
- Pre-release local memory/workflow/suggestion classes and REST helpers.
- The misleading plugin-side AI parser class used only for email composition.

### Verified
- PHP syntax lint passes across all plugin PHP files using Local PHP 8.4.18.
- JavaScript syntax checks pass across all plugin assets.
- WordPress runtime smoke loads chat and execute routes, confirms `/run` is removed, and exposes the structured action catalog.
- Plugin-to-Laravel handshake smoke passes against the configured local Laravel API, with `conversation` enabled and `ai_router` absent.
- Chat contract smoke normalizes a Laravel-shaped confirmation response into conversation id, pending operation token, action plan, and confirmation result fields.
- Pending operation execute contract smoke fetches the Laravel pending graph, confirms it, executes the local store action, and reports the action event once.
- Direct executor smoke runs `orders.search` through `/execute` and returns a completed structured result.
- Admin render smoke verifies YoRo Chat shell, composer, run button, status panel, script dependencies, chat/execute localized URLs, and absence of localized `/run` or the stale confirmation prompt key.
- Laravel Pint and full feature suite pass against the conversation backend.
