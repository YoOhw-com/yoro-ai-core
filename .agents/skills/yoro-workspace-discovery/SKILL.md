---
name: yoro-workspace-discovery
description: Discover and report YoRo repository boundaries, Git integrity, runtime ownership, contracts, tests, and gates without mutation. Use for inventories, architecture reports, evidence refreshes, preflight baselines, or when a task spans yoro-ai-api, yoro-ai-core, yoro-apps, and yoro-codex-os.
---

# YoRo Workspace Discovery

1. Record each repository path by component ID, HEAD, branch, and complete status without reading secret files.
2. Treat pre-existing changes as owner-controlled and all components as read-only unless the task explicitly authorizes one.
3. Read `../../../.codex/yoro-os/manifests/components.yaml` and the relevant current bootstrap evidence.
4. Trace executable registrations, guards, schemas, tests, and CI before prose.
5. Label every conclusion `OBSERVED`, `INTENDED`, `PROPOSED`, or `UNRESOLVED`.
6. Report component, commit, file/symbol, confidence, and unresolved owner role. Never change files, install dependencies, or run stateful commands during discovery.
