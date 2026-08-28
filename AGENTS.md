# YoRo AI Core Engineering Guidance

## Component authority

This repository owns the WordPress connector and store-side runtime for YoRo.
Read `AI_FIRST_ENGINEERING.md` before changing behavior; it is the primary
component-local technical authority.

- YoRo AI API owns conversation reasoning and canonical API or commerce
  contracts.
- YoRo AI Core owns WordPress-local context and capability exposure, validation,
  permission and confirmation enforcement, execution, rendering, and structured
  result reporting.
- Keep natural-language interpretation and conversation state out of the plugin.
  Do not introduce phrase-specific routing or redefine contracts owned by another
  repository.

## Working rules

- Identify the owning layer before making a fix.
- Preserve WordPress and WooCommerce capability, authorization, confirmation,
  idempotency, and fail-closed boundaries.
- Keep changes narrowly scoped and preserve unrelated user work.
- Do not add dependencies or alter public contract semantics without explicit
  authority.
- Run the focused affected tests and the applicable smoke/contract checks before
  handing off a change.

## Local coordination overlay

A locally generated `AGENTS.override.md` may add machine-specific coordination
guidance. It is untracked, must not be committed, does not replace this file, and
does not supersede `AI_FIRST_ENGINEERING.md` or executable component authority.
