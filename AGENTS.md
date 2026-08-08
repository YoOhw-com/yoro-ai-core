<!-- BEGIN YORO CODEX OS GENERATED -->
YoRo Codex OS 0.1.2 governance layer for `yoro-ai-core`.

- Source governance commit: `9ccb64e17a8a408e20a4992579f4f38816137a5b`.
- Installed governance is discoverable under `.codex/yoro-os/`, custom agents under `.codex/agents/yoro-*.toml`, and skills under `.agents/skills/yoro-*/SKILL.md`.
- Read `.codex/yoro-os/README.md` and route through `.codex/yoro-os/manifests/routing.yaml` plus `routing-selectors.yaml` before writes.
- For implementation work, use the installed task-handoff manifest, templates, and governance document. `implementation_complete` is not `accepted`; the executor leaves independent review pending and never authorizes its proposed next task.
- Runtime contracts remain canonical in this component; this generated layer contains governance only.
- Record HEAD/status before work, preserve owner-controlled changes, and keep other YoRo repositories read-only unless explicitly authorized.
- Classify every task with exactly one primary class plus risk flags from the canonical routing manifest.
- Use `OBSERVED`, `INTENDED`, `PROPOSED`, and `UNRESOLVED` exactly. Never promote an unresolved behavior to an invariant.
- Do not read secret files or modify credentials, dependencies, lockfiles, migrations, release state, deployment state, or protected gates without explicit scoped authority.
- Cross-repository contract work requires named ownership, immutable SHAs/digests, provider/consumer evidence, and contract review.
- Mutation work requires a per-family safety classification; unknown properties stop affected writes.
- Required architecture/security/contract/release agents may not be silently replaced with a lower capability tier.
- An agent's `sandbox_mode` is not task approval or proof of repository isolation. `candidate_write_scope` contains repository IDs only; protected writes use `effective_write_scope`. Before component writes, verify it is contained by the environment/UI permission boundary; adoption requires an exact single-target boundary.
- Release automation may recommend readiness but may not authorize or execute production deployment.

Template digest: `3f34430d0e6234a021d0b5af203a23b71a1afdb7b465b339f7e51b91ae03887e`. Do not edit this generated block; keep component-local instructions outside its markers. No hidden local overlay is used.


Component overlay: WordPress/WooCommerce connector runtime.

- Store-local WordPress actor capabilities, policy, schema/version preflight, and final authorization must be rechecked immediately before mutation.
- Treat the action registry/catalog builder, connector runtime, and smoke contracts as component-owned executable evidence.
- Classify each mutation family independently; order-status and inventory bounded recovery evidence must not be generalized to other actions.
- Unknown replay, concurrency, side-effect, compensation, or recovery properties require security/architecture review before affected changes.
- Do not run stateful WordPress/WooCommerce smoke tests without a disposable approved fixture.
- Preserve the current plugin header/stable-tag mismatch as a release-readiness finding; do not resolve it as incidental governance adoption work.
<!-- END YORO CODEX OS GENERATED -->
