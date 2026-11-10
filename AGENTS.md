# YoRo AI Core Engineering Guidance

## Component authority

This repository owns the WordPress connector and store-side runtime for YoRo.
Read `AI_FIRST_ENGINEERING.md` before changing behavior; it is the primary
component-local technical authority.

- The primary logical path is `ChatGPT/Codex -> YoRo API -> YoRo Core -> WordPress/WooCommerce`. YoRo AI API owns canonical operational semantics, action/capability identity, authority/Safety, orchestration, durable operation state and API/commerce contracts. AI hosts and retained Apps access API only; no direct connector bypass is permitted.
- YoRo AI Core owns WordPress-local context and capability exposure, validation,
  permission and confirmation enforcement, execution, rendering, and structured
  result reporting.
- Keep natural-language interpretation and conversation state out of the plugin.
  Do not introduce phrase-specific routing or redefine contracts owned by another
  repository.
- Retained Apps/native chat and their API conversation/provider dependencies remain supported for compatibility. Chat-specific rules apply to those retained paths; an already-structured MCP request does not require a second inference merely for reinterpretation. Preserve shared policy, storage, audit and lifecycle services.

## Working rules

- Identify the owning layer before making a fix.
- Preserve WordPress and WooCommerce capability, authorization, confirmation,
  idempotency, and fail-closed boundaries.
- Keep changes narrowly scoped and preserve unrelated user work.
- Do not add dependencies or alter public contract semantics without explicit
  authority.
- Run the focused affected tests and the applicable smoke/contract checks before
  handing off a change. Use [the existing verification navigation](AI_FIRST_ENGINEERING.md#verification-navigation) for standalone PHP/Node, disposable WordPress/WooCommerce, exact-pair and authorized host evidence.

## Local coordination overlay

A locally generated `AGENTS.override.md` may add machine-specific coordination
guidance. It is untracked, must not be committed, does not replace this file, and
does not supersede `AI_FIRST_ENGINEERING.md` or executable component authority.

The intended hosted source surface is one genuine Core Quick Gate; exact pair,
WordPress/WooCommerce/MySQL, full PHP/Node smoke and package proofs use the local
API owner verifier and explicit clean roots/heads. Preserve actual native
semantics, immutable historical provenance and current live required contexts
until separately authorized cutover. Source guidance does not authorize merge,
settings adoption, runtime/Cloud effects or activation.
