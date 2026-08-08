---
name: yoro-repository-adoption
description: Preview, diff, check, install, or roll back a deterministic YoRo Codex OS governance layer in an explicitly authorized component repository. Use for repository adoption or drift remediation; preserve component-owned instructions and Codex files, read only declared managed paths, never copy secrets/runtime contracts, and default to preview-only.
---

# YoRo Repository Adoption

1. **Preflight:** Record target HEAD/status, component profile, existing instructions, and explicit write authorization.
2. **Evidence gathering:** Read `../../../.codex/yoro-os/manifests/governance-installation.yaml`; from the authorized canonical governance checkout, read its shared template and component overlay. In the target read prior metadata and only explicitly declared managed paths. Never recursively enumerate or copy the repository.
3. **Classification:** Assign the first-class `repository adoption` primary class. Exactly one of `yoro-ai-api`, `yoro-ai-core`, or `yoro-apps` is the candidate target; multi-component adoption is unsupported in v0.1.2.
4. **Planning:** Render an in-memory managed-file plan into a temporary or fixture output; identify generated blocks, generated files, component ownership, Git tracking, and rollback scope.
5. **Bounded implementation:** Pass the approved route-input record to the authorized canonical `yoro-codex-os` installer (`scripts/install-component.sh` in that checkout); the installer resolves it again and writes only when component-scoped adoption authority is bound to the exact target component, requested operation, and target baseline SHA, `effective_write_scope` equals the one candidate target, and the observed environment/UI writable boundary equals that target. Each install, update, check, or rollback entry point must match that authorized operation; a read-only check route cannot authorize a write. Derive clean Git provenance, verify prior digests before overwrite, and fail on drift, marker conflict, unknown ownership, runtime-source paths, symlinks, absolute paths, or secrets. Normal install is not repair.
6. **Validation:** Run check, deterministic double-render, install/check/install idempotence, component-content preservation, reference resolution, narrow-ignore tracking, rollback, drift, and digest validation.
7. **Independent review:** Review every generated file/block and preservation evidence against the exact target baseline. Installation completion is not acceptance; create component-owned execution evidence, leave review pending, and never self-approve.
8. **Documentation/contract updates:** Record OS version and immutable source governance commit; do not modify application source or runtime contracts.
9. **Completion evidence:** Report target files, digests, diff, validation, rollback identifier, and post-install Git status.
