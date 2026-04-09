# LIVE_CHECKOUT_SPEC

Last updated: 2026-04-09  
Status: Implementation contract for checkout-extension prompts

## 1) Current codebase baseline (must be treated as authoritative)

### Plugin baseline
- Plugin entrypoint: `lilleprinsen-cargonizer-connector.php`.
- Current implementation is admin-focused (settings page, order modal, admin-side estimation/booking AJAX flows).
- Core orchestrator: `LP_Cargonizer_Connector` in `includes/class-lp-cargonizer-connector.php`.
- API integration and XML assembly/parsing logic: `includes/class-lp-cargonizer-api-service.php`.
- Price-source selection and estimate math logic: `includes/class-lp-cargonizer-estimator-service.php`.
- Settings schema/sanitization/defaulting: `includes/class-lp-cargonizer-settings-service.php`.
- Admin rendering and AJAX controller traits:
  - `includes/trait-lp-cargonizer-admin-page.php`
  - `includes/trait-lp-cargonizer-ajax-controller.php`

### Existing behavior already present and must stay intact
- Existing auth flow and request headers are already working.
- Existing transport-agreement retrieval is working.
- Existing servicepartner lookup and auto-selection behavior is working.
- Existing live estimate behavior, price source fallback order, manual Norgespakke handling, Bring manual handling, DSV optimization, and SMS-service handling are working.
- Existing booking flow + printer support + booking-state persistence + admin modal UX are working.

## 2) Final target architecture for checkout extension

- Add **one real WooCommerce shipping method/integration** that can be added in shipping zones.
- That one method must return **multiple real WooCommerce rates** from one internal Cargonizer-based rating engine.
- Norway-only checkout scope in this phase.
- Pickup-point capable rates must carry attached pickup-point data compatible with embedded checkouts.
- Nearest pickup point must be preselected automatically; customer may override.
- Selected shipping method + pickup point must be persisted to order data via:
  - classic checkout hooks, and
  - Store API (Blocks) order-save hooks.
- Existing admin “Book shipment” flow must later prefill from saved checkout choice while retaining admin override.

## 3) Required invariants to preserve during all later prompts

1. No breaking change to current admin estimator behavior.
2. No breaking change to current admin booking behavior.
3. No auth changes (flow, headers, storage, sender/API key handling, endpoint base URL).
4. No change to existing nonce/action/option/hook/admin slug/public payload contracts unless explicitly approved.
5. No change to existing core business logic already in production.
6. Keep current product meta usage for separate-package handling.
7. Keep current booking-state order meta key and semantics.
8. New checkout config must be backward-compatible in `lp_cargonizer_settings`.

## 4) Business rules for checkout phase

- Norway only.
- Prices shown including VAT.
- For cart/order total under NOK 1500, there must always be an available option shown at NOK 69.
- The under-NOK-1500 low-price option should come from the cheapest eligible live carrier estimate.
- Above NOK 1500, default behavior: cheapest standard eligible option is free; this must be configurable.
- Admin must be able to hide/show methods via smart rules based on:
  - value,
  - weight,
  - profile,
  - category,
  - missing dimensions,
  - separate-package presence,
  - security/high-value rules.
- Missing dimensions must resolve via admin-configurable fallback order.
- Admin must manage shipping profiles.
- Safe fallback behavior must exist for quote failures/timeouts.

## 5) Cargonizer API contract for checkout work (source of truth)

Use the same API family and exact field/header names:

### Base + headers
- Base URL: `https://api.cargonizer.no`
- Required auth headers:
  - `X-Cargonizer-Key`
  - `X-Cargonizer-Sender`

### Endpoints that matter for checkout extension
- `transport_agreements.xml` (method and service capability discovery)
- `service_partners.xml` (pickup/service points)
- `consignment_costs.xml` (live cost estimation)

