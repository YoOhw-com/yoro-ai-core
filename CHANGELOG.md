# Changelog

## Unreleased

### Changed
- Switched command routing to YoRo AI API first, backed by action catalog metadata.
- Updated runtime preview handling to use `ai_router` parser source.
- Updated history filtering to focus on active parser sources: rule, guard, ai_router, memory, direct.
- Restricted test/mock hooks to debug or test mode so release runtime cannot be overridden by fixtures.
- Made debug tooling optional so release packages can omit development fixtures.

### Added
- Release packaging ignore rules for development-only docs and debug test files.
- AI metadata on action registry entries.
- Action catalog builder for AI-safe routing to registered actions.
- Strict Command Decision schema and normalizer for AI router output.
- Debug-only AI command router mock filter and parser test fixtures.
- Deterministic AI router fixtures for parser and pipeline test groups.
- Deterministic AI router fixtures for preview test group.
- Deterministic AI router fixtures for execute test group.
- Deterministic AI router fixtures for undo test group.

### Removed
- Local third-party AI direct support from plugin settings and runtime.
- Local command-router prompt runtime from the plugin.
- Plugin-side prompt builder classes for workflow, email, and suggestions.
- Pre-release API compatibility aliases and settings migration code.
- Pre-release table/option migration code.

### Verified
- PHP syntax lint passes across all plugin PHP files using Local PHP 8.4.18.
- Live AI smoke checks route key commands through `ai_router`.
- Parser test group passes 162/162 using deterministic AI router fixtures.
- Pipeline test group passes 29/29 using deterministic AI router fixtures.
- Preview test group passes 13/13 using deterministic AI router fixtures.
- Execute test group passes 40/40 using deterministic AI router fixtures.
- Undo test group passes 3/3 using deterministic AI router fixtures.
