# Distribution and adoption

The canonical ownership registry is `manifests/governance-installation.yaml`. Every destination is declared as a generated full file, generated block in an owner-controlled file, component-owned file, canonical-only source, or read-only evidence reference. Existence never implies ownership.

## Managed-only planning

`render` and `diff` build an in-memory desired map and read only declared managed destinations. They never copy a target repository, enumerate its children, traverse ignored/untracked content, or inspect `.env`, dependencies, caches, databases, build output, or SDK state. A preview output contains only managed candidate files. `AGENTS.md` and `.gitignore` are marker merges; bytes outside their markers remain component-owned.

The installed package includes project-discoverable YoRo agents in `.codex/agents/yoro-*.toml`, skills in `.agents/skills/yoro-*`, and supporting routing/model/mutation/component/provenance material under `.codex/yoro-os`. It also includes portable task-handoff schemas/templates/documentation and read-only invariant/ADR governance. Runtime contracts and mutable task/review records are not copied. There is no hidden local overlay: root instructions point directly to the installed package and component rules remain outside generated markers or in existing component-owned skills/configuration.

The generated `.gitignore` block first keeps `.codex` private by default, then narrowly allows YoRo agent files and `.codex/yoro-os` portable content. It does not allow `.codex/config.toml`, rollback data, or unrelated local Codex state. Existing tracked files remain tracked and existing ignored local configuration remains untouched. `git add -f` is not used.

## Drift, provenance, and rollback

Before any overwrite, install validates metadata, recomputes every recorded full-file or block digest, and returns `generated_content_drift` on disagreement. Normal install never repairs drift; v0.1 has no repair mode. The adopter must resolve drift under explicit owner authority.

Adopted provenance is derived from the canonical governance Git repository. HEAD must be a full SHA, the source tree must have no tracked or untracked changes, every rendered source and `VERSION` must be tracked, and an optional caller SHA must equal actual HEAD. `UNCOMMITTED-PREVIEW` is accepted only by preview operations.

Rollback snapshots only the immediate previous managed state. Blocks are restored or removed inside the current owner-controlled file, so unrelated later owner content survives. Full generated files are restored only to their prior declared state. All destinations and rollback paths are boundary-checked and unexpected symlinks fail closed; writes are atomic where practical.

Agent `sandbox_mode`, candidate repository scope, task-specific authorization, effective repository scope, and environment/UI filesystem permission are separate. Component writes require preflight evidence that effective scope is contained by the observed environment boundary. Repository adoption is first-class, single-component work: the installer and rollback command resolve the supplied route-input again; effective scope and the observed writable boundary must both equal the explicitly authorized target, and managed governance paths cannot include runtime source.
