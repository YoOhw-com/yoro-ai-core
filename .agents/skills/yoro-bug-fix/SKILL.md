---
name: yoro-bug-fix
description: Reproduce, diagnose, fix, and regression-test a bounded component-local YoRo defect. Use for ordinary bugs with one owning component; reroute contract drift, security flaws, incidents, migrations, or cross-repository failures to their specialized workflow.
---

# YoRo Bug Fix

1. **Preflight:** Preserve Git baselines and confirm one writable component.
2. **Evidence gathering:** Reproduce safely and trace the failing execution path with the debugger/explorer.
3. **Classification:** Confirm `component-local bug fix`; add mutation, contract, security, or other flags revealed by evidence.
4. **Planning:** State root cause, smallest fix, regression test, affected contract, and rollback.
5. **Bounded implementation:** Fix the cause only; do not widen behavior or silently redesign contracts.
6. **Validation:** Run the regression first, then relevant component gates.
7. **Independent review:** Review correctness, regression risk, and missing negative cases.
8. **Documentation/contract updates:** Update only stale owned documentation or projections caused by the fix.
9. **Completion evidence:** Report reproduction, root cause, diff, test results, residual risk, and integrity comparison.
