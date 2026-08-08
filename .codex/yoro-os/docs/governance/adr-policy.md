# ADR governance

Use the [ADR template](../../templates/adr/ADR-template.md) and [ADR governance registry](../../manifests/adr-governance.yaml). Installed component copies are read-only governance evidence; organization-wide ADRs remain canonical in `yoro-codex-os`. ADR states are `proposed`, `accepted`, `implementing`, `verified`, `superseded`, and `rolled_back`.

An ADR is mandatory for component ownership changes, public contract version changes, new connector or execution modes, MCP write/scope changes, trust-boundary changes, new cloud-held credentials or PII, idempotency/replay changes, rollback/recovery changes, and protected-gate exceptions.

The coding agent is not an architectural, security, product, or release owner. Record owner roles; where no person/group is assigned, mark assignment `UNRESOLVED`. Accepted decisions require the designated human owner role and independent reviews called for by routing.
