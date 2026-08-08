# Installed YoRo Codex OS governance

This directory is generated from YoRo Codex OS `0.1.2` at `9ccb64e17a8a408e20a4992579f4f38816137a5b` for `yoro-ai-core`.

- Route with `ruby .codex/yoro-os/bin/resolve-route.rb INPUT.yaml .codex/agents`.
- Read `manifests/routing.yaml`, `manifests/routing-selectors.yaml`, and `manifests/model-routing.yaml` before writes.
- Read `manifests/task-handoff.yaml` and `docs/governance/task-handoff.md` for implementation completion and independent acceptance. Start from `templates/task-spec/task-spec.yaml`, `templates/task-handoff/execution-handoff.yaml`, and `templates/independent-review/independent-review.yaml`.
- Architecture tasks must read the portable, read-only `manifests/invariants.yaml`, `manifests/adr-governance.yaml`, `docs/governance/invariants.md`, and `docs/governance/adr-policy.md`. Organization-wide ADRs remain canonical in `yoro-codex-os`.
- For mutation work, read `manifests/mutation-safety.yaml`; unknown safety blocks affected application writes.
- Runtime contracts remain canonical in this component and are not copied here.
- Installation, diff, repair decisions, and MCP parity are performed through the canonical governance repository or owning component gates; this installed package does not grant additional write authority.
- `sandbox_mode` is agent capability only. Candidate repository scope, task-specific authorization, effective repository scope, and the observed environment/UI permission boundary are separate. Adoption requires effective scope and the environment boundary to equal one explicitly authorized target.

For implementation tasks requiring review, write mutable task specifications, execution handoffs, and review records in component-owned evidence paths or attach them to the exact GitHub PR. Never place them inside this generated directory. Record the exact result commit or PR head, leave review pending, and return control to an independent reviewer/owner. The executor may propose—but never authorize—the next task.

Do not edit generated files. Component instructions remain outside YoRo markers in root `AGENTS.md`; component-owned `.codex/config.toml`, task/review evidence, and non-YoRo skills remain untouched.
