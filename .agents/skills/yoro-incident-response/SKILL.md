---
name: yoro-incident-response
description: Investigate a suspected YoRo production incident with evidence preservation, severity, tenant/site/action/contract blast radius, containment options, unknown-outcome reconciliation, recovery classification, credential escalation, regression gates, and follow-up governance. Use for incidents or recovery analysis; production state changes require explicit human authority.
---

# YoRo Incident Response

1. Preserve commits, versions, contract snapshots, trace/operation IDs, logs, and timelines without exposing secrets.
2. Classify severity and tenant/site/action/contract blast radius; keep unknown outcomes explicit.
3. Identify the smallest containment options and required incident/security/customer-communication authorities.
4. Reconcile cloud and store state independently before recovery.
5. Apply the mutation family recovery classification; unsupported/unknown recovery stops automatic action.
6. Require explicit authority for containment, credentials, customer communication, destructive recovery, or production changes.
7. Run regression and state-reconciliation gates after authorized recovery.
8. Populate `../../../.codex/yoro-os/templates/incident/incident-record.yaml` and propose any ADR, contract-lock, mutation-matrix, or process updates.
