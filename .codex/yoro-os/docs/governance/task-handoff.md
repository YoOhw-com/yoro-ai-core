# Chat–Codex task handoff

Repository evidence is durable shared memory. ChatGPT, a human, or another independent reviewer may define or review work; Codex executes. No participant needs to remember a previous conversation when the specification, exact baselines, execution evidence, result SHA, and review are committed or attached to a PR.

## Records and states

Use the [task specification](../../templates/task-spec/task-spec.yaml) for objective, acceptance criteria, exact baselines, repository authority, routing, protected constraints, gates, recovery, and stops. Use the [execution handoff](../../templates/task-handoff/execution-handoff.yaml) for actual paths, agents, tests, deviations, integrity, result SHA/PR, and unresolved findings. The executor must leave independent review pending and cannot claim approval.

Use the [independent review](../../templates/independent-review/independent-review.yaml) to pin the reviewed commit or PR head, a non-empty independent reviewer identity and allowed reviewer class, inspected evidence, severity findings, disposition, residual risk, and next authorized action. `implementation_complete` is not `accepted`. A review-required task is accepted only when the execution state is complete, its reviewer identity/status match the independent record, and that record has an allowed acceptance disposition referencing exactly the execution result SHA and specification.

## Preferred workflow

1. Chat or a human defines business intent and approves a task specification.
2. Codex executes against the exact recorded baselines and writes an execution handoff.
3. The result is committed to an exact Git SHA or exposed as a PR with an exact head SHA.
4. An independent Chat/human reviewer reads the actual code, diff, tests, and handoff at that SHA.
5. The reviewer records a disposition. Only `approved` or `approved_with_findings` can accept the exact result.
6. The accepted result may become the next baseline; the next task is planned from repository evidence, not conversation memory.

The protocol does not authorize commits, pushes, PRs, releases, or deployments by itself.

## Component-primary portable workflow

An installed component uses `.codex/yoro-os/manifests/task-handoff.yaml` and the templates beneath `.codex/yoro-os/templates/`. The executor records actual execution evidence and an exact result commit or PR head when one exists, sets `execution_state: implementation_complete`, keeps `review_status: pending`, leaves `independent_reviewer_identity` unset, and keeps `accepted: false`. It may propose a next task but cannot authorize it.

Mutable task specifications, execution handoffs, and independent review records are component/review evidence, not generated OS source. Persist them only in an owner-approved component path outside `.codex/yoro-os/`—for example `docs/governance/task-runs/<task-id>/`—or as durable PR/commit review evidence. The component owner controls that path and its retention policy; the installer never creates, overwrites, rolls back, or claims ownership of these records.

An independent reviewer must inspect the exact result commit or PR head and produce a matching allowed disposition. Only then may a completed execution become accepted. A proposed next task remains unauthorized until that review and any task-specific owner approval are recorded.
