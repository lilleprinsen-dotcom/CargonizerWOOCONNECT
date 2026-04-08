<?php

if (!defined('ABSPATH')) {
	exit;
}

trait LP_Cargonizer_Admin_Page_Trait {
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			'Cargonizer',
			'Cargonizer',
			'manage_woocommerce',
			'lp-cargonizer',
			array($this, 'render_admin_page')
		);
	}

	public function register_settings() {
		register_setting('lp_cargonizer_group', self::OPTION_KEY, array($this, 'sanitize_settings'));
	}

	private function is_single_order_edit_screen() {
		global $pagenow;

		if ($pagenow !== 'post.php') {
			return false;
		}

		if (isset($_GET['post']) && absint($_GET['post']) > 0 && get_post_type(absint($_GET['post'])) !== 'shop_order') {
			return false;
		}

		$screen = function_exists('get_current_screen') ? get_current_screen() : null;
		if (!$screen || strpos((string) $screen->id, 'shop_order') === false) {
			return false;
		}

		return true;
	}

	public function enqueue_estimate_modal_assets($hook_suffix) {
		if (!current_user_can('manage_woocommerce')) {
			return;
		}

		if (!$this->is_single_order_edit_screen()) {
			return;
		}

		$script_path = dirname(__DIR__) . '/assets/js/admin-estimate-modal.js';
		$script_url = plugins_url('assets/js/admin-estimate-modal.js', dirname(__DIR__) . '/lilleprinsen-cargonizer-connector.php');
		$script_version = file_exists($script_path) ? (string) filemtime($script_path) : '1.0.0';

		wp_enqueue_script('lp-cargonizer-admin-estimate-modal', $script_url, array(), $script_version, true);
		$settings = $this->get_settings();
		wp_localize_script('lp-cargonizer-admin-estimate-modal', 'lpCargonizerEstimateModalConfig', array(
			'nonces' => array(
				'orderData' => wp_create_nonce(self::NONCE_ACTION_ORDER_DATA),
				'fetchOptions' => wp_create_nonce(self::NONCE_ACTION_FETCH_OPTIONS),
				'estimate' => wp_create_nonce(self::NONCE_ACTION_ESTIMATE),
				'estimateBaseline' => wp_create_nonce(self::NONCE_ACTION_ESTIMATE_BASELINE),
				'optimizeDsv' => wp_create_nonce(self::NONCE_ACTION_OPTIMIZE_DSV),
				'servicepartners' => wp_create_nonce(self::NONCE_ACTION_SERVICEPARTNERS),
				'book' => wp_create_nonce(self::NONCE_ACTION_BOOK),
				'printers' => wp_create_nonce(self::NONCE_ACTION_PRINTERS),
			),
			'bookingDefaults' => array(
				'notifyEmailToConsignee' => isset($settings['booking_email_notification_default']) ? (int) $this->sanitize_checkbox_value($settings['booking_email_notification_default']) : 1,
			),
		));
	}

	public function render_order_estimate_button($order) {
		if (!current_user_can('manage_woocommerce')) {
			return;
		}

		if (!$this->is_single_order_edit_screen()) {
			return;
		}

		if (!$order || !is_a($order, 'WC_Order')) {
			return;
		}

		echo '<div class="lp-cargonizer-order-actions" style="clear:both;margin-top:16px;padding-top:12px;border-top:1px solid #eee;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">'
			. '<button type="button" class="button lp-cargonizer-estimate-open" data-order-id="' . esc_attr($order->get_id()) . '">Estimer fraktkostnad</button>'
			. '<button type="button" class="button lp-cargonizer-book-open" data-order-id="' . esc_attr($order->get_id()) . '">Book shipment</button>'
			. '</div>';
	}

	public function render_estimate_modal() {
		if (!current_user_can('manage_woocommerce')) {
			return;
		}

		if (!$this->is_single_order_edit_screen()) {
			return;
		}
		?>
		<div id="lp-cargonizer-estimate-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:100000;">
			<div style="background:#fff;max-width:1100px;width:96%;max-height:90vh;overflow:auto;margin:3vh auto;padding:20px 20px 28px 20px;border-radius:8px;box-shadow:0 20px 50px rgba(0,0,0,.25);position:relative;">
				<button type="button" class="lp-cargonizer-close" style="position:absolute;right:16px;top:12px;border:none;background:transparent;font-size:26px;line-height:1;cursor:pointer;">&times;</button>
				<h2 id="lp-cargonizer-modal-title" style="margin-top:0;">Estimer fraktkostnad</h2>
				<div id="lp-cargonizer-estimate-loading" style="display:none;margin:12px 0;"><em>Laster ordredata...</em></div>
				<div id="lp-cargonizer-estimate-error" style="display:none;margin:12px 0;color:#b32d2e;"></div>
				<div id="lp-cargonizer-estimate-content" style="display:none;">
					<div id="lp-cargonizer-estimate-overview" style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px;margin-bottom:16px;"></div>
					<div id="lp-cargonizer-estimate-recipient" style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px;margin-bottom:16px;"></div>
					<div id="lp-cargonizer-estimate-lines" style="margin-bottom:16px;"></div>
					<div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;border:1px solid #dcdcde;background:#f6f7f7;">
						<h3 style="margin:0;">Kolli</h3>
						<button type="button" class="button button-primary" id="lp-cargonizer-add-colli">+ Legg til kolli</button>
					</div>
					<div id="lp-cargonizer-colli-validation" style="display:none;margin-top:8px;padding:8px 10px;border:1px solid #dba617;background:#fcf9e8;color:#6d4f00;"></div>
					<div id="lp-cargonizer-colli-list" style="margin-top:10px;"></div>
					<div id="lp-cargonizer-estimate-shipping-options" style="margin-top:16px;padding:12px;border:1px solid #dcdcde;background:#f6f7f7;">
						<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
							<h3 style="margin:0;">Fraktvalg</h3>
							<button type="button" class="button" id="lp-cargonizer-select-all-shipping">Velg alle</button>
						</div>
						<div id="lp-cargonizer-shipping-options-list"><em>Laster fraktvalg...</em></div>
					</div>
					<div id="lp-cargonizer-estimate-price-results" style="margin-top:12px;padding:12px;border:1px solid #dcdcde;background:#fcfcfc;">
						<h3 style="margin:0 0 8px 0;">Prisresultater</h3>
						<div id="lp-cargonizer-results-content" style="color:#646970;">Ingen estimater kjørt enda.</div>
					</div>
					<div id="lp-cargonizer-booking-printer-section" style="display:none;margin-top:12px;padding:12px;border:1px solid #dcdcde;background:#fcfcfc;">
						<h3 style="margin:0 0 8px 0;">Utskrift</h3>
						<label style="display:flex;flex-direction:column;gap:4px;">
							<span>Printer</span>
							<select id="lp-cargonizer-booking-printer-choice" style="max-width:420px;">
								<option value="">Ingen utskrift</option>
							</select>
						</label>
						<div id="lp-cargonizer-booking-printer-help" style="margin-top:6px;color:#646970;"></div>
					</div>
					<div id="lp-cargonizer-booking-notify-section" style="display:none;margin-top:12px;padding:12px;border:1px solid #dcdcde;background:#fcfcfc;">
						<label style="display:flex;gap:6px;align-items:center;">
							<input type="checkbox" id="lp-cargonizer-booking-notify-email">
							<span>Notify customer by e-mail via Cargonizer</span>
						</label>
						<div style="margin-top:6px;color:#646970;">Sender track &amp; trace-link til mottaker når sendingen er overført til transportør.</div>
					</div>
					<div id="lp-cargonizer-booking-services-section" style="display:none;margin-top:12px;padding:12px;border:1px solid #dcdcde;background:#fcfcfc;">
						<h3 style="margin:0 0 8px 0;">Tjenester</h3>
						<div style="margin-bottom:8px;color:#646970;">Valgfrie tilleggstjenester for valgt fraktmetode.</div>
						<select id="lp-cargonizer-booking-services-choice" multiple size="6" style="width:100%;max-width:520px;"></select>
						<div id="lp-cargonizer-booking-services-help" style="margin-top:6px;color:#646970;"></div>
					</div>
					<div id="lp-cargonizer-booking-servicepartner-section" style="display:none;margin-top:12px;padding:12px;border:1px solid #dcdcde;background:#fcfcfc;">
						<h3 style="margin:0 0 8px 0;">Utleveringssted / servicepartner</h3>
						<div id="lp-cargonizer-booking-servicepartner-help" style="margin-bottom:8px;color:#646970;"></div>
						<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
							<select id="lp-cargonizer-booking-servicepartner-select" style="min-width:280px;max-width:100%;">
								<option value="">Velg servicepartner…</option>
							</select>
							<button type="button" class="button button-small" id="lp-cargonizer-booking-servicepartner-refresh">Hent servicepartnere</button>
						</div>
					</div>
					<div id="lp-cargonizer-booking-results" style="display:none;margin-top:12px;padding:12px;border:1px solid #dcdcde;background:#fcfcfc;">
						<h3 style="margin:0 0 8px 0;">Booking result</h3>
						<div id="lp-cargonizer-booking-results-content" style="color:#646970;">Ingen booking kjørt enda.</div>
					</div>
					<div style="display:flex;justify-content:space-between;gap:8px;margin-top:16px;align-items:center;">
						<button type="button" class="button button-primary" id="lp-cargonizer-run-estimate">Estimer fraktpris</button>
						<button type="button" class="button button-primary" id="lp-cargonizer-run-booking" style="display:none;">Book shipment</button>
						<button type="button" class="button" id="lp-cargonizer-close-bottom">Lukk</button>
					</div>
				</div>
			</div>
		</div>

		<?php
	}

	public function render_admin_page() {
		if (!current_user_can('manage_woocommerce')) {
			return;
		}

		$settings = $this->get_settings();
		$settings['available_methods'] = $this->ensure_internal_manual_methods(isset($settings['available_methods']) ? $settings['available_methods'] : array());
		$current_user_id = get_current_user_id();
		$current_user_default_printer_id = get_user_meta($current_user_id, 'lp_cargonizer_default_printer_id', true);
		$current_user_default_printer_id = is_scalar($current_user_default_printer_id) ? sanitize_text_field((string) $current_user_default_printer_id) : '';
		$result   = null;
		$method_refresh = null;

		// Lagre innstillinger
		if (
			isset($_POST['lp_cargonizer_save_settings'])
			&& isset($_POST['_wpnonce'])
			&& wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::NONCE_ACTION_SAVE)
		) {
			$posted_enabled_methods = isset($_POST['lp_cargonizer_enabled_methods']) && is_array($_POST['lp_cargonizer_enabled_methods'])
				? array_map('sanitize_text_field', wp_unslash($_POST['lp_cargonizer_enabled_methods']))
				: null;

			$new_settings = array(
				'api_key'   => isset($_POST['lp_cargonizer_api_key']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_api_key'])) : '',
				'sender_id' => isset($_POST['lp_cargonizer_sender_id']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_sender_id'])) : '',
				'booking_email_notification_default' => isset($_POST['lp_cargonizer_booking_email_notification_default']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_booking_email_notification_default'])) : '0',
				'available_methods' => isset($settings['available_methods']) && is_array($settings['available_methods']) ? $settings['available_methods'] : array(),
				'enabled_methods' => is_array($posted_enabled_methods)
					? $posted_enabled_methods
					: (isset($settings['enabled_methods']) && is_array($settings['enabled_methods']) ? $settings['enabled_methods'] : array()),
				'method_discounts' => isset($_POST['lp_cargonizer_method_discounts']) && is_array($_POST['lp_cargonizer_method_discounts']) ? wp_unslash($_POST['lp_cargonizer_method_discounts']) : array(),
				'method_pricing' => isset($_POST['lp_cargonizer_method_pricing']) && is_array($_POST['lp_cargonizer_method_pricing']) ? wp_unslash($_POST['lp_cargonizer_method_pricing']) : array(),
			);

			$new_settings = $this->sanitize_settings($new_settings);
			update_option(self::OPTION_KEY, $new_settings);

			$posted_default_printer_id = isset($_POST['lp_cargonizer_default_printer_id']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_default_printer_id'])) : '';
			update_user_meta($current_user_id, 'lp_cargonizer_default_printer_id', $posted_default_printer_id);

			$settings = $this->get_settings();
			$current_user_default_printer_id = $posted_default_printer_id;

			echo '<div class="notice notice-success"><p>Innstillinger lagret.</p></div>';
		}

		// Test autentisering + hent fraktmetoder
		if (
			isset($_POST['lp_cargonizer_fetch_methods'])
			&& isset($_POST['_wpnonce'])
			&& wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::NONCE_ACTION_FETCH)
		) {
			$result = $this->fetch_transport_agreements();
		}



		if (
			isset($_POST['lp_cargonizer_refresh_method_choices'])
			&& isset($_POST['_wpnonce'])
			&& (
				wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::NONCE_ACTION_SAVE)
				|| wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::NONCE_ACTION_FETCH)
			)
		) {
			$method_refresh = $this->fetch_transport_agreements();
			if ($method_refresh['success']) {
				$available_methods = $this->ensure_internal_manual_methods($this->flatten_shipping_methods($method_refresh['data']));
				$posted_enabled_methods = isset($_POST['lp_cargonizer_enabled_methods']) && is_array($_POST['lp_cargonizer_enabled_methods'])
					? array_map('sanitize_text_field', wp_unslash($_POST['lp_cargonizer_enabled_methods']))
					: null;
				$enabled_map = array();
				$enabled_source = is_array($posted_enabled_methods)
					? $posted_enabled_methods
					: (isset($settings['enabled_methods']) && is_array($settings['enabled_methods']) ? $settings['enabled_methods'] : array());
				foreach ($enabled_source as $saved_key) {
						$enabled_map[(string) $saved_key] = true;
				}
				$new_enabled = array();
				foreach ($available_methods as $method) {
					$key = isset($method['key']) ? (string) $method['key'] : '';
					if ($key !== '' && isset($enabled_map[$key])) {
						$new_enabled[] = $key;
					}
				}

				$settings['available_methods'] = $available_methods;
				$settings['enabled_methods'] = $new_enabled;
				update_option(self::OPTION_KEY, $this->sanitize_settings($settings));
				$settings = $this->get_settings();
				echo '<div class="notice notice-success"><p>Fraktmetoder ble hentet. Velg hvilke som skal være tilgjengelige og lagre innstillingene.</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>' . esc_html($method_refresh['message']) . '</p></div>';
			}
		}
		?>
		<div class="wrap">
			<h1>Cargonizer for WooCommerce</h1>

			<p>
				Legg inn autentisering for Cargonizer og hent en oversikt over tilgjengelige fraktmetoder.
			</p>

			<div style="background:#fff;border:1px solid #ddd;padding:20px;margin:20px 0;max-width:900px;">
				<h2>Autentisering</h2>
				<form method="post">
					<?php wp_nonce_field(self::NONCE_ACTION_SAVE); ?>

					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="lp_cargonizer_api_key">API key</label>
								</th>
								<td>
									<input
										name="lp_cargonizer_api_key"
										id="lp_cargonizer_api_key"
										type="text"
										class="regular-text"
										value="<?php echo esc_attr($settings['api_key']); ?>"
										autocomplete="off"
									/>
									<p class="description">
										Brukes som header: <code>X-Cargonizer-Key</code>
									</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lp_cargonizer_sender_id">Sender ID</label>
								</th>
								<td>
									<input
										name="lp_cargonizer_sender_id"
										id="lp_cargonizer_sender_id"
										type="text"
										class="regular-text"
										value="<?php echo esc_attr($settings['sender_id']); ?>"
										autocomplete="off"
									/>
									<p class="description">
										Brukes som header: <code>X-Cargonizer-Sender</code>
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<h2>Booking defaults</h2>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">Standardvalg</th>
								<td>
									<label style="display:flex;align-items:center;gap:6px;">
										<input type="hidden" name="lp_cargonizer_booking_email_notification_default" value="0">
										<input type="checkbox" name="lp_cargonizer_booking_email_notification_default" value="1" <?php checked(!empty($settings['booking_email_notification_default'])); ?>>
										<span>Notify customer by e-mail from Cargonizer by default</span>
									</label>
									<p class="description">Bruker Cargonizers e-postvarsling til mottaker når sendingen overføres til transportør.</p>
								</td>
							</tr>
						</tbody>
					</table>

					<h2>Standard printer for innlogget admin</h2>
					<p>Denne innstillingen lagres kun på deg som innlogget admin-bruker.</p>
					<?php
					$printer_fetch_result = array(
						'success' => false,
						'http_status' => 0,
						'message' => '',
						'raw' => '',
						'printers' => array(),
					);
					if (!empty($settings['api_key'])) {
						$printer_fetch_result = $this->fetch_printers();
					}
					$available_printers = isset($printer_fetch_result['printers']) && is_array($printer_fetch_result['printers']) ? $printer_fetch_result['printers'] : array();
					?>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">
									<label for="lp_cargonizer_default_printer_id">Standardprinter</label>
								</th>
								<td>
									<select name="lp_cargonizer_default_printer_id" id="lp_cargonizer_default_printer_id">
										<option value=""><?php echo esc_html('Ingen standardprinter'); ?></option>
										<?php foreach ($available_printers as $printer) : ?>
											<?php
											$printer_id = isset($printer['id']) ? sanitize_text_field((string) $printer['id']) : '';
											if ($printer_id === '') {
												continue;
											}
											$printer_label = isset($printer['label']) ? sanitize_text_field((string) $printer['label']) : $printer_id;
											?>
											<option value="<?php echo esc_attr($printer_id); ?>" <?php selected($current_user_default_printer_id, $printer_id); ?>>
												<?php echo esc_html($printer_label); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<?php if (empty($settings['api_key'])) : ?>
										<p class="description">Legg inn API key for å hente printerliste fra Cargonizer.</p>
									<?php elseif (empty($printer_fetch_result['success'])) : ?>
										<p class="description" style="color:#b32d2e;"><?php echo esc_html($printer_fetch_result['message'] !== '' ? $printer_fetch_result['message'] : 'Kunne ikke hente printerliste.'); ?></p>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>


					<h2>Tilgjengelige fraktmetoder i kalkulator</h2>
					<p>Kun valgte metoder vises for admin ved estimering av fraktkostnad. Prismodellen per metode bruker valgt prisfelt, rabatt, drivstofftillegg, bomtillegg, håndteringstillegg, mva og avrunding.</p>

					<?php if (!empty($settings['available_methods']) && is_array($settings['available_methods'])) : ?>
						<?php
						$method_groups = array();
						foreach ($settings['available_methods'] as $method) {
							$is_manual_norgespakke_method = $this->is_manual_norgespakke_method($method);
							$agreement_id = isset($method['agreement_id']) ? trim((string) $method['agreement_id']) : '';
							$agreement_name = isset($method['agreement_name']) ? trim((string) $method['agreement_name']) : '';
							if ($is_manual_norgespakke_method) {
								$group_key = 'manual_norgespakke';
							} elseif ($agreement_id !== '') {
								$group_key = 'agreement_id:' . $agreement_id;
							} elseif ($agreement_name !== '') {
								$group_key = 'agreement_name:' . $agreement_name;
							} else {
								$group_key = 'agreement_unknown';
							}
							if (!isset($method_groups[$group_key])) {
								$agreement_description = isset($method['agreement_description']) ? trim((string) $method['agreement_description']) : '';
								$label = $agreement_description !== '' ? $agreement_description : ($agreement_name !== '' ? $agreement_name : 'Ukjent fraktavtale');
								if ($is_manual_norgespakke_method) {
									$label = 'Manuell Norgespakke';
								}
								$method_groups[$group_key] = array(
									'group_key' => $group_key,
									'label' => $label,
									'agreement_number' => isset($method['agreement_number']) ? trim((string) $method['agreement_number']) : '',
									'carriers' => array(),
									'methods' => array(),
								);
							}
							$carrier_name = isset($method['carrier_name']) ? trim((string) $method['carrier_name']) : '';
							if ($carrier_name !== '') {
								$method_groups[$group_key]['carriers'][$carrier_name] = true;
							}
							$method_groups[$group_key]['methods'][] = $method;
						}
						?>
						<div style="margin-bottom:10px;display:flex;gap:8px;flex-wrap:wrap;">
							<button type="button" class="button button-small lp-cargonizer-open-all-method-groups">Åpne alle avtaler</button>
							<button type="button" class="button button-small lp-cargonizer-close-all-method-groups">Lukk alle avtaler</button>
						</div>
						<div class="lp-cargonizer-methods-groups" style="max-height:520px;overflow:auto;border:1px solid #dcdcde;padding:12px;background:#fff;">
							<?php foreach ($method_groups as $group) : ?>
								<?php
								$total_count = count($group['methods']);
								$enabled_count = 0;
								foreach ($group['methods'] as $method) {
									$method_key = isset($method['key']) ? (string) $method['key'] : '';
									if ($method_key !== '' && in_array($method_key, $settings['enabled_methods'], true)) {
										$enabled_count++;
									}
								}
								$group_open = $enabled_count > 0;
								$carrier_names = array_keys($group['carriers']);
								$carrier_label = '';
								if (count($carrier_names) === 1) {
									$carrier_label = $carrier_names[0];
								} elseif (count($carrier_names) > 1) {
									$carrier_label = 'flere transportører';
								}
								$summary_label = $group['label'] . ' (' . $enabled_count . '/' . $total_count . ' aktive)';
								?>
								<details class="lp-cargonizer-method-group" <?php echo $group_open ? 'open' : ''; ?> style="border:1px solid #dcdcde;border-radius:4px;background:#fff;margin-bottom:12px;">
									<summary style="cursor:pointer;padding:10px 12px;background:#f6f7f7;display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;">
										<span><strong><?php echo esc_html($summary_label); ?></strong></span>
										<small style="color:#646970;">
											<?php if ($group['agreement_number'] !== '') : ?>
												<?php echo esc_html('Avtalenummer: ' . $group['agreement_number']); ?><?php echo esc_html($carrier_label !== '' ? ' / ' : ''); ?>
											<?php endif; ?>
											<?php if ($carrier_label !== '') : ?>
												<?php echo esc_html('Transportør: ' . $carrier_label); ?>
											<?php endif; ?>
										</small>
									</summary>
									<div style="padding:10px 12px;">
										<div style="margin-bottom:10px;display:flex;gap:8px;flex-wrap:wrap;">
											<button type="button" class="button button-small lp-cargonizer-select-group">Velg alle i avtalen</button>
											<button type="button" class="button button-small lp-cargonizer-clear-group">Fjern alle i avtalen</button>
										</div>
										<?php foreach ($group['methods'] as $method) : ?>
											<?php
											$method_key = isset($method['key']) ? (string) $method['key'] : '';
											$is_enabled = in_array($method_key, $settings['enabled_methods'], true);
											$method_discounts = isset($settings['method_discounts']) && is_array($settings['method_discounts']) ? $settings['method_discounts'] : array();
											$method_pricing = isset($settings['method_pricing']) && is_array($settings['method_pricing']) ? $settings['method_pricing'] : array();
											$pricing = isset($method_pricing[$method_key]) && is_array($method_pricing[$method_key]) ? $method_pricing[$method_key] : array();
											$discount_value = isset($pricing['discount_percent']) ? $this->sanitize_discount_percent($pricing['discount_percent']) : (isset($method_discounts[$method_key]) ? $this->sanitize_discount_percent($method_discounts[$method_key]) : 0);
											$price_source = $this->sanitize_price_source(isset($pricing['price_source']) ? $pricing['price_source'] : 'estimated');
											$fuel_percent = $this->sanitize_non_negative_number(isset($pricing['fuel_surcharge']) ? $pricing['fuel_surcharge'] : 0);
											$toll_surcharge = $this->sanitize_non_negative_number(isset($pricing['toll_surcharge']) ? $pricing['toll_surcharge'] : 0);
											$handling_fee = $this->sanitize_non_negative_number(isset($pricing['handling_fee']) ? $pricing['handling_fee'] : 0);
											$vat_percent = $this->sanitize_non_negative_number(isset($pricing['vat_percent']) ? $pricing['vat_percent'] : 0);
											$rounding_mode = $this->sanitize_rounding_mode(isset($pricing['rounding_mode']) ? $pricing['rounding_mode'] : 'none');
											$delivery_to_pickup_point = isset($pricing['delivery_to_pickup_point']) ? (bool) $this->sanitize_checkbox_value($pricing['delivery_to_pickup_point']) : false;
											$delivery_to_home = isset($pricing['delivery_to_home']) ? (bool) $this->sanitize_checkbox_value($pricing['delivery_to_home']) : true;
											$include_manual_norgespakke_handling = isset($pricing['manual_norgespakke_include_handling']) ? (bool) $this->sanitize_checkbox_value($pricing['manual_norgespakke_include_handling']) : true;
											$is_manual_norgespakke_method = $this->is_manual_norgespakke_method($method);
											?>
											<div class="lp-cargonizer-method-row" style="display:flex;gap:8px;align-items:flex-start;padding:8px 0;border-top:1px solid #f0f0f1;">
												<input class="lp-cargonizer-method-toggle" type="checkbox" name="lp_cargonizer_enabled_methods[]" value="<?php echo esc_attr($method_key); ?>" <?php checked($is_enabled); ?>>
												<span style="flex:1;">
													<strong><?php echo esc_html(isset($method['label']) ? $method['label'] : 'Ukjent metode'); ?></strong><br>
													<small>
														Transportør: <?php echo esc_html(isset($method['carrier_name']) && $method['carrier_name'] !== '' ? $method['carrier_name'] : '—'); ?><?php echo esc_html(isset($method['carrier_id']) && $method['carrier_id'] !== '' ? ' (' . $method['carrier_id'] . ')' : ''); ?> /
														Fraktavtalebeskrivelse: <?php echo esc_html(isset($method['agreement_description']) && $method['agreement_description'] !== '' ? $method['agreement_description'] : (isset($method['agreement_name']) ? $method['agreement_name'] : '—')); ?> /
														Fraktavtalenummer: <?php echo esc_html(isset($method['agreement_number']) && $method['agreement_number'] !== '' ? $method['agreement_number'] : '—'); ?> /
														Agreement ID: <?php echo esc_html(isset($method['agreement_id']) ? $method['agreement_id'] : '—'); ?> /
														Produkt: <?php echo esc_html(isset($method['product_id']) ? $method['product_id'] : '—'); ?>
													</small>
												</span>
												<div style="display:grid;grid-template-columns:repeat(5,minmax(130px,1fr));gap:8px;align-items:end;min-width:760px;">
													<label style="display:flex;flex-direction:column;gap:4px;">
														<span>Prisfelt brukt</span><small style="color:#646970;">Hvilken pris fra Cargonizer som brukes som listepris/grunnlag for beregning.</small>
														<select class="lp-cargonizer-method-input" name="lp_cargonizer_method_pricing[<?php echo esc_attr($method_key); ?>][price_source]" <?php disabled(!$is_enabled); ?>>
															<option value="estimated" <?php selected($price_source, 'estimated'); ?>>Estimert</option>
															<option value="net" <?php selected($price_source, 'net'); ?>>Netto</option>
															<option value="gross" <?php selected($price_source, 'gross'); ?>>Brutto</option>
															<option value="fallback" <?php selected($price_source, 'fallback'); ?>>Automatisk fallback</option>
															<option value="manual_norgespakke" <?php selected($price_source, 'manual_norgespakke'); ?>>Manuell Norgespakke</option>
														</select>
													</label>
													<label style="display:flex;flex-direction:column;gap:4px;">
														<span>Rabatt (%)</span><small style="color:#646970;">Rabatt trekkes kun fra listepris/grunnlag, ikke tillegg.</small>
														<input class="small-text lp-cargonizer-method-input" type="number" min="0" max="100" step="0.01" name="lp_cargonizer_method_pricing[<?php echo esc_attr($method_key); ?>][discount_percent]" value="<?php echo esc_attr($discount_value); ?>" <?php disabled(!$is_enabled); ?>>
													</label>
													<label style="display:flex;flex-direction:column;gap:4px;">
														<span>Drivstofftillegg (%)</span><small style="color:#646970;">Prosent av utledet grunnfrakt (brukes baklengs mot listepris).</small>
														<input class="small-text lp-cargonizer-method-input" type="number" min="0" step="0.01" name="lp_cargonizer_method_pricing[<?php echo esc_attr($method_key); ?>][fuel_surcharge]" value="<?php echo esc_attr($fuel_percent); ?>" <?php disabled(!$is_enabled); ?>>
													</label>
													<label style="display:flex;flex-direction:column;gap:4px;">
														<span>Bomtillegg (kr)</span><small style="color:#646970;">Fast kronepåslag.</small>
														<input class="small-text lp-cargonizer-method-input" type="number" min="0" step="0.01" name="lp_cargonizer_method_pricing[<?php echo esc_attr($method_key); ?>][toll_surcharge]" value="<?php echo esc_attr($toll_surcharge); ?>" <?php disabled(!$is_enabled); ?>>
													</label>
													<label style="display:flex;flex-direction:column;gap:4px;">
														<span>Håndteringstillegg (kr)</span><small style="color:#646970;">Fast kronepåslag.</small>
														<input class="small-text lp-cargonizer-method-input" type="number" min="0" step="0.01" name="lp_cargonizer_method_pricing[<?php echo esc_attr($method_key); ?>][handling_fee]" value="<?php echo esc_attr($handling_fee); ?>" <?php disabled(!$is_enabled); ?>>
													</label>
													<?php if ($is_manual_norgespakke_method) : ?>
														<label style="display:flex;flex-direction:column;gap:4px;">
															<span>Ta hensyn til håndteringstillegg</span><small style="color:#646970;">Gjelder kun manuell Norgespakke.</small>
															<input type="hidden" class="lp-cargonizer-method-input" name="lp_cargonizer_method_pricing[<?php echo esc_attr($method_key); ?>][manual_norgespakke_include_handling]" value="0" <?php disabled(!$is_enabled); ?>>
															<input class="lp-cargonizer-method-input" type="checkbox" name="lp_cargonizer_method_pricing[<?php echo esc_attr($method_key); ?>][manual_norgespakke_include_handling]" value="1" <?php checked($include_manual_norgespakke_handling); ?> <?php disabled(!$is_enabled); ?>>
														</label>
													<?php endif; ?>
													<label style="display:flex;flex-direction:column;gap:4px;">
														<span>MVA (%)</span><small style="color:#646970;">Legges på etter rabatt og tillegg.</small>
														<input class="small-text lp-cargonizer-method-input" type="number" min="0" step="0.01" name="lp_cargonizer_method_pricing[<?php echo esc_attr($method_key); ?>][vat_percent]" value="<?php echo esc_attr($vat_percent); ?>" <?php disabled(!$is_enabled); ?>>
													</label>
													<label style="display:flex;flex-direction:column;gap:4px;">
														<span>Avrunding</span><small style="color:#646970;">Hvordan sluttprisen avrundes.</small>
														<select class="lp-cargonizer-method-input" name="lp_cargonizer_method_pricing[<?php echo esc_attr($method_key); ?>][rounding_mode]" <?php disabled(!$is_enabled); ?>>
															<option value="none" <?php selected($rounding_mode, 'none'); ?>>Ingen avrunding</option>
															<option value="nearest_1" <?php selected($rounding_mode, 'nearest_1'); ?>>Nærmeste 1 kr</option>
															<option value="nearest_10" <?php selected($rounding_mode, 'nearest_10'); ?>>Nærmeste 10 kr</option>
															<option value="price_ending_9" <?php selected($rounding_mode, 'price_ending_9'); ?>>Slutt på 9</option>
														</select>
													</label>
													<label style="display:flex;flex-direction:column;gap:4px;">
														<span>Leveringstype</span><small style="color:#646970;">Velg hvor denne tjenesten leverer til.</small>
														<span style="display:flex;gap:10px;flex-wrap:wrap;">
															<label style="display:flex;align-items:center;gap:5px;">
																<input type="hidden" class="lp-cargonizer-method-input" name="lp_cargonizer_method_pricing[<?php echo esc_attr($method_key); ?>][delivery_to_pickup_point]" value="0" <?php disabled(!$is_enabled); ?>>
																<input class="lp-cargonizer-method-input" type="checkbox" name="lp_cargonizer_method_pricing[<?php echo esc_attr($method_key); ?>][delivery_to_pickup_point]" value="1" <?php checked($delivery_to_pickup_point); ?> <?php disabled(!$is_enabled); ?>>
																<span>HENTESTED</span>
															</label>
															<label style="display:flex;align-items:center;gap:5px;">
																<input type="hidden" class="lp-cargonizer-method-input" name="lp_cargonizer_method_pricing[<?php echo esc_attr($method_key); ?>][delivery_to_home]" value="0" <?php disabled(!$is_enabled); ?>>
																<input class="lp-cargonizer-method-input" type="checkbox" name="lp_cargonizer_method_pricing[<?php echo esc_attr($method_key); ?>][delivery_to_home]" value="1" <?php checked($delivery_to_home); ?> <?php disabled(!$is_enabled); ?>>
																<span>HJEMLEVERING</span>
															</label>
														</span>
													</label>
												</div>
											</div>
										<?php endforeach; ?>
									</div>
								</details>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<p><em>Ingen fraktmetoder hentet ennå. Klikk "Oppdater liste over fraktmetoder" først.</em></p>
					<?php endif; ?>
					<script>
					(function(){
						var scriptTag = document.currentScript;
						var parentForm = scriptTag ? scriptTag.closest('form') : null;
						var container = parentForm ? parentForm.querySelector('.lp-cargonizer-methods-groups') : null;
						if (!container) { return; }
						container.querySelectorAll('.lp-cargonizer-method-toggle').forEach(function(toggle){
							var row = toggle.closest('.lp-cargonizer-method-row');
							if (!row) { return; }
							var pricingInputs = row.querySelectorAll('.lp-cargonizer-method-input');
							var sync = function(){
								pricingInputs.forEach(function(input){ input.disabled = !toggle.checked; });
							};
							toggle.addEventListener('change', sync);
							sync();
						});

						container.querySelectorAll('.lp-cargonizer-select-group').forEach(function(button){
							button.addEventListener('click', function(){
								var group = button.closest('.lp-cargonizer-method-group');
								if (!group) { return; }
								group.querySelectorAll('.lp-cargonizer-method-toggle').forEach(function(toggle){
									if (!toggle.checked) {
										toggle.checked = true;
										toggle.dispatchEvent(new Event('change', { bubbles: true }));
									}
								});
							});
						});

						container.querySelectorAll('.lp-cargonizer-clear-group').forEach(function(button){
							button.addEventListener('click', function(){
								var group = button.closest('.lp-cargonizer-method-group');
								if (!group) { return; }
								group.querySelectorAll('.lp-cargonizer-method-toggle').forEach(function(toggle){
									if (toggle.checked) {
										toggle.checked = false;
										toggle.dispatchEvent(new Event('change', { bubbles: true }));
									}
								});
							});
						});

						var openAllButton = parentForm ? parentForm.querySelector('.lp-cargonizer-open-all-method-groups') : null;
						var closeAllButton = parentForm ? parentForm.querySelector('.lp-cargonizer-close-all-method-groups') : null;
						if (openAllButton) {
							openAllButton.addEventListener('click', function(){
								container.querySelectorAll('.lp-cargonizer-method-group').forEach(function(group){
									group.open = true;
								});
							});
						}
						if (closeAllButton) {
							closeAllButton.addEventListener('click', function(){
								container.querySelectorAll('.lp-cargonizer-method-group').forEach(function(group){
									group.open = false;
								});
							});
						}
					})();
					</script>

					<p>
						<button type="submit" name="lp_cargonizer_refresh_method_choices" class="button button-secondary">
							Oppdater liste over fraktmetoder
						</button>
					</p>

					<p>
						<button type="submit" name="lp_cargonizer_save_settings" class="button button-primary">
							Lagre innstillinger og metodevalg
						</button>
					</p>
				</form>
			</div>

			<div style="background:#fff;border:1px solid #ddd;padding:20px;margin:20px 0;max-width:900px;">
				<h2>Tilkoblingstest</h2>

				<p>
					Lagrede verdier:
				</p>

				<ul style="list-style:disc;padding-left:20px;">
					<li><strong>API key:</strong> <?php echo esc_html($settings['api_key'] ? $this->mask_value($settings['api_key']) : 'Ikke lagret'); ?></li>
					<li><strong>Sender ID:</strong> <?php echo esc_html($settings['sender_id'] ? $settings['sender_id'] : 'Ikke lagret'); ?></li>
				</ul>

				<form method="post">
					<?php wp_nonce_field(self::NONCE_ACTION_FETCH); ?>
					<p>
						<button type="submit" name="lp_cargonizer_fetch_methods" class="button button-secondary">
							Test autentisering og hent fraktmetoder
						</button>
					</p>
				</form>
			</div>

			<?php if ($result !== null) : ?>
				<div style="background:#fff;border:1px solid #ddd;padding:20px;margin:20px 0;max-width:1100px;">
					<h2>Resultat</h2>

					<?php if ($result['success']) : ?>
						<div class="notice notice-success inline">
							<p><?php echo esc_html($result['message']); ?> HTTP-status: <?php echo esc_html($result['status']); ?></p>
						</div>

						<?php if (!empty($result['data'])) : ?>
							<table class="widefat striped" style="margin-top:20px;">
								<thead>
									<tr>
									<th>Transport agreement ID</th>
									<th>Transport agreement</th>
									<th>Transportør / provider</th>
									<th>Produkt ID</th>
									<th>Produkt</th>
									<th>Tjenester</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($result['data'] as $agreement) : ?>
										<?php if (!empty($agreement['products'])) : ?>
											<?php foreach ($agreement['products'] as $product) : ?>
												<tr>
													<td><?php echo esc_html($agreement['agreement_id']); ?></td>
													<td><?php echo esc_html($agreement['agreement_name']); ?></td>
													<td><?php echo esc_html(!empty($agreement['carrier_name']) ? $agreement['carrier_name'] : '—'); ?><?php echo esc_html(!empty($agreement['carrier_id']) ? ' (' . $agreement['carrier_id'] . ')' : ''); ?></td>
													<td><?php echo esc_html($product['product_id']); ?></td>
													<td><?php echo esc_html($product['product_name']); ?></td>
													<td>
														<?php
														if (!empty($product['services'])) {
															$services = array();
															foreach ($product['services'] as $service) {
																$services[] = trim($service['service_name'] . ' (' . $service['service_id'] . ')');
															}
															echo esc_html(implode(', ', $services));
														} else {
															echo '—';
														}
														?>
													</td>
												</tr>
											<?php endforeach; ?>
										<?php else : ?>
											<tr>
												<td><?php echo esc_html($agreement['agreement_id']); ?></td>
												<td><?php echo esc_html($agreement['agreement_name']); ?></td>
												<td><?php echo esc_html(!empty($agreement['carrier_name']) ? $agreement['carrier_name'] : '—'); ?><?php echo esc_html(!empty($agreement['carrier_id']) ? ' (' . $agreement['carrier_id'] . ')' : ''); ?></td>
												<td>—</td>
												<td>—</td>
												<td>—</td>
											</tr>
										<?php endif; ?>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php else : ?>
							<p>Autentiseringen fungerte, men ingen fraktmetoder/produkter ble funnet i responsen.</p>
						<?php endif; ?>

						<details style="margin-top:20px;">
							<summary><strong>Vis rå XML-respons</strong></summary>
							<pre style="white-space:pre-wrap;background:#f6f7f7;padding:15px;border:1px solid #ddd;max-height:500px;overflow:auto;"><?php echo esc_html($result['raw']); ?></pre>
						</details>

					<?php else : ?>
						<div class="notice notice-error inline">
							<p><?php echo esc_html($result['message']); ?></p>
						</div>

						<?php if (!empty($result['raw'])) : ?>
							<details style="margin-top:20px;">
								<summary><strong>Vis respons fra Cargonizer</strong></summary>
								<pre style="white-space:pre-wrap;background:#f6f7f7;padding:15px;border:1px solid #ddd;max-height:500px;overflow:auto;"><?php echo esc_html($result['raw']); ?></pre>
							</details>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
