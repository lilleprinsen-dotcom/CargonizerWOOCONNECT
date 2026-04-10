# Final Regression Checklist (2026-04-10)

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

1. **Cart placeholder behavior (default mode)**
   - Keep `live_checkout.quote_timing_mode = checkout_only`.
   - On cart/add-to-cart flow, confirm no remote quote request is made and cart shows placeholder rate (`Frakt beregnes i kassen`) instead of “ingen fraktmetoder”.

2. **Checkout live rate timing**
   - Enter a Norwegian destination with postcode + city in checkout.
   - Confirm real live rates from `lp_cargonizer_live` appear on checkout refresh (without full page reload).
   - Change postcode/city and verify rates refresh asynchronously.

3. **Pickup-point loading and selection**
   - Choose a pickup-capable rate.
   - Verify pickup selector renders only for selected pickup-capable rate and nearest point is preselected.
   - Change selected pickup point and verify async checkout refresh keeps the override.

4. **Dintero active checkout load**
   - Open checkout with embedded/Store API flow active (Dintero-style).
   - Confirm no fatal error and compatibility endpoint `admin-ajax.php?action=lp_cargonizer_get_checkout_pickup_points` returns payload successfully.

5. **Dintero order creation**
   - Complete order via Store API / embedded checkout.
   - Verify order is created and `_lp_cargonizer_checkout_selection` is saved with shipping + pickup context.

6. **Missing pickup point after recalculation**
   - Select a pickup point, then change destination so previous pickup point is no longer returned.
   - Verify fallback to nearest available pickup point occurs without checkout fatal/breakage.

7. **Under-threshold NOK 69 behavior**
   - Set subtotal below threshold (default NOK 1500).
   - Confirm at least one eligible method is shown at configured low price (default NOK 69).

8. **Over-threshold free-shipping behavior**
   - Set subtotal above threshold.
   - Confirm cheapest eligible standard method is free when strategy is `cheapest_standard_eligible`.

9. **Separate-package multi-colli behavior**
   - Use product with `_wildrobot_separate_package_for_product = yes`, quantity > 1.
   - Verify package builder splits into multiple colli and live quotes still return.

10. **Fallback behavior on quote failure**
    - Simulate quote failure/timeout (invalid credentials/network block/forced timeout).
    - Confirm configured fallback behavior is applied and no hard checkout failure occurs unless explicitly configured.

11. **Admin booking prefill**
    - Place order with live checkout shipping and pickup selection.
    - In admin booking modal, verify checkout selection metadata is available for prefill while allowing admin override.

12. **Multiple eligible methods (4–7 methods) with fast response**
    - Configure rules so 4–7 methods are eligible for the same Norwegian destination.
    - Refresh checkout and verify rates return quickly (cache-first behavior) and render without waiting for pickup-point payload completion.

13. **Pickup-capable rate visible before pickup points load**
    - Ensure at least one returned rate requires pickup points.
    - Verify the pickup-capable shipping rate is displayed/selectable before pickup-point data is fully loaded.

14. **Temporary pickup lookup failure does not remove shipping rate**
    - Simulate temporary failure in pickup lookup path for a pickup-capable method.
    - Verify the shipping rate itself remains visible/selectable; only pickup-point data is deferred/retried/fallback-handled.

15. **Repeated checkout refreshes do not duplicate identical remote requests**
    - Trigger rapid repeated checkout updates (postcode edits, shipping toggles, repeated refresh events).
    - Verify request locking / short failure cache prevent duplicate identical concurrent upstream requests.

16. **Sequential fallback works when parallel executor is unavailable**
    - Disable/fail the parallel execution path for uncached quote collection.
    - Verify quote collection still succeeds using sequential fallback and rates are returned.

## Zip readiness

The plugin directory is structurally complete and ready for external zipping.

- Plugin folder path: `/workspace/CargonizerWOOCONNECT`
- Zip command from repo root: `zip -r lilleprinsen-cargonizer-connector.zip .`

Note: no archive was created in this task.
