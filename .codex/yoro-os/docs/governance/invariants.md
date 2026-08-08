# Architectural invariant registry

The [invariant registry](../../manifests/invariants.yaml) is authoritative in `yoro-codex-os`. An installed component copy is read-only governance evidence. An active entry must be `OBSERVED`, or be a `PROPOSED` decision with explicit accepted decision status and evidence. `UNRESOLVED` entries may be tracked only as inactive candidates.

Each entry records its statement, classification, status, owner role, assignment state, affected components, evidence, enforcing gates, allowed exceptions, and verification baseline. Exceptions require an accepted ADR and cannot weaken a runtime security control that already fails closed.
