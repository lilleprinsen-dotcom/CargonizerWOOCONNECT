# LIVE_CHECKOUT_ROADMAP

Last updated: 2026-04-09

This roadmap breaks the checkout extension into small, reversible milestones while preserving current admin behavior.

## Milestone 0 — Contract + guardrails (documentation only)
- Strengthen AGENTS.md with checkout-extension phase policy.
- Add `LIVE_CHECKOUT_SPEC.md` and this roadmap.
- Define invariants, API contract, metadata contract, and settings extension shape.

## Milestone 1 — Shipping method skeleton (no pricing logic change)
- Register one WooCommerce shipping method (`lp_cargonizer_live`) for zones.
- Add settings bootstrap for new `live_checkout` subtree (backward-compatible defaults only).
- Ensure method can initialize without affecting admin-only flows.

## Milestone 2 — Quote engine extraction/adaptation (read-only reuse first)
- Reuse existing API/auth/request builders for checkout-safe quote calls.
- Build internal quote engine that can compute multiple candidate rates from one method instance.
- Keep estimator/booking logic parity with existing code where shared.

## Milestone 3 — Multi-rate output from one shipping method
- Map quote-engine outputs to multiple `WC_Shipping_Rate` objects.
- Include Norway-only gating and prices-including-VAT display behavior.
- Add deterministic rate IDs for stable selection.

## Milestone 4 — Pickup point model on rates
- Add rate-attached pickup point payloads using:
  - `krokedil_pickup_points`
  - `krokedil_selected_pickup_point`
  - `krokedil_selected_pickup_point_id`
- Implement nearest-point preselection.
- Allow customer override path.

## Milestone 5 — Checkout save flows (classic + Store API)
- Persist selected shipping method and pickup point to new order meta `_lp_cargonizer_checkout_selection`.
- Support both classic checkout and Store API save hooks.
- Preserve all existing order meta behavior including `_lp_cargonizer_booking_state`.

## Milestone 6 — Rules engine (hide/show + profile logic)
- Implement rule evaluation for:
  - value, weight, profile, category,
  - missing dimensions,
  - separate-package presence,
  - security/high-value policies.
- Keep rules additive and configurable under `lp_cargonizer_settings.live_checkout.method_rules`.

## Milestone 7 — Pricing business-rule layer
- Under NOK 1500: always expose NOK 69 option sourced from cheapest eligible live estimate.
- Above NOK 1500: configurable free-shipping mode defaulting to cheapest standard eligible option free.
- Ensure rule outcomes are transparent in debug diagnostics.

## Milestone 8 — Dimension fallback resolver
- Implement admin-configurable dimension fallback order.
- Ensure separate-package product meta remains authoritative.
- Add safe handling for missing/invalid package dimensions.

## Milestone 9 — Cache + resilience layer
- Add request caching for quotes and pickup points.
- Add invalidation strategy tied to cart/address/method-affecting inputs.
- Add timeout + safe fallback behavior for API failures.

## Milestone 10 — Admin booking prefill from checkout selection
- Prefill existing admin booking modal from `_lp_cargonizer_checkout_selection`.
- Keep admin override always available.
- Do not regress existing booking-state tracking and history.

## Milestone 11 — Regression hardening + rollout controls
- Expand regression checklist for checkout flows and existing admin invariants.
- Add feature flags and staged enablement.
- Validate backward compatibility of `lp_cargonizer_settings` updates.

