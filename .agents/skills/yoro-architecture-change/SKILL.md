---
name: yoro-architecture-change
description: Govern YoRo architecture decisions involving component ownership, trust boundaries, migration architecture, concurrency/idempotency, connector modes, recovery policy, or high blast radius. Use before implementation and require an ADR plus architecture-owner review; remain read-only until the decision is accepted.
---

# YoRo Architecture Change

1. **Preflight:** Baseline all affected repositories and keep them read-only.
2. **Evidence gathering:** Trace current boundaries, ownership, data, contracts, failure modes, and gates.
3. **Classification:** Assign `architecture` and relevant risk flags.
4. **Planning:** Read `../../../.codex/yoro-os/manifests/invariants.yaml` and `../../../.codex/yoro-os/manifests/adr-governance.yaml`, then draft `../../../.codex/yoro-os/templates/adr/ADR-template.md` with alternatives, invariants, compatibility, rollout, and recovery.
5. **Bounded implementation:** Permit only governance/ADR edits until acceptance; component implementation requires a new approved execution plan.
6. **Validation:** Validate ADR metadata, invariant impact, contract locks, and rollback design.
7. **Independent review:** Require architect plus contract/security reviewers indicated by risk.
8. **Documentation/contract updates:** Update canonical organization-wide ADRs and invariant registries only through the approved governance process after acceptance; a portable component copy is read-only evidence and never becomes canonical. Never activate an unresolved behavior.
9. **Completion evidence:** Record decision state, owner assignment, approvals, open questions, and next authorized task.
