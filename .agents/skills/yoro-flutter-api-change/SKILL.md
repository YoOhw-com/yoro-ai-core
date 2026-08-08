---
name: yoro-flutter-api-change
description: Govern changes between the Flutter client and Laravel App Gateway, including routes, OpenAPI, models, activity, confirmation/rejection, operation polling, retry/backoff, timeout, cancellation, and lifecycle tests. Use for Flutter API transport/model changes; current App Gateway gaps require a separate product-contract plan and must not be silently finalized.
---

# YoRo Flutter API Change

1. **Preflight:** Baseline yoro-ai-api and yoro-apps and keep both read-only during reconciliation.
2. **Evidence gathering:** Compare Laravel registrations/resources/tests, OpenAPI paths/schemas, Flutter production client/models/UI, and mocks.
3. **Classification:** Use `cross-repository contract` for producer/consumer changes; a UI-only local change remains component-local.
4. **Planning:** Follow `../../../.codex/yoro-os/docs/contracts/app-gateway-reconciliation.md`; assign product/API/app/security owners and create an ADR.
5. **Bounded implementation:** Do not implement activity, confirmation/rejection, polling, backoff, timeout, cancellation, or version semantics until the ADR and lock plan are approved.
6. **Validation:** Require route/OpenAPI/client parity, lifecycle, fake-clock/network, replay, authorization, tenant-isolation, timeout, cancellation, and consumer tests.
7. **Independent review:** Require architect, contract auditor, security reviewer, and reviewer.
8. **Documentation/contract updates:** Update the API-owned OpenAPI and Flutter consumer models with immutable digest evidence.
9. **Completion evidence:** Report SHAs, versions/digests, provider/consumer results, lifecycle decisions, rollout/rollback, and unresolved exceptions.
