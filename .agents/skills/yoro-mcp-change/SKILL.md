---
name: yoro-mcp-change
description: Govern YoRo MCP tool registration, catalog, schema, scope, annotations, confirmation, reauthentication, idempotency, or error behavior. Use for any MCP read/write/tool-scope change and run general runtime/catalog parity; require architecture and security review for writes or scope changes.
---

# YoRo MCP Change

1. **Preflight:** Record API HEAD/status and current runtime/catalog counts; 41/41 is the reconciled baseline.
2. **Evidence gathering:** Trace registered server list, tool class/schema, scopes, annotations, adapters, confirmation/reauth, idempotency, and errors.
3. **Classification:** Assign `MCP change` with `MCP`; add mutation/public-contract/security flags as applicable.
4. **Planning:** Define exact exposure delta, trust metadata, compatibility, error mapping, rollback, and lock impact.
5. **Bounded implementation:** Modify only the owning runtime/projection after required owners approve; never revive the historical 14-tool file as current.
6. **Validation:** Run the owning component parity gate or canonical `yoro-codex-os` `scripts/validate-mcp-parity.sh`; stop if neither exact gate is available. Then run schema, scope, annotation, approval, reauthentication, idempotency, and error tests.
7. **Independent review:** Require contract review; require architect and security reviewer for writes/scopes/trust changes.
8. **Documentation/contract updates:** Regenerate the component-owned current catalog and governance digest metadata without copying it into Codex OS.
9. **Completion evidence:** Report exact count/order/classes, trust metadata coverage, tests, digest, compatibility, and remaining error-mapping gaps.
