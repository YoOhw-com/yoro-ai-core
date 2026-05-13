# Changelog

## Unreleased

### Changed
- Switched command routing direction to AI command router first, backed by action catalog metadata.
- Replaced legacy AI intent fallback setting with AI command router setting.
- Updated runtime preview handling to use `ai_router` parser source.
- Updated history filtering to focus on active parser sources: rule, guard, ai_router, memory, direct.

### Added
- AI metadata on action registry entries.
- Action catalog builder for AI-safe routing to registered actions.
- Strict Command Decision schema and normalizer for AI router output.
- Multilingual command router prompt.
- Git-tracked migration notes in `docs/AI_ROUTER_MIGRATION.md`.

### Removed From Runtime
- Legacy AI intent fallback route.
- Legacy AI workflow fallback route.
- Retrieval-candidate and semantic-intent parser routes.

### Removed
- Legacy AI intent parser wrapper, prompt, and result normalizer.
- Legacy route example retriever.
- Legacy semantic intent router.

### Verified
- PHP syntax lint passes across all plugin PHP files using Local PHP 8.4.18.
- Live AI smoke checks route key commands through `ai_router`.
