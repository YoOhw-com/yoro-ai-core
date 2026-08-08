---
name: yoro-security-change
description: Govern YoRo authentication, authorization, tenant/site isolation, credentials, PII, billing, trust-boundary, signing, or security-gate changes. Use whenever any security risk flag applies; require an independent high-reasoning security reviewer and negative tests.
---

# YoRo Security Change

1. **Preflight:** Preserve baselines, deny secret-file access, and identify the security owner role.
2. **Evidence gathering:** Map actors, assets, trust boundaries, credentials, tenants/sites, scopes, logs, and failure behavior.
3. **Classification:** Assign `security/auth/tenant` unless another primary class is clearly stronger; retain all security risk flags.
4. **Planning:** Produce a threat model, least-authority write scope, negative tests, rollback/containment, and approval points.
5. **Bounded implementation:** Implement only after security review of the plan; never weaken an existing fail-closed gate.
6. **Validation:** Test authentication, authorization, tenant binding, replay, reauth, secret absence, and relevant failure paths.
7. **Independent review:** Require a security reviewer independent of the implementer and architecture review for trust-boundary changes.
8. **Documentation/contract updates:** Update owned contracts, ADRs, mutation records, and runbooks without secrets.
9. **Completion evidence:** Report reviewed threats, negative tests, residual risk, owner approvals, and integrity status.
