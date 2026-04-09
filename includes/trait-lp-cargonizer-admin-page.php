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

		$checkout_summary_html = $this->render_checkout_selection_summary_html($order);

		echo '<div class="lp-cargonizer-order-actions" style="clear:both;margin-top:16px;padding-top:12px;border-top:1px solid #eee;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">'
			. '<button type="button" class="button lp-cargonizer-estimate-open" data-order-id="' . esc_attr($order->get_id()) . '">Estimer fraktkostnad</button>'
			. '<button type="button" class="button lp-cargonizer-book-open" data-order-id="' . esc_attr($order->get_id()) . '">Book shipment</button>'
			. '</div>'
			. $checkout_summary_html;
	}

	private function render_checkout_selection_summary_html($order) {
		if (!$order || !is_a($order, 'WC_Order')) {
			return '';
		}

		$selection = $order->get_meta('_lp_cargonizer_checkout_selection', true);
		if (!is_array($selection)) {
			return '';
		}

		$shipping = isset($selection['shipping']) && is_array($selection['shipping']) ? $selection['shipping'] : array();
		$pickup = isset($selection['pickup_point']) && is_array($selection['pickup_point']) ? $selection['pickup_point'] : array();
		$pickup_selected = isset($pickup['selected']) && is_array($pickup['selected']) ? $pickup['selected'] : array();
		$selected_service_ids = isset($shipping['selected_service_ids']) && is_array($shipping['selected_service_ids']) ? $shipping['selected_service_ids'] : array();
		$selected_service_ids = array_values(array_filter(array_map('sanitize_text_field', array_map('strval', $selected_service_ids)), 'strlen'));

		$method_label = isset($shipping['label']) ? sanitize_text_field((string) $shipping['label']) : '';
		$product_id = isset($shipping['product_id']) ? sanitize_text_field((string) $shipping['product_id']) : '';
		$agreement_id = isset($shipping['transport_agreement_id']) ? sanitize_text_field((string) $shipping['transport_agreement_id']) : '';
		$pickup_name = isset($pickup_selected['name']) ? sanitize_text_field((string) $pickup_selected['name']) : '';
		$pickup_id = isset($pickup['selected_id']) ? sanitize_text_field((string) $pickup['selected_id']) : '';
		$pickup_address = trim(
			(isset($pickup_selected['address1']) ? sanitize_text_field((string) $pickup_selected['address1']) : '')
			. ' '
			. (isset($pickup_selected['postcode']) ? sanitize_text_field((string) $pickup_selected['postcode']) : '')
			. ' '
			. (isset($pickup_selected['city']) ? sanitize_text_field((string) $pickup_selected['city']) : '')
		);
		$saved_at = isset($selection['saved_at_gmt']) ? sanitize_text_field((string) $selection['saved_at_gmt']) : '';

		if ($method_label === '' && $product_id === '' && $pickup_id === '' && empty($selected_service_ids)) {
			return '';
		}

		$html = '<div style="margin-top:10px;padding:10px 12px;border:1px solid #dcdcde;background:#f6f7f7;">';
		$html .= '<strong>Kundevalgt frakt ved checkout</strong>';
		$html .= '<div style="margin-top:6px;color:#1d2327;">Metode: ' . esc_html($method_label !== '' ? $method_label : '—');
		if ($product_id !== '' || $agreement_id !== '') {
			$html .= ' <span style="color:#646970;">(' . esc_html(trim('product=' . $product_id . ', agreement=' . $agreement_id, ', ')) . ')</span>';
		}
		$html .= '</div>';
		if ($pickup_id !== '' || $pickup_name !== '') {
			$html .= '<div style="margin-top:4px;color:#1d2327;">Pickup/service point: ' . esc_html($pickup_name !== '' ? $pickup_name : $pickup_id);
			if ($pickup_address !== '') {
				$html .= ' <span style="color:#646970;">(' . esc_html($pickup_address) . ')</span>';
			}
			$html .= '</div>';
		}
		if (!empty($selected_service_ids)) {
			$html .= '<div style="margin-top:4px;color:#1d2327;">Valgte tjenester: ' . esc_html(implode(', ', $selected_service_ids)) . '</div>';
		}
		if ($saved_at !== '') {
			$html .= '<div style="margin-top:4px;color:#646970;">Lagret (GMT): ' . esc_html($saved_at) . '</div>';
		}
		$html .= '</div>';

		return $html;
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

	private function parse_live_checkout_json_array_input($raw_value) {
		if (!is_scalar($raw_value)) {
			return array();
		}
		$decoded = json_decode((string) $raw_value, true);
		return is_array($decoded) ? $decoded : array();
	}

	private function parse_live_checkout_lines_to_list($raw_value) {
		if (!is_scalar($raw_value)) {
			return array();
		}
		$lines = preg_split('/\r\n|\r|\n/', (string) $raw_value);
		if (!is_array($lines)) {
			return array();
		}
		$result = array();
		foreach ($lines as $line) {
			$line = sanitize_text_field(trim((string) $line));
			if ($line !== '') {
				$result[] = $line;
			}
		}
		return $result;
	}

	private function parse_csv_slug_list($raw_value) {
		if (!is_scalar($raw_value)) {
			return array();
		}
		$parts = explode(',', (string) $raw_value);
		$list = array();
		foreach ($parts as $part) {
			$slug = sanitize_key(trim((string) $part));
			if ($slug !== '') {
				$list[] = $slug;
			}
		}
		return array_values(array_unique($list));
	}

	private function parse_profile_rows_editor_input($profiles_input) {
		$profiles = array();
		if (!is_array($profiles_input)) {
			return $profiles;
		}
		foreach ($profiles_input as $row) {
			if (!is_array($row)) {
				continue;
			}
			$slug = isset($row['slug']) ? sanitize_key((string) $row['slug']) : '';
			if ($slug === '') {
				continue;
			}
			$profiles[] = array(
				'slug' => $slug,
				'label' => isset($row['label']) ? sanitize_text_field((string) $row['label']) : $slug,
				'default_weight' => isset($row['default_weight']) ? (string) $row['default_weight'] : '',
				'default_dimensions' => array(
					'length' => isset($row['length']) ? (string) $row['length'] : '',
					'width' => isset($row['width']) ? (string) $row['width'] : '',
					'height' => isset($row['height']) ? (string) $row['height'] : '',
				),
				'flags' => array(
					'pickup_capable' => isset($row['pickup_capable']) ? sanitize_text_field((string) $row['pickup_capable']) : '0',
					'mailbox_capable' => isset($row['mailbox_capable']) ? sanitize_text_field((string) $row['mailbox_capable']) : '0',
					'bulky' => isset($row['bulky']) ? sanitize_text_field((string) $row['bulky']) : '0',
					'high_value_secure' => isset($row['high_value_secure']) ? sanitize_text_field((string) $row['high_value_secure']) : '0',
					'force_separate_package' => isset($row['force_separate_package']) ? sanitize_text_field((string) $row['force_separate_package']) : '0',
				),
			);
		}
		return $profiles;
	}

	private function parse_profile_map_rows_editor_input($rows_input) {
		$map = array();
		if (!is_array($rows_input)) {
			return $map;
		}
		foreach ($rows_input as $row) {
			if (!is_array($row)) {
				continue;
			}
			$key = isset($row['source_slug']) ? sanitize_key((string) $row['source_slug']) : '';
			$value = isset($row['profile_slug']) ? sanitize_key((string) $row['profile_slug']) : '';
			if ($key === '' || $value === '') {
				continue;
			}
			$map[$key] = $value;
		}
		return $map;
	}

	private function parse_value_rules_editor_input($rules_input) {
		$rules = array();
		if (!is_array($rules_input)) {
			return $rules;
		}
		foreach ($rules_input as $row) {
			if (!is_array($row)) {
				continue;
			}
			$profile_slug = isset($row['profile_slug']) ? sanitize_key((string) $row['profile_slug']) : '';
			if ($profile_slug === '') {
				continue;
			}
			$rules[] = array(
				'profile_slug' => $profile_slug,
				'min_total' => isset($row['min_total']) ? (string) $row['min_total'] : '',
				'max_total' => isset($row['max_total']) ? (string) $row['max_total'] : '',
				'min_quantity' => isset($row['min_quantity']) ? (string) $row['min_quantity'] : '',
				'max_quantity' => isset($row['max_quantity']) ? (string) $row['max_quantity'] : '',
			);
		}
		return $rules;
	}

	private function parse_checkout_method_rules_editor_input($rules_input) {
		$rules = array();
		if (!is_array($rules_input)) {
			return $rules;
		}

		foreach ($rules_input as $row) {
			if (!is_array($row)) {
				continue;
			}
			$method_key = isset($row['method_key']) ? sanitize_text_field((string) $row['method_key']) : '';
			if ($method_key === '') {
				continue;
			}
			$action = isset($row['action']) ? sanitize_key((string) $row['action']) : 'allow';
			if (!in_array($action, array('allow', 'deny', 'decorate'), true)) {
				$action = 'allow';
			}
			$conditions = array(
				'min_order_value' => isset($row['min_order_value']) ? (string) $row['min_order_value'] : '',
				'max_order_value' => isset($row['max_order_value']) ? (string) $row['max_order_value'] : '',
				'min_total_weight' => isset($row['min_total_weight']) ? (string) $row['min_total_weight'] : '',
				'max_total_weight' => isset($row['max_total_weight']) ? (string) $row['max_total_weight'] : '',
				'has_separate_package' => isset($row['has_separate_package']) ? sanitize_key((string) $row['has_separate_package']) : 'any',
				'has_missing_dimensions' => isset($row['has_missing_dimensions']) ? sanitize_key((string) $row['has_missing_dimensions']) : 'any',
				'has_high_value_secure' => isset($row['has_high_value_secure']) ? sanitize_key((string) $row['has_high_value_secure']) : 'any',
				'mailbox_capable' => isset($row['mailbox_capable']) ? sanitize_key((string) $row['mailbox_capable']) : 'any',
				'pickup_capable' => isset($row['pickup_capable']) ? sanitize_key((string) $row['pickup_capable']) : 'any',
				'bulky' => isset($row['bulky']) ? sanitize_key((string) $row['bulky']) : 'any',
				'profile_slugs' => $this->parse_csv_slug_list(isset($row['profile_slugs']) ? $row['profile_slugs'] : ''),
				'category_slugs' => $this->parse_csv_slug_list(isset($row['category_slugs']) ? $row['category_slugs'] : ''),
			);
			$rules[] = array(
				'method_key' => $method_key,
				'action' => $action,
				'enabled' => isset($row['enabled']) ? sanitize_text_field((string) $row['enabled']) : '0',
				'customer_title' => isset($row['customer_title']) ? sanitize_text_field((string) $row['customer_title']) : '',
				'allow_low_price' => isset($row['allow_low_price']) ? sanitize_text_field((string) $row['allow_low_price']) : '1',
				'allow_free_shipping' => isset($row['allow_free_shipping']) ? sanitize_text_field((string) $row['allow_free_shipping']) : '1',
				'group_label' => isset($row['group_label']) ? sanitize_text_field((string) $row['group_label']) : '',
				'embedded_label' => isset($row['embedded_label']) ? sanitize_text_field((string) $row['embedded_label']) : '',
				'conditions_groups' => array($conditions),
			);
		}

		return $rules;
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
				'live_checkout' => isset($_POST['lp_cargonizer_live_checkout']) && is_array($_POST['lp_cargonizer_live_checkout']) ? wp_unslash($_POST['lp_cargonizer_live_checkout']) : array(),
				'shipping_profiles' => array(
					'default_profile_slug' => isset($_POST['lp_cargonizer_shipping_profiles_default_slug']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_shipping_profiles_default_slug'])) : '',
					'profiles' => $this->parse_profile_rows_editor_input(isset($_POST['lp_cargonizer_shipping_profiles']) && is_array($_POST['lp_cargonizer_shipping_profiles']) ? wp_unslash($_POST['lp_cargonizer_shipping_profiles']) : array()),
					'shipping_class_map' => $this->parse_profile_map_rows_editor_input(isset($_POST['lp_cargonizer_shipping_class_profile_map']) && is_array($_POST['lp_cargonizer_shipping_class_profile_map']) ? wp_unslash($_POST['lp_cargonizer_shipping_class_profile_map']) : array()),
					'category_map' => $this->parse_profile_map_rows_editor_input(isset($_POST['lp_cargonizer_category_profile_map']) && is_array($_POST['lp_cargonizer_category_profile_map']) ? wp_unslash($_POST['lp_cargonizer_category_profile_map']) : array()),
					'value_rules' => $this->parse_value_rules_editor_input(isset($_POST['lp_cargonizer_value_profile_rules']) && is_array($_POST['lp_cargonizer_value_profile_rules']) ? wp_unslash($_POST['lp_cargonizer_value_profile_rules']) : array()),
				),
				'package_resolution' => array(
					'package_build_mode' => isset($_POST['lp_cargonizer_package_build_mode']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_package_build_mode'])) : 'combined_single',
					'fallback_sources' => $this->parse_live_checkout_lines_to_list(isset($_POST['lp_cargonizer_package_resolution_fallback_sources']) ? wp_unslash($_POST['lp_cargonizer_package_resolution_fallback_sources']) : ''),
				),
				'checkout_method_rules' => array(
					'rules' => $this->parse_checkout_method_rules_editor_input(isset($_POST['lp_cargonizer_checkout_method_rules']) && is_array($_POST['lp_cargonizer_checkout_method_rules']) ? wp_unslash($_POST['lp_cargonizer_checkout_method_rules']) : array()),
				),
				'checkout_fallback' => array(
					'on_quote_failure' => isset($_POST['lp_cargonizer_checkout_fallback_on_quote_failure']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_checkout_fallback_on_quote_failure'])) : '',
					'allow_checkout_with_fallback' => isset($_POST['lp_cargonizer_checkout_fallback_allow_checkout']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_checkout_fallback_allow_checkout'])) : '0',
					'safe_fallback_rates' => $this->parse_live_checkout_json_array_input(isset($_POST['lp_cargonizer_checkout_fallback_rates_json']) ? wp_unslash($_POST['lp_cargonizer_checkout_fallback_rates_json']) : ''),
				),
			);

			$new_settings = $this->sanitize_settings($new_settings);
			$profiles_json = $this->parse_live_checkout_json_array_input(isset($_POST['lp_cargonizer_shipping_profiles_json']) ? wp_unslash($_POST['lp_cargonizer_shipping_profiles_json']) : '');
			if (!empty($profiles_json) && empty($new_settings['shipping_profiles']['profiles'])) {
				$new_settings['shipping_profiles']['profiles'] = $profiles_json;
				$new_settings = $this->sanitize_settings($new_settings);
			}
			$method_rules_json = $this->parse_live_checkout_json_array_input(isset($_POST['lp_cargonizer_checkout_method_rules_json']) ? wp_unslash($_POST['lp_cargonizer_checkout_method_rules_json']) : '');
			if (!empty($method_rules_json) && empty($new_settings['checkout_method_rules']['rules'])) {
				$new_settings['checkout_method_rules']['rules'] = $method_rules_json;
				$new_settings = $this->sanitize_settings($new_settings);
			}
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
				Enklere oppsett for butikk: start i <strong>Enkelt oppsett</strong>, og bruk <strong>Avansert</strong> ved behov.
			</p>

			<?php
			$live_checkout_summary = isset($settings['live_checkout']) && is_array($settings['live_checkout']) ? $settings['live_checkout'] : array();
			$threshold_summary = isset($live_checkout_summary['free_shipping_threshold']) ? $this->sanitize_non_negative_number($live_checkout_summary['free_shipping_threshold']) : 1500;
			$threshold_basis_summary = isset($live_checkout_summary['free_shipping_threshold_basis']) ? sanitize_key((string) $live_checkout_summary['free_shipping_threshold_basis']) : 'subtotal_incl_vat';
			$low_price_summary = isset($live_checkout_summary['low_price_option_amount']) ? $this->sanitize_non_negative_number($live_checkout_summary['low_price_option_amount']) : 69;
			$format_summary_price = function ($value) {
				$value = (float) $value;
				if (function_exists('wc_format_localized_price')) {
					return wc_format_localized_price($value);
				}
				return number_format_i18n($value, 2);
			};
			$summary_lines = array();
			$summary_lines[] = !empty($live_checkout_summary['enabled']) ? 'Live checkout er aktivert.' : 'Live checkout er ikke aktivert.';
			$summary_lines[] = !empty($live_checkout_summary['norway_only_enabled']) ? 'Frakt vises kun for Norge (NO).' : 'Frakt kan vises utenfor Norge.';
			$threshold_basis_label = $threshold_basis_summary === 'subtotal_excl_vat' ? 'eks. MVA' : 'inkl. MVA';
			if ((string) (isset($live_checkout_summary['low_price_strategy']) ? $live_checkout_summary['low_price_strategy'] : '') !== 'disabled') {
				$summary_lines[] = 'Under ' . $format_summary_price($threshold_summary) . ' NOK (' . $threshold_basis_label . ') settes billigste kvalifiserte metode til ' . $format_summary_price($low_price_summary) . ' NOK.';
			}
			if ((string) (isset($live_checkout_summary['free_shipping_strategy']) ? $live_checkout_summary['free_shipping_strategy'] : '') === 'cheapest_standard_eligible') {
				$summary_lines[] = 'Over ' . $format_summary_price($threshold_summary) . ' NOK (' . $threshold_basis_label . ') blir billigste kvalifiserte standardmetode gratis.';
			}
			$summary_lines[] = 'Nærmeste hentepunkt velges automatisk når metoden støtter pickup points.';
			?>
			<div style="background:#f0f6fc;border:1px solid #c5d9ed;padding:14px 16px;margin:16px 0 20px 0;max-width:1100px;">
				<h2 style="margin-top:0;margin-bottom:8px;">Kort oppsummering av aktiv oppførsel</h2>
				<ul style="margin:0;padding-left:18px;">
					<?php foreach ($summary_lines as $summary_line) : ?>
						<li><?php echo esc_html($summary_line); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>

			<div style="background:#fff;border:1px solid #ddd;padding:20px;margin:20px 0;max-width:900px;">
				<h2>Tilkobling</h2>
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

					<h2>Booking-standardvalg</h2>
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


					<h2>Synlighet for leveringsmetoder</h2>
					<p>Her velger du hvilke metoder som er synlige. Prisfeltene under hver metode styrer samme beregningslogikk som før.</p>

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

					<?php
					$live_checkout = isset($settings['live_checkout']) && is_array($settings['live_checkout']) ? $settings['live_checkout'] : array();
					$shipping_profiles = isset($settings['shipping_profiles']) && is_array($settings['shipping_profiles']) ? $settings['shipping_profiles'] : array();
					$package_resolution = isset($settings['package_resolution']) && is_array($settings['package_resolution']) ? $settings['package_resolution'] : array();
					$checkout_method_rules = isset($settings['checkout_method_rules']) && is_array($settings['checkout_method_rules']) ? $settings['checkout_method_rules'] : array();
					$checkout_fallback = isset($settings['checkout_fallback']) && is_array($settings['checkout_fallback']) ? $settings['checkout_fallback'] : array();
					$flat_methods = isset($settings['available_methods']) && is_array($settings['available_methods']) ? $settings['available_methods'] : array();
					?>

					<hr style="margin:24px 0;">
					<h2>Enkelt oppsett</h2>
					<p class="description" style="max-width:900px;">Anbefalt for vanlig butikkdrift. Disse feltene dekker standardoppsett som fri frakt over 1500, 69-kr under terskel, nærmeste hentepunkt og Norge-fokus.</p>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">Aktiver live checkout</th>
								<td>
									<input type="hidden" name="lp_cargonizer_live_checkout[enabled]" value="0">
									<label><input type="checkbox" name="lp_cargonizer_live_checkout[enabled]" value="1" <?php checked(!empty($live_checkout['enabled'])); ?>> Vis Cargonizer-metoder i checkout</label>
									<p class="description">Når denne er av, påvirkes ikke checkout av live-rater.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">Norge-begrensning</th>
								<td>
									<input type="hidden" name="lp_cargonizer_live_checkout[norway_only_enabled]" value="0">
									<label><input type="checkbox" name="lp_cargonizer_live_checkout[norway_only_enabled]" value="1" <?php checked(!empty($live_checkout['norway_only_enabled'])); ?>> Kun Norge i checkout (NO)</label>
									<p class="description">Anbefalt for Lilleprinsen-oppsett.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">Prisvisning</th>
								<td>
									<input type="hidden" name="lp_cargonizer_live_checkout[show_prices_including_vat]" value="0">
									<label><input type="checkbox" name="lp_cargonizer_live_checkout[show_prices_including_vat]" value="1" <?php checked(!empty($live_checkout['show_prices_including_vat'])); ?>> Vis priser inkludert MVA</label>
									<p class="description">Styrer hvordan lavpris- og fallback-beløp tolkes: avkrysset = beløp regnes som inkl. MVA, ellers eks. MVA.</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_cargonizer_live_checkout_free_shipping_threshold">Gratis frakt terskel (NOK)</label></th>
								<td>
									<input id="lp_cargonizer_live_checkout_free_shipping_threshold" type="number" min="0" step="0.01" name="lp_cargonizer_live_checkout[free_shipping_threshold]" value="<?php echo esc_attr(isset($live_checkout['free_shipping_threshold']) ? $live_checkout['free_shipping_threshold'] : 1500); ?>">
									<p class="description">Over denne ordresummen kan billigste kvalifiserte standardmetode bli gratis.</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_cargonizer_live_checkout_free_shipping_threshold_basis">Terskel beregnes fra</label></th>
								<td>
									<select id="lp_cargonizer_live_checkout_free_shipping_threshold_basis" name="lp_cargonizer_live_checkout[free_shipping_threshold_basis]">
										<option value="subtotal_incl_vat" <?php selected(isset($live_checkout['free_shipping_threshold_basis']) ? $live_checkout['free_shipping_threshold_basis'] : 'subtotal_incl_vat', 'subtotal_incl_vat'); ?>>Delsum inkl. MVA</option>
										<option value="subtotal_excl_vat" <?php selected(isset($live_checkout['free_shipping_threshold_basis']) ? $live_checkout['free_shipping_threshold_basis'] : 'subtotal_incl_vat', 'subtotal_excl_vat'); ?>>Delsum eks. MVA</option>
									</select>
									<p class="description">Brukes både for lavpris under terskel og gratis frakt over terskel.</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_cargonizer_live_checkout_low_price_option_amount">Lavprisalternativ (NOK)</label></th>
								<td>
									<input id="lp_cargonizer_live_checkout_low_price_option_amount" type="number" min="0" step="0.01" name="lp_cargonizer_live_checkout[low_price_option_amount]" value="<?php echo esc_attr(isset($live_checkout['low_price_option_amount']) ? $live_checkout['low_price_option_amount'] : 69); ?>">
									<p class="description">Under terskel settes billigste kvalifiserte metode til denne prisen.</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_cargonizer_live_checkout_low_price_strategy">Lavpris-strategi</label></th>
								<td>
									<select id="lp_cargonizer_live_checkout_low_price_strategy" name="lp_cargonizer_live_checkout[low_price_strategy]">
										<option value="cheapest_eligible_live" <?php selected(isset($live_checkout['low_price_strategy']) ? $live_checkout['low_price_strategy'] : 'cheapest_eligible_live', 'cheapest_eligible_live'); ?>>Billigste kvalifiserte live-estimat</option>
										<option value="disabled" <?php selected(isset($live_checkout['low_price_strategy']) ? $live_checkout['low_price_strategy'] : 'cheapest_eligible_live', 'disabled'); ?>>Deaktivert</option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_cargonizer_live_checkout_free_shipping_strategy">Gratis frakt-strategi</label></th>
								<td>
									<select id="lp_cargonizer_live_checkout_free_shipping_strategy" name="lp_cargonizer_live_checkout[free_shipping_strategy]">
										<option value="cheapest_standard_eligible" <?php selected(isset($live_checkout['free_shipping_strategy']) ? $live_checkout['free_shipping_strategy'] : 'cheapest_standard_eligible', 'cheapest_standard_eligible'); ?>>Billigste standard kvalifiserte alternativ</option>
										<option value="disabled" <?php selected(isset($live_checkout['free_shipping_strategy']) ? $live_checkout['free_shipping_strategy'] : 'cheapest_standard_eligible', 'disabled'); ?>>Deaktivert</option>
									</select>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_cargonizer_live_checkout_quote_timeout_seconds">Quote timeout (sekunder)</label></th>
								<td>
									<input id="lp_cargonizer_live_checkout_quote_timeout_seconds" type="number" min="0" step="0.1" name="lp_cargonizer_live_checkout[quote_timeout_seconds]" value="<?php echo esc_attr(isset($live_checkout['quote_timeout_seconds']) ? $live_checkout['quote_timeout_seconds'] : 5); ?>">
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_cargonizer_live_checkout_quote_timing_mode">Når live quote kjøres</label></th>
								<td>
									<select id="lp_cargonizer_live_checkout_quote_timing_mode" name="lp_cargonizer_live_checkout[quote_timing_mode]">
										<option value="checkout_only" <?php selected(isset($live_checkout['quote_timing_mode']) ? $live_checkout['quote_timing_mode'] : 'checkout_only', 'checkout_only'); ?>>Kun checkout / checkout refresh / order-pay (anbefalt)</option>
										<option value="cart_and_checkout" <?php selected(isset($live_checkout['quote_timing_mode']) ? $live_checkout['quote_timing_mode'] : 'checkout_only', 'cart_and_checkout'); ?>>Cart og checkout</option>
									</select>
									<p class="description">Nærmeste pickup point blir automatisk forhåndsvalgt for pickup-metoder.</p>
								</td>
							</tr>
								<tr>
									<th scope="row"><label for="lp_cargonizer_live_checkout_quote_cache_ttl_seconds">Quote cache TTL (sekunder)</label></th>
									<td><input id="lp_cargonizer_live_checkout_quote_cache_ttl_seconds" type="number" min="0" step="1" name="lp_cargonizer_live_checkout[quote_cache_ttl_seconds]" value="<?php echo esc_attr(isset($live_checkout['quote_cache_ttl_seconds']) ? $live_checkout['quote_cache_ttl_seconds'] : 300); ?>"></td>
								</tr>
								<tr>
									<th scope="row"><label for="lp_cargonizer_live_checkout_pickup_point_cache_ttl_seconds">Pickup-point cache TTL (sekunder)</label></th>
									<td><input id="lp_cargonizer_live_checkout_pickup_point_cache_ttl_seconds" type="number" min="0" step="1" name="lp_cargonizer_live_checkout[pickup_point_cache_ttl_seconds]" value="<?php echo esc_attr(isset($live_checkout['pickup_point_cache_ttl_seconds']) ? $live_checkout['pickup_point_cache_ttl_seconds'] : 300); ?>"><p class="description">Styrer hvor ofte hentepunktlisten oppdateres.</p></td>
								</tr>
								<tr>
									<th scope="row">Debug-logging (live checkout)</th>
									<td>
										<input type="hidden" name="lp_cargonizer_live_checkout[debug_logging]" value="0">
										<label><input type="checkbox" name="lp_cargonizer_live_checkout[debug_logging]" value="1" <?php checked(!empty($live_checkout['debug_logging'])); ?>> Aktiver debug-logging for live checkout</label>
									</td>
								</tr>
						</tbody>
					</table>

					<details style="margin:18px 0;border:1px solid #dcdcde;padding:10px 12px;background:#fff;">
						<summary style="cursor:pointer;font-weight:600;">Avansert (for utvikler / teknisk ansvarlig)</summary>
						<p class="description" style="margin-top:8px;">Her finner du produkt-/pakkeregler, synlighetsregler og JSON-editorer. Skjult som standard for å gjøre daglig bruk enklere.</p>

					<h2>Produkt- og pakkeregler</h2>
					<p class="description">Vanlige profilfelter kan redigeres her uten JSON. JSON-feltet under er beholdt for avansert redigering og bakoverkompatibilitet.</p>
					<p>
						<label for="lp_cargonizer_shipping_profiles_default_slug"><strong>Default profile slug</strong></label><br>
						<input id="lp_cargonizer_shipping_profiles_default_slug" type="text" class="regular-text" name="lp_cargonizer_shipping_profiles_default_slug" value="<?php echo esc_attr(isset($shipping_profiles['default_profile_slug']) ? $shipping_profiles['default_profile_slug'] : 'default'); ?>">
					</p>
					<?php
					$profile_rows = isset($shipping_profiles['profiles']) && is_array($shipping_profiles['profiles']) ? $shipping_profiles['profiles'] : array();
					if (empty($profile_rows)) {
						$profile_rows[] = array();
					}
					$profile_options = array();
					foreach ($profile_rows as $profile_row) {
						if (!is_array($profile_row)) {
							continue;
						}
						$profile_slug = isset($profile_row['slug']) ? sanitize_key((string) $profile_row['slug']) : '';
						if ($profile_slug !== '') {
							$profile_options[$profile_slug] = isset($profile_row['label']) ? (string) $profile_row['label'] : $profile_slug;
						}
					}
					?>
					<table class="widefat striped" style="margin-top:8px;">
						<thead>
							<tr>
								<th>Slug</th>
								<th>Label</th>
								<th>Vekt (kg)</th>
								<th>L (cm)</th>
								<th>B (cm)</th>
								<th>H (cm)</th>
								<th>Flagg</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($profile_rows as $profile_index => $profile_row) : ?>
							<tr>
								<td><input type="text" name="lp_cargonizer_shipping_profiles[<?php echo esc_attr($profile_index); ?>][slug]" value="<?php echo esc_attr(isset($profile_row['slug']) ? (string) $profile_row['slug'] : ''); ?>" style="width:120px;"></td>
								<td><input type="text" name="lp_cargonizer_shipping_profiles[<?php echo esc_attr($profile_index); ?>][label]" value="<?php echo esc_attr(isset($profile_row['label']) ? (string) $profile_row['label'] : ''); ?>" style="width:150px;"></td>
								<td><input type="number" step="0.01" min="0" name="lp_cargonizer_shipping_profiles[<?php echo esc_attr($profile_index); ?>][default_weight]" value="<?php echo esc_attr(isset($profile_row['default_weight']) ? (string) $profile_row['default_weight'] : ''); ?>" style="width:95px;"></td>
								<td><input type="number" step="0.01" min="0" name="lp_cargonizer_shipping_profiles[<?php echo esc_attr($profile_index); ?>][length]" value="<?php echo esc_attr(isset($profile_row['default_dimensions']['length']) ? (string) $profile_row['default_dimensions']['length'] : ''); ?>" style="width:80px;"></td>
								<td><input type="number" step="0.01" min="0" name="lp_cargonizer_shipping_profiles[<?php echo esc_attr($profile_index); ?>][width]" value="<?php echo esc_attr(isset($profile_row['default_dimensions']['width']) ? (string) $profile_row['default_dimensions']['width'] : ''); ?>" style="width:80px;"></td>
								<td><input type="number" step="0.01" min="0" name="lp_cargonizer_shipping_profiles[<?php echo esc_attr($profile_index); ?>][height]" value="<?php echo esc_attr(isset($profile_row['default_dimensions']['height']) ? (string) $profile_row['default_dimensions']['height'] : ''); ?>" style="width:80px;"></td>
								<td>
									<?php foreach (array('pickup_capable' => 'Pickup', 'mailbox_capable' => 'Mailbox', 'bulky' => 'Bulky', 'high_value_secure' => 'Secure', 'force_separate_package' => 'Force separate') as $flag_key => $flag_label) : ?>
										<label style="display:block;white-space:nowrap;">
											<input type="hidden" name="lp_cargonizer_shipping_profiles[<?php echo esc_attr($profile_index); ?>][<?php echo esc_attr($flag_key); ?>]" value="0">
											<input type="checkbox" name="lp_cargonizer_shipping_profiles[<?php echo esc_attr($profile_index); ?>][<?php echo esc_attr($flag_key); ?>]" value="1" <?php checked(!empty($profile_row['flags'][$flag_key])); ?>>
											<?php echo esc_html($flag_label); ?>
										</label>
									<?php endforeach; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<details style="margin-top:10px;">
						<summary>Avansert JSON (profiler)</summary>
						<textarea id="lp_cargonizer_shipping_profiles_json" name="lp_cargonizer_shipping_profiles_json" rows="10" class="large-text code"><?php echo esc_textarea(wp_json_encode(isset($shipping_profiles['profiles']) ? $shipping_profiles['profiles'] : array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></textarea>
					</details>

					<h3>Shipping class → profil</h3>
					<?php
					$shipping_class_map_rows = array();
					$shipping_class_map = isset($shipping_profiles['shipping_class_map']) && is_array($shipping_profiles['shipping_class_map']) ? $shipping_profiles['shipping_class_map'] : array();
					foreach ($shipping_class_map as $source_slug => $profile_slug) {
						$shipping_class_map_rows[] = array('source_slug' => $source_slug, 'profile_slug' => $profile_slug);
					}
					if (empty($shipping_class_map_rows)) {
						$shipping_class_map_rows[] = array();
					}
					?>
					<table class="widefat striped" style="margin-top:8px;max-width:700px;">
						<thead><tr><th>Shipping class slug</th><th>Profil</th></tr></thead>
						<tbody>
						<?php foreach ($shipping_class_map_rows as $map_index => $map_row) : ?>
							<tr>
								<td><input type="text" name="lp_cargonizer_shipping_class_profile_map[<?php echo esc_attr($map_index); ?>][source_slug]" value="<?php echo esc_attr(isset($map_row['source_slug']) ? (string) $map_row['source_slug'] : ''); ?>" style="width:220px;"></td>
								<td>
									<select name="lp_cargonizer_shipping_class_profile_map[<?php echo esc_attr($map_index); ?>][profile_slug]">
										<option value="">—</option>
										<?php foreach ($profile_options as $profile_slug => $profile_label) : ?>
											<option value="<?php echo esc_attr($profile_slug); ?>" <?php selected(isset($map_row['profile_slug']) ? (string) $map_row['profile_slug'] : '', $profile_slug); ?>><?php echo esc_html($profile_label . ' (' . $profile_slug . ')'); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<h3>Kategori → profil</h3>
					<?php
					$category_map_rows = array();
					$category_map = isset($shipping_profiles['category_map']) && is_array($shipping_profiles['category_map']) ? $shipping_profiles['category_map'] : array();
					foreach ($category_map as $source_slug => $profile_slug) {
						$category_map_rows[] = array('source_slug' => $source_slug, 'profile_slug' => $profile_slug);
					}
					if (empty($category_map_rows)) {
						$category_map_rows[] = array();
					}
					?>
					<table class="widefat striped" style="margin-top:8px;max-width:700px;">
						<thead><tr><th>Kategori slug</th><th>Profil</th></tr></thead>
						<tbody>
						<?php foreach ($category_map_rows as $map_index => $map_row) : ?>
							<tr>
								<td><input type="text" name="lp_cargonizer_category_profile_map[<?php echo esc_attr($map_index); ?>][source_slug]" value="<?php echo esc_attr(isset($map_row['source_slug']) ? (string) $map_row['source_slug'] : ''); ?>" style="width:220px;"></td>
								<td>
									<select name="lp_cargonizer_category_profile_map[<?php echo esc_attr($map_index); ?>][profile_slug]">
										<option value="">—</option>
										<?php foreach ($profile_options as $profile_slug => $profile_label) : ?>
											<option value="<?php echo esc_attr($profile_slug); ?>" <?php selected(isset($map_row['profile_slug']) ? (string) $map_row['profile_slug'] : '', $profile_slug); ?>><?php echo esc_html($profile_label . ' (' . $profile_slug . ')'); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<h3>Verdibaserte profilregler</h3>
					<?php
					$value_rule_rows = isset($shipping_profiles['value_rules']) && is_array($shipping_profiles['value_rules']) ? $shipping_profiles['value_rules'] : array();
					if (empty($value_rule_rows)) {
						$value_rule_rows[] = array();
					}
					?>
					<table class="widefat striped" style="margin-top:8px;max-width:920px;">
						<thead><tr><th>Profil</th><th>Min total</th><th>Max total</th><th>Min antall</th><th>Max antall</th></tr></thead>
						<tbody>
						<?php foreach ($value_rule_rows as $rule_index => $value_rule_row) : ?>
							<tr>
								<td>
									<select name="lp_cargonizer_value_profile_rules[<?php echo esc_attr($rule_index); ?>][profile_slug]">
										<option value="">—</option>
										<?php foreach ($profile_options as $profile_slug => $profile_label) : ?>
											<option value="<?php echo esc_attr($profile_slug); ?>" <?php selected(isset($value_rule_row['profile_slug']) ? (string) $value_rule_row['profile_slug'] : '', $profile_slug); ?>><?php echo esc_html($profile_label . ' (' . $profile_slug . ')'); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td><input type="number" min="0" step="0.01" name="lp_cargonizer_value_profile_rules[<?php echo esc_attr($rule_index); ?>][min_total]" value="<?php echo esc_attr(isset($value_rule_row['min_total']) ? (string) $value_rule_row['min_total'] : ''); ?>" style="width:120px;"></td>
								<td><input type="number" min="0" step="0.01" name="lp_cargonizer_value_profile_rules[<?php echo esc_attr($rule_index); ?>][max_total]" value="<?php echo esc_attr(isset($value_rule_row['max_total']) ? (string) $value_rule_row['max_total'] : ''); ?>" style="width:120px;"></td>
								<td><input type="number" min="0" step="1" name="lp_cargonizer_value_profile_rules[<?php echo esc_attr($rule_index); ?>][min_quantity]" value="<?php echo esc_attr(isset($value_rule_row['min_quantity']) ? (string) $value_rule_row['min_quantity'] : ''); ?>" style="width:95px;"></td>
								<td><input type="number" min="0" step="1" name="lp_cargonizer_value_profile_rules[<?php echo esc_attr($rule_index); ?>][max_quantity]" value="<?php echo esc_attr(isset($value_rule_row['max_quantity']) ? (string) $value_rule_row['max_quantity'] : ''); ?>" style="width:95px;"></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<h2>Package resolution</h2>
					<p>
						<label for="lp_cargonizer_package_build_mode"><strong>Pakkebygging</strong></label><br>
						<select id="lp_cargonizer_package_build_mode" name="lp_cargonizer_package_build_mode">
							<option value="combined_single" <?php selected(isset($package_resolution['package_build_mode']) ? $package_resolution['package_build_mode'] : 'combined_single', 'combined_single'); ?>>En kombinert kolli (dagens enkle oppførsel)</option>
							<option value="split_by_profile" <?php selected(isset($package_resolution['package_build_mode']) ? $package_resolution['package_build_mode'] : 'combined_single', 'split_by_profile'); ?>>Del opp per profil</option>
							<option value="separate_bulky_profiles" <?php selected(isset($package_resolution['package_build_mode']) ? $package_resolution['package_build_mode'] : 'combined_single', 'separate_bulky_profiles'); ?>>Del opp per profil + separate bulky-produkter</option>
						</select>
					</p>
					<p class="description">Fallback-kilder i prioritert rekkefølge, én per linje. Tillatte verdier: product_dimensions, product_override, shipping_class_profile, category_profile, value_rule, default_profile.</p>
					<textarea name="lp_cargonizer_package_resolution_fallback_sources" rows="6" class="large-text code"><?php echo esc_textarea(implode("\n", isset($package_resolution['fallback_sources']) && is_array($package_resolution['fallback_sources']) ? $package_resolution['fallback_sources'] : array())); ?></textarea>

					<h2>Leveringsmetode-synlighet (avansert)</h2>
					<p class="description">Regelredigering per metode (allow/deny/decorate). Hver rad er én regelgruppe; metoden tillates når minst én allow-regel matcher (med mindre en deny-regel matcher).</p>
					<?php
					$method_rule_rows = isset($checkout_method_rules['rules']) && is_array($checkout_method_rules['rules']) ? $checkout_method_rules['rules'] : array();
					if (empty($method_rule_rows)) {
						$method_rule_rows[] = array();
					}
					?>
					<table class="widefat striped" style="margin-top:8px;">
						<thead>
							<tr>
								<th>Metode</th>
								<th>Action</th>
								<th>Aktiv</th>
								<th>Kundetittel</th>
								<th>Lavpris</th>
								<th>Gratis frakt</th>
								<th>Verdi / vekt</th>
								<th>Profiler / kategorier</th>
								<th>Pakke-flagg</th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ($method_rule_rows as $rule_index => $rule_row) :
							$conditions_group = array();
							if (isset($rule_row['conditions_groups'][0]) && is_array($rule_row['conditions_groups'][0])) {
								$conditions_group = $rule_row['conditions_groups'][0];
							} elseif (isset($rule_row['conditions']) && is_array($rule_row['conditions'])) {
								$conditions_group = $rule_row['conditions'];
							}
							?>
							<tr>
								<td>
									<select name="lp_cargonizer_checkout_method_rules[<?php echo esc_attr($rule_index); ?>][method_key]">
										<option value="">—</option>
										<?php foreach ($flat_methods as $method_row) :
											$method_key_row = isset($method_row['key']) ? (string) $method_row['key'] : '';
											if ($method_key_row === '') { continue; }
											$method_label_row = isset($method_row['label']) ? (string) $method_row['label'] : $method_key_row;
											?>
											<option value="<?php echo esc_attr($method_key_row); ?>" <?php selected(isset($rule_row['method_key']) ? (string) $rule_row['method_key'] : '', $method_key_row); ?>><?php echo esc_html($method_label_row); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<select name="lp_cargonizer_checkout_method_rules[<?php echo esc_attr($rule_index); ?>][action]">
										<option value="allow" <?php selected(isset($rule_row['action']) ? (string) $rule_row['action'] : 'allow', 'allow'); ?>>allow</option>
										<option value="deny" <?php selected(isset($rule_row['action']) ? (string) $rule_row['action'] : '', 'deny'); ?>>deny</option>
										<option value="decorate" <?php selected(isset($rule_row['action']) ? (string) $rule_row['action'] : '', 'decorate'); ?>>decorate</option>
									</select>
								</td>
								<td>
									<input type="hidden" name="lp_cargonizer_checkout_method_rules[<?php echo esc_attr($rule_index); ?>][enabled]" value="0">
									<input type="checkbox" name="lp_cargonizer_checkout_method_rules[<?php echo esc_attr($rule_index); ?>][enabled]" value="1" <?php checked(!isset($rule_row['enabled']) || !empty($rule_row['enabled'])); ?>>
								</td>
								<td>
									<input type="text" class="regular-text" name="lp_cargonizer_checkout_method_rules[<?php echo esc_attr($rule_index); ?>][customer_title]" value="<?php echo esc_attr(isset($rule_row['customer_title']) ? (string) $rule_row['customer_title'] : ''); ?>">
									<input type="text" class="regular-text" placeholder="group_label" style="margin-top:4px;" name="lp_cargonizer_checkout_method_rules[<?php echo esc_attr($rule_index); ?>][group_label]" value="<?php echo esc_attr(isset($rule_row['group_label']) ? (string) $rule_row['group_label'] : ''); ?>">
									<input type="text" class="regular-text" placeholder="embedded_label" style="margin-top:4px;" name="lp_cargonizer_checkout_method_rules[<?php echo esc_attr($rule_index); ?>][embedded_label]" value="<?php echo esc_attr(isset($rule_row['embedded_label']) ? (string) $rule_row['embedded_label'] : ''); ?>">
								</td>
								<td>
									<input type="hidden" name="lp_cargonizer_checkout_method_rules[<?php echo esc_attr($rule_index); ?>][allow_low_price]" value="0">
									<input type="checkbox" name="lp_cargonizer_checkout_method_rules[<?php echo esc_attr($rule_index); ?>][allow_low_price]" value="1" <?php checked(!isset($rule_row['allow_low_price']) || !empty($rule_row['allow_low_price'])); ?>>
								</td>
								<td>
									<input type="hidden" name="lp_cargonizer_checkout_method_rules[<?php echo esc_attr($rule_index); ?>][allow_free_shipping]" value="0">
									<input type="checkbox" name="lp_cargonizer_checkout_method_rules[<?php echo esc_attr($rule_index); ?>][allow_free_shipping]" value="1" <?php checked(!isset($rule_row['allow_free_shipping']) || !empty($rule_row['allow_free_shipping'])); ?>>
								</td>
								<td>
									<input type="number" step="0.01" placeholder="min order" name="lp_cargonizer_checkout_method_rules[<?php echo esc_attr($rule_index); ?>][min_order_value]" value="<?php echo esc_attr(isset($conditions_group['min_order_value']) ? $conditions_group['min_order_value'] : ''); ?>" style="width:110px;">
									<input type="number" step="0.01" placeholder="max order" name="lp_cargonizer_checkout_method_rules[<?php echo esc_attr($rule_index); ?>][max_order_value]" value="<?php echo esc_attr(isset($conditions_group['max_order_value']) ? $conditions_group['max_order_value'] : ''); ?>" style="width:110px;margin-top:4px;">
									<input type="number" step="0.01" placeholder="min kg" name="lp_cargonizer_checkout_method_rules[<?php echo esc_attr($rule_index); ?>][min_total_weight]" value="<?php echo esc_attr(isset($conditions_group['min_total_weight']) ? $conditions_group['min_total_weight'] : (isset($conditions_group['min_weight']) ? $conditions_group['min_weight'] : '')); ?>" style="width:110px;margin-top:4px;">
									<input type="number" step="0.01" placeholder="max kg" name="lp_cargonizer_checkout_method_rules[<?php echo esc_attr($rule_index); ?>][max_total_weight]" value="<?php echo esc_attr(isset($conditions_group['max_total_weight']) ? $conditions_group['max_total_weight'] : (isset($conditions_group['max_weight']) ? $conditions_group['max_weight'] : '')); ?>" style="width:110px;margin-top:4px;">
								</td>
								<td>
									<input type="text" placeholder="profile slugs (csv)" name="lp_cargonizer_checkout_method_rules[<?php echo esc_attr($rule_index); ?>][profile_slugs]" value="<?php echo esc_attr(implode(', ', isset($conditions_group['profile_slugs']) && is_array($conditions_group['profile_slugs']) ? $conditions_group['profile_slugs'] : array())); ?>" style="width:170px;">
									<input type="text" placeholder="category slugs (csv)" name="lp_cargonizer_checkout_method_rules[<?php echo esc_attr($rule_index); ?>][category_slugs]" value="<?php echo esc_attr(implode(', ', isset($conditions_group['category_slugs']) && is_array($conditions_group['category_slugs']) ? $conditions_group['category_slugs'] : array())); ?>" style="width:170px;margin-top:4px;">
								</td>
								<td>
									<?php foreach (array('has_separate_package' => 'Separate', 'has_missing_dimensions' => 'Missing dims', 'has_high_value_secure' => 'High value', 'mailbox_capable' => 'Mailbox', 'pickup_capable' => 'Pickup', 'bulky' => 'Bulky') as $flag_key => $flag_label) : ?>
										<select name="lp_cargonizer_checkout_method_rules[<?php echo esc_attr($rule_index); ?>][<?php echo esc_attr($flag_key); ?>]" style="width:130px;margin:2px 0;">
											<option value="any" <?php selected(isset($conditions_group[$flag_key]) ? (string) $conditions_group[$flag_key] : 'any', 'any'); ?>><?php echo esc_html($flag_label . ': any'); ?></option>
											<option value="yes" <?php selected(isset($conditions_group[$flag_key]) ? (string) $conditions_group[$flag_key] : '', 'yes'); ?>><?php echo esc_html($flag_label . ': yes'); ?></option>
											<option value="no" <?php selected(isset($conditions_group[$flag_key]) ? (string) $conditions_group[$flag_key] : '', 'no'); ?>><?php echo esc_html($flag_label . ': no'); ?></option>
										</select><br>
									<?php endforeach; ?>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<details style="margin-top:10px;">
						<summary>Avansert JSON (bakoverkompatibilitet)</summary>
						<textarea name="lp_cargonizer_checkout_method_rules_json" rows="10" class="large-text code"><?php echo esc_textarea(wp_json_encode(isset($checkout_method_rules['rules']) ? $checkout_method_rules['rules'] : array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></textarea>
					</details>

					<h2>Fallbacks</h2>
					<p>
						<label for="lp_cargonizer_checkout_fallback_on_quote_failure"><strong>Ved timeout/API-feil</strong></label><br>
							<select id="lp_cargonizer_checkout_fallback_on_quote_failure" name="lp_cargonizer_checkout_fallback_on_quote_failure">
								<option value="safe_fallback_rate" <?php selected(isset($checkout_fallback['on_quote_failure']) ? $checkout_fallback['on_quote_failure'] : 'safe_fallback_rate', 'safe_fallback_rate'); ?>>Bruk sikker fallback-rate</option>
								<option value="use_last_known_rate" <?php selected(isset($checkout_fallback['on_quote_failure']) ? $checkout_fallback['on_quote_failure'] : 'safe_fallback_rate', 'use_last_known_rate'); ?>>Bruk sist kjente rate</option>
								<option value="block_checkout" <?php selected(isset($checkout_fallback['on_quote_failure']) ? $checkout_fallback['on_quote_failure'] : 'safe_fallback_rate', 'block_checkout'); ?>>Blokker checkout</option>
								<option value="hide_live_checkout" <?php selected(isset($checkout_fallback['on_quote_failure']) ? $checkout_fallback['on_quote_failure'] : 'safe_fallback_rate', 'hide_live_checkout'); ?>>Skjul live checkout-metoder</option>
							</select>
					</p>
					<p>
						<input type="hidden" name="lp_cargonizer_checkout_fallback_allow_checkout" value="0">
						<label><input type="checkbox" name="lp_cargonizer_checkout_fallback_allow_checkout" value="1" <?php checked(!empty($checkout_fallback['allow_checkout_with_fallback'])); ?>> Tillat checkout å fortsette med fallback-rater</label>
					</p>
					<p class="description">Fallback-rater som JSON-array med feltene method_key, label og price.</p>
					<textarea name="lp_cargonizer_checkout_fallback_rates_json" rows="8" class="large-text code"><?php echo esc_textarea(wp_json_encode(isset($checkout_fallback['safe_fallback_rates']) ? $checkout_fallback['safe_fallback_rates'] : array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></textarea>
					</details>
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
