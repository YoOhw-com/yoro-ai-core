# Runtime hardening packaging manifest

## Scope

Package only the runtime/router/security hardening changes. The worktree contains many older or unrelated changes, so avoid `git add -A` and avoid staging whole large files unless the intent is to include all existing hunks in those files.

## Primary files

- `includes/rest/class-yoro-rest-run-handler.php`
- `includes/rest/class-yoro-rest-confirmation-flow.php`
- `includes/rest/class-yoro-rest-workflow-state.php`
- `includes/dev/class-yoro-parser-test-suite.php`
- `includes/parsing/class-yoro-parser-engine.php`
- `includes/parsing/class-yoro-orders-command-parser.php`
- `includes/actions/class-yoro-action-catalog-builder.php`
- `includes/execution/class-yoro-plan-risk-engine.php`
- `includes/ai/class-yoro-ai-settings.php`
- `includes/admin/class-yoro-admin-page.php`

## Fix groups

- Confirmation state hardening:
  - Configured TTL is used for workflow/action state and pending confirmation expiry.
  - Low-confidence AI routes with concrete action/params now create `workflow_state_token` and `pending_plan_token`.

- Parser/router hardening:
  - Deterministic current-email, guided workflow preview, contextual bulk order write, and query-action paths run before AI router when they are concrete.
  - Action catalog scopes shrink candidate actions for single order, multi-order, queue, customer, template, and store-summary requests.

- Settings hardening:
  - Secret fields are not rendered back into admin forms.
  - Blank secret inputs preserve existing values unless clear is explicit.
  - Constants can own OpenAI/cloud keys and cloud API URL.
  - Stored secret options are updated with autoload disabled.

- Risk/confirmation consistency:
  - Semantic warnings are propagated by `YoRo_Plan_Risk_Engine`.

## Verification

- `git diff --check`: pass
- PHP lint: pass
- `parser`: 121/121
- `pipeline`: 29/29
- `planner`: 19/19
- `preview`: 13/13
- `execute`: 47/47
- `undo`: 3/3

## Packaging guidance

Use interactive staging for the primary files:

```sh
git add -p includes/rest/class-yoro-rest-run-handler.php
git add -p includes/rest/class-yoro-rest-confirmation-flow.php
git add -p includes/rest/class-yoro-rest-workflow-state.php
git add -p includes/dev/class-yoro-parser-test-suite.php
git add -p includes/parsing/class-yoro-parser-engine.php
git add -p includes/parsing/class-yoro-orders-command-parser.php
git add -p includes/actions/class-yoro-action-catalog-builder.php
git add -p includes/execution/class-yoro-plan-risk-engine.php
git add -p includes/ai/class-yoro-ai-settings.php
git add -p includes/admin/class-yoro-admin-page.php
```

Do not stage unrelated frontend, service, core, AI client/prompt, or workflow files unless intentionally expanding the PR.

## Suggested commit message

```text
Harden YoRo runtime confirmation and router scope
```

## Suggested PR summary

- Harden confirmation state by using configured TTL consistently and requiring pending tokens for low-confidence AI confirmations.
- Prefer deterministic routing for concrete current-email, guided workflow, contextual bulk-order, and query-action commands before AI routing.
- Narrow AI action catalog exposure by request scope/profile to reduce wrong-action routing.
- Harden AI settings storage and admin rendering so secrets are not echoed or blank-overwritten.
- Add regression coverage for confirmation tokens, TTL, policy/risk propagation, parser, pipeline, preview, execute, and undo paths.

## Known risk

The repository has a broad dirty worktree that predates this packaging pass. Several primary files have large existing diffs, so staging whole files may include unrelated work. Prefer hunk-level staging or split into a dedicated branch/commit only after reviewing each hunk.
