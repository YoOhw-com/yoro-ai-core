---
name: yoro-release-readiness
description: Assemble and review YoRo release evidence from immutable component SHAs, contract digests, compatibility checks, gates, approvals, deployment order, rollback checkpoints, and unresolved exceptions. Use for readiness assessment only; never authorize, sign, publish, deploy, or release.
---

# YoRo Release Readiness

1. Record clean immutable component inputs and Codex OS version.
2. Validate governance installation drift and `../../../.codex/yoro-os/manifests/contract-locks.yaml`.
3. Require producer/consumer compatibility evidence; reject floating refs and pins without verification.
4. Run or collect all routed component, contract, security, signing, and release gates.
5. Populate `../../../.codex/yoro-os/templates/release-evidence/release-evidence.yaml` with deployment order, rollback checkpoints, approvals, and exceptions.
6. Invoke the independent release reviewer and any required contract/security reviewers.
7. Recommend only `ready`, `not_ready`, or `owner_decision_required`. Never perform production action or act as release owner.
