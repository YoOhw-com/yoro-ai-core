---
name: yoro-feature-delivery
description: Deliver a bounded component-local YoRo feature that does not change cross-repository architecture or public contracts. Use for ordinary Laravel, WordPress, or Flutter features after routing confirms one writable component; do not use for cross-repository, security, MCP, migration, or unresolved contract work.
---

# YoRo Feature Delivery

1. **Preflight:** Record all repository baselines and the one approved writable component.
2. **Evidence gathering:** Trace the current execution path, tests, local instructions, and contract surfaces.
3. **Classification:** Confirm `component-local feature` and add all risk flags; reroute if a public/cross-repository contract is touched.
4. **Planning:** Produce a bounded file plan, acceptance criteria, tests, reviewer, and recovery note.
5. **Bounded implementation:** Change only approved files; stop on architecture, contract, migration, or security expansion.
6. **Validation:** Run relevant local tests and changed-surface gates without installing dependencies.
7. **Independent review:** Invoke the reviewer and any risk-triggered specialist.
8. **Documentation/contract updates:** Update owned documentation/projections only when behavior actually changed; never create a competing runtime contract.
9. **Completion evidence:** Report diff scope, commands/results, unresolved items, integrity comparison, and rollback path.
