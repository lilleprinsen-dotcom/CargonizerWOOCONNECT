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

## Zip readiness

The plugin directory is structurally complete and ready for external zipping.

- Plugin folder path: `/workspace/CargonizerWOOCONNECT`
- Zip command from repo root: `zip -r lilleprinsen-cargonizer-connector.zip .`

Note: no archive was created in this task.
