---
name: yoro-task-router
description: Classify every YoRo engineering, governance, release, or incident request into exactly one primary class plus risk flags and produce its least-authority route. Use before any YoRo task planning, delegation, testing, review, or write; fail closed when classification, ownership, capability, or required agents are unavailable.
---

# YoRo Task Router

1. Record in-scope repository HEAD/status and preserve owner-controlled changes.
2. Read `../../../.codex/yoro-os/manifests/routing.yaml` and `../../../.codex/yoro-os/manifests/routing-selectors.yaml` completely.
3. Assign exactly one listed primary class. Add every applicable risk flag; never use a risk flag as the primary class.
4. Put the class, flags, affected components, and required selector context in an input record; run `../../../.codex/yoro-os/bin/resolve-route.rb`. Treat `repository adoption` as its own class, never as `documentation/governance`. Accept only concrete custom-agent names and stop on an unknown selector or missing agent.
5. Use capability tiers, not model product names, in governance output. Resolve the environment mapping from `../../../.codex/yoro-os/manifests/model-routing.yaml`.
6. Stop before application writes if a required high-reasoning/security/architecture/contract/release agent cannot be created. Do not substitute a lower tier.
7. Treat `candidate_write_scope` as repository IDs only. Keep it unchanged by approvals; derive `effective_write_scope` only after task-specific approvals and environment preconditions pass. `write_scope`, when present, must equal the effective scope; non-repository outputs belong in `artifact_write_scope`.
8. Treat unassigned global owner metadata as non-blocking unless the resolved task requires that protected approval. Return the resolved record directly comparable with `../../../.codex/yoro-os/manifests/routing-fixtures.yaml`.
