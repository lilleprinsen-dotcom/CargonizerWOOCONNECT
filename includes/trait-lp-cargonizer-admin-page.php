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
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonces' => array(
				'orderData' => wp_create_nonce(self::NONCE_ACTION_ORDER_DATA),
				'fetchOptions' => wp_create_nonce(self::NONCE_ACTION_FETCH_OPTIONS),
				'estimate' => wp_create_nonce(self::NONCE_ACTION_ESTIMATE),
				'estimateBaseline' => wp_create_nonce(self::NONCE_ACTION_ESTIMATE_BASELINE),
				'optimizeDsv' => wp_create_nonce(self::NONCE_ACTION_OPTIMIZE_DSV),
				'servicepartners' => wp_create_nonce(self::NONCE_ACTION_SERVICEPARTNERS),
				'book' => wp_create_nonce(self::NONCE_ACTION_BOOK),
				'reprintBookingLabels' => wp_create_nonce(self::NONCE_ACTION_REPRINT_BOOKING_LABELS),
				'postenQueue' => class_exists('LP_Cargonizer_Posten_Label_Automation') ? wp_create_nonce(LP_Cargonizer_Posten_Label_Automation::get_queue_nonce_action()) : '',
				'printers' => wp_create_nonce(self::NONCE_ACTION_PRINTERS),
			),
			'bookingDefaults' => array(
				'notifyEmailToConsignee' => isset($settings['booking_email_notification_default']) ? (int) $this->sanitize_checkbox_value($settings['booking_email_notification_default']) : 1,
				'estimatorTopCount' => isset($settings['booking_estimator_top_count']) ? max(3, min(5, absint($settings['booking_estimator_top_count']))) : 3,
				'pickupAutoselectMode' => isset($settings['booking_pickup_autoselect_mode']) && in_array((string) $settings['booking_pickup_autoselect_mode'], array('nearest', 'none'), true) ? (string) $settings['booking_pickup_autoselect_mode'] : 'nearest',
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
		$booking_reprint_html = $this->render_booking_reprint_controls_html($order);

		echo '<div class="lp-cargonizer-order-actions" style="clear:both;margin-top:16px;padding-top:12px;border-top:1px solid #eee;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">'
			. '<button type="button" class="button lp-cargonizer-estimate-open" data-order-id="' . esc_attr($order->get_id()) . '">Estimer fraktkostnad</button>'
			. '<button type="button" class="button lp-cargonizer-book-open" data-order-id="' . esc_attr($order->get_id()) . '">Book shipment</button>'
			. $booking_reprint_html
			. '</div>'
			. $checkout_summary_html;
	}

	private function render_booking_reprint_controls_html($order) {
		if (!$order || !is_a($order, 'WC_Order')) {
			return '';
		}

		$booking_state = $this->load_order_booking_state($order);
		if (empty($booking_state['booked'])) {
			return '';
		}

		$method_key = isset($booking_state['method_key']) ? sanitize_text_field((string) $booking_state['method_key']) : '';
		if ($method_key === self::MANUAL_NORGESPAKKE_KEY) {
			return '';
		}

		$consignment_id = isset($booking_state['consignment_id']) ? sanitize_text_field((string) $booking_state['consignment_id']) : '';
		$piece_ids = isset($booking_state['piece_ids']) && is_array($booking_state['piece_ids'])
			? array_values(array_filter(array_map('sanitize_text_field', array_map('strval', $booking_state['piece_ids'])), 'strlen'))
			: array();
		if ($consignment_id === '' && empty($piece_ids)) {
			return '';
		}

		$print_state = isset($booking_state['print']) && is_array($booking_state['print']) ? $booking_state['print'] : array();
		$selected_printer_id = isset($print_state['printer_id']) ? sanitize_text_field((string) $print_state['printer_id']) : '';
		if ($selected_printer_id === 'multiple') {
			$selected_printer_id = '';
		}
		if ($selected_printer_id === '') {
			$selected_printer_id = $this->get_current_user_default_printer_id();
		}

		$printer_options = $this->get_printer_label_map();
		$printer_label = isset($print_state['printer_label']) ? sanitize_text_field((string) $print_state['printer_label']) : '';
		if ($selected_printer_id !== '' && !isset($printer_options[$selected_printer_id])) {
			$printer_options[$selected_printer_id] = $printer_label !== '' ? $printer_label : $selected_printer_id;
		}
		$user_default_printer_id = $this->get_current_user_default_printer_id();
		if ($user_default_printer_id !== '' && !isset($printer_options[$user_default_printer_id])) {
			$printer_options[$user_default_printer_id] = $user_default_printer_id;
		}
		if ($selected_printer_id === '' && !empty($printer_options)) {
			$printer_ids = array_keys($printer_options);
			$selected_printer_id = isset($printer_ids[0]) ? (string) $printer_ids[0] : '';
		}
		if (empty($printer_options)) {
			return '<span class="lp-cargonizer-booking-reprint-unavailable" style="color:#b32d2e;">Ingen DirectPrint-printere tilgjengelig for reprint.</span>';
		}

		$html = '<span class="lp-cargonizer-booking-reprint-controls" data-order-id="' . esc_attr($order->get_id()) . '" style="display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap;">';
		$html .= '<select class="lp-cargonizer-booking-reprint-printer" aria-label="Velg printer for Cargonizer reprint" style="max-width:220px;">';
		foreach ($printer_options as $printer_id => $label) {
			$html .= '<option value="' . esc_attr((string) $printer_id) . '"' . selected((string) $printer_id, $selected_printer_id, false) . '>' . esc_html((string) $label) . '</option>';
		}
		$html .= '</select>';
		$html .= '<button type="button" class="button lp-cargonizer-booking-reprint-button">Print på nytt</button>';
		$html .= '<span class="lp-cargonizer-booking-reprint-status" style="color:#646970;"></span>';
		$html .= '</span>';

		return $html;
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
					<div id="lp-cargonizer-booking-sender-section" style="background:#fcfcfc;border:1px solid #dcdcde;padding:12px;margin-bottom:16px;">
						<label style="display:flex;flex-direction:column;gap:4px;">
							<span>Senderadresse</span>
							<select id="lp-cargonizer-booking-sender-profile" style="max-width:520px;">
								<option value="">Standard sender</option>
							</select>
						</label>
						<div id="lp-cargonizer-booking-sender-help" style="margin-top:6px;color:#646970;"></div>
					</div>
					<div id="lp-cargonizer-estimate-overview" style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px;margin-bottom:16px;"></div>
					<div id="lp-cargonizer-estimate-recipient" style="background:#f6f7f7;border:1px solid #dcdcde;padding:12px;margin-bottom:16px;"></div>
					<div id="lp-cargonizer-estimate-lines" style="margin-bottom:16px;"></div>
					<div id="lp-cargonizer-booking-setup-section" style="display:none;margin-top:12px;padding:12px;border:1px solid #dcdcde;background:#fcfcfc;">
						<h3 style="margin:0 0 10px 0;">Booking-oppsett</h3>
						<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;align-items:start;">
							<div id="lp-cargonizer-booking-printer-section">
								<label style="display:flex;flex-direction:column;gap:4px;">
									<span>Standardprinter for sendingen</span>
									<select id="lp-cargonizer-booking-printer-choice" style="max-width:420px;">
										<option value="">Ingen utskrift</option>
									</select>
								</label>
								<div id="lp-cargonizer-booking-printer-help" style="margin-top:6px;color:#646970;"></div>
							</div>
							<div id="lp-cargonizer-booking-notify-section">
								<label style="display:flex;gap:6px;align-items:center;margin-top:2px;">
									<input type="checkbox" id="lp-cargonizer-booking-notify-email">
									<span>Notify customer by e-mail via Cargonizer</span>
								</label>
								<div style="margin-top:6px;color:#646970;">Sender track &amp; trace-link til mottaker når sendingen er overført til transportør.</div>
							</div>
						</div>
					</div>
					<div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;border:1px solid #dcdcde;background:#f6f7f7;">
						<h3 style="margin:0;">Kolli</h3>
						<button type="button" class="button button-primary" id="lp-cargonizer-add-colli">+ Legg til kolli</button>
					</div>
					<div id="lp-cargonizer-colli-validation" style="display:none;margin-top:8px;padding:8px 10px;border:1px solid #dba617;background:#fcf9e8;color:#6d4f00;"></div>
					<div id="lp-cargonizer-colli-list" style="margin-top:10px;"></div>
					<div id="lp-cargonizer-booking-recommendations" style="display:none;margin-top:16px;padding:12px;border:1px solid #2271b1;background:#f0f6fc;">
						<div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
							<h3 style="margin:0;">Anbefalte fraktvalg</h3>
							<button type="button" class="button button-primary" id="lp-cargonizer-run-booking-estimator">Estimer og vis billigste</button>
						</div>
						<div id="lp-cargonizer-booking-recommendations-content" style="color:#646970;">Ingen anbefalinger hentet enda.</div>
					</div>
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
						<div id="lp-cargonizer-booking-confirmation" style="display:none;margin-top:12px;padding:12px;border:1px solid #2271b1;background:#f0f6fc;">
							<h3 style="margin:0 0 8px 0;">Bekreft booking</h3>
							<div id="lp-cargonizer-booking-confirmation-content" style="color:#1d2327;"></div>
							<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
								<button type="button" class="button button-primary" id="lp-cargonizer-confirm-booking">Bekreft booking</button>
								<button type="button" class="button" id="lp-cargonizer-cancel-booking-confirmation">Endre valg</button>
							</div>
						</div>
						<div id="lp-cargonizer-booking-results" style="display:none;margin-top:12px;padding:12px;border:1px solid #dcdcde;background:#fcfcfc;">
							<h3 style="margin:0 0 8px 0;">Booking result</h3>
							<div id="lp-cargonizer-booking-results-content" style="color:#646970;">Ingen booking kjørt enda.</div>
						</div>
						<div style="display:flex;justify-content:space-between;gap:8px;margin-top:16px;align-items:center;">
							<button type="button" class="button button-primary" id="lp-cargonizer-run-estimate">Estimer fraktpris</button>
							<button type="button" class="button button-primary" id="lp-cargonizer-run-booking" style="display:none;">Vis bekreftelse</button>
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

	private function get_live_checkout_last_no_rates_status() {
		$transient_key = defined('LP_Cargonizer_Live_Shipping_Method::LAST_NO_RATES_STATUS_TRANSIENT')
			? LP_Cargonizer_Live_Shipping_Method::LAST_NO_RATES_STATUS_TRANSIENT
			: 'lp_cargonizer_last_no_rates_status';
		$status = get_transient($transient_key);
		return is_array($status) ? $status : array();
	}

	private function get_no_rates_reason_group_label($group) {
		$group = sanitize_key((string) $group);
		$labels = array(
			'destination' => 'Destinasjon',
			'rules' => 'Regler',
			'api' => 'API/quote',
			'pickup_points' => 'Hentepunkt',
			'configuration' => 'Konfigurasjon/autentisering',
		);
		return isset($labels[$group]) ? $labels[$group] : 'Ukjent';
	}

	private function get_no_rates_reason_code_label($code) {
		$code = sanitize_key((string) $code);
		$labels = array(
			'destination_incomplete' => 'Destinasjon mangler data',
			'rules_filtered_all' => 'Regler filtrerte bort alle metoder',
			'quote_api_failure' => 'Ingen brukbare quote-responser',
			'pickup_point_required_no_points' => 'Pickup-metode manglet hentepunkter',
			'auth_or_config_problem' => 'Autentisering/konfigurasjon feilet',
		);
		return isset($labels[$code]) ? $labels[$code] : ($code !== '' ? $code : 'ukjent');
	}

	private function get_live_checkout_setup_warnings($settings, $last_no_rates_status = array()) {
		$warnings = array();
		$enabled_methods = isset($settings['enabled_methods']) && is_array($settings['enabled_methods']) ? $settings['enabled_methods'] : array();
		$enabled_methods = array_values(array_filter(array_map('sanitize_text_field', array_map('strval', $enabled_methods)), 'strlen'));
		$enabled_map = array_fill_keys($enabled_methods, true);

		$live_checkout = isset($settings['live_checkout']) && is_array($settings['live_checkout']) ? $settings['live_checkout'] : array();
		$low_strategy = isset($live_checkout['low_price_strategy']) ? (string) $live_checkout['low_price_strategy'] : 'cheapest_eligible_live';
		$free_strategy = isset($live_checkout['free_shipping_strategy']) ? (string) $live_checkout['free_shipping_strategy'] : 'cheapest_standard_eligible';

		$decorator_by_method = array();
		$rules = isset($settings['checkout_method_rules']['rules']) && is_array($settings['checkout_method_rules']['rules']) ? $settings['checkout_method_rules']['rules'] : array();
		foreach ($rules as $rule) {
			if (!is_array($rule)) {
				continue;
			}
			$method_key = isset($rule['method_key']) ? sanitize_text_field((string) $rule['method_key']) : '';
			if ($method_key === '' || !isset($enabled_map[$method_key])) {
				continue;
			}
			$action = isset($rule['action']) ? sanitize_key((string) $rule['action']) : '';
			$is_enabled = !isset($rule['enabled']) || !empty($rule['enabled']);
			if ($action !== 'decorate' || !$is_enabled) {
				continue;
			}
			$decorator_by_method[$method_key] = array(
				'allow_low_price' => !isset($rule['allow_low_price']) || !empty($rule['allow_low_price']),
				'allow_free_shipping' => !isset($rule['allow_free_shipping']) || !empty($rule['allow_free_shipping']),
			);
		}

		$has_low_price_candidate = false;
		$has_free_shipping_candidate = false;
		foreach ($enabled_methods as $method_key) {
			$rule = isset($decorator_by_method[$method_key]) ? $decorator_by_method[$method_key] : array(
				'allow_low_price' => true,
				'allow_free_shipping' => true,
			);
			if (!empty($rule['allow_low_price'])) {
				$has_low_price_candidate = true;
			}
			if (!empty($rule['allow_free_shipping'])) {
				$has_free_shipping_candidate = true;
			}
		}

		if (!empty($enabled_methods) && $low_strategy !== 'disabled' && !$has_low_price_candidate) {
			$warnings[] = 'Ingen aktiv metode er kvalifisert for lavpris under terskel. Slå på lavpris for minst én metode, eller sett strategien til «Deaktivert».';
		}
		if (!empty($enabled_methods) && $free_strategy !== 'disabled' && !$has_free_shipping_candidate) {
			$warnings[] = 'Ingen aktiv metode er kvalifisert for gratis frakt over terskel. Slå på gratis frakt for minst én metode, eller sett strategien til «Deaktivert».';
		}

		$reason_code = isset($last_no_rates_status['reason_code']) ? sanitize_key((string) $last_no_rates_status['reason_code']) : '';
		if ($reason_code === 'rules_filtered_all') {
			$warnings[] = 'Siste checkout-forsøk ble filtrert bort av regler (ingen metoder vist). Gå gjennom «Fraktmetoder per senderadresse».';
		}

		return $warnings;
	}

	private function fetch_transport_agreements_for_sender_profiles($settings) {
		$settings = is_array($settings) ? $settings : $this->get_settings();
		$profiles = $this->get_sender_profiles_from_settings($settings);
		$active_profiles = array();
		foreach ($profiles as $profile) {
			if (!is_array($profile) || empty($profile['sender_id'])) {
				continue;
			}
			$active_profiles[] = $profile;
		}

		if (empty($active_profiles)) {
			$fallback_sender_id = isset($settings['sender_id']) ? sanitize_text_field((string) $settings['sender_id']) : '';
			if ($fallback_sender_id !== '') {
				$active_profiles[] = array(
					'profile_id' => 'default_sender',
					'name' => 'Default sender',
					'sender_id' => $fallback_sender_id,
				);
			}
		}

		$all_methods = array();
		$results = array();
		$errors = array();
		$raw_by_sender = array();
		$success_count = 0;
		$api_key = isset($settings['api_key']) ? sanitize_text_field((string) $settings['api_key']) : '';

		foreach ($active_profiles as $profile) {
			$sender_id = isset($profile['sender_id']) ? sanitize_text_field((string) $profile['sender_id']) : '';
			$profile_id = isset($profile['profile_id']) ? sanitize_key((string) $profile['profile_id']) : '';
			$profile_name = isset($profile['name']) ? sanitize_text_field((string) $profile['name']) : $sender_id;
			$sender_entity_id = isset($profile['sender_entity_id']) ? sanitize_text_field((string) $profile['sender_entity_id']) : '';
			if ($sender_id === '') {
				continue;
			}

			$result = $this->fetch_transport_agreements($sender_id, $api_key);
			$effective_sender_id = $sender_id;
			if (
				empty($result['success'])
				&& in_array(isset($result['status']) ? (int) $result['status'] : 0, array(401, 403), true)
				&& $sender_entity_id !== ''
				&& $sender_entity_id !== $sender_id
			) {
				$fallback_result = $this->fetch_transport_agreements($sender_entity_id, $api_key);
				if (!empty($fallback_result['success'])) {
					$effective_sender_id = $sender_entity_id;
					$fallback_result['message'] = (isset($fallback_result['message']) ? (string) $fallback_result['message'] : 'Fraktmetoder hentet.')
						. ' Hentet med Cargonizer sender ' . $sender_entity_id . ' etter HTTP ' . (isset($result['status']) ? (int) $result['status'] : 0) . ' for API Sender ID ' . $sender_id . '.';
					$result = $fallback_result;
				} else {
					$result['message'] = (isset($result['message']) ? (string) $result['message'] : 'Henting feilet.')
						. ' Fallback med Cargonizer sender ' . $sender_entity_id . ' feilet også'
						. (!empty($fallback_result['status']) ? ' (HTTP ' . (int) $fallback_result['status'] . ')' : '')
						. ': ' . (isset($fallback_result['message']) ? (string) $fallback_result['message'] : 'Ukjent feil');
				}
			}
			$flattened_methods = array();
			if (!empty($result['success'])) {
				$effective_profile = $profile;
				$effective_profile['sender_id'] = $effective_sender_id;
				$effective_profile['sender_entity_id'] = $sender_entity_id;
				$flattened_methods = $this->flatten_shipping_methods(isset($result['data']) ? $result['data'] : array(), $effective_profile);
			}
			$results[] = array(
				'profile_id' => $profile_id,
				'profile_name' => $profile_name,
				'sender_id' => $sender_id,
				'sender_entity_id' => $sender_entity_id,
				'effective_sender_id' => $effective_sender_id,
				'success' => !empty($result['success']),
				'message' => isset($result['message']) ? (string) $result['message'] : '',
				'status' => isset($result['status']) ? (int) $result['status'] : 0,
				'agreement_count' => !empty($result['data']) && is_array($result['data']) ? count($result['data']) : 0,
				'method_count' => count($flattened_methods),
				'fetched_at_gmt' => gmdate('Y-m-d H:i:s'),
			);
			$raw_by_sender[$profile_id !== '' ? $profile_id : $sender_id] = array(
				'label' => trim($profile_name . ' (' . $sender_id . ($effective_sender_id !== $sender_id ? ' / hentet med ' . $effective_sender_id : '') . ')'),
				'raw' => isset($result['raw']) ? (string) $result['raw'] : '',
			);

			if (empty($result['success'])) {
				$errors[] = trim(($profile_name !== '' ? $profile_name : 'Sender') . ' (' . $sender_id . '): ' . (isset($result['message']) ? (string) $result['message'] : 'Ukjent feil'));
				continue;
			}

			$success_count++;
			$all_methods = array_merge($all_methods, $flattened_methods);
		}

		if (!empty($errors)) {
			if ($success_count > 0) {
				$all_methods = $this->ensure_internal_manual_methods($all_methods);
				return array(
					'success' => true,
					'partial_success' => true,
					'message' => 'Fraktmetoder ble hentet for ' . $success_count . ' av ' . count($active_profiles) . ' senderadresse(r). Metoder fra senderadresser med feil er beholdt som før. Feil: ' . implode(' | ', $errors),
					'methods' => $all_methods,
					'results' => $results,
					'raw_by_sender' => $raw_by_sender,
				);
			}

			return array(
				'success' => false,
				'message' => 'Kunne ikke hente fraktavtaler for alle senderadresser. Eksisterende metodevalg er beholdt. ' . implode(' | ', $errors),
				'methods' => array(),
				'results' => $results,
				'raw_by_sender' => $raw_by_sender,
			);
		}

		$all_methods = $this->ensure_internal_manual_methods($all_methods);
		return array(
			'success' => true,
			'partial_success' => false,
			'message' => 'Fraktmetoder ble hentet for ' . count($active_profiles) . ' senderadresse(r). Velg hvilke som skal være tilgjengelige og lagre innstillingene.',
			'methods' => $all_methods,
			'results' => $results,
			'raw_by_sender' => $raw_by_sender,
		);
	}

	private function build_sender_identity_aliases($profile_id = '', $sender_id = '', $sender_entity_id = '') {
		$aliases = array();
		$profile_id = sanitize_key((string) $profile_id);
		$sender_id = sanitize_text_field((string) $sender_id);
		$sender_entity_id = sanitize_text_field((string) $sender_entity_id);

		if ($profile_id !== '') {
			$aliases[] = $profile_id;
		}
		if ($sender_id !== '') {
			$aliases[] = $sender_id;
			$aliases[] = $this->build_sender_profile_id($sender_id);
		}
		if ($sender_entity_id !== '') {
			$aliases[] = $sender_entity_id;
			$aliases[] = $this->build_sender_profile_id($sender_entity_id);
		}

		$clean_aliases = array();
		foreach ($aliases as $alias) {
			$alias = sanitize_key((string) $alias);
			if ($alias !== '') {
				$clean_aliases[$alias] = true;
			}
		}

		return array_keys($clean_aliases);
	}

	private function build_method_sender_identity_aliases($method) {
		$method = is_array($method) ? $method : array();
		$profile_id = isset($method['sender_profile_id']) ? sanitize_key((string) $method['sender_profile_id']) : '';
		$sender_id = isset($method['sender_id']) ? sanitize_text_field((string) $method['sender_id']) : '';
		$sender_entity_id = isset($method['sender_entity_id']) ? sanitize_text_field((string) $method['sender_entity_id']) : '';
		$aliases = $this->build_sender_identity_aliases($profile_id, $sender_id, $sender_entity_id);

		if (!empty($method['key']) && strpos((string) $method['key'], '::') !== false) {
			$key_parts = explode('::', (string) $method['key'], 2);
			$key_profile_id = sanitize_key((string) $key_parts[0]);
			if ($key_profile_id !== '') {
				$aliases[] = $key_profile_id;
			}
		}

		$clean_aliases = array();
		foreach ($aliases as $alias) {
			$alias = sanitize_key((string) $alias);
			if ($alias !== '') {
				$clean_aliases[$alias] = true;
			}
		}

		return array_keys($clean_aliases);
	}

	private function method_matches_sender_identity_aliases($method, $sender_alias_map) {
		$sender_alias_map = is_array($sender_alias_map) ? $sender_alias_map : array();
		if (empty($sender_alias_map)) {
			return false;
		}

		foreach ($this->build_method_sender_identity_aliases($method) as $alias) {
			if (isset($sender_alias_map[$alias])) {
				return true;
			}
		}

		return false;
	}

	private function merge_fetched_methods_with_existing_sender_methods($existing_methods, $fetched_methods, $fetch_results) {
		$existing_methods = is_array($existing_methods) ? $existing_methods : array();
		$fetched_methods = is_array($fetched_methods) ? $fetched_methods : array();
		$fetch_results = is_array($fetch_results) ? $fetch_results : array();
		$successful_sender_aliases = array();

		foreach ($fetch_results as $fetch_result) {
			if (!is_array($fetch_result) || empty($fetch_result['success'])) {
				continue;
			}
			$aliases = $this->build_sender_identity_aliases(
				isset($fetch_result['profile_id']) ? $fetch_result['profile_id'] : '',
				isset($fetch_result['sender_id']) ? $fetch_result['sender_id'] : '',
				isset($fetch_result['sender_entity_id']) ? $fetch_result['sender_entity_id'] : ''
			);
			foreach ($aliases as $alias) {
				$successful_sender_aliases[$alias] = true;
			}
		}

		if (empty($successful_sender_aliases)) {
			return $this->ensure_internal_manual_methods($existing_methods);
		}

		$merged_methods = array();
		foreach ($existing_methods as $existing_method) {
			if (!is_array($existing_method)) {
				continue;
			}
			if ($this->is_manual_norgespakke_method($existing_method)) {
				continue;
			}
			if ($this->method_matches_sender_identity_aliases($existing_method, $successful_sender_aliases)) {
				continue;
			}
			$merged_methods[] = $existing_method;
		}

		foreach ($fetched_methods as $fetched_method) {
			if (!is_array($fetched_method) || $this->is_manual_norgespakke_method($fetched_method)) {
				continue;
			}
			$merged_methods[] = $fetched_method;
		}

		return $this->ensure_internal_manual_methods($merged_methods);
	}

	private function get_live_checkout_quote_debug_payload($status_context) {
		$status_context = is_array($status_context) ? $status_context : array();
		$destination = isset($status_context['destination']) && is_array($status_context['destination']) ? $status_context['destination'] : array();
		$request_context = isset($status_context['request_context']) && is_array($status_context['request_context']) ? $status_context['request_context'] : array();
		$failures = isset($status_context['quote_failures']) && is_array($status_context['quote_failures']) ? $status_context['quote_failures'] : array();

		$payload = array(
			'generated_at_gmt' => gmdate('Y-m-d H:i:s'),
			'purpose' => 'Debug helper payload for live checkout no-rates diagnostics',
			'destination' => array(
				'country' => isset($destination['country']) ? sanitize_text_field((string) $destination['country']) : '',
				'postcode_present' => !empty($destination['postcode_present']),
				'city_present' => !empty($destination['city_present']),
				'address_present' => !empty($destination['address_present']),
			),
			'request_context' => array(
				'allow_remote_quotes' => !empty($request_context['allow_remote_quotes']),
				'use_cart_placeholder_rate' => !empty($request_context['use_cart_placeholder_rate']),
			),
			'quote_failures' => array(),
			'notes' => array(
				'Use this snapshot to compare checkout request context with expected NO destination readiness.',
				'If quote_failures contains auth_or_config_problem or 401/403, validate API key and sender settings first.',
			),
		);

		foreach ($failures as $failure) {
			if (!is_array($failure)) {
				continue;
			}
			$payload['quote_failures'][] = array(
				'method_key' => isset($failure['method_key']) ? sanitize_text_field((string) $failure['method_key']) : '',
				'reason_code' => isset($failure['reason_code']) ? sanitize_key((string) $failure['reason_code']) : '',
				'http_status' => isset($failure['http_status']) ? (int) $failure['http_status'] : 0,
				'error' => isset($failure['error']) ? sanitize_text_field((string) $failure['error']) : '',
			);
		}

		return $payload;
	}

	private function get_admin_pickup_lookup_timeout_seconds($settings) {
		$live_settings = isset($settings['live_checkout']) && is_array($settings['live_checkout']) ? $settings['live_checkout'] : array();
		$pickup_timeout = isset($live_settings['pickup_point_timeout_seconds']) ? (float) $live_settings['pickup_point_timeout_seconds'] : 8.0;
		if ($pickup_timeout <= 0) {
			$pickup_timeout = 8.0;
		}
		return max(1.0, min(30.0, $pickup_timeout));
	}

	private function get_admin_servicepartner_docs_expectation($method) {
		$product_id = isset($method['product_id']) ? sanitize_key((string) $method['product_id']) : '';
		$product_name_raw = isset($method['product_name']) ? (string) $method['product_name'] : '';
		$product_name = sanitize_text_field($product_name_raw);
		$product_name_lc = function_exists('mb_strtolower') ? mb_strtolower($product_name_raw, 'UTF-8') : strtolower($product_name_raw);
		$requires_by_product_id = in_array($product_id, array(
			'mypack_collect',
			'postnord_mypack_collect',
			'mypack_small_home',
			'postnord_mypack_small_home',
		), true);
		if ($requires_by_product_id) {
			return array(
				'docs_require_servicepartner' => true,
				'docs_reason' => 'Logistra docs: product_id "' . $product_id . '" requires <parts><service_partner> for this shipment type.',
			);
		}
		if ($product_name_lc !== '' && strpos($product_name_lc, 'return dropoff') !== false) {
			return array(
				'docs_require_servicepartner' => true,
				'docs_reason' => 'Logistra docs: product name indicates Return Dropoff, which requires <parts><service_partner>.',
			);
		}
		return array(
			'docs_require_servicepartner' => false,
			'docs_reason' => 'No explicit Logistra docs requirement matched for service_partner based on product_id/product_name.',
		);
	}

	private function run_admin_estimate_diagnostic($packages, $recipient, $method_payload, $pricing_config) {
		$xml = $this->build_estimate_request_xml(array(
			'recipient' => $recipient,
			'packages' => $packages,
			'servicepartner' => isset($method_payload['servicepartner']) ? $method_payload['servicepartner'] : '',
			'servicepartner_customer_number' => isset($method_payload['servicepartner_customer_number']) ? $method_payload['servicepartner_customer_number'] : '',
			'servicepartner_selected_option' => isset($method_payload['servicepartner_selected_option']) && is_array($method_payload['servicepartner_selected_option']) ? $method_payload['servicepartner_selected_option'] : array(),
			'servicepartner_options' => isset($method_payload['servicepartner_options']) && is_array($method_payload['servicepartner_options']) ? $method_payload['servicepartner_options'] : array(),
			'use_sms_service' => !empty($method_payload['use_sms_service']),
			'sms_service_id' => isset($method_payload['sms_service_id']) ? $method_payload['sms_service_id'] : '',
			'selected_service_ids' => isset($method_payload['selected_service_ids']) && is_array($method_payload['selected_service_ids']) ? $method_payload['selected_service_ids'] : array(),
		), $method_payload);

		$estimate_result = $this->run_consignment_estimate_for_packages($packages, $recipient, $method_payload, $pricing_config);
		$raw_excerpt = isset($estimate_result['raw_response']) ? (string) $estimate_result['raw_response'] : '';
		if (strlen($raw_excerpt) > 1000) {
			$raw_excerpt = substr($raw_excerpt, 0, 1000);
		}

		return array(
			'status' => isset($estimate_result['status']) && $estimate_result['status'] === 'ok' ? 'ok' : 'failed',
			'xml_preview' => (string) $xml,
			'http_status' => isset($estimate_result['http_status']) ? (int) $estimate_result['http_status'] : 0,
			'raw_response_excerpt' => $raw_excerpt,
			'error' => isset($estimate_result['error']) ? sanitize_text_field((string) $estimate_result['error']) : '',
			'error_code' => isset($estimate_result['error_code']) ? sanitize_text_field((string) $estimate_result['error_code']) : '',
			'error_type' => isset($estimate_result['error_type']) ? sanitize_text_field((string) $estimate_result['error_type']) : '',
			'error_details' => isset($estimate_result['error_details']) ? sanitize_text_field((string) $estimate_result['error_details']) : '',
			'parsed_error_message' => isset($estimate_result['parsed_error_message']) ? sanitize_text_field((string) $estimate_result['parsed_error_message']) : '',
			'gross_amount' => isset($estimate_result['gross_amount']) ? (string) $estimate_result['gross_amount'] : '',
			'net_amount' => isset($estimate_result['net_amount']) ? (string) $estimate_result['net_amount'] : '',
			'estimated_cost' => isset($estimate_result['estimated_cost']) ? (string) $estimate_result['estimated_cost'] : '',
			'selected_price_source' => isset($estimate_result['selected_price_source']) ? (string) $estimate_result['selected_price_source'] : '',
			'selected_price_value' => isset($estimate_result['selected_price_value']) ? (string) $estimate_result['selected_price_value'] : '',
		);
	}

	private function handle_direct_print_upload($settings) {
		$result = array(
			'success' => false,
			'message' => '',
			'http_status' => 0,
			'raw_response' => '',
		);

		if (empty($settings['api_key'])) {
			$result['message'] = 'Legg inn API key før du bruker DirectPrint dokumentutskrift.';
			return $result;
		}

		$printer_id = isset($_POST['lp_cargonizer_direct_print_printer_id']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_direct_print_printer_id'])) : '';
		if ($printer_id === '') {
			$result['message'] = 'Velg en printer.';
			return $result;
		}

		if (empty($_FILES['lp_cargonizer_direct_print_file']) || !is_array($_FILES['lp_cargonizer_direct_print_file'])) {
			$result['message'] = 'Velg en PDF-, ZPL- eller EPL-fil.';
			return $result;
		}

		$uploaded_file = $_FILES['lp_cargonizer_direct_print_file'];
		$upload_error = isset($uploaded_file['error']) ? (int) $uploaded_file['error'] : UPLOAD_ERR_NO_FILE;
		if ($upload_error !== UPLOAD_ERR_OK) {
			$result['message'] = $this->get_direct_print_upload_error_message($upload_error);
			return $result;
		}

		$tmp_name = isset($uploaded_file['tmp_name']) && is_string($uploaded_file['tmp_name']) ? $uploaded_file['tmp_name'] : '';
		if ($tmp_name === '' || !is_uploaded_file($tmp_name) || !is_readable($tmp_name)) {
			$result['message'] = 'Kunne ikke lese den opplastede filen.';
			return $result;
		}

		$file_size = isset($uploaded_file['size']) ? (int) $uploaded_file['size'] : 0;
		if ($file_size <= 0) {
			$result['message'] = 'Filen er tom.';
			return $result;
		}

		$max_upload_size = (int) apply_filters('lp_cargonizer_direct_print_max_upload_size', 10 * 1024 * 1024);
		if (function_exists('wp_max_upload_size')) {
			$wp_max_upload_size = (int) wp_max_upload_size();
			if ($wp_max_upload_size > 0 && ($max_upload_size <= 0 || $wp_max_upload_size < $max_upload_size)) {
				$max_upload_size = $wp_max_upload_size;
			}
		}
		if ($max_upload_size > 0 && $file_size > $max_upload_size) {
			$result['message'] = 'Filen er for stor. Maks størrelse er ' . size_format($max_upload_size) . '.';
			return $result;
		}

		$content_type = $this->resolve_direct_print_upload_content_type($uploaded_file);
		if ($content_type === '') {
			$result['message'] = 'Filtypen støttes ikke. Bruk PDF, ZPL eller EPL.';
			return $result;
		}

		$file_contents = file_get_contents($tmp_name);
		if ($file_contents === false || $file_contents === '') {
			$result['message'] = 'Kunne ikke lese filinnholdet.';
			return $result;
		}

		if ($content_type === 'application/pdf' && strpos($file_contents, '%PDF') !== 0) {
			$result['message'] = 'PDF-filen ser ikke ut til å være en gyldig PDF.';
			return $result;
		}

		$print_result = $this->print_document_to_printer($printer_id, $file_contents, $content_type);
		$result['http_status'] = isset($print_result['http_status']) ? (int) $print_result['http_status'] : 0;
		$result['raw_response'] = isset($print_result['raw_response']) ? (string) $print_result['raw_response'] : '';

		if (empty($print_result['success'])) {
			$error = isset($print_result['error']) ? sanitize_text_field((string) $print_result['error']) : '';
			$result['message'] = $error !== '' ? 'DirectPrint feilet: ' . $error : 'DirectPrint feilet.';
			return $result;
		}

		$file_name = isset($uploaded_file['name']) && is_scalar($uploaded_file['name']) ? sanitize_file_name((string) $uploaded_file['name']) : '';
		$result['success'] = true;
		$result['message'] = 'Filen ble sendt til DirectPrint' . ($file_name !== '' ? ': ' . $file_name : '') . '.';
		return $result;
	}

	private function get_direct_print_upload_error_message($upload_error) {
		$messages = array(
			UPLOAD_ERR_INI_SIZE => 'Filen er større enn serverens opplastingsgrense.',
			UPLOAD_ERR_FORM_SIZE => 'Filen er større enn tillatt opplastingsgrense.',
			UPLOAD_ERR_PARTIAL => 'Filen ble bare delvis lastet opp.',
			UPLOAD_ERR_NO_FILE => 'Velg en PDF-, ZPL- eller EPL-fil.',
			UPLOAD_ERR_NO_TMP_DIR => 'Serveren mangler midlertidig opplastingsmappe.',
			UPLOAD_ERR_CANT_WRITE => 'Serveren kunne ikke lagre opplastingen midlertidig.',
			UPLOAD_ERR_EXTENSION => 'En PHP-utvidelse stoppet opplastingen.',
		);

		return isset($messages[$upload_error]) ? $messages[$upload_error] : 'Opplastingen feilet.';
	}

	private function resolve_direct_print_upload_content_type($uploaded_file) {
		$file_name = isset($uploaded_file['name']) && is_scalar($uploaded_file['name']) ? sanitize_file_name((string) $uploaded_file['name']) : '';
		$extension = strtolower((string) pathinfo($file_name, PATHINFO_EXTENSION));
		$extension_map = array(
			'pdf' => 'application/pdf',
			'zpl' => 'application/x.zpl',
			'epl' => 'application/x.epl',
		);
		if (isset($extension_map[$extension])) {
			return $extension_map[$extension];
		}

		$client_type = isset($uploaded_file['type']) && is_scalar($uploaded_file['type']) ? strtolower(trim((string) $uploaded_file['type'])) : '';
		$client_type = strtok($client_type, ';');
		$mime_map = array(
			'application/pdf' => 'application/pdf',
			'application/x-pdf' => 'application/pdf',
			'application/x.zpl' => 'application/x.zpl',
			'application/x-zpl' => 'application/x.zpl',
			'application/vnd.zebra-zpl' => 'application/x.zpl',
			'application/x.epl' => 'application/x.epl',
			'application/x-epl' => 'application/x.epl',
		);

		return isset($mime_map[$client_type]) ? $mime_map[$client_type] : '';
	}

	private function build_sender_transport_agreement_summary($settings) {
		$settings = is_array($settings) ? $settings : $this->get_settings();
		$summaries = array();
		$sender_profile_aliases = array();
		$add_sender_summary_aliases = function($profile_id, $sender_id = '', $sender_entity_id = '') use (&$sender_profile_aliases) {
			$profile_id = sanitize_key((string) $profile_id);
			if ($profile_id === '') {
				return;
			}
			$aliases = array($profile_id);
			$sender_id = sanitize_text_field((string) $sender_id);
			$sender_entity_id = sanitize_text_field((string) $sender_entity_id);
			if ($sender_id !== '') {
				$aliases[] = $sender_id;
				$aliases[] = $this->build_sender_profile_id($sender_id);
			}
			if ($sender_entity_id !== '') {
				$aliases[] = $sender_entity_id;
				$aliases[] = $this->build_sender_profile_id($sender_entity_id);
			}
			foreach (array_values(array_unique(array_filter($aliases, 'strlen'))) as $alias) {
				$sender_profile_aliases[sanitize_key((string) $alias)] = $profile_id;
				$sender_profile_aliases[sanitize_text_field((string) $alias)] = $profile_id;
			}
		};
		$profiles = $this->get_sender_profiles_from_settings($settings);
		foreach ($profiles as $profile) {
			if (!is_array($profile)) {
				continue;
			}
			$profile_id = isset($profile['profile_id']) ? sanitize_key((string) $profile['profile_id']) : '';
			$sender_id = isset($profile['sender_id']) ? sanitize_text_field((string) $profile['sender_id']) : '';
			$sender_entity_id = isset($profile['sender_entity_id']) ? sanitize_text_field((string) $profile['sender_entity_id']) : '';
			if ($profile_id === '' && $sender_id !== '') {
				$profile_id = $this->build_sender_profile_id($sender_id);
			}
			if ($profile_id === '') {
				continue;
			}
			$sender_label = isset($profile['label']) ? sanitize_text_field((string) $profile['label']) : '';
			if ($sender_label === '') {
				$profile_name = isset($profile['name']) ? sanitize_text_field((string) $profile['name']) : '';
				$sender_label = trim($profile_name . ($sender_id !== '' ? ' (' . $sender_id . ')' : ''));
			}
			if ($sender_label === '') {
				$sender_label = $profile_id;
			}
			$summaries[$profile_id] = array(
				'profile_id' => $profile_id,
				'sender_id' => $sender_id,
				'sender_entity_id' => $sender_entity_id,
				'sender_label' => $sender_label,
				'total_methods' => 0,
				'enabled_methods' => 0,
				'agreements' => array(),
				'agreement_count' => 0,
				'carrier_names' => array(),
				'last_fetch' => array(),
				'is_legacy_unscoped' => false,
			);
			$add_sender_summary_aliases($profile_id, $sender_id, $sender_entity_id);
		}

		$fetch_results = isset($settings['transport_agreement_fetch_results']) && is_array($settings['transport_agreement_fetch_results'])
			? $settings['transport_agreement_fetch_results']
			: array();
		foreach ($fetch_results as $fetch_result) {
			if (!is_array($fetch_result)) {
				continue;
			}
			$profile_id = isset($fetch_result['profile_id']) ? sanitize_key((string) $fetch_result['profile_id']) : '';
			$sender_id = isset($fetch_result['sender_id']) ? sanitize_text_field((string) $fetch_result['sender_id']) : '';
			$sender_entity_id = isset($fetch_result['sender_entity_id']) ? sanitize_text_field((string) $fetch_result['sender_entity_id']) : '';
			if ($profile_id === '' && $sender_id !== '') {
				$profile_id = $this->build_sender_profile_id($sender_id);
			}
			if ($profile_id === '') {
				continue;
			}
			$fetch_profile_aliases = array($profile_id);
			if ($sender_id !== '') {
				$fetch_profile_aliases[] = $sender_id;
				$fetch_profile_aliases[] = $this->build_sender_profile_id($sender_id);
			}
			if ($sender_entity_id !== '') {
				$fetch_profile_aliases[] = $sender_entity_id;
				$fetch_profile_aliases[] = $this->build_sender_profile_id($sender_entity_id);
			}
			foreach (array_values(array_unique(array_filter($fetch_profile_aliases, 'strlen'))) as $fetch_profile_alias) {
				$fetch_profile_alias_key = sanitize_key((string) $fetch_profile_alias);
				if ($fetch_profile_alias_key !== '' && isset($sender_profile_aliases[$fetch_profile_alias_key]) && isset($summaries[$sender_profile_aliases[$fetch_profile_alias_key]])) {
					$profile_id = $sender_profile_aliases[$fetch_profile_alias_key];
					break;
				}
			}
			if (!isset($summaries[$profile_id])) {
				$profile_name = isset($fetch_result['profile_name']) ? sanitize_text_field((string) $fetch_result['profile_name']) : '';
				$summaries[$profile_id] = array(
					'profile_id' => $profile_id,
					'sender_id' => $sender_id,
					'sender_entity_id' => $sender_entity_id,
					'sender_label' => trim($profile_name . ($sender_id !== '' ? ' (' . $sender_id . ')' : '')),
					'total_methods' => 0,
					'enabled_methods' => 0,
					'agreements' => array(),
					'agreement_count' => 0,
					'carrier_names' => array(),
					'last_fetch' => array(),
					'is_legacy_unscoped' => false,
				);
			}
			if ($sender_id !== '' && empty($summaries[$profile_id]['sender_id'])) {
				$summaries[$profile_id]['sender_id'] = $sender_id;
			}
			if ($sender_entity_id !== '' && empty($summaries[$profile_id]['sender_entity_id'])) {
				$summaries[$profile_id]['sender_entity_id'] = $sender_entity_id;
			}
			$add_sender_summary_aliases($profile_id, $sender_id, $sender_entity_id);
			$summaries[$profile_id]['last_fetch'] = array(
				'success' => !empty($fetch_result['success']),
				'message' => isset($fetch_result['message']) ? sanitize_text_field((string) $fetch_result['message']) : '',
				'status' => isset($fetch_result['status']) ? (int) $fetch_result['status'] : 0,
				'agreement_count' => isset($fetch_result['agreement_count']) ? (int) $fetch_result['agreement_count'] : 0,
				'method_count' => isset($fetch_result['method_count']) ? (int) $fetch_result['method_count'] : 0,
				'fetched_at_gmt' => isset($fetch_result['fetched_at_gmt']) ? sanitize_text_field((string) $fetch_result['fetched_at_gmt']) : '',
				'effective_sender_id' => isset($fetch_result['effective_sender_id']) ? sanitize_text_field((string) $fetch_result['effective_sender_id']) : '',
			);
		}

		$enabled_methods = isset($settings['enabled_methods']) && is_array($settings['enabled_methods']) ? $settings['enabled_methods'] : array();
		$enabled_methods = array_values(array_filter(array_map('sanitize_text_field', array_map('strval', $enabled_methods)), 'strlen'));
		$enabled_map = array_fill_keys($enabled_methods, true);
		$available_methods = isset($settings['available_methods']) && is_array($settings['available_methods']) ? $settings['available_methods'] : array();

		foreach ($available_methods as $method) {
			if (!is_array($method) || $this->is_manual_norgespakke_method($method)) {
				continue;
			}

			$method_key = isset($method['key']) ? sanitize_text_field((string) $method['key']) : '';
			$sender_profile_id = isset($method['sender_profile_id']) ? sanitize_key((string) $method['sender_profile_id']) : '';
			$sender_id = isset($method['sender_id']) ? sanitize_text_field((string) $method['sender_id']) : '';
			$sender_entity_id = isset($method['sender_entity_id']) ? sanitize_text_field((string) $method['sender_entity_id']) : '';
			if ($sender_profile_id === '' && $sender_id !== '') {
				$sender_profile_id = $this->build_sender_profile_id($sender_id);
			}
			if ($sender_profile_id === '' && !empty($method['key']) && strpos((string) $method['key'], '::') !== false) {
				$key_parts = explode('::', (string) $method['key'], 2);
				$sender_profile_id = sanitize_key((string) $key_parts[0]);
			}
			if ($sender_profile_id !== '' && !isset($summaries[$sender_profile_id]) && isset($sender_profile_aliases[$sender_profile_id])) {
				$sender_profile_id = $sender_profile_aliases[$sender_profile_id];
			}
			if ($sender_profile_id === '' || !isset($summaries[$sender_profile_id])) {
				foreach ($this->build_sender_identity_aliases('', $sender_id, $sender_entity_id) as $sender_alias) {
					if (isset($sender_profile_aliases[$sender_alias])) {
						$sender_profile_id = $sender_profile_aliases[$sender_alias];
						break;
					}
				}
			}
			if ($sender_profile_id === '') {
				$sender_profile_id = '__legacy_unscoped';
			}

			if (!isset($summaries[$sender_profile_id])) {
				$sender_profile_name = isset($method['sender_profile_name']) ? sanitize_text_field((string) $method['sender_profile_name']) : '';
				$sender_label = trim($sender_profile_name . ($sender_id !== '' ? ' (' . $sender_id . ')' : ''));
				if ($sender_profile_id === '__legacy_unscoped') {
					$sender_label = 'Eldre metoder uten Sender ID';
				}
				$summaries[$sender_profile_id] = array(
					'profile_id' => $sender_profile_id,
					'sender_id' => $sender_id,
					'sender_entity_id' => $sender_entity_id,
					'sender_label' => $sender_label !== '' ? $sender_label : $sender_profile_id,
					'total_methods' => 0,
					'enabled_methods' => 0,
					'agreements' => array(),
					'agreement_count' => 0,
					'carrier_names' => array(),
					'last_fetch' => array(),
					'is_legacy_unscoped' => $sender_profile_id === '__legacy_unscoped',
				);
			}

			$summaries[$sender_profile_id]['total_methods']++;
			if ($method_key !== '' && isset($enabled_map[$method_key])) {
				$summaries[$sender_profile_id]['enabled_methods']++;
			}

			$agreement_id = isset($method['agreement_id']) ? trim((string) $method['agreement_id']) : '';
			$agreement_name = isset($method['agreement_name']) ? trim((string) $method['agreement_name']) : '';
			$agreement_description = isset($method['agreement_description']) ? trim((string) $method['agreement_description']) : '';
			$agreement_key = $agreement_id !== '' ? 'id:' . $agreement_id : ($agreement_name !== '' ? 'name:' . $agreement_name : 'method:' . $method_key);
			$agreement_label = $agreement_description !== '' ? $agreement_description : ($agreement_name !== '' ? $agreement_name : ($agreement_id !== '' ? $agreement_id : 'Ukjent fraktavtale'));
			if ($agreement_key !== '') {
				$summaries[$sender_profile_id]['agreements'][$agreement_key] = $agreement_label;
			}

			$carrier_name = isset($method['carrier_name']) ? trim((string) $method['carrier_name']) : '';
			if ($carrier_name !== '') {
				$summaries[$sender_profile_id]['carrier_names'][$carrier_name] = true;
			}
		}

		foreach ($summaries as &$summary) {
			ksort($summary['agreements']);
			ksort($summary['carrier_names']);
			$summary['agreement_count'] = count($summary['agreements']);
			$summary['carrier_names'] = array_keys($summary['carrier_names']);
		}
		unset($summary);

		return $summaries;
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
		$admin_servicepoint_diag_result = null;
		$direct_print_result = null;
		$posten_robot_plain_token = '';

		// Lagre innstillinger
		if (
			(isset($_POST['lp_cargonizer_save_settings']) || isset($_POST['lp_cargonizer_generate_posten_robot_token']))
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
				'booking_estimator_top_count' => isset($_POST['lp_cargonizer_booking_estimator_top_count']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_booking_estimator_top_count'])) : (isset($settings['booking_estimator_top_count']) ? $settings['booking_estimator_top_count'] : 3),
				'booking_pickup_autoselect_mode' => isset($_POST['lp_cargonizer_booking_pickup_autoselect_mode']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_booking_pickup_autoselect_mode'])) : (isset($settings['booking_pickup_autoselect_mode']) ? $settings['booking_pickup_autoselect_mode'] : 'nearest'),
				'booking_order_status_after_created' => isset($_POST['lp_cargonizer_booking_order_status_after_created']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_booking_order_status_after_created'])) : (isset($settings['booking_order_status_after_created']) ? $settings['booking_order_status_after_created'] : ''),
				'posten_robot' => array(
					'enabled' => isset($_POST['lp_cargonizer_posten_robot_enabled']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_posten_robot_enabled'])) : '0',
					'auto_queue_checkout_norgespakke' => isset($_POST['lp_cargonizer_posten_robot_auto_queue']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_posten_robot_auto_queue'])) : '0',
					'token_hash' => isset($settings['posten_robot']['token_hash']) ? sanitize_text_field((string) $settings['posten_robot']['token_hash']) : '',
					'token_generated_at_gmt' => isset($settings['posten_robot']['token_generated_at_gmt']) ? sanitize_text_field((string) $settings['posten_robot']['token_generated_at_gmt']) : '',
					'status_when_queued' => 'lp-waiting-label',
					'status_after_label_created' => 'lp-label-created',
					'direct_print_enabled' => isset($_POST['lp_cargonizer_posten_robot_direct_print_enabled']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_posten_robot_direct_print_enabled'])) : '0',
					'direct_print_printer_id' => isset($_POST['lp_cargonizer_posten_robot_direct_print_printer_id']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_posten_robot_direct_print_printer_id'])) : '',
					'stamp_enabled' => isset($_POST['lp_cargonizer_posten_robot_stamp_enabled']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_posten_robot_stamp_enabled'])) : '0',
					'stamp_text_template' => isset($_POST['lp_cargonizer_posten_robot_stamp_text_template']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_posten_robot_stamp_text_template'])) : '',
					'stamp_font_size' => isset($_POST['lp_cargonizer_posten_robot_stamp_font_size']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_posten_robot_stamp_font_size'])) : '',
					'stamp_x_mm' => isset($_POST['lp_cargonizer_posten_robot_stamp_x_mm']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_posten_robot_stamp_x_mm'])) : '',
					'stamp_y_mm' => isset($_POST['lp_cargonizer_posten_robot_stamp_y_mm']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_posten_robot_stamp_y_mm'])) : '',
					'stamp_max_width_mm' => isset($_POST['lp_cargonizer_posten_robot_stamp_max_width_mm']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_posten_robot_stamp_max_width_mm'])) : '',
					'stamp_max_lines' => isset($_POST['lp_cargonizer_posten_robot_stamp_max_lines']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_posten_robot_stamp_max_lines'])) : '',
					'stamp_required_for_print' => isset($_POST['lp_cargonizer_posten_robot_stamp_required_for_print']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_posten_robot_stamp_required_for_print'])) : '0',
				),
				'printer_aliases' => isset($_POST['lp_cargonizer_printer_aliases']) && is_array($_POST['lp_cargonizer_printer_aliases']) ? wp_unslash($_POST['lp_cargonizer_printer_aliases']) : array(),
				'available_methods' => isset($settings['available_methods']) && is_array($settings['available_methods']) ? $settings['available_methods'] : array(),
				'enabled_methods' => is_array($posted_enabled_methods)
					? $posted_enabled_methods
					: (isset($settings['enabled_methods']) && is_array($settings['enabled_methods']) ? $settings['enabled_methods'] : array()),
				'method_discounts' => isset($_POST['lp_cargonizer_method_discounts']) && is_array($_POST['lp_cargonizer_method_discounts']) ? wp_unslash($_POST['lp_cargonizer_method_discounts']) : array(),
				'method_pricing' => isset($_POST['lp_cargonizer_method_pricing']) && is_array($_POST['lp_cargonizer_method_pricing']) ? wp_unslash($_POST['lp_cargonizer_method_pricing']) : array(),
				'method_extra_services' => isset($_POST['lp_cargonizer_method_extra_services']) && is_array($_POST['lp_cargonizer_method_extra_services']) ? wp_unslash($_POST['lp_cargonizer_method_extra_services']) : (isset($settings['method_extra_services']) && is_array($settings['method_extra_services']) ? $settings['method_extra_services'] : array()),
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
					'separate_package_strategy' => isset($_POST['lp_cargonizer_separate_package_strategy']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_separate_package_strategy'])) : 'keep_separate_colli',
					'fallback_sources' => $this->parse_live_checkout_lines_to_list(isset($_POST['lp_cargonizer_package_resolution_fallback_sources']) ? wp_unslash($_POST['lp_cargonizer_package_resolution_fallback_sources']) : ''),
				),
				'checkout_method_rules' => array(
					'rules' => $this->parse_checkout_method_rules_editor_input(isset($_POST['lp_cargonizer_checkout_method_rules']) && is_array($_POST['lp_cargonizer_checkout_method_rules']) ? wp_unslash($_POST['lp_cargonizer_checkout_method_rules']) : array()),
				),
				'warehouse_profiles' => isset($_POST['lp_cargonizer_warehouse_profiles']) && is_array($_POST['lp_cargonizer_warehouse_profiles'])
					? wp_unslash($_POST['lp_cargonizer_warehouse_profiles'])
					: (isset($settings['warehouse_profiles']) && is_array($settings['warehouse_profiles']) ? $settings['warehouse_profiles'] : array()),
				'checkout_fallback' => array(
					'on_quote_failure' => isset($_POST['lp_cargonizer_checkout_fallback_on_quote_failure']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_checkout_fallback_on_quote_failure'])) : '',
					'allow_checkout_with_fallback' => isset($_POST['lp_cargonizer_checkout_fallback_allow_checkout']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_checkout_fallback_allow_checkout'])) : '0',
					'safe_fallback_rates' => $this->parse_live_checkout_json_array_input(isset($_POST['lp_cargonizer_checkout_fallback_rates_json']) ? wp_unslash($_POST['lp_cargonizer_checkout_fallback_rates_json']) : ''),
				),
			);

			$posted_sender_id_field = isset($_POST['lp_cargonizer_sender_id']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_sender_id'])) : '';
			$sender_id_field_changed = $posted_sender_id_field !== '' && $posted_sender_id_field !== (isset($settings['sender_id']) ? sanitize_text_field((string) $settings['sender_id']) : '');
			if (!$sender_id_field_changed && !empty($new_settings['warehouse_profiles']['default_profile_id']) && !empty($new_settings['warehouse_profiles']['profiles']) && is_array($new_settings['warehouse_profiles']['profiles'])) {
				$posted_default_sender_profile_id = sanitize_key((string) $new_settings['warehouse_profiles']['default_profile_id']);
				foreach ($new_settings['warehouse_profiles']['profiles'] as $posted_sender_profile) {
					if (!is_array($posted_sender_profile)) {
						continue;
					}
					$posted_sender_profile_id = isset($posted_sender_profile['profile_id']) ? sanitize_key((string) $posted_sender_profile['profile_id']) : '';
					$posted_sender_id = isset($posted_sender_profile['sender_id']) ? sanitize_text_field((string) $posted_sender_profile['sender_id']) : '';
					if ($posted_sender_profile_id !== '' && $posted_sender_profile_id === $posted_default_sender_profile_id && $posted_sender_id !== '') {
						$new_settings['sender_id'] = $posted_sender_id;
						break;
					}
				}
			}

			if (isset($_POST['lp_cargonizer_generate_posten_robot_token']) && class_exists('LP_Cargonizer_Posten_Label_Automation')) {
				$posten_robot_plain_token = LP_Cargonizer_Posten_Label_Automation::generate_robot_token();
				$new_settings['posten_robot']['token_hash'] = LP_Cargonizer_Posten_Label_Automation::hash_robot_token($posten_robot_plain_token);
				$new_settings['posten_robot']['token_generated_at_gmt'] = gmdate('Y-m-d H:i:s');
			}

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
			if ($posten_robot_plain_token !== '') {
				echo '<div class="notice notice-warning"><p><strong>Ny Posten robot-token:</strong> <code>' . esc_html($posten_robot_plain_token) . '</code></p><p>Kopier denne naa. Den lagres bare som hash og kan ikke vises igjen.</p></div>';
			}
		}

		if (
			isset($_POST['lp_cargonizer_clear_stored_methods'])
			&& isset($_POST['_wpnonce'])
			&& wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::NONCE_ACTION_SAVE)
		) {
			$settings['available_methods'] = array();
			$settings['enabled_methods'] = array();
			$settings['method_discounts'] = array();
			$settings['method_pricing'] = array();
			$settings['method_extra_services'] = array();
			$settings['transport_agreement_fetch_results'] = array();

			update_option(self::OPTION_KEY, $this->sanitize_settings($settings));
			$settings = $this->get_settings();

			echo '<div class="notice notice-success"><p>Alle lagrede Cargonizer-fraktmetoder, metodevalg, prisoppsett, tilleggstjenestevalg og hentestatus er fjernet. Hent fraktavtaler på nytt for å bygge listen.</p></div>';
		} elseif (isset($_POST['lp_cargonizer_clear_stored_methods'])) {
			echo '<div class="notice notice-error"><p>Kunne ikke fjerne lagrede fraktmetoder fordi sikkerhetsnøkkelen var ugyldig. Last siden på nytt og prøv igjen.</p></div>';
		}

		if (isset($_POST['lp_cargonizer_direct_print_upload'])) {
			if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::NONCE_ACTION_DIRECT_PRINT_UPLOAD)) {
				$direct_print_result = array(
					'success' => false,
					'message' => 'Ugyldig sikkerhetsnøkkel. Last siden på nytt og prøv igjen.',
				);
			} else {
				$direct_print_result = $this->handle_direct_print_upload($settings);
			}

			$direct_print_notice_class = !empty($direct_print_result['success']) ? 'notice-success' : 'notice-error';
			$direct_print_message = isset($direct_print_result['message']) ? (string) $direct_print_result['message'] : '';
			echo '<div class="notice ' . esc_attr($direct_print_notice_class) . '"><p>' . esc_html($direct_print_message !== '' ? $direct_print_message : 'DirectPrint request fullført.') . '</p></div>';
		}

		// Test autentisering + hent fraktmetoder
		if (
			isset($_POST['lp_cargonizer_fetch_methods'])
			&& isset($_POST['_wpnonce'])
			&& wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::NONCE_ACTION_FETCH)
		) {
			$method_fetch_settings = $settings;
			if (isset($_POST['lp_cargonizer_api_key'])) {
				$method_fetch_settings['api_key'] = sanitize_text_field(wp_unslash($_POST['lp_cargonizer_api_key']));
			}
			if (isset($_POST['lp_cargonizer_sender_id'])) {
				$method_fetch_settings['sender_id'] = sanitize_text_field(wp_unslash($_POST['lp_cargonizer_sender_id']));
			}
			if (isset($_POST['lp_cargonizer_warehouse_profiles']) && is_array($_POST['lp_cargonizer_warehouse_profiles'])) {
				$method_fetch_settings['warehouse_profiles'] = wp_unslash($_POST['lp_cargonizer_warehouse_profiles']);
			}
			$result = $this->fetch_transport_agreements_for_sender_profiles($method_fetch_settings);
			if (!empty($result['results']) && is_array($result['results'])) {
				$settings['transport_agreement_fetch_results'] = $result['results'];
			}
			if (!empty($result['success'])) {
				$settings['api_key'] = isset($method_fetch_settings['api_key']) ? $method_fetch_settings['api_key'] : (isset($settings['api_key']) ? $settings['api_key'] : '');
				$settings['sender_id'] = isset($method_fetch_settings['sender_id']) ? $method_fetch_settings['sender_id'] : (isset($settings['sender_id']) ? $settings['sender_id'] : '');
				$settings['available_methods'] = $this->merge_fetched_methods_with_existing_sender_methods(
					isset($settings['available_methods']) && is_array($settings['available_methods']) ? $settings['available_methods'] : array(),
					isset($result['methods']) && is_array($result['methods']) ? $result['methods'] : array(),
					isset($result['results']) && is_array($result['results']) ? $result['results'] : array()
				);
				$settings['enabled_methods'] = isset($settings['enabled_methods']) && is_array($settings['enabled_methods']) ? $settings['enabled_methods'] : array();
				if (isset($method_fetch_settings['warehouse_profiles']) && is_array($method_fetch_settings['warehouse_profiles'])) {
					$settings['warehouse_profiles'] = $method_fetch_settings['warehouse_profiles'];
				}
				update_option(self::OPTION_KEY, $this->sanitize_settings($settings));
				$settings = $this->get_settings();
				$result['message'] = (isset($result['message']) ? (string) $result['message'] : 'Fraktmetoder ble hentet.') . ' Metodene ble lagret i innstillingene.';
			} elseif (!empty($result['results']) && is_array($result['results'])) {
				update_option(self::OPTION_KEY, $this->sanitize_settings($settings));
				$settings = $this->get_settings();
			}
		}



		if (
			isset($_POST['lp_cargonizer_refresh_method_choices'])
			&& isset($_POST['_wpnonce'])
			&& (
				wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::NONCE_ACTION_SAVE)
				|| wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::NONCE_ACTION_FETCH)
			)
		) {
			$method_fetch_settings = $settings;
			if (isset($_POST['lp_cargonizer_warehouse_profiles']) && is_array($_POST['lp_cargonizer_warehouse_profiles'])) {
				$method_fetch_settings['warehouse_profiles'] = wp_unslash($_POST['lp_cargonizer_warehouse_profiles']);
			}
			$method_refresh = $this->fetch_transport_agreements_for_sender_profiles($method_fetch_settings);
			if ($method_refresh['success']) {
				$posted_enabled_methods = isset($_POST['lp_cargonizer_enabled_methods']) && is_array($_POST['lp_cargonizer_enabled_methods'])
					? array_map('sanitize_text_field', wp_unslash($_POST['lp_cargonizer_enabled_methods']))
					: null;

				$settings['api_key'] = isset($method_fetch_settings['api_key']) ? $method_fetch_settings['api_key'] : (isset($settings['api_key']) ? $settings['api_key'] : '');
				$settings['sender_id'] = isset($method_fetch_settings['sender_id']) ? $method_fetch_settings['sender_id'] : (isset($settings['sender_id']) ? $settings['sender_id'] : '');
				if (isset($method_fetch_settings['warehouse_profiles']) && is_array($method_fetch_settings['warehouse_profiles'])) {
					$settings['warehouse_profiles'] = $method_fetch_settings['warehouse_profiles'];
					if (!empty($settings['warehouse_profiles']['default_profile_id']) && !empty($settings['warehouse_profiles']['profiles']) && is_array($settings['warehouse_profiles']['profiles'])) {
						$posted_default_sender_profile_id = sanitize_key((string) $settings['warehouse_profiles']['default_profile_id']);
						foreach ($settings['warehouse_profiles']['profiles'] as $posted_sender_profile) {
							if (!is_array($posted_sender_profile)) {
								continue;
							}
							$posted_sender_profile_id = isset($posted_sender_profile['profile_id']) ? sanitize_key((string) $posted_sender_profile['profile_id']) : '';
							$posted_sender_id = isset($posted_sender_profile['sender_id']) ? sanitize_text_field((string) $posted_sender_profile['sender_id']) : '';
							if ($posted_sender_profile_id !== '' && $posted_sender_profile_id === $posted_default_sender_profile_id && $posted_sender_id !== '') {
								$settings['sender_id'] = $posted_sender_id;
								break;
							}
						}
					}
				}
				$settings['available_methods'] = $this->merge_fetched_methods_with_existing_sender_methods(
					isset($settings['available_methods']) && is_array($settings['available_methods']) ? $settings['available_methods'] : array(),
					isset($method_refresh['methods']) && is_array($method_refresh['methods']) ? $method_refresh['methods'] : array(),
					isset($method_refresh['results']) && is_array($method_refresh['results']) ? $method_refresh['results'] : array()
				);
				$settings['transport_agreement_fetch_results'] = isset($method_refresh['results']) && is_array($method_refresh['results']) ? $method_refresh['results'] : array();
				$settings['enabled_methods'] = is_array($posted_enabled_methods)
					? $posted_enabled_methods
					: (isset($settings['enabled_methods']) && is_array($settings['enabled_methods']) ? $settings['enabled_methods'] : array());
				update_option(self::OPTION_KEY, $this->sanitize_settings($settings));
				$settings = $this->get_settings();
				$method_refresh_notice_class = !empty($method_refresh['partial_success']) ? 'notice-warning' : 'notice-success';
				echo '<div class="notice ' . esc_attr($method_refresh_notice_class) . '"><p>' . esc_html(isset($method_refresh['message']) ? $method_refresh['message'] : 'Fraktmetoder ble hentet.') . '</p></div>';
			} else {
				if (!empty($method_refresh['results']) && is_array($method_refresh['results'])) {
					$settings['transport_agreement_fetch_results'] = $method_refresh['results'];
					update_option(self::OPTION_KEY, $this->sanitize_settings($settings));
					$settings = $this->get_settings();
				}
				echo '<div class="notice notice-error"><p>' . esc_html($method_refresh['message']) . '</p></div>';
			}
		}

		if (
			isset($_POST['lp_cargonizer_run_servicepoint_diagnostic'])
			&& isset($_POST['_wpnonce'])
			&& wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), self::NONCE_ACTION_ADMIN_SERVICEPOINT_DIAGNOSTIC)
		) {
			if (!current_user_can('manage_woocommerce')) {
				echo '<div class="notice notice-error"><p>Mangler tilgang til å kjøre diagnostikk.</p></div>';
			} else {
				$available_methods = isset($settings['available_methods']) && is_array($settings['available_methods']) ? $settings['available_methods'] : array();
				$available_map = array();
				foreach ($available_methods as $available_method) {
					if (!is_array($available_method)) {
						continue;
					}
					$method_key = isset($available_method['key']) ? sanitize_text_field((string) $available_method['key']) : '';
					if ($method_key !== '') {
						$available_map[$method_key] = $available_method;
					}
				}
				$selected_method_key = isset($_POST['lp_cargonizer_diag_method_key']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_method_key'])) : '';
				$selected_method = isset($available_map[$selected_method_key]) ? $available_map[$selected_method_key] : null;

				if (!$selected_method) {
					echo '<div class="notice notice-error"><p>Velg en gyldig metode for diagnostikk.</p></div>';
				} else {
					$recipient = array(
						'name' => isset($_POST['lp_cargonizer_diag_recipient_name']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_recipient_name'])) : '',
						'address_1' => isset($_POST['lp_cargonizer_diag_address_1']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_address_1'])) : '',
						'postcode' => isset($_POST['lp_cargonizer_diag_postcode']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_postcode'])) : '',
						'city' => isset($_POST['lp_cargonizer_diag_city']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_city'])) : '',
						'country' => isset($_POST['lp_cargonizer_diag_country']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_country'])) : 'NO',
					);
					if ($recipient['country'] === '') {
						$recipient['country'] = 'NO';
					}
					$package = array(
						'weight' => isset($_POST['lp_cargonizer_diag_weight_kg']) ? (float) wp_unslash($_POST['lp_cargonizer_diag_weight_kg']) : 1.0,
						'length' => isset($_POST['lp_cargonizer_diag_length_cm']) ? (float) wp_unslash($_POST['lp_cargonizer_diag_length_cm']) : 10.0,
						'width' => isset($_POST['lp_cargonizer_diag_width_cm']) ? (float) wp_unslash($_POST['lp_cargonizer_diag_width_cm']) : 10.0,
						'height' => isset($_POST['lp_cargonizer_diag_height_cm']) ? (float) wp_unslash($_POST['lp_cargonizer_diag_height_cm']) : 10.0,
					);
					$packages = array($package);

					$manual_servicepartner = isset($_POST['lp_cargonizer_diag_manual_servicepartner']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_manual_servicepartner'])) : '';
					$manual_servicepartner_customer_number = isset($_POST['lp_cargonizer_diag_manual_servicepartner_customer_number']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_manual_servicepartner_customer_number'])) : '';
					$run_without_servicepartner_first = !empty($_POST['lp_cargonizer_diag_run_without_servicepartner_first']);
					$run_with_auto_selected_servicepartner = !empty($_POST['lp_cargonizer_diag_run_with_auto_selected_servicepartner']);

					$selected_method['servicepartner'] = '';
					$selected_method['servicepartner_customer_number'] = '';
					$pricing_map = isset($settings['method_pricing']) && is_array($settings['method_pricing']) ? $settings['method_pricing'] : array();
					$pricing_config = isset($pricing_map[$selected_method_key]) && is_array($pricing_map[$selected_method_key]) ? $pricing_map[$selected_method_key] : array();
					$selected_method['request_timeout_seconds'] = $this->get_admin_pickup_lookup_timeout_seconds($settings);

					$docs_expectation = $this->get_admin_servicepartner_docs_expectation($selected_method);
					$method_summary = array(
						'method_key' => $selected_method_key,
						'agreement_id' => isset($selected_method['agreement_id']) ? sanitize_text_field((string) $selected_method['agreement_id']) : '',
						'carrier_id' => isset($selected_method['carrier_id']) ? sanitize_text_field((string) $selected_method['carrier_id']) : '',
						'carrier_name' => isset($selected_method['carrier_name']) ? sanitize_text_field((string) $selected_method['carrier_name']) : '',
						'product_id' => isset($selected_method['product_id']) ? sanitize_text_field((string) $selected_method['product_id']) : '',
						'product_name' => isset($selected_method['product_name']) ? sanitize_text_field((string) $selected_method['product_name']) : '',
						'delivery_to_pickup_point' => !empty($selected_method['delivery_to_pickup_point']),
						'delivery_to_home' => !empty($selected_method['delivery_to_home']),
						'is_method_explicitly_pickup_point' => $this->is_method_explicitly_pickup_point($selected_method),
						'is_method_explicitly_home_delivery' => $this->is_method_explicitly_home_delivery($selected_method),
						'method_requires_servicepartner_for_estimate' => $this->method_requires_servicepartner_for_estimate($selected_method),
						'docs_expectation' => $docs_expectation,
					);

					$lookup_method = array_merge($selected_method, array(
						'country' => $recipient['country'],
						'postcode' => $recipient['postcode'],
						'city' => $recipient['city'],
						'address' => $recipient['address_1'],
						'name' => $recipient['name'],
					));
					$servicepoint_lookup = $this->fetch_servicepartner_options($lookup_method);
					$options = isset($servicepoint_lookup['options']) && is_array($servicepoint_lookup['options']) ? $servicepoint_lookup['options'] : array();
					$option_summaries = array();
					$option_count = 0;
					foreach ($options as $option) {
						if (!is_array($option)) {
							continue;
						}
						$option_count++;
						if (count($option_summaries) >= 10) {
							continue;
						}
						$option_summaries[] = array(
							'number' => isset($option['number']) ? sanitize_text_field((string) $option['number']) : '',
							'value' => isset($option['value']) ? sanitize_text_field((string) $option['value']) : '',
							'customer_number' => isset($option['customer_number']) ? sanitize_text_field((string) $option['customer_number']) : '',
							'name' => isset($option['name']) ? sanitize_text_field((string) $option['name']) : '',
							'address1' => isset($option['address1']) ? sanitize_text_field((string) $option['address1']) : '',
							'postcode' => isset($option['postcode']) ? sanitize_text_field((string) $option['postcode']) : '',
							'city' => isset($option['city']) ? sanitize_text_field((string) $option['city']) : '',
							'country' => isset($option['country']) ? sanitize_text_field((string) $option['country']) : '',
							'distance_meters' => isset($option['distance_meters']) ? sanitize_text_field((string) $option['distance_meters']) : '',
						);
					}

					$auto_selection = $this->resolve_default_servicepartner_selection($lookup_method, $recipient);
					$auto_servicepartner = isset($auto_selection['servicepartner']) ? sanitize_text_field((string) $auto_selection['servicepartner']) : '';
					$auto_customer_number = isset($auto_selection['servicepartner_customer_number']) ? sanitize_text_field((string) $auto_selection['servicepartner_customer_number']) : '';

					$estimate_without = null;
					if ($run_without_servicepartner_first) {
						$without_method_payload = $selected_method;
						$without_method_payload['servicepartner'] = '';
						$without_method_payload['servicepartner_customer_number'] = '';
						$estimate_without = $this->run_admin_estimate_diagnostic($packages, $recipient, $without_method_payload, $pricing_config);
					}

					$estimate_auto = null;
					if ($run_with_auto_selected_servicepartner && $auto_servicepartner !== '') {
						$auto_method_payload = $selected_method;
						$auto_method_payload['servicepartner'] = $auto_servicepartner;
						$auto_method_payload['servicepartner_customer_number'] = $auto_customer_number;
						$auto_method_payload['servicepartner_selected_option'] = isset($auto_selection['selected_option']) && is_array($auto_selection['selected_option']) ? $auto_selection['selected_option'] : array();
						$estimate_auto = $this->run_admin_estimate_diagnostic($packages, $recipient, $auto_method_payload, $pricing_config);
					}

					$estimate_manual = null;
					if ($manual_servicepartner !== '') {
						$manual_method_payload = $selected_method;
						$manual_method_payload['servicepartner'] = $manual_servicepartner;
						$manual_method_payload['servicepartner_customer_number'] = $manual_servicepartner_customer_number;
						$estimate_manual = $this->run_admin_estimate_diagnostic($packages, $recipient, $manual_method_payload, $pricing_config);
					}

					$conclusion = 'No deterministic conclusion matched from current diagnostic output.';
					$docs_require = !empty($docs_expectation['docs_require_servicepartner']);
					$plugin_classification_requires = !empty($method_summary['method_requires_servicepartner_for_estimate']);
					$lookup_success = !empty($servicepoint_lookup['success']) && $option_count > 0;
					$without_failed = is_array($estimate_without) && (isset($estimate_without['status']) ? (string) $estimate_without['status'] : '') !== 'ok';
					$auto_ok = is_array($estimate_auto) && (isset($estimate_auto['status']) ? (string) $estimate_auto['status'] : '') === 'ok';
					$manual_ok = is_array($estimate_manual) && (isset($estimate_manual['status']) ? (string) $estimate_manual['status'] : '') === 'ok';
					$servicepartner_success = $auto_ok || $manual_ok;
					$servicepartner_failed = (is_array($estimate_auto) || is_array($estimate_manual)) && !$servicepartner_success;
					$without_ok = is_array($estimate_without) && (isset($estimate_without['status']) ? (string) $estimate_without['status'] : '') === 'ok';

					if (!$lookup_success) {
						$conclusion = 'Conclusion: service point lookup failed before pricing.';
					} elseif ($without_failed && $servicepartner_success) {
						$conclusion = 'Conclusion: method requires servicepartner before quote estimation.';
					} elseif ($docs_require && !$plugin_classification_requires) {
						$conclusion = 'Conclusion: plugin classification is not aligned with Logistra docs for this method.';
					} elseif ($lookup_success && $servicepartner_failed) {
						$conclusion = 'Conclusion: lookup works, but estimate payload or product handling is still wrong.';
					} elseif ($without_ok && ($estimate_auto === null || $auto_ok) && ($estimate_manual === null || $manual_ok)) {
						$conclusion = 'Conclusion: servicepartner is not the blocking issue for this method/destination/package combination.';
					}

					$admin_servicepoint_diag_result = array(
						'input' => array(
							'recipient' => $recipient,
							'package' => $package,
							'manual_servicepartner' => $manual_servicepartner,
							'manual_servicepartner_customer_number' => $manual_servicepartner_customer_number,
							'run_without_servicepartner_first' => $run_without_servicepartner_first,
							'run_with_auto_selected_servicepartner' => $run_with_auto_selected_servicepartner,
						),
						'method_summary' => $method_summary,
						'servicepoint_lookup' => array(
							'success' => !empty($servicepoint_lookup['success']),
							'error_message' => isset($servicepoint_lookup['error_message']) ? (string) $servicepoint_lookup['error_message'] : '',
							'http_status' => isset($servicepoint_lookup['http_status']) ? (int) $servicepoint_lookup['http_status'] : 0,
							'request_url' => isset($servicepoint_lookup['request_url']) ? (string) $servicepoint_lookup['request_url'] : '',
							'winning_attempt_label' => isset($servicepoint_lookup['winning_attempt_label']) ? (string) $servicepoint_lookup['winning_attempt_label'] : '',
							'carrier_family' => isset($servicepoint_lookup['carrier_family']) ? (string) $servicepoint_lookup['carrier_family'] : '',
							'omitted_params' => isset($servicepoint_lookup['omitted_params']) ? $servicepoint_lookup['omitted_params'] : array(),
							'custom_params_debug' => isset($servicepoint_lookup['custom_params_debug']) ? $servicepoint_lookup['custom_params_debug'] : array(),
							'parser_debug' => isset($servicepoint_lookup['parser_debug']) ? $servicepoint_lookup['parser_debug'] : array(),
							'attempts' => isset($servicepoint_lookup['attempts']) ? $servicepoint_lookup['attempts'] : array(),
							'option_count' => $option_count,
							'first_options' => $option_summaries,
						),
						'auto_selection' => array(
							'servicepartner' => $auto_servicepartner,
							'servicepartner_customer_number' => $auto_customer_number,
							'servicepartner_selection_source' => isset($auto_selection['servicepartner_selection_source']) ? (string) $auto_selection['servicepartner_selection_source'] : '',
							'servicepartner_auto_selected' => !empty($auto_selection['servicepartner_auto_selected']),
							'auto_selection_reason' => isset($auto_selection['auto_selection_reason']) ? (string) $auto_selection['auto_selection_reason'] : '',
							'selected_option_summary' => isset($auto_selection['selected_option']) && is_array($auto_selection['selected_option']) ? $auto_selection['selected_option'] : array(),
						),
						'estimate_without_servicepartner' => $estimate_without,
						'estimate_with_auto_selected_servicepartner' => $estimate_auto,
						'estimate_with_manual_override' => $estimate_manual,
						'conclusion' => $conclusion,
					);
				}
			}
		}
		?>
		<div class="wrap lp-cargonizer-admin-page">
			<h1>Cargonizer for WooCommerce</h1>

			<style>
				.lp-cargonizer-admin-page {
					max-width: 1320px;
				}
				.lp-cargonizer-admin-page h1 {
					margin-bottom: 6px;
				}
				.lp-cargonizer-admin-page h2 {
					margin-top: 0;
					padding-bottom: 8px;
					border-bottom: 1px solid #dcdcde;
				}
				.lp-cargonizer-admin-page .form-table th {
					width: 230px;
				}
				.lp-cargonizer-admin-page .form-table td {
					max-width: 980px;
				}
				.lp-cargonizer-settings-shell,
				.lp-cargonizer-admin-card {
					background: #fff;
					border: 1px solid #dcdcde;
					border-radius: 6px;
					padding: 18px 20px;
					margin: 18px 0;
					box-shadow: 0 1px 1px rgba(0, 0, 0, 0.03);
				}
				.lp-cargonizer-settings-nav {
					position: sticky;
					top: 32px;
					z-index: 2;
					display: flex;
					gap: 8px;
					flex-wrap: wrap;
					align-items: center;
					padding: 10px;
					margin: 0 0 18px 0;
					border: 1px solid #c3c4c7;
					border-radius: 6px;
					background: #f6f7f7;
				}
				.lp-cargonizer-settings-nav strong {
					margin-right: 4px;
				}
				.lp-cargonizer-settings-nav a {
					display: inline-flex;
					align-items: center;
					min-height: 28px;
					padding: 0 9px;
					border-radius: 4px;
					text-decoration: none;
					background: #fff;
					border: 1px solid #dcdcde;
				}
				.lp-cargonizer-settings-nav a:hover,
				.lp-cargonizer-settings-nav a:focus {
					border-color: #2271b1;
					box-shadow: 0 0 0 1px #2271b1;
				}
				.lp-cargonizer-settings-section {
					padding: 16px 0 18px;
					border-top: 1px solid #f0f0f1;
				}
				.lp-cargonizer-settings-section:first-of-type {
					padding-top: 0;
					border-top: 0;
				}
				.lp-cargonizer-methods-groups {
					max-height: none !important;
				}
				.lp-cargonizer-method-row {
					border-top-color: #dcdcde !important;
				}
				.lp-cargonizer-method-row > div:last-child {
					min-width: 0 !important;
					width: 100%;
					overflow-x: auto;
					padding-bottom: 4px;
				}
				.lp-cargonizer-save-strip {
					position: sticky;
					bottom: 0;
					z-index: 3;
					display: flex;
					gap: 8px;
					flex-wrap: wrap;
					justify-content: flex-end;
					align-items: center;
					padding: 12px 0 0;
					margin-top: 14px;
					border-top: 1px solid #dcdcde;
					background: #fff;
				}
				@media (max-width: 960px) {
					.lp-cargonizer-settings-nav {
						position: static;
					}
					.lp-cargonizer-admin-page .form-table th,
					.lp-cargonizer-admin-page .form-table td {
						display: block;
						width: 100%;
						max-width: 100%;
					}
				}
			</style>

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
				$summary_lines[] = 'Ordre under ' . $format_summary_price($threshold_summary) . ' kr (' . $threshold_basis_label . ') viser billigste godkjente metode til ' . $format_summary_price($low_price_summary) . ' kr.';
			}
			if ((string) (isset($live_checkout_summary['free_shipping_strategy']) ? $live_checkout_summary['free_shipping_strategy'] : '') === 'cheapest_standard_eligible') {
				$summary_lines[] = 'Ordre over ' . $format_summary_price($threshold_summary) . ' kr (' . $threshold_basis_label . ') gjør billigste godkjente standardmetode gratis.';
			}
			$summary_lines[] = 'Nærmeste hentested velges automatisk når metoden støtter hentepunkt.';
			$summary_lines[] = (isset($live_checkout_summary['quote_timing_mode']) && (string) $live_checkout_summary['quote_timing_mode'] === 'checkout_only')
				? 'Live prisberegning kjøres først i checkout.'
				: 'Live prisberegning kjøres i både handlekurv og checkout.';
			?>
			<div style="background:#f0f6fc;border:1px solid #c5d9ed;padding:14px 16px;margin:16px 0 20px 0;max-width:1100px;">
				<h2 style="margin-top:0;margin-bottom:8px;">Kort oppsummering av aktiv oppførsel</h2>
				<ul style="margin:0;padding-left:18px;">
					<?php foreach ($summary_lines as $summary_line) : ?>
						<li><?php echo esc_html($summary_line); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php
			$last_no_rates_status = $this->get_live_checkout_last_no_rates_status();
			$status_reason_group = isset($last_no_rates_status['reason_group']) ? (string) $last_no_rates_status['reason_group'] : '';
			$status_reason_code = isset($last_no_rates_status['reason_code']) ? (string) $last_no_rates_status['reason_code'] : '';
			$status_message = isset($last_no_rates_status['message']) ? (string) $last_no_rates_status['message'] : '';
			$status_occurred_at = isset($last_no_rates_status['occurred_at_gmt']) ? (string) $last_no_rates_status['occurred_at_gmt'] : '';
			$status_context = isset($last_no_rates_status['context']) && is_array($last_no_rates_status['context']) ? $last_no_rates_status['context'] : array();
			?>
			<div style="background:#fff8e5;border:1px solid #f0c36d;padding:14px 16px;margin:16px 0 20px 0;max-width:1100px;">
				<h2 style="margin-top:0;margin-bottom:8px;">Live checkout – siste «ingen fraktmetoder»-årsak</h2>
				<?php if (empty($last_no_rates_status)) : ?>
					<p style="margin:0;">Ingen registrert no-rates-hendelse enda.</p>
				<?php else : ?>
					<ul style="margin:0;padding-left:18px;">
						<li><strong>Kategori:</strong> <?php echo esc_html($this->get_no_rates_reason_group_label($status_reason_group)); ?></li>
						<li><strong>Årsakskode:</strong> <?php echo esc_html($this->get_no_rates_reason_code_label($status_reason_code)); ?> (<code><?php echo esc_html($status_reason_code); ?></code>)</li>
						<?php if ($status_message !== '') : ?>
							<li><strong>Melding:</strong> <?php echo esc_html($status_message); ?></li>
						<?php endif; ?>
						<?php if ($status_occurred_at !== '') : ?>
							<li><strong>Tid (GMT):</strong> <?php echo esc_html($status_occurred_at); ?></li>
						<?php endif; ?>
						<?php if (isset($status_context['fallback']['mode'])) : ?>
							<li><strong>Fallback-modus:</strong> <?php echo esc_html((string) $status_context['fallback']['mode']); ?><?php echo isset($status_context['fallback']['added_count']) ? esc_html(' (rater lagt til: ' . (int) $status_context['fallback']['added_count'] . ')') : ''; ?></li>
						<?php endif; ?>
					</ul>
						<details style="margin-top:8px;">
							<summary>Kort diagnostikk (JSON)</summary>
							<pre style="white-space:pre-wrap;background:#f6f7f7;padding:12px;border:1px solid #ddd;max-height:220px;overflow:auto;"><?php echo esc_html(wp_json_encode($status_context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
						</details>
						<?php $quote_debug_payload = $this->get_live_checkout_quote_debug_payload($status_context); ?>
						<details style="margin-top:8px;">
							<summary>Debugverktøy: quote replay-snapshot</summary>
							<p style="margin-top:8px;">Denne payloaden kan kopieres for feilsøking når checkout viser «ingen fraktmetoder».</p>
							<pre style="white-space:pre-wrap;background:#f6f7f7;padding:12px;border:1px solid #ddd;max-height:220px;overflow:auto;"><?php echo esc_html(wp_json_encode($quote_debug_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
						</details>
					<?php endif; ?>
				</div>
			<?php $setup_warnings = $this->get_live_checkout_setup_warnings($settings, $last_no_rates_status); ?>
			<?php if (!empty($setup_warnings)) : ?>
				<div style="background:#fff1f0;border:1px solid #f1aeb5;padding:14px 16px;margin:16px 0 20px 0;max-width:1100px;">
					<h2 style="margin-top:0;margin-bottom:8px;">Sjekk oppsettet ditt</h2>
					<ul style="margin:0;padding-left:18px;">
						<?php foreach ($setup_warnings as $warning) : ?>
							<li><?php echo esc_html($warning); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

			<?php
			$diag_selected_method_key = isset($_POST['lp_cargonizer_diag_method_key']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_method_key'])) : '';
			$diag_recipient_name = isset($_POST['lp_cargonizer_diag_recipient_name']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_recipient_name'])) : 'Diagnostic recipient';
			$diag_address_1 = isset($_POST['lp_cargonizer_diag_address_1']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_address_1'])) : '';
			$diag_postcode = isset($_POST['lp_cargonizer_diag_postcode']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_postcode'])) : '';
			$diag_city = isset($_POST['lp_cargonizer_diag_city']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_city'])) : '';
			$diag_country = isset($_POST['lp_cargonizer_diag_country']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_country'])) : 'NO';
			$diag_weight_kg = isset($_POST['lp_cargonizer_diag_weight_kg']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_weight_kg'])) : '1';
			$diag_length_cm = isset($_POST['lp_cargonizer_diag_length_cm']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_length_cm'])) : '10';
			$diag_width_cm = isset($_POST['lp_cargonizer_diag_width_cm']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_width_cm'])) : '10';
			$diag_height_cm = isset($_POST['lp_cargonizer_diag_height_cm']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_height_cm'])) : '10';
			$diag_manual_servicepartner = isset($_POST['lp_cargonizer_diag_manual_servicepartner']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_manual_servicepartner'])) : '';
			$diag_manual_customer_number = isset($_POST['lp_cargonizer_diag_manual_servicepartner_customer_number']) ? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_diag_manual_servicepartner_customer_number'])) : '';
			$diag_run_without = !isset($_POST['lp_cargonizer_run_servicepoint_diagnostic']) || !empty($_POST['lp_cargonizer_diag_run_without_servicepartner_first']);
			$diag_run_with_auto = !isset($_POST['lp_cargonizer_run_servicepoint_diagnostic']) || !empty($_POST['lp_cargonizer_diag_run_with_auto_selected_servicepartner']);
			?>
			<div style="background:#fff;border:1px solid #ddd;padding:20px;margin:20px 0;max-width:1100px;">
				<h2>Pickup/service point diagnostics</h2>
				<p class="description">Admin-only testverktøy for å sammenligne servicepunkt-oppslag og estimate med/uten servicepartner. Påvirker ikke checkout-runtime.</p>
				<form method="post">
					<?php wp_nonce_field(self::NONCE_ACTION_ADMIN_SERVICEPOINT_DIAGNOSTIC); ?>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="lp_cargonizer_diag_method_key">Method</label></th>
								<td>
									<select name="lp_cargonizer_diag_method_key" id="lp_cargonizer_diag_method_key" required>
										<option value="">Velg metode</option>
										<?php foreach ($settings['available_methods'] as $method_option) : ?>
											<?php
											$method_key = isset($method_option['key']) ? sanitize_text_field((string) $method_option['key']) : '';
											if ($method_key === '') { continue; }
											$method_label = isset($method_option['label']) ? sanitize_text_field((string) $method_option['label']) : '';
											$fallback_label = trim(
												(isset($method_option['agreement_id']) ? sanitize_text_field((string) $method_option['agreement_id']) : '—')
												. ' / '
												. (isset($method_option['product_id']) ? sanitize_text_field((string) $method_option['product_id']) : '—')
												. ' / '
												. (isset($method_option['carrier_id']) ? sanitize_text_field((string) $method_option['carrier_id']) : '—')
											);
											?>
											<option value="<?php echo esc_attr($method_key); ?>" <?php selected($diag_selected_method_key, $method_key); ?>>
												<?php echo esc_html($method_label !== '' ? $method_label : $fallback_label); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
							<tr><th scope="row">Recipient</th><td>
								<input type="text" name="lp_cargonizer_diag_recipient_name" value="<?php echo esc_attr($diag_recipient_name); ?>" placeholder="Name" class="regular-text" />
								<input type="text" name="lp_cargonizer_diag_address_1" value="<?php echo esc_attr($diag_address_1); ?>" placeholder="Address 1" class="regular-text" />
								<input type="text" name="lp_cargonizer_diag_postcode" value="<?php echo esc_attr($diag_postcode); ?>" placeholder="Postcode" style="max-width:120px;" />
								<input type="text" name="lp_cargonizer_diag_city" value="<?php echo esc_attr($diag_city); ?>" placeholder="City" class="regular-text" />
								<input type="text" name="lp_cargonizer_diag_country" value="<?php echo esc_attr($diag_country); ?>" placeholder="Country" style="max-width:100px;" />
							</td></tr>
							<tr><th scope="row">Package</th><td>
								<input type="number" step="0.01" min="0" name="lp_cargonizer_diag_weight_kg" value="<?php echo esc_attr($diag_weight_kg); ?>" placeholder="Weight kg" style="max-width:120px;" />
								<input type="number" step="0.01" min="0" name="lp_cargonizer_diag_length_cm" value="<?php echo esc_attr($diag_length_cm); ?>" placeholder="Length cm" style="max-width:120px;" />
								<input type="number" step="0.01" min="0" name="lp_cargonizer_diag_width_cm" value="<?php echo esc_attr($diag_width_cm); ?>" placeholder="Width cm" style="max-width:120px;" />
								<input type="number" step="0.01" min="0" name="lp_cargonizer_diag_height_cm" value="<?php echo esc_attr($diag_height_cm); ?>" placeholder="Height cm" style="max-width:120px;" />
							</td></tr>
							<tr><th scope="row">Manual override</th><td>
								<input type="text" name="lp_cargonizer_diag_manual_servicepartner" value="<?php echo esc_attr($diag_manual_servicepartner); ?>" placeholder="servicepartner number" class="regular-text" />
								<input type="text" name="lp_cargonizer_diag_manual_servicepartner_customer_number" value="<?php echo esc_attr($diag_manual_customer_number); ?>" placeholder="servicepartner customer number" class="regular-text" />
							</td></tr>
							<tr><th scope="row">Estimate runs</th><td>
								<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="lp_cargonizer_diag_run_without_servicepartner_first" value="1" <?php checked($diag_run_without); ?>> run_without_servicepartner_first</label>
								<label style="display:block;"><input type="checkbox" name="lp_cargonizer_diag_run_with_auto_selected_servicepartner" value="1" <?php checked($diag_run_with_auto); ?>> run_with_auto_selected_servicepartner</label>
							</td></tr>
						</tbody>
					</table>
					<p><button type="submit" name="lp_cargonizer_run_servicepoint_diagnostic" class="button button-secondary">Run pickup/service point diagnostics</button></p>
				</form>
				<?php if (is_array($admin_servicepoint_diag_result)) : ?>
					<h3>Input summary</h3>
					<pre style="white-space:pre-wrap;background:#f6f7f7;padding:12px;border:1px solid #ddd;"><?php echo esc_html(wp_json_encode($admin_servicepoint_diag_result['input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>

					<h3>Method classification vs docs expectation</h3>
					<pre style="white-space:pre-wrap;background:#f6f7f7;padding:12px;border:1px solid #ddd;"><?php echo esc_html(wp_json_encode($admin_servicepoint_diag_result['method_summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>

					<h3>Service-point lookup</h3>
					<table class="widefat striped" style="margin-bottom:10px;">
						<tbody>
							<tr><th>success</th><td><?php echo !empty($admin_servicepoint_diag_result['servicepoint_lookup']['success']) ? 'true' : 'false'; ?></td></tr>
							<tr><th>error_message</th><td><?php echo esc_html((string) $admin_servicepoint_diag_result['servicepoint_lookup']['error_message']); ?></td></tr>
							<tr><th>http_status</th><td><?php echo esc_html((string) $admin_servicepoint_diag_result['servicepoint_lookup']['http_status']); ?></td></tr>
							<tr><th>request_url</th><td><code><?php echo esc_html((string) $admin_servicepoint_diag_result['servicepoint_lookup']['request_url']); ?></code></td></tr>
							<tr><th>winning_attempt_label</th><td><?php echo esc_html((string) $admin_servicepoint_diag_result['servicepoint_lookup']['winning_attempt_label']); ?></td></tr>
							<tr><th>carrier_family</th><td><?php echo esc_html((string) $admin_servicepoint_diag_result['servicepoint_lookup']['carrier_family']); ?></td></tr>
							<tr><th>option count</th><td><?php echo esc_html((string) $admin_servicepoint_diag_result['servicepoint_lookup']['option_count']); ?></td></tr>
						</tbody>
					</table>
					<?php if (!empty($admin_servicepoint_diag_result['servicepoint_lookup']['first_options'])) : ?>
							<table class="widefat striped" style="margin-bottom:10px;">
								<thead><tr><th>number/value</th><th>customer_number</th><th>name</th><th>address1</th><th>postcode</th><th>city</th><th>country</th><th>distance_meters</th></tr></thead>
							<tbody>
								<?php foreach ($admin_servicepoint_diag_result['servicepoint_lookup']['first_options'] as $opt) : ?>
									<tr>
										<td><?php echo esc_html((string) ($opt['number'] !== '' ? $opt['number'] : $opt['value'])); ?></td>
										<td><?php echo esc_html((string) $opt['customer_number']); ?></td>
										<td><?php echo esc_html((string) $opt['name']); ?></td>
										<td><?php echo esc_html((string) $opt['address1']); ?></td>
										<td><?php echo esc_html((string) $opt['postcode']); ?></td>
										<td><?php echo esc_html((string) $opt['city']); ?></td>
										<td><?php echo esc_html((string) $opt['country']); ?></td>
										<td><?php echo esc_html((string) $opt['distance_meters']); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
					<details><summary>attempts</summary><pre style="white-space:pre-wrap;background:#f6f7f7;padding:12px;border:1px solid #ddd;"><?php echo esc_html(wp_json_encode($admin_servicepoint_diag_result['servicepoint_lookup']['attempts'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details>
					<details><summary>parser_debug</summary><pre style="white-space:pre-wrap;background:#f6f7f7;padding:12px;border:1px solid #ddd;"><?php echo esc_html(wp_json_encode($admin_servicepoint_diag_result['servicepoint_lookup']['parser_debug'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details>
					<details><summary>custom_params_debug</summary><pre style="white-space:pre-wrap;background:#f6f7f7;padding:12px;border:1px solid #ddd;"><?php echo esc_html(wp_json_encode($admin_servicepoint_diag_result['servicepoint_lookup']['custom_params_debug'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details>

					<h3>Auto-selection</h3>
					<pre style="white-space:pre-wrap;background:#f6f7f7;padding:12px;border:1px solid #ddd;"><?php echo esc_html(wp_json_encode($admin_servicepoint_diag_result['auto_selection'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>

					<?php $diag_estimate_sections = array(
						'Estimate without servicepartner' => $admin_servicepoint_diag_result['estimate_without_servicepartner'],
						'Estimate with auto-selected servicepartner' => $admin_servicepoint_diag_result['estimate_with_auto_selected_servicepartner'],
						'Estimate with manual override' => $admin_servicepoint_diag_result['estimate_with_manual_override'],
					); ?>
					<?php foreach ($diag_estimate_sections as $section_title => $section_payload) : ?>
						<?php if (!is_array($section_payload)) { continue; } ?>
						<h3><?php echo esc_html($section_title); ?></h3>
						<table class="widefat striped" style="margin-bottom:10px;">
							<tbody>
								<tr><th>status</th><td><?php echo esc_html((string) $section_payload['status']); ?></td></tr>
								<tr><th>http_status</th><td><?php echo esc_html((string) $section_payload['http_status']); ?></td></tr>
								<tr><th>parsed_error_message</th><td><?php echo esc_html((string) $section_payload['parsed_error_message']); ?></td></tr>
								<tr><th>gross_amount</th><td><?php echo esc_html((string) $section_payload['gross_amount']); ?></td></tr>
								<tr><th>net_amount</th><td><?php echo esc_html((string) $section_payload['net_amount']); ?></td></tr>
								<tr><th>estimated_cost</th><td><?php echo esc_html((string) $section_payload['estimated_cost']); ?></td></tr>
								<tr><th>selected_price_source</th><td><?php echo esc_html((string) $section_payload['selected_price_source']); ?></td></tr>
								<tr><th>selected_price_value</th><td><?php echo esc_html((string) $section_payload['selected_price_value']); ?></td></tr>
							</tbody>
						</table>
						<details><summary>XML preview</summary><pre style="white-space:pre-wrap;background:#f6f7f7;padding:12px;border:1px solid #ddd;"><?php echo esc_html((string) $section_payload['xml_preview']); ?></pre></details>
						<details><summary>raw response excerpt</summary><pre style="white-space:pre-wrap;background:#f6f7f7;padding:12px;border:1px solid #ddd;"><?php echo esc_html((string) $section_payload['raw_response_excerpt']); ?></pre></details>
					<?php endforeach; ?>

					<h3>Final conclusion</h3>
					<p><strong><?php echo esc_html((string) $admin_servicepoint_diag_result['conclusion']); ?></strong></p>
				<?php endif; ?>
			</div>

			<div class="lp-cargonizer-settings-shell">
				<h2 id="lp-cargonizer-section-connection">Tilkobling</h2>
				<p class="description">Legg inn API-nøkkel og sender-ID for å hente metoder og priser fra Cargonizer.</p>
				<form method="post">
					<?php wp_nonce_field(self::NONCE_ACTION_SAVE); ?>
					<nav class="lp-cargonizer-settings-nav" aria-label="Cargonizer innstillinger">
						<strong>Hopp til</strong>
						<a href="#lp-cargonizer-section-connection">Tilkobling</a>
						<a href="#lp-cargonizer-section-booking">Booking</a>
						<a href="#lp-cargonizer-section-posten-robot">Posten robot</a>
						<a href="#lp-cargonizer-section-printers">Printere</a>
						<a href="#lp-cargonizer-section-methods">Fraktmetoder</a>
						<a href="#lp-cargonizer-section-checkout">Checkout</a>
						<a href="#lp-cargonizer-section-advanced">Avansert</a>
					</nav>

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

					<h2>Senderadresser og fraktavtaler</h2>
					<p class="description">Senderadressene hentes fra <code>profile.xml</code> og lagres som valg for booking. ID-en under brukes som <code>X-Cargonizer-Sender</code> mot Cargonizer, og fraktavtaler hentes fra <code>transport_agreements.xml</code> per slik sender.</p>
					<?php
					$sender_addresses_fetch_result = array(
						'success' => false,
						'http_status' => 0,
						'message' => '',
						'raw' => '',
						'addresses' => array(),
					);
					if (!empty($settings['api_key'])) {
						$sender_addresses_fetch_result = $this->fetch_sender_addresses();
					}
					$sender_addresses = isset($sender_addresses_fetch_result['addresses']) && is_array($sender_addresses_fetch_result['addresses'])
						? $sender_addresses_fetch_result['addresses']
						: array();
					$default_sender_profile_id = $this->get_default_sender_profile_id($settings);
					$current_default_sender_profile = $this->resolve_selected_warehouse_profile($default_sender_profile_id);
					$current_default_sender_id = isset($current_default_sender_profile['sender_id']) ? sanitize_text_field((string) $current_default_sender_profile['sender_id']) : '';
					$sender_method_summary = $this->build_sender_transport_agreement_summary($settings);
					?>
					<?php if (!empty($sender_addresses_fetch_result['success']) && !empty($sender_addresses)) : ?>
						<p class="description">Velg standard senderadresse. Denne brukes som forhåndsvalg i Book shipment-popupen og som standard for checkout, men lageret kan bytte sender før estimering og booking.</p>
						<table class="widefat striped" style="max-width:1000px;">
							<thead>
								<tr>
									<th>Standard</th>
									<th>API Sender ID</th>
									<th>Navn</th>
									<th>Adresse</th>
									<th>Postnr/Sted</th>
									<th>Land</th>
									<th>Fraktavtaler</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($sender_addresses as $sender_address_index => $sender_address) : ?>
									<?php
									$sender_id = isset($sender_address['id']) ? sanitize_text_field((string) $sender_address['id']) : '';
									$sender_name = isset($sender_address['name']) ? sanitize_text_field((string) $sender_address['name']) : '';
									$sender_entity_id = isset($sender_address['sender_entity_id']) ? sanitize_text_field((string) $sender_address['sender_entity_id']) : '';
									$sender_address_1 = isset($sender_address['address1']) ? sanitize_text_field((string) $sender_address['address1']) : '';
									$sender_address_2 = isset($sender_address['address2']) ? sanitize_text_field((string) $sender_address['address2']) : '';
									$sender_postcode = isset($sender_address['postcode']) ? sanitize_text_field((string) $sender_address['postcode']) : '';
									$sender_city = isset($sender_address['city']) ? sanitize_text_field((string) $sender_address['city']) : '';
									$sender_country = isset($sender_address['country']) ? sanitize_text_field((string) $sender_address['country']) : '';
									$sender_address_line = trim($sender_address_1 . ($sender_address_2 !== '' ? ', ' . $sender_address_2 : ''));
									$sender_postal_line = trim($sender_postcode . ' ' . $sender_city);
									$sender_profile_id = $this->build_sender_profile_id($sender_id);
									$sender_profile_name = $sender_name !== '' ? $sender_name : ($sender_id !== '' ? 'Sender ' . $sender_id : 'Sender');
									$sender_summary = isset($sender_method_summary[$sender_profile_id]) ? $sender_method_summary[$sender_profile_id] : array();
									if (empty($sender_summary)) {
										foreach ($sender_method_summary as $sender_summary_candidate) {
											if (!is_array($sender_summary_candidate) || !empty($sender_summary_candidate['is_legacy_unscoped'])) {
												continue;
											}
											$candidate_sender_id = isset($sender_summary_candidate['sender_id']) ? sanitize_text_field((string) $sender_summary_candidate['sender_id']) : '';
											$candidate_sender_entity_id = isset($sender_summary_candidate['sender_entity_id']) ? sanitize_text_field((string) $sender_summary_candidate['sender_entity_id']) : '';
											if (
												($sender_id !== '' && ($candidate_sender_id === $sender_id || $candidate_sender_entity_id === $sender_id))
												|| ($sender_entity_id !== '' && ($candidate_sender_id === $sender_entity_id || $candidate_sender_entity_id === $sender_entity_id))
											) {
												$sender_summary = $sender_summary_candidate;
												break;
											}
										}
									}
									$sender_total_methods = isset($sender_summary['total_methods']) ? (int) $sender_summary['total_methods'] : 0;
									$sender_enabled_methods = isset($sender_summary['enabled_methods']) ? (int) $sender_summary['enabled_methods'] : 0;
									$sender_agreement_count = isset($sender_summary['agreement_count']) ? (int) $sender_summary['agreement_count'] : 0;
									$sender_fetch = isset($sender_summary['last_fetch']) && is_array($sender_summary['last_fetch']) ? $sender_summary['last_fetch'] : array();
									$sender_fetch_message = isset($sender_fetch['message']) ? trim((string) $sender_fetch['message']) : '';
									$sender_fetch_status = isset($sender_fetch['status']) ? (int) $sender_fetch['status'] : 0;
									$sender_fetch_agreement_count = isset($sender_fetch['agreement_count']) ? (int) $sender_fetch['agreement_count'] : 0;
									$sender_fetch_method_count = isset($sender_fetch['method_count']) ? (int) $sender_fetch['method_count'] : 0;
									$sender_fetch_time = isset($sender_fetch['fetched_at_gmt']) ? trim((string) $sender_fetch['fetched_at_gmt']) : '';
									$sender_fetch_effective_sender_id = isset($sender_fetch['effective_sender_id']) ? trim((string) $sender_fetch['effective_sender_id']) : '';
									?>
									<tr>
										<td>
											<?php if ($sender_id !== '') : ?>
												<label>
													<input type="radio" name="lp_cargonizer_warehouse_profiles[default_profile_id]" value="<?php echo esc_attr($sender_profile_id); ?>" <?php checked($default_sender_profile_id === $sender_profile_id || $current_default_sender_id === $sender_id || ($default_sender_profile_id === '' && (string) $settings['sender_id'] === $sender_id) || ($default_sender_profile_id === '' && empty($settings['sender_id']) && (int) $sender_address_index === 0)); ?>>
													<span class="screen-reader-text"><?php echo esc_html('Bruk ' . $sender_profile_name . ' som standard sender'); ?></span>
												</label>
												<input type="hidden" name="lp_cargonizer_warehouse_profiles[profiles][<?php echo esc_attr($sender_address_index); ?>][profile_id]" value="<?php echo esc_attr($sender_profile_id); ?>">
												<input type="hidden" name="lp_cargonizer_warehouse_profiles[profiles][<?php echo esc_attr($sender_address_index); ?>][name]" value="<?php echo esc_attr($sender_profile_name); ?>">
												<input type="hidden" name="lp_cargonizer_warehouse_profiles[profiles][<?php echo esc_attr($sender_address_index); ?>][sender_id]" value="<?php echo esc_attr($sender_id); ?>">
												<input type="hidden" name="lp_cargonizer_warehouse_profiles[profiles][<?php echo esc_attr($sender_address_index); ?>][sender_entity_id]" value="<?php echo esc_attr($sender_entity_id); ?>">
												<input type="hidden" name="lp_cargonizer_warehouse_profiles[profiles][<?php echo esc_attr($sender_address_index); ?>][company]" value="<?php echo esc_attr($sender_name); ?>">
												<input type="hidden" name="lp_cargonizer_warehouse_profiles[profiles][<?php echo esc_attr($sender_address_index); ?>][address1]" value="<?php echo esc_attr($sender_address_1); ?>">
												<input type="hidden" name="lp_cargonizer_warehouse_profiles[profiles][<?php echo esc_attr($sender_address_index); ?>][address2]" value="<?php echo esc_attr($sender_address_2); ?>">
												<input type="hidden" name="lp_cargonizer_warehouse_profiles[profiles][<?php echo esc_attr($sender_address_index); ?>][postcode]" value="<?php echo esc_attr($sender_postcode); ?>">
												<input type="hidden" name="lp_cargonizer_warehouse_profiles[profiles][<?php echo esc_attr($sender_address_index); ?>][city]" value="<?php echo esc_attr($sender_city); ?>">
												<input type="hidden" name="lp_cargonizer_warehouse_profiles[profiles][<?php echo esc_attr($sender_address_index); ?>][country]" value="<?php echo esc_attr($sender_country); ?>">
												<input type="hidden" name="lp_cargonizer_warehouse_profiles[profiles][<?php echo esc_attr($sender_address_index); ?>][active]" value="1">
											<?php else : ?>
												—
											<?php endif; ?>
										</td>
										<td>
											<?php echo esc_html($sender_id !== '' ? $sender_id : '—'); ?>
											<?php if ($sender_entity_id !== '' && $sender_entity_id !== $sender_id) : ?>
												<br><small><?php echo esc_html('Cargonizer sender: ' . $sender_entity_id); ?></small>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html($sender_name !== '' ? $sender_name : '—'); ?></td>
										<td><?php echo esc_html($sender_address_line !== '' ? $sender_address_line : '—'); ?></td>
										<td><?php echo esc_html($sender_postal_line !== '' ? $sender_postal_line : '—'); ?></td>
										<td><?php echo esc_html($sender_country !== '' ? $sender_country : '—'); ?></td>
										<td>
											<?php if ($sender_total_methods > 0) : ?>
												<strong><?php echo esc_html($sender_enabled_methods . '/' . $sender_total_methods . ' aktive produkter'); ?></strong><br>
												<small><?php echo esc_html($sender_agreement_count . ' fraktavtale(r) hentet'); ?></small><br>
												<?php if ($sender_fetch_time !== '') : ?>
													<small><?php echo esc_html('Sist hentet: ' . $sender_fetch_time . ' GMT'); ?></small><br>
												<?php endif; ?>
												<?php if ($sender_fetch_effective_sender_id !== '' && $sender_fetch_effective_sender_id !== $sender_id) : ?>
													<small><?php echo esc_html('API-kall brukt sender: ' . $sender_fetch_effective_sender_id); ?></small><br>
												<?php endif; ?>
												<a href="#lp-cargonizer-section-methods">Vis/velg fraktmetoder</a>
											<?php elseif (!empty($sender_fetch)) : ?>
												<?php if (!empty($sender_fetch['success'])) : ?>
													<?php if ($sender_fetch_method_count > 0) : ?>
														<span style="color:#8a4b00;">Hentet, men lagrede produkter matcher ikke sender-raden</span><br>
														<small><?php echo esc_html($sender_fetch_agreement_count . ' avtale(r), ' . $sender_fetch_method_count . ' produkt(er) fra Cargonizer' . ($sender_fetch_time !== '' ? ' - ' . $sender_fetch_time . ' GMT' : '') . '. Fjern lagrede fraktmetoder og hent på nytt dersom dette vedvarer.'); ?></small>
													<?php else : ?>
														<span style="color:#8a4b00;">Hentet, men ingen produkter funnet</span><br>
														<small><?php echo esc_html($sender_fetch_agreement_count . ' avtale(r), ' . $sender_fetch_method_count . ' produkt(er) fra Cargonizer' . ($sender_fetch_time !== '' ? ' - ' . $sender_fetch_time . ' GMT' : '')); ?></small>
													<?php endif; ?>
												<?php else : ?>
													<span style="color:#b32d2e;">Henting feilet<?php echo esc_html($sender_fetch_status > 0 ? ' (HTTP ' . $sender_fetch_status . ')' : ''); ?></span><br>
													<small><?php echo esc_html($sender_fetch_message !== '' ? $sender_fetch_message : 'Ukjent feil ved henting fra Cargonizer.'); ?></small>
												<?php endif; ?>
											<?php else : ?>
												<span style="color:#8a4b00;">Ikke hentet ennå</span><br>
												<small>Klikk hent/oppdater under for å laste avtaler for denne senderen.</small>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php elseif (empty($settings['api_key'])) : ?>
						<p class="description">Legg inn API key for å hente sender-adresser fra Cargonizer.</p>
					<?php else : ?>
						<p class="description" style="color:#b32d2e;"><?php echo esc_html($sender_addresses_fetch_result['message'] !== '' ? $sender_addresses_fetch_result['message'] : 'Kunne ikke hente sender-adresser.'); ?></p>
					<?php endif; ?>
					<?php if (!empty($settings['api_key']) && (!empty($sender_addresses) || !empty($settings['sender_id']))) : ?>
						<p style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
							<button type="submit" name="lp_cargonizer_refresh_method_choices" class="button button-secondary">
								Hent/oppdater fraktavtaler for alle senderadresser
							</button>
							<button type="submit" name="lp_cargonizer_clear_stored_methods" value="1" class="button button-secondary" onclick="return window.confirm('Dette fjerner alle lagrede Cargonizer-fraktmetoder, aktive metodevalg, prisoppsett, tilleggstjenestevalg og hentestatus. Fortsette?');">
								Fjern alle lagrede fraktmetoder
							</button>
							<a class="button button-secondary" href="#lp-cargonizer-section-methods">Vis fraktmetoder</a>
						</p>
						<p class="description">Hent-knappen laster transport agreements og produkter for alle senderadressene som vises over med hver rad sin <code>X-Cargonizer-Sender</code>. Fjern-knappen rydder lagrede produkter og metodevalg, men beholder API key, senderadresser, printere og bookingoppsett.</p>
					<?php endif; ?>

					<h2 id="lp-cargonizer-section-booking">Booking-standardvalg</h2>
					<?php
					$booking_estimator_top_count = isset($settings['booking_estimator_top_count']) ? max(3, min(5, absint($settings['booking_estimator_top_count']))) : 3;
					$booking_pickup_autoselect_mode = isset($settings['booking_pickup_autoselect_mode']) && in_array((string) $settings['booking_pickup_autoselect_mode'], array('nearest', 'none'), true)
						? (string) $settings['booking_pickup_autoselect_mode']
						: 'nearest';
					$booking_order_status_after_created = isset($settings['booking_order_status_after_created']) ? sanitize_key((string) $settings['booking_order_status_after_created']) : '';
					if (strpos($booking_order_status_after_created, 'wc-') === 0) {
						$booking_order_status_after_created = substr($booking_order_status_after_created, 3);
					}
					$order_status_options = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : array();
					?>
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
							<tr>
								<th scope="row">
									<label for="lp_cargonizer_booking_estimator_top_count">Estimator-anbefalinger</label>
								</th>
								<td>
									<select name="lp_cargonizer_booking_estimator_top_count" id="lp_cargonizer_booking_estimator_top_count">
										<option value="3" <?php selected($booking_estimator_top_count, 3); ?>>Vis topp 3</option>
										<option value="4" <?php selected($booking_estimator_top_count, 4); ?>>Vis topp 4</option>
										<option value="5" <?php selected($booking_estimator_top_count, 5); ?>>Vis topp 5</option>
									</select>
									<p class="description">Brukes i Book shipment-popupen for å vise de billigste anbefalte fraktvalgene over Fraktvalg.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lp_cargonizer_booking_pickup_autoselect_mode">Hentested ved booking</label>
								</th>
								<td>
									<select name="lp_cargonizer_booking_pickup_autoselect_mode" id="lp_cargonizer_booking_pickup_autoselect_mode">
										<option value="nearest" <?php selected($booking_pickup_autoselect_mode, 'nearest'); ?>>Velg nærmeste automatisk</option>
										<option value="none" <?php selected($booking_pickup_autoselect_mode, 'none'); ?>>Ikke forhåndsvelg</option>
									</select>
									<p class="description">Admin kan fortsatt overstyre hentested/servicepartner i booking-popupen før sendingen bookes.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">
									<label for="lp_cargonizer_booking_order_status_after_created">Ordrestatus etter booking</label>
								</th>
								<td>
									<select name="lp_cargonizer_booking_order_status_after_created" id="lp_cargonizer_booking_order_status_after_created">
										<option value="" <?php selected($booking_order_status_after_created, ''); ?>>Ikke endre status automatisk</option>
										<?php foreach ($order_status_options as $status_key => $status_label) : ?>
											<?php
											$clean_status_key = sanitize_key((string) $status_key);
											if (strpos($clean_status_key, 'wc-') === 0) {
												$clean_status_key = substr($clean_status_key, 3);
											}
											if ($clean_status_key === '') {
												continue;
											}
											?>
											<option value="<?php echo esc_attr($clean_status_key); ?>" <?php selected($booking_order_status_after_created, $clean_status_key); ?>>
												<?php echo esc_html($status_label); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<p class="description">Når Cargonizer-booking er opprettet, endres statusen automatisk, ordren lastes inn på nytt, og pluginen verifiserer at statusen faktisk ble lagret. Hvis verifisering feiler, planlegges automatisk retry uten admin-handling.</p>
								</td>
							</tr>
						</tbody>
					</table>

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
					$available_printers = $this->apply_printer_aliases($available_printers);
					$saved_printer_aliases = isset($settings['printer_aliases']) && is_array($settings['printer_aliases']) ? $settings['printer_aliases'] : array();
					?>

					<h2 id="lp-cargonizer-section-posten-robot">Posten Norgespakke robot</h2>
					<?php
					$posten_robot_settings = isset($settings['posten_robot']) && is_array($settings['posten_robot']) ? $settings['posten_robot'] : array();
					$posten_robot_token_exists = !empty($posten_robot_settings['token_hash']);
					$posten_robot_token_generated = isset($posten_robot_settings['token_generated_at_gmt']) ? sanitize_text_field((string) $posten_robot_settings['token_generated_at_gmt']) : '';
					?>
					<p class="description">For manuell Norgespakke blir Book shipment omdirigert til en lokal Posten-etikettkø. Den lokale Playwright-roboten henter jobber via REST API og laster sporing/etikett tilbake til ordren.</p>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">Robotko</th>
								<td>
									<input type="hidden" name="lp_cargonizer_posten_robot_enabled" value="0">
									<label>
										<input type="checkbox" name="lp_cargonizer_posten_robot_enabled" value="1" <?php checked(!empty($posten_robot_settings['enabled'])); ?>>
										Aktiver Posten-etikettkø for manuell Norgespakke
									</label>
									<p class="description">Paavirker bare <code>manual|norgespakke</code>. Vanlige Logistra/Cargonizer-metoder bruker fortsatt eksisterende booking, print og tracking.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">Auto-ko fra checkout</th>
								<td>
									<input type="hidden" name="lp_cargonizer_posten_robot_auto_queue" value="0">
									<label>
										<input type="checkbox" name="lp_cargonizer_posten_robot_auto_queue" value="1" <?php checked(!empty($posten_robot_settings['auto_queue_checkout_norgespakke'])); ?>>
										Opprett kojobb automatisk naar checkout-valget er manuell Norgespakke og betaling/status trigges
									</label>
									<p class="description">Standard er av. Admin-booking fra ordresiden kan alltid opprette kojobb naar robotkoen er aktiv.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">REST-token</th>
								<td>
									<p style="margin-top:0;">
										Status:
										<strong><?php echo $posten_robot_token_exists ? esc_html('Token er generert') : esc_html('Ingen token generert'); ?></strong>
										<?php if ($posten_robot_token_generated !== '') : ?>
											<br><small><?php echo esc_html('Sist generert (GMT): ' . $posten_robot_token_generated); ?></small>
										<?php endif; ?>
									</p>
									<button type="submit" name="lp_cargonizer_generate_posten_robot_token" value="1" class="button button-secondary">
										Generer ny robot-token
									</button>
									<p class="description">Token vises bare én gang etter generering. Roboten kan sende den som <code><?php echo esc_html(LP_Cargonizer_Posten_Label_Automation::TOKEN_HEADER); ?></code> eller Bearer-token.</p>
									<p class="description">REST-endepunkt: <code><?php echo esc_html(rest_url(LP_Cargonizer_Posten_Label_Automation::REST_NAMESPACE . LP_Cargonizer_Posten_Label_Automation::REST_ROUTE_PRIMARY)); ?></code></p>
								</td>
							</tr>
							<tr>
								<th scope="row">Ordrestatus</th>
								<td>
									<p style="margin-top:0;">Ved ko: <code>wc-<?php echo esc_html(LP_Cargonizer_Posten_Label_Automation::ORDER_STATUS_WAITING); ?></code></p>
									<p>Ved opplastet label: <code>wc-<?php echo esc_html(LP_Cargonizer_Posten_Label_Automation::ORDER_STATUS_CREATED); ?></code></p>
									<p class="description">Status settes og verifiseres av ko-tjenesten naar jobben opprettes og naar roboten fullfoerer jobben.</p>
								</td>
							</tr>
							<tr>
								<th scope="row">Posten DirectPrint</th>
								<td>
									<input type="hidden" name="lp_cargonizer_posten_robot_direct_print_enabled" value="0">
									<label>
										<input type="checkbox" name="lp_cargonizer_posten_robot_direct_print_enabled" value="1" <?php checked(!empty($posten_robot_settings['direct_print_enabled'])); ?>>
										Print Posten labels with DirectPrint
									</label>
									<p class="description">Roboten sender PDF-er tilbake til WooCommerce. WooCommerce lagrer originalen, lager stemplet kopi og sender stemplet PDF til DirectPrint når dette er aktivert.</p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_cargonizer_posten_robot_direct_print_printer_id">DirectPrint printer for Posten labels</label></th>
								<td>
									<?php $posten_direct_print_printer_id = isset($posten_robot_settings['direct_print_printer_id']) ? sanitize_text_field((string) $posten_robot_settings['direct_print_printer_id']) : ''; ?>
									<select name="lp_cargonizer_posten_robot_direct_print_printer_id" id="lp_cargonizer_posten_robot_direct_print_printer_id">
										<option value=""><?php echo esc_html('Ingen Posten DirectPrint-printer'); ?></option>
										<?php foreach ($available_printers as $printer) : ?>
											<?php
											$printer_id = isset($printer['id']) ? sanitize_text_field((string) $printer['id']) : '';
											if ($printer_id === '') {
												continue;
											}
											$printer_label = isset($printer['label']) ? sanitize_text_field((string) $printer['label']) : $printer_id;
											?>
											<option value="<?php echo esc_attr($printer_id); ?>" <?php selected($posten_direct_print_printer_id, $printer_id); ?>>
												<?php echo esc_html($printer_label); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<?php if ($posten_direct_print_printer_id === '' && $current_user_default_printer_id !== '') : ?>
										<p class="description">Din brukerstandard er <?php echo esc_html($current_user_default_printer_id); ?>. Velg og lagre en egen printer her for Posten-roboten hvis den skal printe automatisk.</p>
									<?php endif; ?>
									<?php if (empty($settings['api_key'])) : ?>
										<p class="description">Legg inn API key for å hente printerliste fra Cargonizer.</p>
									<?php elseif (empty($printer_fetch_result['success'])) : ?>
										<p class="description" style="color:#b32d2e;"><?php echo esc_html($printer_fetch_result['message'] !== '' ? $printer_fetch_result['message'] : 'Kunne ikke hente printerliste.'); ?></p>
									<?php else : ?>
										<p class="description">Printerlisten bruker samme DirectPrint-printere og aliaser som resten av pluginen. Innstillingen lagres separat for Posten-roboten.</p>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row">Posten label-stempel</th>
								<td>
									<input type="hidden" name="lp_cargonizer_posten_robot_stamp_enabled" value="0">
									<label>
										<input type="checkbox" name="lp_cargonizer_posten_robot_stamp_enabled" value="1" <?php checked(!isset($posten_robot_settings['stamp_enabled']) || !empty($posten_robot_settings['stamp_enabled'])); ?>>
										Add kolli description to Posten label PDF
									</label>
									<p class="description">Original PDF bevares alltid. Stemplet PDF brukes til DirectPrint og kan lastes ned separat fra ordrevisningen.</p>
									<p>
										<label for="lp_cargonizer_posten_robot_stamp_text_template">Text template</label><br>
										<input type="text" name="lp_cargonizer_posten_robot_stamp_text_template" id="lp_cargonizer_posten_robot_stamp_text_template" class="regular-text" value="<?php echo esc_attr(isset($posten_robot_settings['stamp_text_template']) ? (string) $posten_robot_settings['stamp_text_template'] : 'Kolli {index}: {description}'); ?>">
									</p>
									<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;max-width:820px;">
										<label>X position in mm<br><input type="number" step="0.1" min="0" name="lp_cargonizer_posten_robot_stamp_x_mm" value="<?php echo esc_attr(isset($posten_robot_settings['stamp_x_mm']) ? (string) $posten_robot_settings['stamp_x_mm'] : '8'); ?>"></label>
										<label>Y position in mm<br><input type="number" step="0.1" min="0" name="lp_cargonizer_posten_robot_stamp_y_mm" value="<?php echo esc_attr(isset($posten_robot_settings['stamp_y_mm']) ? (string) $posten_robot_settings['stamp_y_mm'] : '8'); ?>"></label>
										<label>Font size<br><input type="number" step="1" min="6" max="24" name="lp_cargonizer_posten_robot_stamp_font_size" value="<?php echo esc_attr(isset($posten_robot_settings['stamp_font_size']) ? (string) $posten_robot_settings['stamp_font_size'] : '10'); ?>"></label>
										<label>Max width in mm<br><input type="number" step="0.1" min="10" name="lp_cargonizer_posten_robot_stamp_max_width_mm" value="<?php echo esc_attr(isset($posten_robot_settings['stamp_max_width_mm']) ? (string) $posten_robot_settings['stamp_max_width_mm'] : '80'); ?>"></label>
										<label>Max lines<br><input type="number" step="1" min="1" max="5" name="lp_cargonizer_posten_robot_stamp_max_lines" value="<?php echo esc_attr(isset($posten_robot_settings['stamp_max_lines']) ? (string) $posten_robot_settings['stamp_max_lines'] : '2'); ?>"></label>
									</div>
									<p>
										<input type="hidden" name="lp_cargonizer_posten_robot_stamp_required_for_print" value="0">
										<label>
											<input type="checkbox" name="lp_cargonizer_posten_robot_stamp_required_for_print" value="1" <?php checked(!isset($posten_robot_settings['stamp_required_for_print']) || !empty($posten_robot_settings['stamp_required_for_print'])); ?>>
											Do not DirectPrint if stamping fails
										</label>
									</p>
								</td>
							</tr>
						</tbody>
					</table>

					<h2 id="lp-cargonizer-section-printers">Standard printer for innlogget admin</h2>
					<p>Denne innstillingen lagres kun på deg som innlogget admin-bruker.</p>
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

					<h2>Egendefinerte printernavn</h2>
					<p>Legg inn et visningsnavn per printer. Vises som «Ditt navn (faktisk printer-navn)» i valg og booking.</p>
					<table class="form-table" role="presentation">
						<tbody>
							<?php if (!empty($available_printers)) : ?>
								<?php foreach ($available_printers as $printer) : ?>
									<?php
									$printer_id = isset($printer['id']) ? sanitize_text_field((string) $printer['id']) : '';
									if ($printer_id === '') {
										continue;
									}
									$printer_label = isset($printer['label']) ? sanitize_text_field((string) $printer['label']) : $printer_id;
									$saved_alias = isset($saved_printer_aliases[$printer_id]) ? sanitize_text_field((string) $saved_printer_aliases[$printer_id]) : '';
									?>
									<tr>
										<th scope="row">
											<label for="<?php echo esc_attr('lp_cargonizer_printer_alias_' . $printer_id); ?>"><?php echo esc_html($printer_label); ?></label>
										</th>
										<td>
											<input
												type="text"
												name="lp_cargonizer_printer_aliases[<?php echo esc_attr($printer_id); ?>]"
												id="<?php echo esc_attr('lp_cargonizer_printer_alias_' . $printer_id); ?>"
												value="<?php echo esc_attr($saved_alias); ?>"
												class="regular-text"
												placeholder="<?php echo esc_attr('f.eks. Printing desk 1'); ?>"
											/>
										</td>
									</tr>
								<?php endforeach; ?>
							<?php elseif (empty($settings['api_key'])) : ?>
								<tr><td colspan="2"><p class="description">Legg inn API key for å hente printerliste fra Cargonizer.</p></td></tr>
							<?php else : ?>
								<tr><td colspan="2"><p class="description" style="color:#b32d2e;"><?php echo esc_html($printer_fetch_result['message'] !== '' ? $printer_fetch_result['message'] : 'Kunne ikke hente printerliste.'); ?></p></td></tr>
							<?php endif; ?>
						</tbody>
					</table>


					<h2 id="lp-cargonizer-section-methods">Fraktmetoder per senderadresse</h2>
					<p class="description">Velg hvilke Cargonizer-produkter som skal være aktive for hver senderadresse. Prisfeltene under hver metode bruker samme beregningslogikk som før.</p>

					<?php if (!empty($sender_method_summary)) : ?>
						<div style="margin:10px 0 12px;padding:10px 12px;border:1px solid #dcdcde;background:#f6f7f7;max-width:1000px;">
							<strong>Hentede fraktavtaler per senderadresse</strong>
							<ul style="margin:8px 0 0 18px;">
								<?php foreach ($sender_method_summary as $sender_summary) : ?>
									<?php
									$summary_sender_label = isset($sender_summary['sender_label']) ? (string) $sender_summary['sender_label'] : 'Sender';
									$summary_total_methods = isset($sender_summary['total_methods']) ? (int) $sender_summary['total_methods'] : 0;
									$summary_enabled_methods = isset($sender_summary['enabled_methods']) ? (int) $sender_summary['enabled_methods'] : 0;
									$summary_agreement_count = isset($sender_summary['agreement_count']) ? (int) $sender_summary['agreement_count'] : 0;
									$summary_last_fetch = isset($sender_summary['last_fetch']) && is_array($sender_summary['last_fetch']) ? $sender_summary['last_fetch'] : array();
									if (!empty($sender_summary['is_legacy_unscoped'])) {
										$summary_text = $summary_total_methods > 0
											? $summary_enabled_methods . '/' . $summary_total_methods . ' aktive eldre produkter uten Sender ID. Hent fraktavtaler på nytt for å knytte dem til riktig Cargonizer sender.'
											: 'Ingen eldre metoder uten Sender ID.';
									} elseif ($summary_total_methods > 0) {
										$summary_text = $summary_enabled_methods . '/' . $summary_total_methods . ' aktive produkter, ' . $summary_agreement_count . ' fraktavtale(r)';
									} elseif (!empty($summary_last_fetch)) {
										if (!empty($summary_last_fetch['success'])) {
											$summary_fetch_methods = isset($summary_last_fetch['method_count']) ? (int) $summary_last_fetch['method_count'] : 0;
											if ($summary_fetch_methods > 0) {
												$summary_text = 'Hentet fra Cargonizer med ' . $summary_fetch_methods . ' produkt(er), men lagrede produkter matcher ikke senderprofilen. Fjern lagrede fraktmetoder og hent på nytt dersom dette vedvarer.';
											} else {
												$summary_text = 'Hentet fra Cargonizer, men ingen produkter funnet (' . (isset($summary_last_fetch['agreement_count']) ? (int) $summary_last_fetch['agreement_count'] : 0) . ' avtale(r)).';
											}
										} else {
											$summary_text = 'Henting feilet' . (!empty($summary_last_fetch['status']) ? ' (HTTP ' . (int) $summary_last_fetch['status'] . ')' : '') . ': ' . (isset($summary_last_fetch['message']) && $summary_last_fetch['message'] !== '' ? (string) $summary_last_fetch['message'] : 'Ukjent feil');
										}
									} else {
										$summary_text = 'Ingen fraktavtaler hentet ennå';
									}
									?>
									<li><strong><?php echo esc_html($summary_sender_label); ?>:</strong> <?php echo esc_html($summary_text); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>

					<?php if (!empty($settings['available_methods']) && is_array($settings['available_methods'])) : ?>
						<?php
						$method_groups = array();
						$method_sender_profile_aliases = array();
						foreach ($sender_method_summary as $summary_profile_id => $summary) {
							if (!is_array($summary) || !empty($summary['is_legacy_unscoped'])) {
								continue;
							}
							$summary_profile_id = sanitize_key((string) $summary_profile_id);
							if ($summary_profile_id === '') {
								continue;
							}
							$summary_sender_id = isset($summary['sender_id']) ? sanitize_text_field((string) $summary['sender_id']) : '';
							$summary_sender_entity_id = isset($summary['sender_entity_id']) ? sanitize_text_field((string) $summary['sender_entity_id']) : '';
							$summary_aliases = array($summary_profile_id);
							if ($summary_sender_id !== '') {
								$summary_aliases[] = $summary_sender_id;
								$summary_aliases[] = $this->build_sender_profile_id($summary_sender_id);
							}
							if ($summary_sender_entity_id !== '') {
								$summary_aliases[] = $summary_sender_entity_id;
								$summary_aliases[] = $this->build_sender_profile_id($summary_sender_entity_id);
							}
							foreach (array_values(array_unique(array_filter($summary_aliases, 'strlen'))) as $summary_alias) {
								$method_sender_profile_aliases[sanitize_key((string) $summary_alias)] = $summary_profile_id;
								$method_sender_profile_aliases[sanitize_text_field((string) $summary_alias)] = $summary_profile_id;
							}
						}
						foreach ($settings['available_methods'] as $method) {
							$is_manual_norgespakke_method = $this->is_manual_norgespakke_method($method);
							$agreement_id = isset($method['agreement_id']) ? trim((string) $method['agreement_id']) : '';
							$agreement_name = isset($method['agreement_name']) ? trim((string) $method['agreement_name']) : '';
							$sender_profile_id = isset($method['sender_profile_id']) ? sanitize_key((string) $method['sender_profile_id']) : '';
							$sender_profile_name = isset($method['sender_profile_name']) ? trim((string) $method['sender_profile_name']) : '';
							$sender_id = isset($method['sender_id']) ? trim((string) $method['sender_id']) : '';
							$sender_entity_id = isset($method['sender_entity_id']) ? trim((string) $method['sender_entity_id']) : '';
							if ($sender_profile_id === '' && $sender_id !== '') {
								$sender_profile_id = $this->build_sender_profile_id($sender_id);
							}
							if ($sender_profile_id === '' && !empty($method['key']) && strpos((string) $method['key'], '::') !== false) {
								$key_parts = explode('::', (string) $method['key'], 2);
								$sender_profile_id = sanitize_key((string) $key_parts[0]);
							}
							if ($sender_profile_id !== '' && !isset($sender_method_summary[$sender_profile_id]) && isset($method_sender_profile_aliases[$sender_profile_id])) {
								$sender_profile_id = $method_sender_profile_aliases[$sender_profile_id];
							}
							if ($sender_profile_id === '' || !isset($sender_method_summary[$sender_profile_id])) {
								foreach ($this->build_sender_identity_aliases('', $sender_id, $sender_entity_id) as $method_sender_alias) {
									if (isset($method_sender_profile_aliases[$method_sender_alias])) {
										$sender_profile_id = $method_sender_profile_aliases[$method_sender_alias];
										break;
									}
								}
							}
							$is_legacy_unscoped_method = $sender_profile_id === '' && !$is_manual_norgespakke_method;
							if ($is_legacy_unscoped_method) {
								$sender_profile_id = '__legacy_unscoped';
								$sender_profile_name = 'Eldre metoder uten Sender ID';
							}
							if ($sender_profile_id !== '' && isset($sender_method_summary[$sender_profile_id])) {
								if ($sender_profile_name === '' && !empty($sender_method_summary[$sender_profile_id]['sender_label'])) {
									$sender_profile_name = preg_replace('/\s*\([^)]*\)\s*$/', '', (string) $sender_method_summary[$sender_profile_id]['sender_label']);
								}
								if ($sender_id === '' && !empty($sender_method_summary[$sender_profile_id]['sender_id'])) {
									$sender_id = (string) $sender_method_summary[$sender_profile_id]['sender_id'];
								}
							}
							$sender_group_key = $sender_profile_id !== '' ? $sender_profile_id : 'sender_default';
							if ($is_manual_norgespakke_method) {
								$group_key = 'manual_norgespakke';
							} elseif ($agreement_id !== '') {
								$group_key = $sender_group_key . ':agreement_id:' . $agreement_id;
							} elseif ($agreement_name !== '') {
								$group_key = $sender_group_key . ':agreement_name:' . $agreement_name;
							} else {
								$group_key = $sender_group_key . ':agreement_unknown';
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
									'sender_label' => trim($sender_profile_name . ($sender_id !== '' ? ' (' . $sender_id . ')' : '')),
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
								$sender_label = isset($group['sender_label']) ? trim((string) $group['sender_label']) : '';
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
											<?php if ($sender_label !== '') : ?>
												<?php echo esc_html(($carrier_label !== '' || $group['agreement_number'] !== '') ? ' / ' : ''); ?><?php echo esc_html('Sender: ' . $sender_label); ?>
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
											$method_services = isset($method['services']) && is_array($method['services']) ? $method['services'] : array();
											$method_sender_label = trim((isset($method['sender_profile_name']) ? (string) $method['sender_profile_name'] : '') . (!empty($method['sender_id']) ? ' (' . (string) $method['sender_id'] . ')' : ''));
											if ($method_sender_label === '' && !empty($group['sender_label'])) {
												$method_sender_label = (string) $group['sender_label'];
											}
											$all_method_service_ids = array();
											foreach ($method_services as $method_service) {
												if (!is_array($method_service)) {
													continue;
												}
												$method_service_id = isset($method_service['service_id']) ? sanitize_text_field((string) $method_service['service_id']) : '';
												if ($method_service_id !== '') {
													$all_method_service_ids[] = $method_service_id;
												}
											}
											$all_method_service_ids = array_values(array_unique($all_method_service_ids));
											$method_extra_services = isset($settings['method_extra_services']) && is_array($settings['method_extra_services']) ? $settings['method_extra_services'] : array();
											$selected_extra_service_ids = array_key_exists($method_key, $method_extra_services) && is_array($method_extra_services[$method_key])
												? array_values(array_unique(array_map('strval', $method_extra_services[$method_key])))
												: $all_method_service_ids;
											?>
											<div class="lp-cargonizer-method-row" style="display:flex;gap:8px;align-items:flex-start;padding:8px 0;border-top:1px solid #f0f0f1;">
												<input class="lp-cargonizer-method-toggle" type="checkbox" name="lp_cargonizer_enabled_methods[]" value="<?php echo esc_attr($method_key); ?>" <?php checked($is_enabled); ?>>
												<span style="flex:1;">
													<strong><?php echo esc_html(isset($method['label']) ? $method['label'] : 'Ukjent metode'); ?></strong><br>
													<small>
														Transportør: <?php echo esc_html(isset($method['carrier_name']) && $method['carrier_name'] !== '' ? $method['carrier_name'] : '—'); ?><?php echo esc_html(isset($method['carrier_id']) && $method['carrier_id'] !== '' ? ' (' . $method['carrier_id'] . ')' : ''); ?> /
														Sender: <?php echo esc_html($method_sender_label !== '' ? $method_sender_label : 'Standard'); ?> /
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
													<?php if (!empty($method_services)) : ?>
														<div style="grid-column:1 / -1;padding:8px 10px;border:1px solid #dcdcde;background:#fcfcfc;">
															<div style="font-weight:600;margin-bottom:4px;">Tilleggstjenester tilgjengelig for lager</div>
															<div style="color:#646970;margin-bottom:6px;">Velg hvilke hentede Cargonizer-tjenester som skal kunne velges i Book shipment-popupen.</div>
															<input type="hidden" name="lp_cargonizer_method_extra_services[<?php echo esc_attr($method_key); ?>][]" value="">
															<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:6px 10px;">
																<?php foreach ($method_services as $method_service) : ?>
																	<?php
																	if (!is_array($method_service)) {
																		continue;
																	}
																	$service_id = isset($method_service['service_id']) ? sanitize_text_field((string) $method_service['service_id']) : '';
																	$service_name = isset($method_service['service_name']) ? sanitize_text_field((string) $method_service['service_name']) : '';
																	if ($service_id === '') {
																		continue;
																	}
																	$attributes = isset($method_service['attributes']) && is_array($method_service['attributes']) ? $method_service['attributes'] : array();
																	$requires_attributes = false;
																	foreach ($attributes as $attribute) {
																		$required_value = isset($attribute['required']) ? strtolower((string) $attribute['required']) : '';
																		if (in_array($required_value, array('true', '1', 'yes'), true)) {
																			$requires_attributes = true;
																			break;
																		}
																	}
																	$service_label = $service_name !== '' ? $service_name . ' (' . $service_id . ')' : $service_id;
																	?>
																	<label style="display:flex;gap:6px;align-items:flex-start;">
																		<input type="checkbox" name="lp_cargonizer_method_extra_services[<?php echo esc_attr($method_key); ?>][]" value="<?php echo esc_attr($service_id); ?>" <?php checked(in_array($service_id, $selected_extra_service_ids, true)); ?>>
																		<span>
																			<?php echo esc_html($service_label); ?>
																			<?php if ($requires_attributes) : ?>
																				<small style="display:block;color:#8a4b00;">Krever parametre; vises deaktivert i booking til parameterstøtte finnes.</small>
																			<?php endif; ?>
																		</span>
																	</label>
																<?php endforeach; ?>
															</div>
														</div>
													<?php endif; ?>
												</div>
											</div>
										<?php endforeach; ?>
									</div>
								</details>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<p><em>Ingen fraktmetoder hentet ennå. Klikk "Hent/oppdater fraktavtaler for alle senderadresser" først.</em></p>
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
							Hent/oppdater fraktavtaler for alle senderadresser
						</button>
						<button type="submit" name="lp_cargonizer_clear_stored_methods" value="1" class="button button-secondary" onclick="return window.confirm('Dette fjerner alle lagrede Cargonizer-fraktmetoder, aktive metodevalg, prisoppsett, tilleggstjenestevalg og hentestatus. Fortsette?');">
							Fjern alle lagrede fraktmetoder
						</button>
					</p>

					<p class="lp-cargonizer-save-strip">
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
					<h2 id="lp-cargonizer-section-checkout">Grunnoppsett / Enkel oppsett</h2>
					<p class="description" style="max-width:900px;">Anbefalt for vanlig butikkdrift. Sett Norge-only, fri frakt-terskel, 69 kr under terskel, hentested og fallback uten å åpne avanserte JSON-felt.</p>
					<h3 style="margin-bottom:4px;">Fraktpriser i checkout (valgfritt)</h3>
					<p class="description" style="margin-top:0;">Standardmodus er admin booking only. Aktiver live checkout under for å vise Cargonizer/Logistra i kundekassen.</p>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row">Enable live checkout rates and pickup points</th>
								<td>
									<input type="hidden" name="lp_cargonizer_live_checkout[enabled]" value="0">
									<label><input type="checkbox" name="lp_cargonizer_live_checkout[enabled]" value="1" <?php checked(!empty($live_checkout['enabled'])); ?>> Vis live Cargonizer/Logistra-rater og hentepunkt i checkout</label>
									<p class="description">Av (standard): Admin booking only. Kunder ser ikke Cargonizer/Logistra-rater, pickup-point selector eller checkout-script.</p>
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
									<p class="description">Velg <strong>Kun checkout</strong> for «estimate only in checkout».</p>
								</td>
							</tr>
							<tr>
								<th scope="row" colspan="2" style="padding-top:18px;">
									<h3 style="margin:0;">Utleveringssteder</h3>
									<p class="description" style="margin:4px 0 0 0;">Forenklet oppsett for hentepunkt i checkout.</p>
								</th>
							</tr>
							<tr>
								<th scope="row">Utleveringssteder</th>
								<td>
									<p style="margin:0 0 4px 0;"><strong>Nærmeste hentested velges automatisk</strong></p>
									<p class="description" style="margin:0;">Aktiv oppførsel er bevart: pickup-metoder forhåndsvelger nærmeste hentested, kunden kan fortsatt overstyre.</p>
								</td>
							</tr>
							<tr>
								<th scope="row" colspan="2" style="padding-top:18px;">
									<h3 style="margin:0;">Reserveoppsett ved feil</h3>
									<p class="description" style="margin:4px 0 0 0;">Velg hva kunden skal se hvis Cargonizer ikke svarer.</p>
								</th>
							</tr>
							<tr>
								<th scope="row"><label for="lp_cargonizer_checkout_fallback_on_quote_failure">Reserveoppsett ved feil</label></th>
								<td>
									<select id="lp_cargonizer_checkout_fallback_on_quote_failure" name="lp_cargonizer_checkout_fallback_on_quote_failure">
										<option value="safe_fallback_rate" <?php selected(isset($checkout_fallback['on_quote_failure']) ? $checkout_fallback['on_quote_failure'] : 'safe_fallback_rate', 'safe_fallback_rate'); ?>>Bruk sikker fallback-rate</option>
										<option value="use_last_known_rate" <?php selected(isset($checkout_fallback['on_quote_failure']) ? $checkout_fallback['on_quote_failure'] : 'safe_fallback_rate', 'use_last_known_rate'); ?>>Bruk sist kjente rate</option>
										<option value="block_checkout" <?php selected(isset($checkout_fallback['on_quote_failure']) ? $checkout_fallback['on_quote_failure'] : 'safe_fallback_rate', 'block_checkout'); ?>>Blokker checkout</option>
										<option value="hide_live_checkout" <?php selected(isset($checkout_fallback['on_quote_failure']) ? $checkout_fallback['on_quote_failure'] : 'safe_fallback_rate', 'hide_live_checkout'); ?>>Skjul live checkout-metoder</option>
									</select>
									<p class="description">Hva som skal skje hvis API-estimat feiler eller får timeout.</p>
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

					<details id="lp-cargonizer-section-advanced" style="margin:18px 0;border:1px solid #dcdcde;padding:10px 12px;background:#fff;">
						<summary style="cursor:pointer;font-weight:600;">Avansert</summary>
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

					<h2>Produkt- og pakkeregler: Pakkeoppløsning</h2>
					<p>
						<label for="lp_cargonizer_package_build_mode"><strong>Pakkebygging</strong></label><br>
						<select id="lp_cargonizer_package_build_mode" name="lp_cargonizer_package_build_mode">
							<option value="combined_single" <?php selected(isset($package_resolution['package_build_mode']) ? $package_resolution['package_build_mode'] : 'combined_single', 'combined_single'); ?>>En kombinert kolli (dagens enkle oppførsel)</option>
							<option value="split_by_profile" <?php selected(isset($package_resolution['package_build_mode']) ? $package_resolution['package_build_mode'] : 'combined_single', 'split_by_profile'); ?>>Del opp per profil</option>
							<option value="separate_bulky_profiles" <?php selected(isset($package_resolution['package_build_mode']) ? $package_resolution['package_build_mode'] : 'combined_single', 'separate_bulky_profiles'); ?>>Del opp per profil + separate bulky-produkter</option>
						</select>
					</p>
					<p>
						<label for="lp_cargonizer_separate_package_strategy"><strong>Separate pakker + øvrige varer</strong></label><br>
						<select id="lp_cargonizer_separate_package_strategy" name="lp_cargonizer_separate_package_strategy">
							<option value="keep_separate_colli" <?php selected(isset($package_resolution['separate_package_strategy']) ? $package_resolution['separate_package_strategy'] : 'keep_separate_colli', 'keep_separate_colli'); ?>>Lag separate kolli (ett kolli for separat-pakken + ett for øvrige varer)</option>
							<option value="merge_non_separate_into_first_separate" <?php selected(isset($package_resolution['separate_package_strategy']) ? $package_resolution['separate_package_strategy'] : 'keep_separate_colli', 'merge_non_separate_into_first_separate'); ?>>Legg øvrige varer inn i første separate pakke</option>
						</select>
					</p>
					<p class="description">Fallback-kilder i prioritert rekkefølge, én per linje. Tillatte verdier: product_dimensions, product_override, shipping_class_profile, category_profile, value_rule, default_profile.</p>
					<textarea name="lp_cargonizer_package_resolution_fallback_sources" rows="6" class="large-text code"><?php echo esc_textarea(implode("\n", isset($package_resolution['fallback_sources']) && is_array($package_resolution['fallback_sources']) ? $package_resolution['fallback_sources'] : array())); ?></textarea>

					<h2>Fraktmetoder og visningsregler (avansert)</h2>
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

					<h2>Reserveoppsett ved feil</h2>
					<p>
						<input type="hidden" name="lp_cargonizer_checkout_fallback_allow_checkout" value="0">
						<label><input type="checkbox" name="lp_cargonizer_checkout_fallback_allow_checkout" value="1" <?php checked(!empty($checkout_fallback['allow_checkout_with_fallback'])); ?>> Tillat checkout å fortsette med fallback-rater</label>
					</p>
					<p class="description">Fallback-rater som JSON-array med feltene method_key, label og price.</p>
					<textarea name="lp_cargonizer_checkout_fallback_rates_json" rows="8" class="large-text code"><?php echo esc_textarea(wp_json_encode(isset($checkout_fallback['safe_fallback_rates']) ? $checkout_fallback['safe_fallback_rates'] : array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></textarea>
					</details>
					<p class="lp-cargonizer-save-strip">
						<button type="submit" name="lp_cargonizer_save_settings" class="button button-primary">
							Lagre innstillinger
						</button>
					</p>
				</form>

				<hr style="margin:24px 0;">
				<h2>DirectPrint dokumentutskrift</h2>
				<p class="description">Last opp en ferdig PDF-, ZPL- eller EPL-fil og send den direkte til valgt Logistra/Cargonizer DirectPrint-printer.</p>
				<?php
				$direct_print_selected_printer_id = isset($_POST['lp_cargonizer_direct_print_printer_id'])
					? sanitize_text_field(wp_unslash($_POST['lp_cargonizer_direct_print_printer_id']))
					: $current_user_default_printer_id;
				$direct_print_disabled = empty($settings['api_key']) || empty($available_printers);
				?>
				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field(self::NONCE_ACTION_DIRECT_PRINT_UPLOAD); ?>
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><label for="lp_cargonizer_direct_print_printer_id">Printer</label></th>
								<td>
									<select name="lp_cargonizer_direct_print_printer_id" id="lp_cargonizer_direct_print_printer_id" required <?php disabled($direct_print_disabled); ?>>
										<option value="">Velg printer</option>
										<?php foreach ($available_printers as $printer) : ?>
											<?php
											$printer_id = isset($printer['id']) ? sanitize_text_field((string) $printer['id']) : '';
											if ($printer_id === '') {
												continue;
											}
											$printer_label = isset($printer['label']) ? sanitize_text_field((string) $printer['label']) : $printer_id;
											?>
											<option value="<?php echo esc_attr($printer_id); ?>" <?php selected($direct_print_selected_printer_id, $printer_id); ?>>
												<?php echo esc_html($printer_label); ?>
											</option>
										<?php endforeach; ?>
									</select>
									<?php if (empty($settings['api_key'])) : ?>
										<p class="description">Legg inn API key for å hente printere før DirectPrint kan brukes.</p>
									<?php elseif (empty($available_printers)) : ?>
										<p class="description" style="color:#b32d2e;"><?php echo esc_html($printer_fetch_result['message'] !== '' ? $printer_fetch_result['message'] : 'Kunne ikke hente printerliste.'); ?></p>
									<?php else : ?>
										<p class="description">Printerlisten hentes fra Cargonizer uten Sender ID, slik DirectPrint printer-endepunktet krever.</p>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="lp_cargonizer_direct_print_file">Fil</label></th>
								<td>
									<input
										type="file"
										name="lp_cargonizer_direct_print_file"
										id="lp_cargonizer_direct_print_file"
										accept=".pdf,.zpl,.epl,application/pdf,application/x.zpl,application/x.epl"
										required
										<?php disabled($direct_print_disabled); ?>
									/>
									<p class="description">Støttede formater: PDF, ZPL og EPL. Filen lagres ikke i WordPress; den sendes videre til Cargonizer som DirectPrint-jobb.</p>
								</td>
							</tr>
						</tbody>
					</table>
					<p>
						<button type="submit" name="lp_cargonizer_direct_print_upload" class="button button-primary" <?php disabled($direct_print_disabled); ?>>
							Skriv ut fil
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
							Test autentisering, hent og lagre fraktmetoder
						</button>
					</p>
				</form>
			</div>

			<?php if ($result !== null) : ?>
				<div style="background:#fff;border:1px solid #ddd;padding:20px;margin:20px 0;max-width:1100px;">
					<h2>Resultat</h2>

					<?php if ($result['success']) : ?>
						<div class="notice <?php echo esc_attr(!empty($result['partial_success']) ? 'notice-warning' : 'notice-success'); ?> inline">
							<p><?php echo esc_html($result['message']); ?></p>
						</div>

						<?php if (!empty($result['results']) && is_array($result['results'])) : ?>
							<table class="widefat striped" style="margin-top:12px;max-width:900px;">
								<thead>
									<tr>
										<th>Sender</th>
										<th>Sender ID</th>
										<th>API-kall brukt sender</th>
										<th>Status</th>
										<th>Fraktavtaler</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($result['results'] as $sender_result) : ?>
										<tr>
											<td><?php echo esc_html(isset($sender_result['profile_name']) ? $sender_result['profile_name'] : '—'); ?></td>
											<td><?php echo esc_html(isset($sender_result['sender_id']) ? $sender_result['sender_id'] : '—'); ?></td>
											<td><?php echo esc_html(!empty($sender_result['effective_sender_id']) ? $sender_result['effective_sender_id'] : (isset($sender_result['sender_id']) ? $sender_result['sender_id'] : '—')); ?></td>
											<td><?php echo !empty($sender_result['success']) ? '<span style="color:#125228;">OK</span>' : '<span style="color:#b32d2e;">Feilet</span>'; ?></td>
											<td><?php echo esc_html(isset($sender_result['agreement_count']) ? (string) $sender_result['agreement_count'] : '0'); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>

						<?php if (!empty($result['methods']) && is_array($result['methods'])) : ?>
							<table class="widefat striped" style="margin-top:20px;">
								<thead>
									<tr>
										<th>Sender</th>
										<th>Transport agreement ID</th>
										<th>Transport agreement</th>
										<th>Transportør / provider</th>
										<th>Produkt ID</th>
										<th>Produkt</th>
										<th>Tjenester</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ($result['methods'] as $method) : ?>
										<?php if ($this->is_manual_norgespakke_method($method)) { continue; } ?>
											<tr>
												<td><?php echo esc_html(trim((isset($method['sender_profile_name']) ? $method['sender_profile_name'] : '') . (!empty($method['sender_id']) ? ' (' . $method['sender_id'] . ')' : '')) ?: '—'); ?></td>
												<td><?php echo esc_html(isset($method['agreement_id']) ? $method['agreement_id'] : '—'); ?></td>
												<td><?php echo esc_html(isset($method['agreement_name']) ? $method['agreement_name'] : '—'); ?></td>
												<td><?php echo esc_html(!empty($method['carrier_name']) ? $method['carrier_name'] : '—'); ?><?php echo esc_html(!empty($method['carrier_id']) ? ' (' . $method['carrier_id'] . ')' : ''); ?></td>
												<td><?php echo esc_html(isset($method['product_id']) ? $method['product_id'] : '—'); ?></td>
												<td><?php echo esc_html(isset($method['product_name']) ? $method['product_name'] : '—'); ?></td>
												<td>
													<?php
													if (!empty($method['services']) && is_array($method['services'])) {
														$services = array();
														foreach ($method['services'] as $service) {
															if (!is_array($service)) {
																continue;
															}
															$services[] = trim((isset($service['service_name']) ? $service['service_name'] : '') . ' (' . (isset($service['service_id']) ? $service['service_id'] : '') . ')');
														}
														echo esc_html(implode(', ', array_filter($services, 'strlen')));
													} else {
														echo '—';
													}
													?>
												</td>
											</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php else : ?>
							<p>Autentiseringen fungerte, men ingen fraktmetoder/produkter ble funnet i responsen.</p>
						<?php endif; ?>

						<details style="margin-top:20px;">
							<summary><strong>Vis rå XML-respons</strong></summary>
							<?php if (!empty($result['raw_by_sender']) && is_array($result['raw_by_sender'])) : ?>
								<?php foreach ($result['raw_by_sender'] as $sender_raw) : ?>
									<h4><?php echo esc_html(isset($sender_raw['label']) ? $sender_raw['label'] : 'Sender'); ?></h4>
									<pre style="white-space:pre-wrap;background:#f6f7f7;padding:15px;border:1px solid #ddd;max-height:500px;overflow:auto;"><?php echo esc_html(isset($sender_raw['raw']) ? $sender_raw['raw'] : ''); ?></pre>
								<?php endforeach; ?>
							<?php endif; ?>
						</details>

					<?php else : ?>
						<div class="notice notice-error inline">
							<p><?php echo esc_html($result['message']); ?></p>
						</div>

						<?php if (!empty($result['raw']) || (!empty($result['raw_by_sender']) && is_array($result['raw_by_sender']))) : ?>
							<details style="margin-top:20px;">
								<summary><strong>Vis respons fra Cargonizer</strong></summary>
								<?php if (!empty($result['raw_by_sender']) && is_array($result['raw_by_sender'])) : ?>
									<?php foreach ($result['raw_by_sender'] as $sender_raw) : ?>
										<h4><?php echo esc_html(isset($sender_raw['label']) ? $sender_raw['label'] : 'Sender'); ?></h4>
										<pre style="white-space:pre-wrap;background:#f6f7f7;padding:15px;border:1px solid #ddd;max-height:500px;overflow:auto;"><?php echo esc_html(isset($sender_raw['raw']) ? $sender_raw['raw'] : ''); ?></pre>
									<?php endforeach; ?>
								<?php else : ?>
									<pre style="white-space:pre-wrap;background:#f6f7f7;padding:15px;border:1px solid #ddd;max-height:500px;overflow:auto;"><?php echo esc_html($result['raw']); ?></pre>
								<?php endif; ?>
							</details>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}
