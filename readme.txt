=== Lilleprinsen Cargonizer Connector ===
Contributors: lilleprinsen
Tags: woocommerce, shipping, cargonizer
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrates WooCommerce admin workflows with Cargonizer transport agreements and estimate tooling.

== Description ==
This plugin provides a WooCommerce admin integration for Cargonizer settings, transport agreement retrieval, and shipping estimate helpers.

== Installation ==
1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress.

== Changelog ==
= 1.1.0 =
* 2026-04-08: PR #29 - Fixed order action button layout collision on the WooCommerce admin order page.
* 2026-04-08: PR #30 - Added additive booking/printer diagnostics in the admin UI.
* 2026-04-08: PR #31 - Added booking defaults, multi-booking history, and service selection support.
* 2026-04-08: PR #32 - Improved booking note readability and added estimate fallback pricing.
* 2026-04-08: PR #33 - Grouped admin freight methods by agreement in collapsible sections.
* 2026-04-08: PR #34 - Improved booking-mode servicepartner handling and API params.
* 2026-04-08: PR #35 - Hardened servicepartner lookup with progressive fallback attempts.
* 2026-04-08: PR #36 - Improved booking modal proactive servicepartner selection flow.
* 2026-04-08: PR #37 - Fixed servicepartner fallback/parsing and attempt summary counts.
* 2026-04-08: PR #38 - Tightened servicepartner detection for pickup-only methods.
* 2026-04-08: PR #39 - Auto-selected default servicepartner for pickup methods.
* 2026-04-09: PR #40 - Fixed missing servicepartner helper passthroughs in connector.
* 2026-04-09: PR #41 - Added live checkout extension contract and phased roadmap docs.
* 2026-04-09: PR #42 - Added live checkout settings schema and admin configuration UI.
* 2026-04-09: PR #43 - Added reusable package/profile/rule checkout subsystem scaffolding.
* 2026-04-09: PR #44 - Added zone shipping method for Cargonizer live multi-rate checkout.
* 2026-04-09: PR #45 - Added checkout pickup-point support for live shipping rates.
* 2026-04-09: PR #46 - Persisted live checkout shipping selection on classic and Store API orders.
* 2026-04-09: PR #47 - Prefilled the admin booking modal from checkout shipping selection.
* 2026-04-09: PR #48 - Hardened live checkout quote/pickup flow and docs.

= 1.0.0 =
* Initial release.