### Request fields relevant for service points (`service_partners.xml`)
Use documented query fields (as applicable per product/carrier and agreement context):
- `transport_agreement_id`
- `product`
- `carrier`
- `country`
- `postcode`
- `address`
- optional carrier/product-specific custom params under `custom[params][...]`

### Request fields relevant for cost estimation (`consignment_costs.xml`)
Request body mirrors consignment XML model. Checkout estimation payload must include valid equivalents of:
- root `<consignments>` / `<consignment>`
- `transport_agreement` (attribute)
- `<product>`
- `<parts><consignee>...` (recipient address including postcode/city/country)
- `<items><item ...>` with package data needed by selected product
- optional `<service_partner>` when product requires pickup point
- optional `<services>` when carrier-product requires extra services

### Response fields used in pricing extraction
- `estimated-cost`
- `gross-amount`
- `net-amount`

## 6) Existing metadata keys that must remain in use

### Product meta (must remain authoritative for separate-package handling)
- `_wildrobot_separate_package_for_product`
- `_wildrobot_separate_package_for_product_name`

### Order meta (must remain authoritative for booking state)
- `_lp_cargonizer_booking_state`

## 7) Proposed new order meta for checkout selection persistence

### Key
- `_lp_cargonizer_checkout_selection`

### Intended schema (v1)
```json
{
  "version": 1,
  "saved_at_gmt": "YYYY-MM-DD HH:MM:SS",
  "source": "classic_checkout|store_api",
  "shipping": {
    "method_id": "lp_cargonizer_live",
    "rate_id": "lp_cargonizer_live:instance_id:rate_key",
    "label": "Carrier Product Label",
    "cost_incl_vat": "69.00",
    "currency": "NOK",
    "transport_agreement_id": "string",
    "carrier_id": "string",
    "product_id": "string"
  },
  "pickup_point": {
    "required": true,
    "selected_id": "string",
    "selected": {
      "id": "string",
      "name": "string",
      "address1": "string",
      "address2": "string",
      "postcode": "string",
      "city": "string",
      "country": "NO",
      "customer_number": "string",
      "distance_meters": 0,
      "opening_hours": "string"
    },
    "selection_source": "auto_nearest|customer_override"
  },
  "krokedil": {
    "krokedil_pickup_points": [],
    "krokedil_selected_pickup_point": {},
    "krokedil_selected_pickup_point_id": "string"
  },
  "quote_context": {
    "package_index": 0,
    "instance_id": "string",
    "rate_meta": {}
  },
  "packages": [
    {
      "shipping": {
        "selected_service_ids": [],
        "available_service_ids": []
      }
    }
  ]
}
```

Notes:
- This is additive and must not replace `_lp_cargonizer_booking_state`.
- Schema is designed to feed future admin booking prefill while allowing admin override.

## 8) Proposed settings schema extension inside `lp_cargonizer_settings`

All keys below are additive and backward-compatible under a new nested key: `live_checkout`.

```json
{
  "live_checkout": {
    "enabled": 0,
    "scope": {
      "country_whitelist": ["NO"],
      "prices_include_vat": 1
    },
    "method_engine": {
      "shipping_method_id": "lp_cargonizer_live",
      "instance_support": 1,
      "return_multiple_rates": 1
    },
    "pricing_rules": {
      "under_1500_nok": {
        "enabled": 1,
        "threshold": 1500,
        "display_price": 69,
        "source": "cheapest_eligible_live"
      },
      "over_1500_nok": {
        "enabled": 1,
        "threshold": 1500,
        "free_shipping_mode": "cheapest_standard_eligible"
      }
    },
    "shipping_profiles": {
      "default_profile_id": "default",
      "profiles": []
    },
    "method_rules": {
      "rules": [],
      "conditions_supported": [
        "order_value",
        "weight",
        "profile",
        "category",
        "missing_dimensions",
        "separate_package_presence",
        "security_high_value"
      ]
    },
    "dimensions": {
      "fallback_resolution_order": [
        "product_shipping_dimensions",
        "product_dimensions",
        "category_defaults",
        "global_defaults"
      ]
    },
    "pickup_points": {
      "enable": 1,
      "preselect_nearest": 1,
      "metadata_keys": {
        "pickup_points": "krokedil_pickup_points",
        "selected_pickup_point": "krokedil_selected_pickup_point",
        "selected_pickup_point_id": "krokedil_selected_pickup_point_id"
      }
    },
    "cache": {
      "quotes_enabled": 1,
      "quotes_ttl_seconds": 300,
      "pickup_points_enabled": 1,
      "pickup_points_ttl_seconds": 300
    },
    "fallback": {
      "on_quote_failure": "safe_fallback_rate",
      "safe_fallback_label": "Standard frakt",
      "safe_fallback_price_incl_vat": 69,
      "log_failures": 1,
      "timeout_ms": 5000
    }
  }
}
```

