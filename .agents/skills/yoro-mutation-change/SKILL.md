---
name: yoro-mutation-change
description: Evaluate and govern a YoRo write/mutation family against authorization, scope, preflight, confirmation, step-up, concurrency, idempotency, replay, durable reporting, audit, side effects, and recovery. Use for any store, cloud, external communication, admin, billing, or data mutation; stop before writes when any affected property is unknown.
---

# YoRo Mutation Change

1. **Preflight:** Record baselines, exact action family, data owner, and writable component.
2. **Evidence gathering:** Read the matching family in `../../../.codex/yoro-os/manifests/mutation-safety.yaml` and trace every declared property to executable evidence.
3. **Classification:** Add `mutation` and any contract, security, communication, billing, or irreversible flags.
4. **Planning:** Define authorization, scope, preflight, confirmation, step-up, concurrency, idempotency, replay, result/audit, side effects, compensation, and recovery tests.
5. **Bounded implementation:** Stop if any affected property is `unknown`, the family is unclassified, or required owner/reviewer is absent.
6. **Validation:** Run optimistic-version/concurrency, duplicate/replay, confirmation/reauth, partial failure, durable result/audit, and recovery tests as applicable.
7. **Independent review:** Require security review and architecture review for high blast radius or recovery policy changes.
8. **Documentation/contract updates:** Update the per-family matrix from evidence; never infer universal guarantees.
9. **Completion evidence:** Report every property and recovery classification, tests, reviewers, residual unknowns, and rollback/recovery procedure.
