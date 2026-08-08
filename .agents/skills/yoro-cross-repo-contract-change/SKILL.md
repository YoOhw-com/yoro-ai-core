---
name: yoro-cross-repo-contract-change
description: Govern a YoRo public or internal contract change with two or more producer/consumer repositories. Use for routes/OpenAPI/client models, commerce envelopes/catalogs, connector contracts, shared schemas, or compatibility pin changes; require explicit ownership and immutable compatible evidence before implementation.
---

# YoRo Cross-Repository Contract Change

1. **Preflight:** Record all component SHAs/statuses and keep all components read-only.
2. **Evidence gathering:** Identify executable producer, published projection, every consumer, fixtures, tests, pins, and owners.
3. **Classification:** Assign `cross-repository contract` with `public contract` and `cross-repository` as applicable.
4. **Planning:** Create an ADR/execution plan, compatibility range, ordered rollout/rollback, and proposed lock update.
5. **Bounded implementation:** Start only after contract and component owners approve exact write scopes and compatibility evidence requirements.
6. **Validation:** Run provider, consumer, negative, versioning, immutable-ref, and matrix tests.
7. **Independent review:** Require architect, contract auditor, reviewer, and security reviewer for trust/mutation exposure.
8. **Documentation/contract updates:** Update the owning runtime contract and projections, then metadata/digests; do not copy executable contracts into Codex OS.
9. **Completion evidence:** Provide exact SHAs/digests, compatibility result, gates, approvals, rollout, rollback, and unresolved exceptions.

Treat a pinned commit alone as `unverified` or `incomplete`, never `proven`.
