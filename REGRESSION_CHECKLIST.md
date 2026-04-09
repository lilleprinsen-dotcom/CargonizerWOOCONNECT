# Final Regression Checklist (2026-04-08)

Scope: compare current refactored plugin to original monolithic implementation at commit `deb7659`.

## Checklist

| Invariant | Status | Verification summary |
|---|---|---|
| authentication headers and auth flow | PASS | `X-Cargonizer-Key`, `X-Cargonizer-Sender`, and `Accept: application/xml` preserved; settings-sourced auth flow unchanged. |
| Cargonizer endpoints | PASS | Same endpoints preserved: `transport_agreements.xml`, `service_partners.xml`, `consignment_costs.xml`. |
| nonce action strings | PASS | All nonce constants unchanged. |
| AJAX action names | PASS | All `wp_ajax_lp_cargonizer_*` actions unchanged. |
| option key | PASS | `lp_cargonizer_settings` unchanged. |
| hooks | PASS | Admin, WooCommerce order details, admin footer, and AJAX hooks preserved. |
| admin page slug | PASS | `lp-cargonizer` unchanged. |
| settings schema and defaults | PASS | Same top-level keys and method pricing defaults preserved. |
| available/enabled methods behavior | PASS | Available methods sanitization, enabled filtering, and internal manual method enforcement preserved. |
| manual Norgespakke behavior | PASS | Manual method key and estimator path preserved. |
| servicepartner behavior | PASS | Servicepartner endpoint/query/autodetect behavior preserved. |
| SMS-service behavior | PASS | SMS service inclusion in request XML and fallback selection behavior preserved. |
| XML request generation | PASS | Consignment XML structure and package field mapping preserved. |
| XML parsing behavior | PASS | Transport agreement and servicepartner parsing behavior preserved. |
| price-source selection and fallback order | PASS | Priority map and fallback resolution unchanged. |
| Bring manual handling behavior | PASS | Bring detection and handling fee trigger/fee rules preserved. |
| DSV optimization behavior | PASS | DSV partition generation/evaluation logic preserved in estimator service + AJAX optimizer flow. |
| JSON response shapes | PASS | `wp_send_json_success/error` payload keys and response structure preserved. |
| modal UI behavior | PASS | Same modal markup + behavior retained, JS moved to external asset with localized nonces. |
| error/debug payload behavior | PASS | Error handling and debug payload keys/messages preserved. |

## Structural verification

- PHP linted all plugin PHP files (`php -l`) with no syntax errors.
- Verified all bootstrap `require_once` targets exist.
- Verified no duplicate class/trait declarations in `includes/*.php`.
- Verified bootstrap remains `lilleprinsen-cargonizer-connector.php` requiring connector/services/plugin orchestrator classes.

## Manual checkout regression scenarios (live checkout hardening)

Run these scenarios with live checkout enabled, at least one pickup-capable method, and at least one non-pickup method:

1. **Live checkout rates (baseline happy path)**
   - Enter a Norwegian destination with postcode + city.
   - Confirm rates appear from `lp_cargonizer_live` without page reload.
   - Change address/postcode and verify rates refresh asynchronously.

2. **Pickup-point selection**
   - Choose a pickup-capable rate.
   - Verify pickup dropdown renders with nearest point preselected.
   - Change selection and confirm checkout refreshes asynchronously and keeps the selected point.

3. **Embedded checkout persistence assumptions (Dintero-style)**
   - Complete checkout through a Store API / embedded checkout path.
   - Verify compatibility endpoint `admin-ajax.php?action=lp_cargonizer_get_checkout_pickup_points` returns pickup-point context for current shipping rates.
   - Verify order meta `_lp_cargonizer_checkout_selection` contains:
     - selected method/rate context
     - `krokedil_pickup_points`
     - `krokedil_selected_pickup_point`
     - `krokedil_selected_pickup_point_id`
   - Verify changing pickup point dispatches `lp_cargonizer_pickup_point_updated` even when classic checkout markup listeners are not present.

4. **Under-threshold NOK 69 behavior**
   - Set cart subtotal below configured threshold (default NOK 1500).
   - Confirm at least one eligible live method is priced to configured low-price value (default NOK 69).

5. **Over-threshold free shipping behavior**
   - Set cart subtotal above threshold.
   - Confirm cheapest eligible standard method is free when configured strategy is `cheapest_standard_eligible`.

6. **Separate-package multi-colli behavior**
   - Add products with `_wildrobot_separate_package_for_product = yes`, quantity > 1.
   - Verify package builder splits to multiple collis and live quotes still return.

7. **Fallback behavior on API failure/timeout**
   - Simulate quote failures/timeouts (bad key/network block/forced timeout).
   - Confirm configured fallback rates are shown when quote collection yields no usable live rate.

8. **Admin booking prefill from checkout selection**
   - Place order with live checkout method and pickup-point selection.
   - In admin booking modal, verify checkout selection metadata is available for prefill/override paths.

## Zip readiness

The plugin directory is structurally complete and ready for external zipping.

- Plugin folder path: `/workspace/CargonizerWOOCONNECT`
- Zip command from repo root: `zip -r lilleprinsen-cargonizer-connector.zip .`

Note: no archive was created in this task.
