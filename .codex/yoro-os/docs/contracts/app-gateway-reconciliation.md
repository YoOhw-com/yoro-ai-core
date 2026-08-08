# Future App Gateway reconciliation workflow

This workflow is for a separate product-contract task. It does not select endpoint shapes or implement runtime behavior.

1. Baseline Laravel routes/controllers/resources/tests, OpenAPI, Flutter routes/models/UI, and current immutable commits.
2. Assign the product contract owner, API producer owner, Flutter consumer owner, security owner, and release owner; unresolved assignments stop implementation.
3. Create an ADR choosing activity/timeline representation, confirmation/rejection transport, contract version strategy, and operation lifecycle semantics.
4. Specify polling, retry/backoff, timeout, cancellation, same-ID safe resume, operation-status, expiry, replay, reauthentication, and tenant-isolation behavior.
5. Update producer and consumer contracts in a coordinated plan with immutable contract-lock evidence.
6. Test routes, OpenAPI, client models, activity, pending confirmation/rejection, lifecycle, negative authorization, replay, timeout, cancellation, and recovery.
7. Run independent contract, architecture, security, and reviewer passes. Release remains separately human-authorized.

Until completed, Flutter activity and pending-operation endpoints and production lifecycle behavior are `UNRESOLVED` and must not be encoded as invariants.