Implementation note:
- Existing top-level settings keys remain untouched.
- Sanitization/defaults for `live_checkout` must gracefully handle absence of this key.

## 9) Non-goals for this current documentation prompt

- No runtime PHP/JS logic changes.
- No hook wiring changes.
- No checkout UI behavior changes yet.
- No migration routines executed yet.

## 10) Reusable package/profile/rule subsystem (implemented 2026-04-09)

The codebase now contains additive reusable services intended for both future checkout and current/future admin reuse:

- `LP_Cargonizer_Package_Resolution_Service`
  - Resolves the effective package-dimension fallback order from `lp_cargonizer_settings.package_resolution.fallback_sources`.
  - Guarantees a stable fallback chain with defaults appended if missing.

- `LP_Cargonizer_Shipping_Profile_Resolver`
  - Resolves package profile + dimensions/weight per product using configurable priority order.
  - Supported sources in order (driven by package-resolution settings):
    1. product dimensions/weight
    2. product override
    3. shipping class profile
    4. category profile
    5. value-based rule
    6. default profile
  - Returns a resolution trace for diagnostics/replay.

- `LP_Cargonizer_Package_Builder`
  - Builds reusable package/colli output from cart/order lines.
  - Preserves existing separate-package semantics using:
    - `_wildrobot_separate_package_for_product`
    - `_wildrobot_separate_package_for_product_name`
  - Multi-quantity separate-package lines become multiple collis.
  - Non-separate lines become one combined package with summary metadata.

- `LP_Cargonizer_Method_Rule_Engine`
  - Evaluates method eligibility before customer-pricing transformation.
  - Uses context dimensions:
    - order value
    - total weight
    - resolved profile slugs
    - category presence
    - separate-package presence
    - missing-dimensions signal
    - high-value/security flag presence
  - Reads rules from `lp_cargonizer_settings.checkout_method_rules.rules`.

Important compatibility note:
- Existing admin estimator/booking/AJAX flows are intentionally unchanged in runtime behavior in this milestone; new services are additive scaffolding only.

## 11) Hardening notes (implemented 2026-04-09)

- Live quote caching now keys on:
  - method identity (`method_key`, agreement/carrier/product),
  - resolved package payload,
  - destination recipient fields,
  - method-pricing context and relevant live-checkout context.
- Pickup-point caching now keys on:
  - destination (`country`, `postcode`, `city`, `address`),
  - method context (agreement/carrier/product),
  - applicable `custom[params]` context.
- Norway destination readiness guard:
  - live quotes are not requested before country=`NO`, postcode, and city are available.
  - pickup-point lookups are not requested before country=`NO`, postcode, city, and address are available.
- Checkout pickup-point UI updates remain asynchronous (`update_checkout`) and do not rely on full page reloads.
- Fallback behavior remains settings-driven and is applied when live quote collection does not yield any usable checkout rates.
- High-noise estimate dimension debug logging is now gated behind debug toggles (`debug_logging` / `live_checkout.debug_logging`) or `WP_DEBUG`.
