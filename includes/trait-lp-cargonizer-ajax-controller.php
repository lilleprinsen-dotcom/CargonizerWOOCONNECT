<?php

if (!defined('ABSPATH')) {
	exit;
}

trait LP_Cargonizer_Ajax_Controller_Trait {
		public function ajax_get_order_estimate_data() {
			if (!current_user_can('manage_woocommerce')) {
				wp_send_json_error(array('message' => 'Ingen tilgang.'), 403);
			}

		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (!wp_verify_nonce($nonce, self::NONCE_ACTION_ORDER_DATA)) {
			wp_send_json_error(array('message' => 'Ugyldig nonce.'), 403);
		}

		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		$order = $order_id ? wc_get_order($order_id) : false;
			if (!$order) {
				wp_send_json_error(array('message' => 'Ordre ikke funnet.'), 404);
			}

			$result = $this->get_operations_facade()->get_order_booking_context($order_id);
			if (is_wp_error($result)) {
				wp_send_json_error(array('message' => $result->get_error_message()), 400);
			}
			wp_send_json_success($result);
		}

		public function operations_get_order_booking_context($order_id) {
			$order_id = absint($order_id);
			$order = $order_id ? wc_get_order($order_id) : false;
			if (!$order || !is_a($order, 'WC_Order')) {
				return new WP_Error('lp_cargonizer_order_not_found', 'Ordre ikke funnet.');
			}

			$items = array();
			$packages = array();

		foreach ($order->get_items() as $item) {
			if (!is_a($item, 'WC_Order_Item_Product')) {
				continue;
			}

			$product = $item->get_product();
			$quantity = (int) $item->get_quantity();

			$items[] = array(
				'name' => $item->get_name(),
				'quantity' => $quantity,
				'sku' => $product ? $product->get_sku() : '',
			);

		}

		if (isset($this->package_builder_service) && is_object($this->package_builder_service) && method_exists($this->package_builder_service, 'build_admin_prefill_packages_from_order')) {
			$packages = $this->package_builder_service->build_admin_prefill_packages_from_order($order);
		}

		$settings = $this->get_settings();
		$sender_profiles = $this->get_sender_profiles_from_settings($settings);
		$recipient_country = $this->resolve_recipient_country_for_context($order);
		$data = array(
			'order' => array(
				'number' => $order->get_order_number(),
				'date' => $order->get_date_created() ? $order->get_date_created()->date_i18n('Y-m-d H:i') : '',
				'total' => wp_strip_all_tags($order->get_formatted_order_total()),
			),
			'recipient' => array(
				'name' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
				'address_1' => $order->get_shipping_address_1(),
				'address_2' => $order->get_shipping_address_2(),
				'postcode' => $order->get_shipping_postcode(),
				'city' => $order->get_shipping_city(),
				'country' => isset($recipient_country['normalized']) && $recipient_country['normalized'] !== '' ? $recipient_country['normalized'] : '',
				'email' => $order->get_billing_email(),
				'phone' => $this->get_order_recipient_phone_for_api($order),
			),
			'items' => $items,
			'packages' => $packages,
			'booking_state' => $this->load_order_booking_state($order),
			'checkout_selection' => $this->load_order_checkout_selection($order),
			'sender_profiles' => $sender_profiles,
			'default_sender_profile_id' => $this->get_default_sender_profile_id($settings),
			'booking_defaults' => array(
				'notify_email_to_consignee' => isset($settings['booking_email_notification_default']) ? (int) $this->sanitize_checkbox_value($settings['booking_email_notification_default']) : 1,
				'estimator_top_count' => isset($settings['booking_estimator_top_count']) ? max(3, min(5, absint($settings['booking_estimator_top_count']))) : 3,
				'pickup_autoselect_mode' => isset($settings['booking_pickup_autoselect_mode']) && in_array((string) $settings['booking_pickup_autoselect_mode'], array('nearest', 'none'), true) ? (string) $settings['booking_pickup_autoselect_mode'] : 'nearest',
				'order_status_after_created' => isset($settings['booking_order_status_after_created']) ? $this->normalize_wc_order_status_slug($settings['booking_order_status_after_created']) : '',
			),
		);

			if ($data['recipient']['name'] === '') {
				$data['recipient']['name'] = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
			}

			return $data;
		}

	private function get_booking_state_meta_key() {
		return '_lp_cargonizer_booking_state';
	}

		private function get_checkout_selection_meta_key() {
			return '_lp_cargonizer_checkout_selection';
		}

		private function operations_error($code, $message, $http_status = 400, $payload = array()) {
			$payload = is_array($payload) ? $payload : array();
			if (!isset($payload['message'])) {
				$payload['message'] = $message;
			}
			return new WP_Error($code, $message, array(
				'http_status' => (int) $http_status,
				'payload' => $payload,
			));
		}

		public function operations_get_current_wordpress_actor_context() {
			$current_user = wp_get_current_user();
			return $this->normalize_operations_actor_context(array(
				'source' => 'wordpress_admin',
				'wordpress_user_id' => get_current_user_id(),
				'wordpress_user_login' => $current_user && isset($current_user->user_login) ? (string) $current_user->user_login : '',
				'employee_display_name' => $current_user && isset($current_user->display_name) ? (string) $current_user->display_name : '',
			));
		}

		private function normalize_operations_actor_context($actor_context) {
			$actor_context = is_array($actor_context) ? $actor_context : array();
			return array(
				'source' => isset($actor_context['source']) ? sanitize_key((string) $actor_context['source']) : '',
				'employee_id' => isset($actor_context['employee_id']) ? sanitize_text_field((string) $actor_context['employee_id']) : '',
				'employee_display_name' => isset($actor_context['employee_display_name']) ? sanitize_text_field((string) $actor_context['employee_display_name']) : '',
				'device_id' => isset($actor_context['device_id']) ? sanitize_text_field((string) $actor_context['device_id']) : '',
				'wordpress_user_id' => isset($actor_context['wordpress_user_id']) ? absint($actor_context['wordpress_user_id']) : 0,
				'wordpress_user_login' => isset($actor_context['wordpress_user_login']) ? sanitize_text_field((string) $actor_context['wordpress_user_login']) : '',
			);
		}

		public function operations_get_booking_state($order_id) {
			$order_id = absint($order_id);
			$order = $order_id ? wc_get_order($order_id) : false;
			if (!$order || !is_a($order, 'WC_Order')) {
				return new WP_Error('lp_cargonizer_order_not_found', 'Ordre ikke funnet.');
			}
			return $this->load_order_booking_state($order);
		}

		public function operations_estimate_shipping($order_id, $packages, $selected_methods, $sender_profile_id = '', $recipient_override = array()) {
			return new WP_Error(
				'lp_cargonizer_estimate_facade_not_enabled',
				'Estimering er foreløpig bevart i eksisterende admin-AJAX fordi regresjon mot alle estimator-sideeffekter ikke er bevist i denne ZIP-en.'
			);
		}

		public function operations_book_shipment($order_id, $booking_request, $actor_context = array()) {
			return new WP_Error(
				'lp_cargonizer_booking_facade_not_enabled',
				'Book shipment er fail-closed i facaden til booking-adapteren er regresjonsbevist. Eksisterende WordPress-admin booking er uendret og fortsatt autoritativ.',
				array('execution_unknown' => false)
			);
		}

		private function derive_generic_label_state($print_state) {
			$print_state = is_array($print_state) ? $print_state : array();
			if (empty($print_state['attempted'])) {
				return 'not_requested';
			}
			if (!empty($print_state['success'])) {
				return 'printed';
			}
			if (!empty($print_state['pieces']) && is_array($print_state['pieces'])) {
				$success_count = 0;
				foreach ($print_state['pieces'] as $piece) {
					if (is_array($piece) && !empty($piece['success'])) {
						$success_count++;
					}
				}
				if ($success_count > 0) {
					return 'partial_failed';
				}
			}
			return 'print_failed';
		}

		private function normalize_servicepartner_options($options) {
			$normalized = array();
			foreach ($options as $option) {
				if (!is_array($option)) {
					continue;
				}
				$value = isset($option['value']) ? sanitize_text_field((string) $option['value']) : (isset($option['id']) ? sanitize_text_field((string) $option['id']) : '');
				$normalized[] = array(
					'value' => $value,
					'id' => isset($option['id']) ? sanitize_text_field((string) $option['id']) : $value,
					'label' => isset($option['label']) ? sanitize_text_field((string) $option['label']) : $value,
					'customer_number' => isset($option['customer_number']) ? sanitize_text_field((string) $option['customer_number']) : '',
					'name' => isset($option['name']) ? sanitize_text_field((string) $option['name']) : '',
					'address' => isset($option['address']) ? sanitize_text_field((string) $option['address']) : '',
					'postcode' => isset($option['postcode']) ? sanitize_text_field((string) $option['postcode']) : '',
					'city' => isset($option['city']) ? sanitize_text_field((string) $option['city']) : '',
					'country' => isset($option['country']) ? sanitize_text_field((string) $option['country']) : '',
				);
			}
			return $normalized;
		}

		private function normalize_printers($printers) {
			$normalized = array();
			foreach ($printers as $printer) {
				if (!is_array($printer)) {
					continue;
				}
				$id = isset($printer['id']) ? sanitize_text_field((string) $printer['id']) : '';
				$normalized[] = array(
					'id' => $id,
					'label' => isset($printer['label']) ? sanitize_text_field((string) $printer['label']) : (isset($printer['name']) ? sanitize_text_field((string) $printer['name']) : $id),
					'name' => isset($printer['name']) ? sanitize_text_field((string) $printer['name']) : '',
					'alias' => isset($printer['alias']) ? sanitize_text_field((string) $printer['alias']) : '',
				);
			}
			return $normalized;
		}

		public function operations_get_capabilities() {
			$settings = $this->get_settings();
			$api_base_url = isset($settings['api_base_url']) ? esc_url_raw((string) $settings['api_base_url']) : '';
			if ($api_base_url === '') {
				$api_base_url = 'https://api.cargonizer.no';
			}
			$host = wp_parse_url($api_base_url, PHP_URL_HOST);
			$posten_settings = isset($settings['posten_robot']) && is_array($settings['posten_robot']) ? $settings['posten_robot'] : array();

			return array(
				'plugin_version' => '1.2.0',
				'booking_supported' => true,
				'estimates_supported' => true,
				'servicepartners_supported' => true,
				'printers_supported' => true,
				'generic_reprint_supported' => true,
				'manual_norgespakke_enabled' => !empty($settings['manual_norgespakke_enabled']) || !empty($settings['enable_manual_norgespakke']),
				'posten_robot_enabled' => !empty($posten_settings['enabled']),
				'posten_job_cancel_supported' => true,
				'posten_reprint_supported' => true,
				'multi_booking_supported' => true,
				'per_package_printer_supported' => true,
				'checkout_selection_prefill_supported' => true,
				'generic_cargonizer_cancel_supported' => false,
				'provider' => array(
					'api_host' => is_string($host) ? $host : '',
					'api_base_url_default_is_production' => $api_base_url === 'https://api.cargonizer.no',
				),
			);
		}

	private function load_order_checkout_selection($order) {
		if (!$order || !is_a($order, 'WC_Order')) {
			return array();
		}

		$raw = $order->get_meta($this->get_checkout_selection_meta_key(), true);
		if (!is_array($raw)) {
			return array();
		}

		$shipping = isset($raw['shipping']) && is_array($raw['shipping']) ? $raw['shipping'] : array();
		$pickup_point = isset($raw['pickup_point']) && is_array($raw['pickup_point']) ? $raw['pickup_point'] : array();
		$selected = isset($pickup_point['selected']) && is_array($pickup_point['selected']) ? $pickup_point['selected'] : array();
		$selected_service_ids = isset($shipping['selected_service_ids']) && is_array($shipping['selected_service_ids']) ? $shipping['selected_service_ids'] : array();

		$clean_service_ids = array();
		foreach ($selected_service_ids as $service_id) {
			$clean_service_id = sanitize_text_field((string) $service_id);
			if ($clean_service_id !== '') {
				$clean_service_ids[] = $clean_service_id;
			}
		}

		return array(
			'version' => isset($raw['version']) ? absint($raw['version']) : 0,
			'source' => isset($raw['source']) ? sanitize_text_field((string) $raw['source']) : '',
			'saved_at_gmt' => isset($raw['saved_at_gmt']) ? sanitize_text_field((string) $raw['saved_at_gmt']) : '',
			'shipping' => array(
				'method_id' => isset($shipping['method_id']) ? sanitize_text_field((string) $shipping['method_id']) : '',
				'method_key' => isset($shipping['method_key']) ? sanitize_text_field((string) $shipping['method_key']) : '',
				'rate_id' => isset($shipping['rate_id']) ? sanitize_text_field((string) $shipping['rate_id']) : '',
				'label' => isset($shipping['label']) ? sanitize_text_field((string) $shipping['label']) : '',
				'transport_agreement_id' => isset($shipping['transport_agreement_id']) ? sanitize_text_field((string) $shipping['transport_agreement_id']) : '',
				'carrier_id' => isset($shipping['carrier_id']) ? sanitize_text_field((string) $shipping['carrier_id']) : '',
				'product_id' => isset($shipping['product_id']) ? sanitize_text_field((string) $shipping['product_id']) : '',
				'selected_service_ids' => array_values(array_unique($clean_service_ids)),
			),
			'pickup_point' => array(
				'required' => !empty($pickup_point['required']),
				'selected_id' => isset($pickup_point['selected_id']) ? sanitize_text_field((string) $pickup_point['selected_id']) : '',
				'selected' => array(
					'id' => isset($selected['id']) ? sanitize_text_field((string) $selected['id']) : '',
					'name' => isset($selected['name']) ? sanitize_text_field((string) $selected['name']) : '',
					'address1' => isset($selected['address1']) ? sanitize_text_field((string) $selected['address1']) : '',
					'postcode' => isset($selected['postcode']) ? sanitize_text_field((string) $selected['postcode']) : '',
					'city' => isset($selected['city']) ? sanitize_text_field((string) $selected['city']) : '',
					'country' => isset($selected['country']) ? sanitize_text_field((string) $selected['country']) : '',
					'customer_number' => isset($selected['customer_number']) ? sanitize_text_field((string) $selected['customer_number']) : '',
				),
				'selection_source' => isset($pickup_point['selection_source']) ? sanitize_text_field((string) $pickup_point['selection_source']) : '',
			),
		);
	}

	private function get_default_booking_state() {
		return array(
			'booked' => false,
			'consignment_number' => '',
			'consignment_id' => '',
			'piece_numbers' => array(),
			'piece_ids' => array(),
			'tracking_url' => '',
			'consignment_pdf_url' => '',
			'waybill_pdf_url' => '',
			'method_key' => '',
			'agreement_id' => '',
			'product_id' => '',
			'sender_profile_id' => '',
			'sender_profile_name' => '',
			'sender_id' => '',
			'sender_entity_id' => '',
			'sender_address' => '',
			'servicepartner' => '',
			'servicepartner_customer_number' => '',
			'servicepartner_selection_source' => '',
			'servicepartner_auto_selected' => false,
			'auto_selection_reason' => '',
			'sms_service_id' => '',
			'selected_service_ids' => array(),
			'notify_email_to_consignee' => false,
			'created_at_gmt' => '',
			'created_by_user_id' => '',
			'created_by_user_login' => '',
			'created_by_display_name' => '',
			'estimated_shipping_price' => '',
			'estimated_shipping_price_source' => 'missing',
			'history' => array(),
			'print' => array(
				'attempted' => false,
				'success' => false,
				'printer_id' => '',
				'printer_label' => '',
				'message' => '',
				'raw_response' => '',
				'groups' => array(),
				'pieces' => array(),
			),
			'status_change' => array(
				'enabled' => false,
				'success' => false,
				'verified' => false,
				'retry_scheduled' => false,
				'retry_attempt' => 0,
				'target_status' => '',
				'target_status_label' => '',
				'previous_status' => '',
				'previous_status_label' => '',
				'final_status' => '',
				'final_status_label' => '',
				'message' => '',
				'attempted_at_gmt' => '',
				'retry_at_gmt' => '',
				'attempts' => array(),
			),
		);
	}

	private function normalize_booking_state($state) {
		$default = $this->get_default_booking_state();
		if (!is_array($state)) {
			return $default;
		}

		$normalized = $default;
		foreach ($default as $key => $value) {
			if ($key === 'print') {
				$print = isset($state['print']) && is_array($state['print']) ? $state['print'] : array();
				foreach ($default['print'] as $print_key => $print_default) {
					if (array_key_exists($print_key, $print)) {
						if (is_bool($print_default)) {
							$normalized['print'][$print_key] = (bool) $print[$print_key];
						} elseif (is_array($print_default)) {
							$normalized['print'][$print_key] = $this->sanitize_booking_print_array(isset($print[$print_key]) && is_array($print[$print_key]) ? $print[$print_key] : array());
						} else {
							$normalized['print'][$print_key] = sanitize_text_field((string) $print[$print_key]);
						}
					}
				}
				continue;
			}

			if ($key === 'status_change') {
				$status_change = isset($state['status_change']) && is_array($state['status_change']) ? $state['status_change'] : array();
				foreach ($default['status_change'] as $status_key => $status_default) {
					if (array_key_exists($status_key, $status_change)) {
						if (is_bool($status_default)) {
							$normalized['status_change'][$status_key] = (bool) $status_change[$status_key];
						} elseif (is_int($status_default)) {
							$normalized['status_change'][$status_key] = absint($status_change[$status_key]);
						} elseif (is_array($status_default)) {
							$normalized['status_change'][$status_key] = $this->sanitize_booking_print_array(isset($status_change[$status_key]) && is_array($status_change[$status_key]) ? $status_change[$status_key] : array());
						} else {
							$normalized['status_change'][$status_key] = sanitize_text_field((string) $status_change[$status_key]);
						}
					}
				}
				continue;
			}

			if (!array_key_exists($key, $state)) {
				continue;
			}

			if (is_bool($value)) {
				$normalized[$key] = (bool) $state[$key];
			} elseif (is_array($value)) {
				if ($key === 'history') {
					$history_rows = is_array($state[$key]) ? $state[$key] : array();
					$normalized_history = array();
					foreach ($history_rows as $history_row) {
						if (!is_array($history_row)) {
							continue;
						}
						$history_row['history'] = array();
						$normalized_history[] = $this->normalize_booking_state($history_row);
					}
					$normalized[$key] = $normalized_history;
					continue;
				}
				$list = is_array($state[$key]) ? $state[$key] : array();
				$normalized[$key] = array_values(array_filter(array_map('sanitize_text_field', array_map('strval', $list)), 'strlen'));
			} else {
				$normalized[$key] = sanitize_text_field((string) $state[$key]);
			}
		}

		return $normalized;
	}

	private function sanitize_booking_print_array($value) {
		$clean = array();
		foreach ((array) $value as $key => $item) {
			$clean_key = is_int($key) ? $key : sanitize_key((string) $key);
			if (is_array($item)) {
				$clean[$clean_key] = $this->sanitize_booking_print_array($item);
			} elseif (is_bool($item)) {
				$clean[$clean_key] = $item;
			} elseif (is_numeric($item)) {
				$clean[$clean_key] = $item + 0;
			} else {
				$clean[$clean_key] = sanitize_text_field((string) $item);
			}
		}
		return $clean;
	}

	private function load_order_booking_state($order) {
		if (!$order || !is_a($order, 'WC_Order')) {
			return array('booked' => false);
		}

		$raw = $order->get_meta($this->get_booking_state_meta_key(), true);
		if (!is_array($raw)) {
			return array('booked' => false);
		}

		return $this->normalize_booking_state($raw);
	}

	private function strip_booking_history_for_snapshot($booking_state) {
		$snapshot = $this->normalize_booking_state($booking_state);
		$snapshot['history'] = array();
		return $snapshot;
	}

	private function get_booking_count_from_state($booking_state) {
		$history = isset($booking_state['history']) && is_array($booking_state['history']) ? $booking_state['history'] : array();
		return !empty($booking_state['booked']) ? (count($history) + 1) : count($history);
	}

	private function build_customer_tracking_order_note_for_booking($booking_state, $method_payload) {
		$booking_state = is_array($booking_state) ? $booking_state : array();
		$method_payload = is_array($method_payload) ? $method_payload : array();
		$tracking_url = isset($booking_state['tracking_url']) ? esc_url_raw((string) $booking_state['tracking_url']) : '';
		if ($tracking_url === '') {
			return '';
		}

		$carrier_name = isset($method_payload['carrier_name']) ? sanitize_text_field((string) $method_payload['carrier_name']) : '';
		$prefix = $carrier_name !== '' ? $carrier_name . '-sporing for ordren din:' : 'Sporing for ordren din:';
		$consignment_number = isset($booking_state['consignment_number']) ? sanitize_text_field((string) $booking_state['consignment_number']) : '';
		$piece_numbers = isset($booking_state['piece_numbers']) && is_array($booking_state['piece_numbers'])
			? array_values(array_filter(array_map('sanitize_text_field', array_map('strval', $booking_state['piece_numbers'])), 'strlen'))
			: array();
		$tracking_number = $consignment_number !== '' ? $consignment_number : (isset($piece_numbers[0]) ? (string) $piece_numbers[0] : '');
		if ($tracking_number !== '') {
			return $prefix . ' ' . $tracking_number . ': ' . $tracking_url;
		}

		return $prefix . ' ' . $tracking_url;
	}

	private function save_order_booking_state($order, $state) {
		if (!$order || !is_a($order, 'WC_Order')) {
			return;
		}

		$order->update_meta_data($this->get_booking_state_meta_key(), $this->normalize_booking_state($state));
		$order->save();
	}

	private function normalize_wc_order_status_slug($status) {
		$status = sanitize_key((string) $status);
		if (strpos($status, 'wc-') === 0) {
			$status = substr($status, 3);
		}
		return $status;
	}

	private function get_wc_order_status_label($status) {
		$status = $this->normalize_wc_order_status_slug($status);
		if ($status === '') {
			return '';
		}
		if (function_exists('wc_get_order_statuses')) {
			$statuses = wc_get_order_statuses();
			$status_key = 'wc-' . $status;
			if (isset($statuses[$status_key])) {
				return sanitize_text_field((string) $statuses[$status_key]);
			}
		}
		return ucwords(str_replace('-', ' ', $status));
	}

	private function get_booking_order_status_after_created_setting() {
		$settings = $this->get_settings();
		return isset($settings['booking_order_status_after_created'])
			? $this->normalize_wc_order_status_slug($settings['booking_order_status_after_created'])
			: '';
	}

	private function build_sender_profile_id($sender_id) {
		$sender_id = sanitize_text_field((string) $sender_id);
		$profile_id = sanitize_key('sender_' . $sender_id);
		return $profile_id !== '' ? $profile_id : 'sender_default';
	}

	private function normalize_sender_profile_row($profile, $fallback_profile_id = '') {
		$profile = is_array($profile) ? $profile : array();
		$sender_id = isset($profile['sender_id']) ? sanitize_text_field((string) $profile['sender_id']) : '';
		if ($sender_id === '' && isset($profile['id'])) {
			$sender_id = sanitize_text_field((string) $profile['id']);
		}
		if ($sender_id === '') {
			return array();
		}

		$profile_id = isset($profile['profile_id']) ? sanitize_key((string) $profile['profile_id']) : '';
		if ($profile_id === '') {
			$profile_id = $fallback_profile_id !== '' ? sanitize_key((string) $fallback_profile_id) : $this->build_sender_profile_id($sender_id);
		}

		$name = isset($profile['name']) ? sanitize_text_field((string) $profile['name']) : '';
		$company = isset($profile['company']) ? sanitize_text_field((string) $profile['company']) : '';
		$address1 = isset($profile['address1']) ? sanitize_text_field((string) $profile['address1']) : '';
		$address2 = isset($profile['address2']) ? sanitize_text_field((string) $profile['address2']) : '';
		$postcode = isset($profile['postcode']) ? sanitize_text_field((string) $profile['postcode']) : '';
		$city = isset($profile['city']) ? sanitize_text_field((string) $profile['city']) : '';
		$country = isset($profile['country']) ? sanitize_text_field((string) $profile['country']) : '';
		$display_name = $name !== '' ? $name : ($company !== '' ? $company : $sender_id);

		return array(
			'profile_id' => $profile_id,
			'name' => $display_name,
			'sender_id' => $sender_id,
			'sender_entity_id' => isset($profile['sender_entity_id']) ? sanitize_text_field((string) $profile['sender_entity_id']) : '',
			'company' => $company,
			'address1' => $address1,
			'address2' => $address2,
			'postcode' => $postcode,
			'city' => $city,
			'country' => $country,
			'email' => isset($profile['email']) ? sanitize_email((string) $profile['email']) : '',
			'phone' => isset($profile['phone']) ? sanitize_text_field((string) $profile['phone']) : '',
			'default_printer_id' => isset($profile['default_printer_id']) ? sanitize_text_field((string) $profile['default_printer_id']) : '',
			'active' => array_key_exists('active', $profile) ? (int) !empty($profile['active']) : 1,
			'use_as_pickup_address' => !empty($profile['use_as_pickup_address']) ? 1 : 0,
			'use_as_return_address' => !empty($profile['use_as_return_address']) ? 1 : 0,
			'address_line' => trim($address1 . ($address2 !== '' ? ', ' . $address2 : '')),
			'postal_line' => trim($postcode . ' ' . $city),
			'label' => trim($display_name . ' (' . $sender_id . ')'),
		);
	}

	private function get_sender_profiles_from_settings($settings = null) {
		$settings = is_array($settings) ? $settings : $this->get_settings();
		$warehouse_profiles = isset($settings['warehouse_profiles']) && is_array($settings['warehouse_profiles']) ? $settings['warehouse_profiles'] : array();
		$profiles = isset($warehouse_profiles['profiles']) && is_array($warehouse_profiles['profiles']) ? $warehouse_profiles['profiles'] : array();
		$normalized_profiles = array();
		$seen_sender_ids = array();

		foreach ($profiles as $profile) {
			$normalized = $this->normalize_sender_profile_row($profile);
			if (empty($normalized) || empty($normalized['active'])) {
				continue;
			}
			if (isset($seen_sender_ids[$normalized['sender_id']])) {
				continue;
			}
			$seen_sender_ids[$normalized['sender_id']] = true;
			$normalized_profiles[] = $normalized;
		}

		$fallback_sender_id = isset($settings['sender_id']) ? sanitize_text_field((string) $settings['sender_id']) : '';
		if ($fallback_sender_id !== '' && !isset($seen_sender_ids[$fallback_sender_id])) {
			$normalized_profiles[] = $this->normalize_sender_profile_row(array(
				'profile_id' => 'default_sender',
				'name' => 'Default sender',
				'sender_id' => $fallback_sender_id,
				'active' => 1,
			));
		}

		$default_profile_id = isset($warehouse_profiles['default_profile_id']) ? sanitize_key((string) $warehouse_profiles['default_profile_id']) : '';
		if ($default_profile_id === '' && !empty($normalized_profiles)) {
			$default_profile_id = $normalized_profiles[0]['profile_id'];
		}

		$default_found = false;
		foreach ($normalized_profiles as &$profile) {
			$profile['is_default'] = $default_profile_id !== '' && $profile['profile_id'] === $default_profile_id;
			if (!empty($profile['is_default'])) {
				$default_found = true;
			}
		}
		unset($profile);

		if (!$default_found && !empty($normalized_profiles)) {
			$normalized_profiles[0]['is_default'] = true;
		}

		return $normalized_profiles;
	}

	private function get_default_sender_profile_id($settings = null) {
		$profiles = $this->get_sender_profiles_from_settings($settings);
		foreach ($profiles as $profile) {
			if (!empty($profile['is_default'])) {
				return $profile['profile_id'];
			}
		}
		return !empty($profiles[0]['profile_id']) ? $profiles[0]['profile_id'] : '';
	}

	private function schedule_booking_order_status_change_retry($order_id, $target_status, $next_attempt) {
		$order_id = absint($order_id);
		$target_status = $this->normalize_wc_order_status_slug($target_status);
		$next_attempt = absint($next_attempt);
		if ($order_id < 1 || $target_status === '' || $next_attempt < 1 || !function_exists('wp_schedule_single_event')) {
			return array(
				'scheduled' => false,
				'retry_at_gmt' => '',
			);
		}

		$delays = array(
			1 => 60,
			2 => 300,
			3 => 900,
			4 => 1800,
			5 => 3600,
		);
		$delay = isset($delays[$next_attempt]) ? $delays[$next_attempt] : 3600;
		$args = array($order_id, $target_status, $next_attempt);
		if (!wp_next_scheduled('lp_cargonizer_retry_booking_order_status_change', $args)) {
			wp_schedule_single_event(time() + $delay, 'lp_cargonizer_retry_booking_order_status_change', $args);
		}

		return array(
			'scheduled' => true,
			'retry_at_gmt' => gmdate('Y-m-d H:i:s', time() + $delay),
		);
	}

	private function apply_booking_order_status_after_created($order, $target_status = '', $retry_attempt = 0) {
		$default_status_change = $this->get_default_booking_state();
		$result = $default_status_change['status_change'];
		$target_status = $this->normalize_wc_order_status_slug($target_status);
		$retry_attempt = absint($retry_attempt);

		if ($target_status === '') {
			$result['message'] = 'Ingen automatisk statusendring er konfigurert.';
			return $result;
		}
		if (function_exists('wc_get_order_statuses')) {
			$runtime_statuses = wc_get_order_statuses();
			if (!isset($runtime_statuses['wc-' . $target_status])) {
				$result['enabled'] = true;
				$result['target_status'] = $target_status;
				$result['target_status_label'] = $this->get_wc_order_status_label($target_status);
				$result['message'] = 'Kunne ikke endre status: valgt ordrestatus finnes ikke lenger.';
				return $result;
			}
		}
		if (!$order || !is_a($order, 'WC_Order')) {
			$result['enabled'] = true;
			$result['target_status'] = $target_status;
			$result['target_status_label'] = $this->get_wc_order_status_label($target_status);
			$result['message'] = 'Kunne ikke endre status: ordreobjekt mangler.';
			return $result;
		}

		$order_id = $order->get_id();
		$current_status = $this->normalize_wc_order_status_slug($order->get_status());
		$result['enabled'] = true;
		$result['retry_attempt'] = $retry_attempt;
		$result['target_status'] = $target_status;
		$result['target_status_label'] = $this->get_wc_order_status_label($target_status);
		$result['previous_status'] = $current_status;
		$result['previous_status_label'] = $this->get_wc_order_status_label($current_status);
		$result['attempted_at_gmt'] = gmdate('Y-m-d H:i:s');

		if ($current_status === $target_status) {
			$result['success'] = true;
			$result['verified'] = true;
			$result['final_status'] = $current_status;
			$result['final_status_label'] = $this->get_wc_order_status_label($current_status);
			$result['message'] = 'Ordrestatus var allerede ' . $result['target_status_label'] . '.';
			$order->delete_meta_data('_lp_cargonizer_pending_status_after_booking');
			$order->update_meta_data('_lp_cargonizer_last_booking_status_change_verified_gmt', $result['attempted_at_gmt']);
			$order->save_meta_data();
			return $result;
		}

		$order->update_meta_data('_lp_cargonizer_pending_status_after_booking', $target_status);
		$order->update_meta_data('_lp_cargonizer_pending_status_after_booking_gmt', $result['attempted_at_gmt']);
		$order->save_meta_data();

		$status_note = 'Cargonizer booking opprettet: endrer ordrestatus automatisk til ' . $result['target_status_label'] . '.';
		try {
			$order->update_status($target_status, $status_note, true);
			$result['attempts'][] = array(
				'method' => 'update_status',
				'success' => true,
				'message' => 'update_status fullført.',
			);
		} catch (Exception $e) {
			$result['attempts'][] = array(
				'method' => 'update_status',
				'success' => false,
				'message' => $e->getMessage(),
			);
		}

		$fresh_order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
		$final_status = $fresh_order ? $this->normalize_wc_order_status_slug($fresh_order->get_status()) : '';

		if ($final_status !== $target_status && $fresh_order && is_a($fresh_order, 'WC_Order')) {
			try {
				$fresh_order->set_status($target_status);
				$fresh_order->save();
				$result['attempts'][] = array(
					'method' => 'set_status_save',
					'success' => true,
					'message' => 'set_status + save fullført.',
				);
			} catch (Exception $e) {
				$result['attempts'][] = array(
					'method' => 'set_status_save',
					'success' => false,
					'message' => $e->getMessage(),
				);
			}
			$fresh_order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
			$final_status = $fresh_order ? $this->normalize_wc_order_status_slug($fresh_order->get_status()) : '';
		}

		$result['final_status'] = $final_status;
		$result['final_status_label'] = $this->get_wc_order_status_label($final_status);
		$result['success'] = $final_status === $target_status;
		$result['verified'] = $result['success'];

		if (!empty($result['verified'])) {
			$result['message'] = 'Ordrestatus endret fra ' . $result['previous_status_label'] . ' til ' . $result['target_status_label'] . ' og verifisert.';
			if ($fresh_order && is_a($fresh_order, 'WC_Order')) {
				$fresh_order->delete_meta_data('_lp_cargonizer_pending_status_after_booking');
				$fresh_order->update_meta_data('_lp_cargonizer_last_booking_status_change_verified_gmt', gmdate('Y-m-d H:i:s'));
				$fresh_order->save_meta_data();
			}
			return $result;
		}

		$result['message'] = 'Ordrestatus ble ikke verifisert som ' . $result['target_status_label'] . '. Nåværende status: ' . ($result['final_status_label'] !== '' ? $result['final_status_label'] : 'ukjent') . '.';
		if ($retry_attempt < 5) {
			$retry = $this->schedule_booking_order_status_change_retry($order_id, $target_status, $retry_attempt + 1);
			$result['retry_scheduled'] = !empty($retry['scheduled']);
			$result['retry_at_gmt'] = isset($retry['retry_at_gmt']) ? $retry['retry_at_gmt'] : '';
			if (!empty($result['retry_scheduled'])) {
				$result['message'] .= ' Automatisk retry er planlagt.';
			}
		}

		return $result;
	}

	public function retry_booking_order_status_change($order_id, $target_status, $retry_attempt = 1) {
		$order_id = absint($order_id);
		if ($order_id < 1 || !function_exists('wc_get_order')) {
			return;
		}

		$order = wc_get_order($order_id);
		if (!$order || !is_a($order, 'WC_Order')) {
			return;
		}

		$status_change = $this->apply_booking_order_status_after_created($order, $target_status, $retry_attempt);
		$refreshed_order = wc_get_order($order_id);
		if (!$refreshed_order || !is_a($refreshed_order, 'WC_Order')) {
			return;
		}

		$booking_state = $this->load_order_booking_state($refreshed_order);
		if (!empty($booking_state['booked'])) {
			$booking_state['status_change'] = $status_change;
			$this->save_order_booking_state($refreshed_order, $booking_state);
			$refreshed_order = wc_get_order($order_id);
			if (!$refreshed_order || !is_a($refreshed_order, 'WC_Order')) {
				return;
			}
		}

		$retry_status = !empty($status_change['verified']) ? 'OK' : 'Feilet';
		$refreshed_order->add_order_note('Cargonizer ordrestatus retry: ' . $retry_status . ' - ' . (isset($status_change['message']) ? $status_change['message'] : 'ukjent resultat'));
	}

	private function get_current_user_default_printer_id() {
		$default_printer_id = get_user_meta(get_current_user_id(), 'lp_cargonizer_default_printer_id', true);
		return is_scalar($default_printer_id) ? sanitize_text_field((string) $default_printer_id) : '';
	}

	private function resolve_effective_printer_choice($posted_printer_choice) {
		$choice = sanitize_text_field((string) $posted_printer_choice);
		if ($choice === '__default__') {
			$choice = $this->get_current_user_default_printer_id();
		}
		return $choice;
	}

	private function sanitize_package_printer_assignments($assignments) {
		$clean = array();
		foreach ((array) $assignments as $package_index => $printer_id) {
			$index = absint($package_index);
			$clean_printer_id = sanitize_text_field((string) $printer_id);
			if ($clean_printer_id === '' || $clean_printer_id === '__default__') {
				continue;
			}
			$clean[$index] = $clean_printer_id;
		}
		return $clean;
	}

	private function get_booking_pickup_autoselect_mode() {
		$settings = $this->get_settings();
		$mode = isset($settings['booking_pickup_autoselect_mode']) ? sanitize_key((string) $settings['booking_pickup_autoselect_mode']) : 'nearest';
		return in_array($mode, array('nearest', 'none'), true) ? $mode : 'nearest';
	}

	private function get_printer_alias_map_from_settings() {
		$settings = $this->get_settings();
		$aliases = isset($settings['printer_aliases']) && is_array($settings['printer_aliases']) ? $settings['printer_aliases'] : array();
		$clean = array();
		foreach ($aliases as $printer_id => $alias) {
			$clean_id = sanitize_text_field((string) $printer_id);
			$clean_alias = sanitize_text_field((string) $alias);
			if ($clean_id === '' || $clean_alias === '') {
				continue;
			}
			$clean[$clean_id] = $clean_alias;
		}
		return $clean;
	}

	private function apply_printer_aliases($printers) {
		$printer_list = is_array($printers) ? $printers : array();
		$aliases = $this->get_printer_alias_map_from_settings();
		foreach ($printer_list as &$printer) {
			if (!is_array($printer)) {
				continue;
			}
			$printer_id = isset($printer['id']) ? sanitize_text_field((string) $printer['id']) : '';
			$base_label = isset($printer['label']) ? sanitize_text_field((string) $printer['label']) : $printer_id;
			if ($printer_id !== '' && isset($aliases[$printer_id])) {
				$printer['label'] = $aliases[$printer_id] . ' (' . $base_label . ')';
			}
		}
		unset($printer);
		return $printer_list;
	}

	private function get_printer_label_map() {
		$printer_result = $this->fetch_printers();
		if (empty($printer_result['success'])) {
			return array();
		}
		$labels = array();
		$aliased_printers = $this->apply_printer_aliases(isset($printer_result['printers']) && is_array($printer_result['printers']) ? $printer_result['printers'] : array());
		foreach ($aliased_printers as $printer) {
			if (!is_array($printer)) {
				continue;
			}
			$printer_id = isset($printer['id']) ? sanitize_text_field((string) $printer['id']) : '';
			if ($printer_id === '') {
				continue;
			}
			$labels[$printer_id] = isset($printer['label']) && $printer['label'] !== '' ? sanitize_text_field((string) $printer['label']) : $printer_id;
		}
		return $labels;
	}

	private function run_booking_label_prints($booking_state, $packages, $default_printer_id, $package_printer_assignments, $sender_id_override = '') {
		$default_booking_state = $this->get_default_booking_state();
		$print_state = isset($booking_state['print']) && is_array($booking_state['print']) ? $booking_state['print'] : $default_booking_state['print'];
		$piece_ids = isset($booking_state['piece_ids']) && is_array($booking_state['piece_ids']) ? array_values($booking_state['piece_ids']) : array();
		$piece_numbers = isset($booking_state['piece_numbers']) && is_array($booking_state['piece_numbers']) ? array_values($booking_state['piece_numbers']) : array();
		$package_count = count((array) $packages);
		$assignments = is_array($package_printer_assignments) ? $package_printer_assignments : array();
		$default_printer_id = sanitize_text_field((string) $default_printer_id);
		$attempted = $default_printer_id !== '' || !empty($assignments);

		if (!$attempted) {
			return $print_state;
		}

		$printer_labels = $this->get_printer_label_map();
		$groups = array();
		$pieces = array();
		$missing_piece_count = 0;
		$target_printer_ids = array();
		$consignment_id = isset($booking_state['consignment_id']) ? sanitize_text_field((string) $booking_state['consignment_id']) : '';
		$used_consignment_fallback = false;

		for ($index = 0; $index < $package_count; $index++) {
			$override_printer_id = isset($assignments[$index]) ? sanitize_text_field((string) $assignments[$index]) : '';
			$printer_id = $override_printer_id !== '' ? $override_printer_id : $default_printer_id;
			if ($printer_id === '') {
				continue;
			}
			$target_printer_ids[$printer_id] = true;

			$piece_id = isset($piece_ids[$index]) ? sanitize_text_field((string) $piece_ids[$index]) : '';
			$piece_number = isset($piece_numbers[$index]) ? sanitize_text_field((string) $piece_numbers[$index]) : '';
			$piece_row = array(
				'package_index' => $index,
				'colli' => $index + 1,
				'piece_id' => $piece_id,
				'piece_number' => $piece_number,
				'printer_id' => $printer_id,
				'printer_label' => isset($printer_labels[$printer_id]) ? $printer_labels[$printer_id] : $printer_id,
				'override' => $override_printer_id !== '',
				'success' => false,
				'message' => '',
			);

			if ($piece_id === '') {
				$piece_row['message'] = 'Mangler piece-id fra bookingrespons.';
				$missing_piece_count++;
				$pieces[] = $piece_row;
				continue;
			}

			if (!isset($groups[$printer_id])) {
				$groups[$printer_id] = array(
					'printer_id' => $printer_id,
					'printer_label' => isset($printer_labels[$printer_id]) ? $printer_labels[$printer_id] : $printer_id,
					'piece_ids' => array(),
					'consignment_ids' => array(),
					'package_indexes' => array(),
					'print_scope' => 'pieces',
					'success' => false,
					'message' => '',
					'http_status' => 0,
					'raw_response' => '',
				);
			}
			$groups[$printer_id]['piece_ids'][] = $piece_id;
			$groups[$printer_id]['package_indexes'][] = $index;
			$pieces[] = $piece_row;
		}

		$unique_target_printer_ids = array_keys($target_printer_ids);
		if (empty($groups) && $missing_piece_count > 0 && count($unique_target_printer_ids) === 1 && $consignment_id !== '') {
			$fallback_printer_id = (string) $unique_target_printer_ids[0];
			$fallback_package_indexes = array();
			foreach ($pieces as $piece_row) {
				if (isset($piece_row['package_index'])) {
					$fallback_package_indexes[] = (int) $piece_row['package_index'];
				}
			}
			$groups[$fallback_printer_id] = array(
				'printer_id' => $fallback_printer_id,
				'printer_label' => isset($printer_labels[$fallback_printer_id]) ? $printer_labels[$fallback_printer_id] : $fallback_printer_id,
				'piece_ids' => array(),
				'consignment_ids' => array($consignment_id),
				'package_indexes' => $fallback_package_indexes,
				'print_scope' => 'consignment',
				'success' => false,
				'message' => '',
				'http_status' => 0,
				'raw_response' => '',
			);
			foreach ($pieces as &$piece_row) {
				$piece_row['message'] = 'Printer hele sendingen fordi bookingresponsen manglet piece-id per kolli.';
			}
			unset($piece_row);
			$missing_piece_count = 0;
			$used_consignment_fallback = true;
		}

		$all_groups_success = true;
		$raw_responses = array();
		foreach ($groups as $printer_id => &$group) {
			$group_piece_ids = isset($group['piece_ids']) && is_array($group['piece_ids']) ? $group['piece_ids'] : array();
			$group_consignment_ids = isset($group['consignment_ids']) && is_array($group['consignment_ids']) ? $group['consignment_ids'] : array();
			$print_result = $this->print_labels_direct($printer_id, $group_consignment_ids, $group_piece_ids, $sender_id_override);
			$group['success'] = !empty($print_result['success']);
			$group['message'] = !empty($print_result['success'])
				? (!empty($group_consignment_ids) ? 'Labeler sendt til printer for hele sendingen.' : 'Label print queued.')
				: (isset($print_result['error']) ? (string) $print_result['error'] : 'Label print feilet.');
			$group['http_status'] = isset($print_result['http_status']) ? (int) $print_result['http_status'] : 0;
			$group['raw_response'] = isset($print_result['raw_response']) ? (string) $print_result['raw_response'] : '';
			if ($group['raw_response'] !== '') {
				$raw_responses[] = $group['raw_response'];
			}
			if (empty($group['success'])) {
				$all_groups_success = false;
			}
			$group_package_indexes = array();
			$raw_group_package_indexes = isset($group['package_indexes']) && is_array($group['package_indexes']) ? $group['package_indexes'] : array();
			foreach ($raw_group_package_indexes as $group_package_index) {
				$group_package_indexes[] = (int) $group_package_index;
			}
			foreach ($pieces as &$piece_row) {
				$piece_printed_by_group = (string) $piece_row['printer_id'] === (string) $printer_id && (
					$piece_row['piece_id'] !== ''
					|| (!empty($group_consignment_ids) && in_array((int) $piece_row['package_index'], $group_package_indexes, true))
				);
				if ($piece_printed_by_group) {
					$piece_row['success'] = $group['success'];
					$piece_row['message'] = $group['message'];
				}
			}
			unset($piece_row);
		}
		unset($group);

		$group_count = count($groups);
		$group_printer_ids = array_keys($groups);
		$first_group_printer_id = isset($group_printer_ids[0]) ? (string) $group_printer_ids[0] : '';
		$print_state['attempted'] = true;
		$print_state['printer_id'] = $group_count === 1 ? $first_group_printer_id : ($group_count > 1 ? 'multiple' : $default_printer_id);
		$print_state['printer_label'] = $group_count === 1
			? (isset($printer_labels[$print_state['printer_id']]) ? $printer_labels[$print_state['printer_id']] : $print_state['printer_id'])
			: ($group_count > 1 ? 'Flere printere' : (isset($printer_labels[$default_printer_id]) ? $printer_labels[$default_printer_id] : $default_printer_id));
		$print_state['groups'] = array_values($groups);
		$print_state['pieces'] = array_values($pieces);
		$print_state['success'] = $group_count > 0 && $all_groups_success && $missing_piece_count === 0;
		$print_state['message'] = $print_state['success']
			? ($used_consignment_fallback ? 'Labeler sendt til printer for hele sendingen.' : ($group_count > 1 ? 'Labeler sendt til ' . $group_count . ' printere.' : 'Labeler sendt til printer.'))
			: ($group_count === 0 ? 'Ingen labeler kunne sendes til printer.' : 'Én eller flere label-utskrifter feilet.');
		if ($missing_piece_count > 0) {
			$print_state['message'] .= ' Mangler piece-id for ' . $missing_piece_count . ' kolli.';
			if (count($unique_target_printer_ids) > 1) {
				$print_state['message'] .= ' Splittet utskrift til flere printere krever piece-id fra bookingresponsen.';
			} elseif ($consignment_id === '') {
				$print_state['message'] .= ' Mangler også consignment-id for fallback-utskrift av hele sendingen.';
			}
		}
		$print_state['raw_response'] = implode("\n---\n", array_map(function ($raw_response) {
			return substr((string) $raw_response, 0, 500);
		}, $raw_responses));

		return $print_state;
	}

	private function build_reprint_packages_from_booking_state($order, $booking_state) {
		$piece_ids = isset($booking_state['piece_ids']) && is_array($booking_state['piece_ids']) ? $booking_state['piece_ids'] : array();
		$piece_numbers = isset($booking_state['piece_numbers']) && is_array($booking_state['piece_numbers']) ? $booking_state['piece_numbers'] : array();
		$expected_count = max(count($piece_ids), count($piece_numbers), 1);
		$packages = array();

		if ($order && is_a($order, 'WC_Order') && isset($this->package_builder_service) && is_object($this->package_builder_service) && method_exists($this->package_builder_service, 'build_admin_prefill_packages_from_order')) {
			$built_packages = $this->package_builder_service->build_admin_prefill_packages_from_order($order);
			if (is_array($built_packages)) {
				$packages = $built_packages;
			}
		}

		if (count($packages) > $expected_count) {
			$packages = array_slice($packages, 0, $expected_count);
		}
		while (count($packages) < $expected_count) {
			$packages[] = array(
				'name' => '',
				'description' => '',
				'weight' => 0,
				'length' => 0,
				'width' => 0,
				'height' => 0,
			);
		}

		return $packages;
	}

		public function ajax_reprint_booking_labels() {
			if (!current_user_can('manage_woocommerce')) {
				wp_send_json_error(array('message' => 'Ingen tilgang.'), 403);
			}

		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
			if (!wp_verify_nonce($nonce, self::NONCE_ACTION_REPRINT_BOOKING_LABELS)) {
				wp_send_json_error(array('message' => 'Ugyldig nonce.'), 403);
			}

			$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
			$posted_printer_choice = isset($_POST['printer_id']) ? wp_unslash($_POST['printer_id']) : '';
			$result = $this->get_operations_facade()->reprint_cargonizer_labels($order_id, $posted_printer_choice, $this->operations_get_current_wordpress_actor_context());
			if (is_wp_error($result)) {
				$error_data = $result->get_error_data();
				$status = is_array($error_data) && isset($error_data['http_status']) ? (int) $error_data['http_status'] : 400;
				$payload = is_array($error_data) && isset($error_data['payload']) && is_array($error_data['payload'])
					? $error_data['payload']
					: array('message' => $result->get_error_message());
				wp_send_json_error($payload, $status);
			}

			wp_send_json_success($result);
		}

		public function operations_reprint_cargonizer_labels($order_id, $printer_choice, $actor_context = array()) {
			$order_id = absint($order_id);
			$order = $order_id ? wc_get_order($order_id) : false;
			if (!$order || !is_a($order, 'WC_Order')) {
				return $this->operations_error('lp_cargonizer_order_not_found', 'Ordre ikke funnet.', 404);
			}

			$booking_state = $this->load_order_booking_state($order);
			if (empty($booking_state['booked'])) {
				return $this->operations_error('lp_cargonizer_reprint_missing_booking', 'Ordren har ingen lagret Cargonizer-booking å skrive ut på nytt.', 400);
			}

			$method_key = isset($booking_state['method_key']) ? sanitize_text_field((string) $booking_state['method_key']) : '';
			if ($method_key === self::MANUAL_NORGESPAKKE_KEY) {
				return $this->operations_error('lp_cargonizer_reprint_manual_norgespakke', 'Bruk Posten/Norgespakke reprint for denne bookingen.', 400);
			}

		$consignment_id = isset($booking_state['consignment_id']) ? sanitize_text_field((string) $booking_state['consignment_id']) : '';
		$piece_ids = isset($booking_state['piece_ids']) && is_array($booking_state['piece_ids'])
			? array_values(array_filter(array_map('sanitize_text_field', array_map('strval', $booking_state['piece_ids'])), 'strlen'))
				: array();
			if ($consignment_id === '' && empty($piece_ids)) {
				return $this->operations_error('lp_cargonizer_reprint_missing_ids', 'Mangler consignment-id eller piece-id fra bookingen.', 400);
			}

			$printer_id = $this->resolve_effective_printer_choice($printer_choice);
			if ($printer_id === '') {
				return $this->operations_error('lp_cargonizer_reprint_printer_missing', 'Velg en DirectPrint-printer.', 400);
			}

			$packages = $this->build_reprint_packages_from_booking_state($order, $booking_state);
			$sender_id_override = isset($booking_state['sender_id']) ? sanitize_text_field((string) $booking_state['sender_id']) : '';
			$booking_state['print'] = $this->run_booking_label_prints($booking_state, $packages, $printer_id, array(), $sender_id_override);
			$booking_state['last_reprint_actor'] = $this->normalize_operations_actor_context($actor_context);
			$this->save_order_booking_state($order, $booking_state);

		$print_state = isset($booking_state['print']) && is_array($booking_state['print']) ? $booking_state['print'] : array();
		$message = isset($print_state['message']) && (string) $print_state['message'] !== '' ? (string) $print_state['message'] : 'Labeler sendt til printer.';
		$printer_label = isset($print_state['printer_label']) ? sanitize_text_field((string) $print_state['printer_label']) : $printer_id;
			$order->add_order_note('Cargonizer reprint: ' . (!empty($print_state['success']) ? 'OK' : 'Feilet') . ' - ' . $message . ' Printer: ' . $printer_label);

			if (empty($print_state['success'])) {
				return $this->operations_error('lp_cargonizer_reprint_failed', $message, 400, array(
					'message' => $message,
					'print' => $print_state,
				));
			}

			return array(
				'message' => $message,
				'print' => $print_state,
				'label_state' => $this->derive_generic_label_state($print_state),
			);
		}

	private function sanitize_posted_packages($packages) {
		$clean_packages = array();
		foreach ($packages as $package) {
			$package_text = $this->sanitize_package_display_text($package);
			$clean_packages[] = array(
				'name' => $package_text,
				'description' => $package_text,
				'weight' => isset($package['weight']) ? (float) $package['weight'] : 0,
				'length' => isset($package['length']) ? (float) $package['length'] : 0,
				'width' => isset($package['width']) ? (float) $package['width'] : 0,
				'height' => isset($package['height']) ? (float) $package['height'] : 0,
			);
		}
		return $clean_packages;
	}

	private function sanitize_package_display_text($package) {
		if (!is_array($package)) {
			return '';
		}

		$name = isset($package['name']) ? sanitize_text_field((string) $package['name']) : '';
		if ($name !== '') {
			return $name;
		}

		return isset($package['description']) ? sanitize_text_field((string) $package['description']) : '';
	}

	private function build_booking_recipient_from_request($order, $method_payload = array()) {
		$recipient_country = $this->resolve_recipient_country_for_context($order, $method_payload);
		$recipient = array(
			'name' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
			'address_1' => $order->get_shipping_address_1(),
			'address_2' => $order->get_shipping_address_2(),
			'postcode' => $order->get_shipping_postcode(),
			'city' => $order->get_shipping_city(),
			'country' => isset($recipient_country['normalized']) ? $recipient_country['normalized'] : '',
			'email' => $order->get_billing_email(),
			'phone' => $this->get_order_recipient_phone_for_api($order),
		);
		if ($recipient['name'] === '') {
			$recipient['name'] = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
		}

		$posted_fields = array(
			'name' => array('recipient_name', 'text'),
			'address_1' => array('recipient_address_1', 'text'),
			'address_2' => array('recipient_address_2', 'text'),
			'postcode' => array('recipient_postcode', 'text'),
			'city' => array('recipient_city', 'text'),
			'country' => array('recipient_country', 'country'),
			'email' => array('recipient_email', 'email'),
			'phone' => array('recipient_phone', 'text'),
		);

		foreach ($posted_fields as $recipient_key => $field) {
			$post_key = $field[0];
			if (!isset($_POST[$post_key]) || is_array($_POST[$post_key])) {
				continue;
			}

			$value = wp_unslash($_POST[$post_key]);
			$value = $field[1] === 'email'
				? sanitize_email((string) $value)
				: sanitize_text_field((string) $value);
			if ($field[1] === 'country') {
				$value = strtoupper($value);
			}
			if ($value !== '') {
				$recipient[$recipient_key] = $value;
			}
		}

		return $recipient;
	}

	private function sanitize_posted_method_payload($method) {
		$agreement_id = '';
		if (isset($method['agreement_id'])) {
			$agreement_id = sanitize_text_field((string) $method['agreement_id']);
		}
		if ($agreement_id === '' && isset($method['transport_agreement_id'])) {
			$agreement_id = sanitize_text_field((string) $method['transport_agreement_id']);
		}

		$selected_service_ids = array();
		if (isset($method['selected_service_ids']) && is_array($method['selected_service_ids'])) {
			foreach ($method['selected_service_ids'] as $selected_service_id) {
				$clean_service_id = sanitize_text_field((string) $selected_service_id);
				if ($clean_service_id !== '') {
					$selected_service_ids[] = $clean_service_id;
				}
			}
		}

		$servicepartner_selected_option = array();
		if (isset($method['servicepartner_selected_option']) && is_array($method['servicepartner_selected_option'])) {
			$servicepartner_selected_option = array(
				'value' => isset($method['servicepartner_selected_option']['value']) ? sanitize_text_field((string) $method['servicepartner_selected_option']['value']) : '',
				'label' => isset($method['servicepartner_selected_option']['label']) ? sanitize_text_field((string) $method['servicepartner_selected_option']['label']) : '',
				'customer_number' => isset($method['servicepartner_selected_option']['customer_number']) ? sanitize_text_field((string) $method['servicepartner_selected_option']['customer_number']) : '',
				'postcode' => isset($method['servicepartner_selected_option']['postcode']) ? sanitize_text_field((string) $method['servicepartner_selected_option']['postcode']) : '',
				'city' => isset($method['servicepartner_selected_option']['city']) ? sanitize_text_field((string) $method['servicepartner_selected_option']['city']) : '',
				'country' => isset($method['servicepartner_selected_option']['country']) ? sanitize_text_field((string) $method['servicepartner_selected_option']['country']) : '',
				'address1' => isset($method['servicepartner_selected_option']['address1']) ? sanitize_text_field((string) $method['servicepartner_selected_option']['address1']) : '',
				'address2' => isset($method['servicepartner_selected_option']['address2']) ? sanitize_text_field((string) $method['servicepartner_selected_option']['address2']) : '',
				'name' => isset($method['servicepartner_selected_option']['name']) ? sanitize_text_field((string) $method['servicepartner_selected_option']['name']) : '',
				'raw' => isset($method['servicepartner_selected_option']['raw']) && is_array($method['servicepartner_selected_option']['raw']) ? $method['servicepartner_selected_option']['raw'] : array(),
			);
		}

		$payload = array(
			'key' => isset($method['key']) ? sanitize_text_field($method['key']) : '',
			'sender_profile_id' => isset($method['sender_profile_id']) ? sanitize_key((string) $method['sender_profile_id']) : '',
			'sender_profile_name' => isset($method['sender_profile_name']) ? sanitize_text_field((string) $method['sender_profile_name']) : '',
			'sender_id' => isset($method['sender_id']) ? sanitize_text_field((string) $method['sender_id']) : '',
			'sender_entity_id' => isset($method['sender_entity_id']) ? sanitize_text_field((string) $method['sender_entity_id']) : '',
			'agreement_id' => $agreement_id,
			'agreement_name' => isset($method['agreement_name']) ? sanitize_text_field($method['agreement_name']) : '',
			'agreement_description' => isset($method['agreement_description']) ? sanitize_text_field($method['agreement_description']) : '',
			'agreement_number' => isset($method['agreement_number']) ? sanitize_text_field($method['agreement_number']) : '',
			'carrier_id' => isset($method['carrier_id']) ? sanitize_text_field($method['carrier_id']) : '',
			'carrier_name' => isset($method['carrier_name']) ? sanitize_text_field($method['carrier_name']) : '',
			'product_id' => isset($method['product_id']) ? sanitize_text_field($method['product_id']) : '',
			'product_name' => isset($method['product_name']) ? sanitize_text_field($method['product_name']) : '',
			'delivery_to_pickup_point' => !empty($method['delivery_to_pickup_point']),
			'delivery_to_home' => !empty($method['delivery_to_home']),
			'servicepartner' => isset($method['servicepartner']) ? sanitize_text_field($method['servicepartner']) : '',
			'servicepartner_customer_number' => isset($method['servicepartner_customer_number']) ? sanitize_text_field($method['servicepartner_customer_number']) : '',
			'servicepartner_selection_source' => isset($method['servicepartner_selection_source']) ? sanitize_text_field($method['servicepartner_selection_source']) : '',
			'servicepartner_user_selected' => !empty($method['servicepartner_user_selected']),
			'servicepartner_selected_option' => $servicepartner_selected_option,
			'use_sms_service' => !empty($method['use_sms_service']),
			'sms_service_id' => isset($method['sms_service_id']) ? sanitize_text_field($method['sms_service_id']) : '',
			'sms_service_name' => isset($method['sms_service_name']) ? sanitize_text_field($method['sms_service_name']) : '',
			'selected_service_ids' => array_values(array_unique($selected_service_ids)),
			'is_manual' => !empty($method['is_manual']),
			'is_manual_norgespakke' => !empty($method['is_manual_norgespakke']),
			'services' => isset($method['services']) && is_array($method['services']) ? $method['services'] : array(),
		);

		if ($payload['key'] === '') {
			$payload['key'] = implode('|', array($payload['agreement_id'], $payload['product_id']));
		}
		$payload['is_manual_norgespakke'] = $this->is_manual_norgespakke_method($payload);
		$payload['services'] = $this->filter_services_by_warehouse_availability($payload['key'], $payload['services']);
		$payload['selected_service_ids'] = $this->filter_selected_service_ids_by_warehouse_availability($payload['key'], $payload['selected_service_ids'], $payload['services']);

		return $payload;
	}

	private function should_attempt_servicepartner_autoselection($method_payload) {
		if (!is_array($method_payload)) {
			return false;
		}
		if ($this->get_booking_pickup_autoselect_mode() === 'none') {
			return false;
		}
		$is_pickup_like = $this->is_method_explicitly_pickup_point($method_payload);
		$is_home_delivery = $this->is_method_explicitly_home_delivery($method_payload);
		return $is_pickup_like && !$is_home_delivery;
	}

	private function apply_servicepartner_resolution_to_method_payload($method_payload, $selection) {
		if (!is_array($method_payload)) {
			$method_payload = array();
		}
		$method_payload['servicepartner'] = isset($selection['servicepartner']) ? sanitize_text_field((string) $selection['servicepartner']) : '';
		$method_payload['servicepartner_customer_number'] = isset($selection['servicepartner_customer_number']) ? sanitize_text_field((string) $selection['servicepartner_customer_number']) : '';
		$method_payload['servicepartner_selection_source'] = isset($selection['servicepartner_selection_source']) ? sanitize_text_field((string) $selection['servicepartner_selection_source']) : 'none';
		$method_payload['servicepartner_auto_selected'] = !empty($selection['servicepartner_auto_selected']);
		$method_payload['servicepartner_selected_option'] = isset($selection['selected_option']) && is_array($selection['selected_option']) ? $selection['selected_option'] : array();
		$method_payload['servicepartner_options'] = isset($selection['servicepartner_options']) && is_array($selection['servicepartner_options']) ? $selection['servicepartner_options'] : array();
		return $method_payload;
	}

	private function resolve_recipient_country_for_context($order = null, $context = array()) {
		$context = is_array($context) ? $context : array();
		$candidates = array();

		if ($order && is_a($order, 'WC_Order')) {
			$candidates[] = array('source' => 'shipping_country', 'value' => $order->get_shipping_country());
			$candidates[] = array('source' => 'billing_country', 'value' => $order->get_billing_country());
		}

		$candidates[] = array('source' => 'method_country', 'value' => isset($context['country']) ? $context['country'] : '');
		$candidates[] = array('source' => 'destination_country', 'value' => isset($context['destination_country']) ? $context['destination_country'] : '');

		$first_raw = '';
		foreach ($candidates as $candidate) {
			$raw_value = sanitize_text_field((string) $candidate['value']);
			if ($raw_value === '') {
				continue;
			}
			if ($first_raw === '') {
				$first_raw = $raw_value;
			}
			$normalized = $this->sanitize_country_code($raw_value);
			if ($normalized !== '') {
				return array(
					'raw' => $raw_value,
					'normalized' => $normalized,
					'source' => $candidate['source'],
				);
			}
		}

		return array(
			'raw' => $first_raw,
			'normalized' => '',
			'source' => '',
		);
	}

	private function resolve_booking_estimated_shipping_price($booking_result, $recipient, $packages, $method_payload) {
		$selection = array(
			'estimated_shipping_price' => 'ikke tilgjengelig',
			'estimated_shipping_price_source' => 'missing',
		);

		if (isset($booking_result['gross_cost']) && (string) $booking_result['gross_cost'] !== '') {
			$selection['estimated_shipping_price'] = (string) $booking_result['gross_cost'];
			$selection['estimated_shipping_price_source'] = 'gross_cost';
			return $selection;
		}

		if (isset($booking_result['net_cost']) && (string) $booking_result['net_cost'] !== '') {
			$selection['estimated_shipping_price'] = (string) $booking_result['net_cost'];
			$selection['estimated_shipping_price_source'] = 'net_cost';
			return $selection;
		}

		$fallback_price = $this->fetch_booking_estimate_fallback_price($recipient, $packages, $method_payload);
		if (isset($fallback_price['gross_amount']) && $fallback_price['gross_amount'] !== '') {
			$selection['estimated_shipping_price'] = $fallback_price['gross_amount'];
			$selection['estimated_shipping_price_source'] = 'estimate_gross_amount';
		} elseif (isset($fallback_price['net_amount']) && $fallback_price['net_amount'] !== '') {
			$selection['estimated_shipping_price'] = $fallback_price['net_amount'];
			$selection['estimated_shipping_price_source'] = 'estimate_net_amount';
		} elseif (isset($fallback_price['estimated_cost']) && $fallback_price['estimated_cost'] !== '') {
			$selection['estimated_shipping_price'] = $fallback_price['estimated_cost'];
			$selection['estimated_shipping_price_source'] = 'estimate_estimated_cost';
		}

		return $selection;
	}

	private function fetch_booking_estimate_fallback_price($recipient, $packages, $method_payload) {
		$empty_result = array(
			'estimated_cost' => '',
			'gross_amount' => '',
			'net_amount' => '',
			'fallback_price' => '',
		);

		$xml = $this->build_estimate_request_xml(array(
			'recipient' => is_array($recipient) ? $recipient : array(),
			'packages' => is_array($packages) ? $packages : array(),
			'servicepartner' => isset($method_payload['servicepartner']) ? $method_payload['servicepartner'] : '',
			'servicepartner_customer_number' => isset($method_payload['servicepartner_customer_number']) ? $method_payload['servicepartner_customer_number'] : '',
			'servicepartner_selected_option' => isset($method_payload['servicepartner_selected_option']) && is_array($method_payload['servicepartner_selected_option']) ? $method_payload['servicepartner_selected_option'] : array(),
			'use_sms_service' => !empty($method_payload['use_sms_service']),
			'sms_service_id' => isset($method_payload['sms_service_id']) ? $method_payload['sms_service_id'] : '',
			'selected_service_ids' => isset($method_payload['selected_service_ids']) && is_array($method_payload['selected_service_ids']) ? $method_payload['selected_service_ids'] : array(),
		), $method_payload);

		if ($xml === '') {
			return $empty_result;
		}

		$response = wp_remote_post(LP_Cargonizer_Api_Service::build_endpoint_url('/consignment_costs.xml'), array(
			'timeout' => 40,
			'headers' => array_merge($this->get_auth_headers(isset($method_payload['sender_id_override']) ? $method_payload['sender_id_override'] : ''), array('Content-Type' => 'application/xml')),
			'body' => $xml,
		));

		if (is_wp_error($response)) {
			return $empty_result;
		}

		$status = wp_remote_retrieve_response_code($response);
		if ($status < 200 || $status >= 300) {
			return $empty_result;
		}

		$body = wp_remote_retrieve_body($response);
		return $this->parse_estimate_price_fields($body);
	}

	public function ajax_book_shipment() {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(array('message' => 'Ingen tilgang.'), 403);
		}

		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (!wp_verify_nonce($nonce, self::NONCE_ACTION_BOOK)) {
			wp_send_json_error(array(
				'message' => 'Ugyldig nonce.',
				'debug' => array(
					'received_nonce' => $nonce,
					'expected_action' => self::NONCE_ACTION_BOOK,
					'has_nonce' => $nonce !== '',
				),
			), 403);
		}

		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		$order = $order_id ? wc_get_order($order_id) : false;
		if (!$order) {
			wp_send_json_error(array('message' => 'Ordre ikke funnet.'), 404);
		}

		$existing_booking_state = $this->load_order_booking_state($order);

		$packages = isset($_POST['packages']) && is_array($_POST['packages']) ? wp_unslash($_POST['packages']) : array();
		$methods = isset($_POST['methods']) && is_array($_POST['methods']) ? wp_unslash($_POST['methods']) : array();
		$package_printer_assignments = isset($_POST['package_printer_assignments']) && is_array($_POST['package_printer_assignments'])
			? $this->sanitize_package_printer_assignments(wp_unslash($_POST['package_printer_assignments']))
			: array();
		$enabled_map = $this->get_enabled_method_map();

		if (empty($enabled_map)) {
			wp_send_json_error(array('message' => 'Ingen fraktmetoder er aktivert i Cargonizer-innstillingene.'), 400);
		}
		if (empty($packages)) {
			wp_send_json_error(array('message' => 'Mangler kolli.'), 400);
		}
		if (count($methods) !== 1) {
			wp_send_json_error(array('message' => 'Velg nøyaktig én fraktmetode for booking.'), 400);
		}

		$clean_packages = $this->sanitize_posted_packages($packages);
		$warehouse_profile = $this->resolve_selected_warehouse_profile(isset($_POST['warehouse_profile_id']) ? sanitize_key(wp_unslash($_POST['warehouse_profile_id'])) : '');
		$method_payload = $this->sanitize_posted_method_payload($methods[0]);
		$notify_email_to_consignee = isset($_POST['notify_email_to_consignee']) ? (bool) $this->sanitize_checkbox_value(wp_unslash($_POST['notify_email_to_consignee'])) : false;
		$method_key = $this->normalize_method_key_for_sender_profile($method_payload, $warehouse_profile);
		$method_payload['key'] = $method_key;
		if (!isset($enabled_map[$method_key]) || !$this->method_matches_selected_sender_profile($method_payload, $warehouse_profile)) {
			wp_send_json_error(array('message' => 'Valgt fraktmetode er ikke aktivert for valgt senderadresse. Last fraktvalg på nytt eller hent/aktiver fraktmetoder i Cargonizer-innstillingene.'), 400);
		}

		$recipient = $this->build_booking_recipient_from_request($order, $method_payload);
		if ($recipient['country'] === '') {
			wp_send_json_error(array('message' => 'Ugyldig mottakerland. Land maa vaere en gyldig ISO-2-kode, for eksempel NO.'), 400);
		}
		if ($notify_email_to_consignee && trim((string) $recipient['email']) === '') {
			wp_send_json_error(array('message' => 'Mottaker mangler e-postadresse, saa e-postvarsling kan ikke brukes for denne bookingen.'), 400);
		}

		if ($this->is_manual_norgespakke_method($method_payload) && class_exists('LP_Cargonizer_Posten_Label_Automation')) {
			$queue_result = LP_Cargonizer_Posten_Label_Automation::instance()->queue_from_admin_request($order, $method_payload, $clean_packages, array(
				'warehouse_profile_id' => isset($warehouse_profile['profile_id']) ? sanitize_key((string) $warehouse_profile['profile_id']) : '',
				'notify_email_to_consignee' => $notify_email_to_consignee,
				'source' => 'legacy_booking_ajax',
				'recipient' => $recipient,
			));
			if (is_wp_error($queue_result)) {
				wp_send_json_error(array('message' => $queue_result->get_error_message()), 400);
			}

			wp_send_json_success($queue_result);
		}

		if ($method_payload['sms_service_id'] === '') {
			$sms_service = $this->find_sms_service_for_method($methods[0]);
			$method_payload['sms_service_id'] = $sms_service['service_id'];
			$method_payload['sms_service_name'] = $sms_service['service_name'];
		}

		$sender_id_override = !empty($method_payload['sender_id']) ? (string) $method_payload['sender_id'] : (isset($warehouse_profile['sender_id']) ? (string) $warehouse_profile['sender_id'] : '');
		$method_payload['sender_id_override'] = $sender_id_override;
		$method_payload['warehouse_profile_id'] = isset($warehouse_profile['profile_id']) ? sanitize_key((string) $warehouse_profile['profile_id']) : '';
		$method_payload['warehouse_profile_name'] = isset($warehouse_profile['name']) ? sanitize_text_field((string) $warehouse_profile['name']) : '';
		$servicepartner_selection_debug = array(
			'servicepartner_selection_source' => $method_payload['servicepartner'] !== '' ? 'manual' : 'none',
			'servicepartner_auto_selected' => false,
			'selected_servicepartner' => $method_payload['servicepartner'],
			'selected_servicepartner_customer_number' => isset($method_payload['servicepartner_customer_number']) ? $method_payload['servicepartner_customer_number'] : '',
			'auto_selection_reason' => $method_payload['servicepartner'] !== '' ? 'manual_selection_present' : 'not_attempted',
		);
		if ($this->should_attempt_servicepartner_autoselection($method_payload) && $method_payload['servicepartner'] === '') {
			$resolved_selection = $this->resolve_default_servicepartner_selection($method_payload, $recipient);
			$method_payload = $this->apply_servicepartner_resolution_to_method_payload($method_payload, $resolved_selection);
			$servicepartner_selection_debug = array(
				'servicepartner_selection_source' => isset($resolved_selection['servicepartner_selection_source']) ? $resolved_selection['servicepartner_selection_source'] : 'none',
				'servicepartner_auto_selected' => !empty($resolved_selection['servicepartner_auto_selected']),
				'selected_servicepartner' => isset($resolved_selection['servicepartner']) ? $resolved_selection['servicepartner'] : '',
				'selected_servicepartner_customer_number' => isset($resolved_selection['servicepartner_customer_number']) ? $resolved_selection['servicepartner_customer_number'] : '',
				'auto_selection_reason' => isset($resolved_selection['auto_selection_reason']) ? $resolved_selection['auto_selection_reason'] : '',
			);
		}

		$xml = $this->build_booking_consignment_xml(array(
			'recipient' => $recipient,
			'packages' => $clean_packages,
			'order_number' => $order->get_order_number(),
			'servicepartner' => $method_payload['servicepartner'],
			'servicepartner_selected_option' => isset($method_payload['servicepartner_selected_option']) && is_array($method_payload['servicepartner_selected_option']) ? $method_payload['servicepartner_selected_option'] : array(),
			'use_sms_service' => $method_payload['use_sms_service'],
			'sms_service_id' => $method_payload['sms_service_id'],
			'selected_service_ids' => isset($method_payload['selected_service_ids']) && is_array($method_payload['selected_service_ids']) ? $method_payload['selected_service_ids'] : array(),
			'notify_email_to_consignee' => $notify_email_to_consignee,
		), $method_payload, array(
			'transfer' => true,
			'booking_request' => false,
		));

		if ($xml === '') {
			$xml_build_error = $this->get_last_xml_build_error();
			wp_send_json_error(array(
				'message' => isset($xml_build_error['message']) && $xml_build_error['message'] !== '' ? $xml_build_error['message'] : 'Kunne ikke bygge booking-XML.',
				'debug' => isset($xml_build_error['context']) ? $xml_build_error['context'] : array(),
			), 500);
		}

		$booking_result = $this->create_booking_consignment($xml, $sender_id_override);
		if (empty($booking_result['success'])) {
			$combined_error_text = strtolower(trim(
				(isset($booking_result['error_code']) ? $booking_result['error_code'] : '') . ' ' .
				(isset($booking_result['error_type']) ? $booking_result['error_type'] : '') . ' ' .
				(isset($booking_result['parsed_error_message']) ? $booking_result['parsed_error_message'] : '') . ' ' .
				(isset($booking_result['error_details']) ? $booking_result['error_details'] : '') . ' ' .
				(isset($booking_result['error']) ? $booking_result['error'] : '')
			));
			$requires_servicepartner = $this->estimate_requires_servicepartner($combined_error_text);
			$requires_sms_service = $this->estimate_requires_sms_service($combined_error_text);
			$servicepartner_fetch = array();
			$servicepartner_options = array();
			if ($requires_servicepartner) {
				$servicepartner_lookup_method = $method_payload;
				$servicepartner_lookup_method['country'] = isset($recipient['country']) ? $recipient['country'] : '';
				$servicepartner_lookup_method['postcode'] = isset($recipient['postcode']) ? $recipient['postcode'] : '';
				$servicepartner_fetch = $this->fetch_servicepartner_options($servicepartner_lookup_method);
				$servicepartner_options = isset($servicepartner_fetch['options']) && is_array($servicepartner_fetch['options']) ? $servicepartner_fetch['options'] : array();
			}

			wp_send_json_error(array(
				'message' => isset($booking_result['error']) && $booking_result['error'] !== '' ? $booking_result['error'] : 'Booking feilet.',
				'error_code' => isset($booking_result['error_code']) ? $booking_result['error_code'] : '',
				'error_type' => isset($booking_result['error_type']) ? $booking_result['error_type'] : '',
				'error_details' => isset($booking_result['error_details']) ? $booking_result['error_details'] : '',
				'parsed_error_message' => isset($booking_result['parsed_error_message']) ? $booking_result['parsed_error_message'] : '',
				'requires_servicepartner' => $requires_servicepartner,
				'requires_sms_service' => $requires_sms_service,
				'servicepartner_options' => $servicepartner_options,
				'servicepartner_fetch' => $servicepartner_fetch,
				'servicepartner_selection_source' => $servicepartner_selection_debug['servicepartner_selection_source'],
				'servicepartner_auto_selected' => $servicepartner_selection_debug['servicepartner_auto_selected'],
				'selected_servicepartner' => $servicepartner_selection_debug['selected_servicepartner'],
				'selected_servicepartner_customer_number' => $servicepartner_selection_debug['selected_servicepartner_customer_number'],
				'auto_selection_reason' => $servicepartner_selection_debug['auto_selection_reason'],
			), 200);
		}

		$booking_state = $this->get_default_booking_state();
		$booking_state['booked'] = true;
		$booking_state['consignment_number'] = isset($booking_result['consignment_number']) ? (string) $booking_result['consignment_number'] : '';
		$booking_state['consignment_id'] = isset($booking_result['consignment_id']) ? (string) $booking_result['consignment_id'] : '';
		$booking_state['piece_numbers'] = isset($booking_result['piece_numbers']) && is_array($booking_result['piece_numbers']) ? $booking_result['piece_numbers'] : array();
		$booking_state['piece_ids'] = isset($booking_result['piece_ids']) && is_array($booking_result['piece_ids']) ? $booking_result['piece_ids'] : array();
		$booking_state['tracking_url'] = isset($booking_result['tracking_url']) ? (string) $booking_result['tracking_url'] : '';
		$booking_state['consignment_pdf_url'] = isset($booking_result['consignment_pdf_url']) ? (string) $booking_result['consignment_pdf_url'] : '';
		$booking_state['waybill_pdf_url'] = isset($booking_result['waybill_pdf_url']) ? (string) $booking_result['waybill_pdf_url'] : '';
		$booking_state['method_key'] = $method_payload['key'];
		$booking_state['agreement_id'] = $method_payload['agreement_id'];
		$booking_state['product_id'] = $method_payload['product_id'];
		$booking_state['sender_profile_id'] = isset($warehouse_profile['profile_id']) ? (string) $warehouse_profile['profile_id'] : '';
		$booking_state['sender_profile_name'] = isset($warehouse_profile['name']) ? (string) $warehouse_profile['name'] : '';
		$booking_state['sender_id'] = $sender_id_override;
		$booking_state['sender_entity_id'] = isset($warehouse_profile['sender_entity_id']) ? (string) $warehouse_profile['sender_entity_id'] : '';
		$sender_address_parts = array();
		if (!empty($warehouse_profile['address_line'])) {
			$sender_address_parts[] = (string) $warehouse_profile['address_line'];
		}
		if (!empty($warehouse_profile['postal_line'])) {
			$sender_address_parts[] = (string) $warehouse_profile['postal_line'];
		}
		if (!empty($warehouse_profile['country'])) {
			$sender_address_parts[] = (string) $warehouse_profile['country'];
		}
		$booking_state['sender_address'] = implode(', ', array_filter($sender_address_parts, 'strlen'));
		$booking_state['servicepartner'] = $method_payload['servicepartner'];
		$booking_state['servicepartner_customer_number'] = isset($method_payload['servicepartner_customer_number']) ? $method_payload['servicepartner_customer_number'] : '';
		$booking_state['servicepartner_selection_source'] = $servicepartner_selection_debug['servicepartner_selection_source'];
		$booking_state['servicepartner_auto_selected'] = $servicepartner_selection_debug['servicepartner_auto_selected'];
		$booking_state['auto_selection_reason'] = $servicepartner_selection_debug['auto_selection_reason'];
		$booking_state['sms_service_id'] = $method_payload['sms_service_id'];
		$booking_state['selected_service_ids'] = isset($method_payload['selected_service_ids']) && is_array($method_payload['selected_service_ids']) ? $method_payload['selected_service_ids'] : array();
		$booking_state['notify_email_to_consignee'] = $notify_email_to_consignee;
		$booking_state['created_at_gmt'] = gmdate('Y-m-d H:i:s');
		$current_user = wp_get_current_user();
		$booking_state['created_by_user_id'] = (string) get_current_user_id();
		$booking_state['created_by_user_login'] = $current_user && isset($current_user->user_login) ? (string) $current_user->user_login : '';
		$booking_state['created_by_display_name'] = $current_user && isset($current_user->display_name) ? (string) $current_user->display_name : '';
		$estimated_price_selection = $this->resolve_booking_estimated_shipping_price($booking_result, $recipient, $clean_packages, $method_payload);
		$booking_state['estimated_shipping_price'] = isset($estimated_price_selection['estimated_shipping_price']) ? (string) $estimated_price_selection['estimated_shipping_price'] : 'ikke tilgjengelig';
		$booking_state['estimated_shipping_price_source'] = isset($estimated_price_selection['estimated_shipping_price_source']) ? (string) $estimated_price_selection['estimated_shipping_price_source'] : 'missing';

		$posted_printer_choice = isset($_POST['printer_choice']) ? wp_unslash($_POST['printer_choice']) : '';
		$printer_id = $this->resolve_effective_printer_choice($posted_printer_choice);
		$booking_state['print'] = $this->run_booking_label_prints($booking_state, $clean_packages, $printer_id, $package_printer_assignments, $sender_id_override);
		$booking_state['status_change'] = $this->apply_booking_order_status_after_created($order, $this->get_booking_order_status_after_created_setting());

		$refreshed_order = function_exists('wc_get_order') ? wc_get_order($order->get_id()) : false;
		if ($refreshed_order && is_a($refreshed_order, 'WC_Order')) {
			$order = $refreshed_order;
		}

		$history = isset($existing_booking_state['history']) && is_array($existing_booking_state['history']) ? $existing_booking_state['history'] : array();
		if (!empty($existing_booking_state['booked'])) {
			$prior_snapshot = $this->strip_booking_history_for_snapshot($existing_booking_state);
			$last_history_snapshot = !empty($history) ? $this->strip_booking_history_for_snapshot($history[count($history) - 1]) : null;
			if ($last_history_snapshot !== $prior_snapshot) {
				$history[] = $prior_snapshot;
			}
		}
		$booking_state['history'] = $history;

		$this->save_order_booking_state($order, $booking_state);
		if (!empty($warehouse_profile)) {
			update_post_meta($order->get_id(), '_lp_cargonizer_warehouse_profile_id', isset($warehouse_profile['profile_id']) ? (string) $warehouse_profile['profile_id'] : '');
			update_post_meta($order->get_id(), '_lp_cargonizer_sender_id_used', isset($warehouse_profile['sender_id']) ? (string) $warehouse_profile['sender_id'] : '');
			update_post_meta($order->get_id(), '_lp_cargonizer_sender_entity_id_used', isset($warehouse_profile['sender_entity_id']) ? (string) $warehouse_profile['sender_entity_id'] : '');
			update_post_meta($order->get_id(), '_lp_cargonizer_sender_profile_name', isset($warehouse_profile['name']) ? (string) $warehouse_profile['name'] : '');
		}

		$creator_name = $booking_state['created_by_display_name'] !== '' ? $booking_state['created_by_display_name'] : $booking_state['created_by_user_login'];
		$tracking_link = $booking_state['tracking_url'] !== ''
			? '<a href="' . esc_url($booking_state['tracking_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($booking_state['tracking_url']) . '</a>'
			: 'ikke tilgjengelig';
		$piece_numbers = isset($booking_state['piece_numbers']) && is_array($booking_state['piece_numbers'])
			? array_values(array_filter(array_map('sanitize_text_field', array_map('strval', $booking_state['piece_numbers'])), 'strlen'))
			: array();
		$selected_service_ids = isset($booking_state['selected_service_ids']) && is_array($booking_state['selected_service_ids'])
			? array_values(array_filter(array_map('sanitize_text_field', array_map('strval', $booking_state['selected_service_ids'])), 'strlen'))
			: array();
		$service_labels_by_id = array();
		if (isset($method_payload['services']) && is_array($method_payload['services'])) {
			foreach ($method_payload['services'] as $service) {
				if (!is_array($service)) {
					continue;
				}
				$service_id = isset($service['service_id']) ? sanitize_text_field((string) $service['service_id']) : '';
				if ($service_id === '') {
					continue;
				}
				$service_name = isset($service['service_name']) ? sanitize_text_field((string) $service['service_name']) : '';
				$service_labels_by_id[$service_id] = $service_name !== '' ? $service_name . ' (' . $service_id . ')' : $service_id;
			}
		}
		$selected_service_labels = array();
		foreach ($selected_service_ids as $selected_service_id) {
			$selected_service_labels[] = isset($service_labels_by_id[$selected_service_id]) ? $service_labels_by_id[$selected_service_id] : $selected_service_id;
		}
		$order_note_lines = array(
			'Cargonizer booking opprettet',
			'Opprettet av: ' . ($creator_name !== '' ? $creator_name : 'ukjent bruker'),
			'Consignment: ' . ($booking_state['consignment_number'] !== '' ? $booking_state['consignment_number'] : 'ukjent'),
			'Sender: ' . ($booking_state['sender_profile_name'] !== '' ? esc_html($booking_state['sender_profile_name']) : 'ukjent') . ($booking_state['sender_id'] !== '' ? ' (' . esc_html($booking_state['sender_id']) . ')' : '') . ($booking_state['sender_address'] !== '' ? ' - ' . esc_html($booking_state['sender_address']) : ''),
			'Antall kolli: ' . count($clean_packages),
			'Piece numbers: ' . (!empty($piece_numbers) ? implode(', ', array_map('esc_html', $piece_numbers)) : '—'),
			'Fraktmetode: ' . $this->format_method_label($method_payload['agreement_name'], $method_payload['product_name'], $method_payload['carrier_name']),
			'Estimert fraktpris: ' . $booking_state['estimated_shipping_price'],
			'Tilleggstjenester: ' . (!empty($selected_service_labels) ? implode(', ', array_map('esc_html', $selected_service_labels)) : 'Ingen'),
			'E-postvarsling til mottaker: ' . (!empty($booking_state['notify_email_to_consignee']) ? 'Ja' : 'Nei'),
			'Tracking: ' . $tracking_link,
		);
		if (!empty($booking_state['servicepartner'])) {
			$servicepartner_line = 'Servicepartner: ' . esc_html($booking_state['servicepartner']);
			if (!empty($booking_state['servicepartner_customer_number'])) {
				$servicepartner_line .= ' / kundenummer: ' . esc_html($booking_state['servicepartner_customer_number']);
			}
			if (!empty($booking_state['servicepartner_selection_source'])) {
				$servicepartner_line .= ' (' . esc_html($booking_state['servicepartner_selection_source']) . ')';
			}
			$order_note_lines[] = $servicepartner_line;
		}
		if (!empty($booking_state['status_change']['enabled'])) {
			$status_change_status = !empty($booking_state['status_change']['verified']) ? 'OK' : 'Feilet';
			$status_change_message = isset($booking_state['status_change']['message']) ? trim((string) $booking_state['status_change']['message']) : '';
			if (!empty($booking_state['status_change']['retry_scheduled']) && !empty($booking_state['status_change']['retry_at_gmt'])) {
				$status_change_message .= ' Retry: ' . $booking_state['status_change']['retry_at_gmt'] . ' GMT.';
			}
			$order_note_lines[] = 'Ordrestatus etter booking: ' . $status_change_status . ($status_change_message !== '' ? ' - ' . esc_html($status_change_message) : '');
		}
		if (!empty($booking_state['print']['attempted'])) {
			$print_status = !empty($booking_state['print']['success']) ? 'OK' : 'Feilet';
			$print_message = isset($booking_state['print']['message']) ? trim((string) $booking_state['print']['message']) : '';
			if ($print_message !== '') {
				$print_status .= ' - ' . $print_message;
			}
			$order_note_lines[] = 'Utskrift: ' . esc_html($print_status);
			if (!empty($booking_state['print']['groups']) && is_array($booking_state['print']['groups'])) {
				foreach ($booking_state['print']['groups'] as $print_group) {
					if (!is_array($print_group)) {
						continue;
					}
					$group_status = !empty($print_group['success']) ? 'OK' : 'Feilet';
					$group_label = isset($print_group['printer_label']) && $print_group['printer_label'] !== '' ? $print_group['printer_label'] : (isset($print_group['printer_id']) ? $print_group['printer_id'] : 'ukjent printer');
					$group_scope = isset($print_group['print_scope']) && $print_group['print_scope'] === 'consignment' ? 'hele sendingen' : 'piece labels';
					$group_message = isset($print_group['message']) ? trim((string) $print_group['message']) : '';
					$order_note_lines[] = 'Printergruppe: ' . esc_html($group_label) . ' (' . esc_html($group_scope) . ') - ' . esc_html($group_status . ($group_message !== '' ? ' - ' . $group_message : ''));
				}
			}
			if (!empty($booking_state['print']['pieces']) && is_array($booking_state['print']['pieces'])) {
				foreach ($booking_state['print']['pieces'] as $print_piece) {
					if (!is_array($print_piece)) {
						continue;
					}
					$piece_status = !empty($print_piece['success']) ? 'OK' : 'Feilet';
					$piece_label = isset($print_piece['piece_number']) && $print_piece['piece_number'] !== '' ? $print_piece['piece_number'] : (isset($print_piece['piece_id']) && $print_piece['piece_id'] !== '' ? $print_piece['piece_id'] : '—');
					$piece_printer_label = isset($print_piece['printer_label']) && $print_piece['printer_label'] !== '' ? $print_piece['printer_label'] : (isset($print_piece['printer_id']) ? $print_piece['printer_id'] : 'ukjent printer');
					$piece_message = isset($print_piece['message']) ? trim((string) $print_piece['message']) : '';
					$order_note_lines[] = 'Kolli ' . esc_html(isset($print_piece['colli']) ? (string) $print_piece['colli'] : '?') . ': ' . esc_html($piece_label) . ' til ' . esc_html($piece_printer_label) . ' (' . esc_html($piece_status . ($piece_message !== '' ? ' - ' . $piece_message : '')) . ')';
				}
			}
		}
		$order->add_order_note(implode("\n", $order_note_lines));
		$customer_tracking_note = $this->build_customer_tracking_order_note_for_booking($booking_state, $method_payload);
		if ($customer_tracking_note !== '') {
			$order->add_order_note($customer_tracking_note, true, true);
		}
		$booking_count = $this->get_booking_count_from_state($booking_state);

		wp_send_json_success(array(
			'message' => 'Shipment booked successfully.',
			'booking' => $booking_state,
			'booking_count' => $booking_count,
			'has_previous_bookings' => $booking_count > 1,
		));
	}



	private function resolve_selected_warehouse_profile($requested_profile_id = '') {
		$settings = $this->get_settings();
		$profiles = $this->get_sender_profiles_from_settings($settings);
		$default_id = $this->get_default_sender_profile_id($settings);
		$requested_profile_id = sanitize_key((string) $requested_profile_id);
		foreach ($profiles as $profile) {
			$pid = isset($profile['profile_id']) ? sanitize_key((string) $profile['profile_id']) : '';
			if ($requested_profile_id !== '' && $pid === $requested_profile_id) {
				return $profile;
			}
			if ($requested_profile_id === '' && $default_id !== '' && $pid === $default_id) {
				return $profile;
			}
		}
		return !empty($profiles[0]) ? $profiles[0] : array();
	}

	private function method_matches_selected_sender_profile($method, $warehouse_profile) {
		$method = is_array($method) ? $method : array();
		if ($this->is_manual_norgespakke_method($method)) {
			return true;
		}

		$selected_profile_id = isset($warehouse_profile['profile_id']) ? sanitize_key((string) $warehouse_profile['profile_id']) : '';
		$selected_sender_id = isset($warehouse_profile['sender_id']) ? sanitize_text_field((string) $warehouse_profile['sender_id']) : '';
		$selected_sender_entity_id = isset($warehouse_profile['sender_entity_id']) ? sanitize_text_field((string) $warehouse_profile['sender_entity_id']) : '';
		$selected_aliases = array($selected_profile_id);
		if ($selected_sender_id !== '') {
			$selected_aliases[] = $selected_sender_id;
			$selected_aliases[] = $this->build_sender_profile_id($selected_sender_id);
		}
		if ($selected_sender_entity_id !== '') {
			$selected_aliases[] = $selected_sender_entity_id;
			$selected_aliases[] = $this->build_sender_profile_id($selected_sender_entity_id);
		}
		$selected_alias_map = array();
		foreach ($selected_aliases as $selected_alias) {
			$selected_alias = sanitize_key((string) $selected_alias);
			if ($selected_alias !== '') {
				$selected_alias_map[$selected_alias] = true;
			}
		}

		$method_profile_id = isset($method['sender_profile_id']) ? sanitize_key((string) $method['sender_profile_id']) : '';
		$method_sender_id = isset($method['sender_id']) ? sanitize_text_field((string) $method['sender_id']) : '';
		$method_sender_entity_id = isset($method['sender_entity_id']) ? sanitize_text_field((string) $method['sender_entity_id']) : '';
		if ($method_profile_id === '' && isset($method['warehouse_profile_id'])) {
			$method_profile_id = sanitize_key((string) $method['warehouse_profile_id']);
		}
		if ($method_profile_id === '' && $method_sender_id !== '') {
			$method_profile_id = $this->build_sender_profile_id($method_sender_id);
		}
		if ($method_profile_id === '' && isset($method['key']) && strpos((string) $method['key'], '::') !== false) {
			$key_parts = explode('::', (string) $method['key'], 2);
			$method_profile_id = sanitize_key((string) $key_parts[0]);
		}

		$method_aliases = array($method_profile_id);
		if ($method_sender_id !== '') {
			$method_aliases[] = $method_sender_id;
			$method_aliases[] = $this->build_sender_profile_id($method_sender_id);
		}
		if ($method_sender_entity_id !== '') {
			$method_aliases[] = $method_sender_entity_id;
			$method_aliases[] = $this->build_sender_profile_id($method_sender_entity_id);
		}

		$has_method_identity = false;
		foreach ($method_aliases as $method_alias) {
			$method_alias = sanitize_key((string) $method_alias);
			if ($method_alias === '') {
				continue;
			}
			$has_method_identity = true;
			if (isset($selected_alias_map[$method_alias])) {
				return true;
			}
		}

		if ($has_method_identity) {
			return false;
		}

		$default_profile_id = $this->get_default_sender_profile_id();
		return $selected_profile_id === '' || $default_profile_id === '' || $selected_profile_id === $default_profile_id;
	}

	private function normalize_method_key_for_sender_profile($method, $warehouse_profile) {
		$method = is_array($method) ? $method : array();
		if ($this->is_manual_norgespakke_method($method)) {
			return self::MANUAL_NORGESPAKKE_KEY;
		}

		$profile_id = isset($warehouse_profile['profile_id']) ? sanitize_key((string) $warehouse_profile['profile_id']) : '';
		$agreement_id = isset($method['agreement_id']) ? sanitize_text_field((string) $method['agreement_id']) : '';
		$product_id = isset($method['product_id']) ? sanitize_text_field((string) $method['product_id']) : '';
		$legacy_key = implode('|', array($agreement_id, $product_id));
		$key = isset($method['key']) ? sanitize_text_field((string) $method['key']) : '';
		$method_profile_id = isset($method['sender_profile_id']) ? sanitize_key((string) $method['sender_profile_id']) : '';
		if ($key !== '' && ($profile_id === '' || $key !== $legacy_key)) {
			return $key;
		}
		if ($key !== '' && $method_profile_id === '' && empty($method['sender_id'])) {
			return $key;
		}
		return $this->build_sender_method_key($agreement_id, $product_id, $profile_id);
	}

	private function find_enabled_method_for_sender_profile($method, $warehouse_profile, $enabled_map = null) {
		$method = is_array($method) ? $method : array();
		$settings = $this->get_settings();
		$enabled_map = is_array($enabled_map) ? $enabled_map : $this->get_enabled_method_map();
		$available_methods = isset($settings['available_methods']) && is_array($settings['available_methods']) ? $settings['available_methods'] : array();
		$available_methods = $this->ensure_internal_manual_methods($available_methods);
		$posted_key = isset($method['key']) ? sanitize_text_field((string) $method['key']) : '';
		if ($posted_key === '' && isset($method['method_key'])) {
			$posted_key = sanitize_text_field((string) $method['method_key']);
		}
		$agreement_id = isset($method['agreement_id']) ? sanitize_text_field((string) $method['agreement_id']) : '';
		$product_id = isset($method['product_id']) ? sanitize_text_field((string) $method['product_id']) : '';

		foreach ($available_methods as $available_method) {
			if (!is_array($available_method)) {
				continue;
			}
			$available_key = $this->normalize_method_key_for_sender_profile($available_method, $warehouse_profile);
			if ($available_key === '' || !isset($enabled_map[$available_key])) {
				continue;
			}
			if (!$this->method_matches_selected_sender_profile($available_method, $warehouse_profile)) {
				continue;
			}
			$available_agreement_id = isset($available_method['agreement_id']) ? sanitize_text_field((string) $available_method['agreement_id']) : '';
			$available_product_id = isset($available_method['product_id']) ? sanitize_text_field((string) $available_method['product_id']) : '';
			if (($posted_key !== '' && $posted_key === $available_key) || ($agreement_id !== '' && $product_id !== '' && $agreement_id === $available_agreement_id && $product_id === $available_product_id)) {
				$available_method['key'] = $available_key;
				return $available_method;
			}
		}

		return array();
	}

		public function ajax_get_shipping_options() {
			if (!current_user_can('manage_woocommerce')) {
				wp_send_json_error(array('message' => 'Ingen tilgang.'), 403);
			}

		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
			if (!wp_verify_nonce($nonce, self::NONCE_ACTION_FETCH_OPTIONS)) {
				wp_send_json_error(array('message' => 'Ugyldig nonce.'), 403);
			}

			$result = $this->get_operations_facade()->get_shipping_options(0, isset($_POST['warehouse_profile_id']) ? sanitize_key(wp_unslash($_POST['warehouse_profile_id'])) : '');
			if (is_wp_error($result)) {
				$error_data = $result->get_error_data();
				$status = is_array($error_data) && isset($error_data['http_status']) ? (int) $error_data['http_status'] : 400;
				$payload = is_array($error_data) && isset($error_data['payload']) && is_array($error_data['payload'])
					? $error_data['payload']
					: array('message' => $result->get_error_message());
				wp_send_json_error($payload, $status);
			}
			wp_send_json_success($result);
		}

		public function operations_get_shipping_options($order_id = 0, $sender_profile_id = '') {
			$settings = $this->get_settings();
			$warehouse_profile = $this->resolve_selected_warehouse_profile($sender_profile_id);
			$available_methods = isset($settings['available_methods']) && is_array($settings['available_methods']) ? $settings['available_methods'] : array();
			$available_methods = $this->ensure_internal_manual_methods($available_methods);

		$allowed_options = $this->filter_options_by_enabled_methods($available_methods);
		$allowed_options = array_values(array_filter($allowed_options, function($method) use ($warehouse_profile) {
			return $this->method_matches_selected_sender_profile($method, $warehouse_profile);
		}));
			if (empty($allowed_options)) {
				$sender_label = isset($warehouse_profile['name']) ? sanitize_text_field((string) $warehouse_profile['name']) : '';
				$sender_id = isset($warehouse_profile['sender_id']) ? sanitize_text_field((string) $warehouse_profile['sender_id']) : '';
				return $this->operations_error('lp_cargonizer_no_enabled_methods_for_sender', 'Ingen fraktmetoder er aktivert for valgt senderadresse' . ($sender_label !== '' || $sender_id !== '' ? ' (' . trim($sender_label . ' ' . ($sender_id !== '' ? $sender_id : '')) . ')' : '') . '. Gå til WooCommerce → Cargonizer, hent fraktmetoder og aktiver metodene for denne senderen.', 400);
			}

		$method_pricing = $this->get_enabled_method_pricing();
		foreach ($allowed_options as &$option) {
			$method_key = $this->normalize_method_key_for_sender_profile($option, $warehouse_profile);
			$option['key'] = $method_key;
			$pricing = isset($method_pricing[$method_key]) && is_array($method_pricing[$method_key]) ? $method_pricing[$method_key] : $this->get_default_method_pricing();
			$option['delivery_to_pickup_point'] = !empty($pricing['delivery_to_pickup_point']);
			$option['delivery_to_home'] = !empty($pricing['delivery_to_home']);
			$option['services'] = $this->filter_services_by_warehouse_availability($method_key, isset($option['services']) && is_array($option['services']) ? $option['services'] : array());
		}
		unset($option);

			return array(
				'options' => $allowed_options,
				'sender_profile_id' => isset($warehouse_profile['profile_id']) ? (string) $warehouse_profile['profile_id'] : '',
				'sender_id' => isset($warehouse_profile['sender_id']) ? (string) $warehouse_profile['sender_id'] : '',
			);
		}

	public function ajax_get_servicepartner_options() {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(array('message' => 'Ingen tilgang.'), 403);
		}

		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
			if (!wp_verify_nonce($nonce, self::NONCE_ACTION_SERVICEPARTNERS)) {
				wp_send_json_error(array('message' => 'Ugyldig nonce.'), 403);
			}

			$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
			$posted_agreement_id = isset($_POST['agreement_id']) ? sanitize_text_field(wp_unslash($_POST['agreement_id'])) : '';
			if ($posted_agreement_id === '' && isset($_POST['transport_agreement_id'])) {
				$posted_agreement_id = sanitize_text_field(wp_unslash($_POST['transport_agreement_id']));
			}
			$method = array(
				'key' => isset($_POST['method_key']) ? sanitize_text_field(wp_unslash($_POST['method_key'])) : '',
				'agreement_id' => $posted_agreement_id,
				'product_id' => isset($_POST['product_id']) ? sanitize_text_field(wp_unslash($_POST['product_id'])) : '',
				'carrier_id' => isset($_POST['carrier_id']) ? sanitize_text_field(wp_unslash($_POST['carrier_id'])) : '',
				'carrier_name' => isset($_POST['carrier_name']) ? sanitize_text_field(wp_unslash($_POST['carrier_name'])) : '',
				'product_name' => isset($_POST['product_name']) ? sanitize_text_field(wp_unslash($_POST['product_name'])) : '',
			);
			$recipient = array(
				'country' => isset($_POST['recipient_country']) ? sanitize_text_field(wp_unslash($_POST['recipient_country'])) : '',
				'postcode' => isset($_POST['recipient_postcode']) ? sanitize_text_field(wp_unslash($_POST['recipient_postcode'])) : '',
				'city' => isset($_POST['recipient_city']) ? sanitize_text_field(wp_unslash($_POST['recipient_city'])) : '',
				'address_1' => isset($_POST['recipient_address_1']) ? sanitize_text_field(wp_unslash($_POST['recipient_address_1'])) : '',
			);
			$result = $this->get_operations_facade()->get_servicepartners($order_id, $method, isset($_POST['warehouse_profile_id']) ? sanitize_key(wp_unslash($_POST['warehouse_profile_id'])) : '', $recipient);
			if (is_wp_error($result)) {
				$error_data = $result->get_error_data();
				$status = is_array($error_data) && isset($error_data['http_status']) ? (int) $error_data['http_status'] : 200;
				$payload = is_array($error_data) && isset($error_data['payload']) && is_array($error_data['payload'])
					? $error_data['payload']
					: array('message' => $result->get_error_message());
				wp_send_json_error($payload, $status);
			}
			wp_send_json_success($result);
		}

		public function operations_get_servicepartners($order_id, $method, $sender_profile_id = '', $recipient = array()) {
			$order_id = absint($order_id);
			$order = $order_id ? wc_get_order($order_id) : false;
			$warehouse_profile = $this->resolve_selected_warehouse_profile($sender_profile_id);
			$sender_id_override = isset($warehouse_profile['sender_id']) ? sanitize_text_field((string) $warehouse_profile['sender_id']) : '';

			$method = array(
				'key' => isset($method['key']) ? sanitize_text_field((string) $method['key']) : '',
				'agreement_id' => isset($method['agreement_id']) ? sanitize_text_field((string) $method['agreement_id']) : '',
				'product_id' => isset($method['product_id']) ? sanitize_text_field((string) $method['product_id']) : '',
				'carrier_id' => isset($method['carrier_id']) ? sanitize_text_field((string) $method['carrier_id']) : '',
				'carrier_name' => isset($method['carrier_name']) ? sanitize_text_field((string) $method['carrier_name']) : '',
				'product_name' => isset($method['product_name']) ? sanitize_text_field((string) $method['product_name']) : '',
				'country' => isset($recipient['country']) ? sanitize_text_field((string) $recipient['country']) : '',
				'postcode' => isset($recipient['postcode']) ? sanitize_text_field((string) $recipient['postcode']) : '',
				'city' => isset($recipient['city']) ? sanitize_text_field((string) $recipient['city']) : '',
				'address' => isset($recipient['address_1']) ? sanitize_text_field((string) $recipient['address_1']) : '',
				'sender_id_override' => $sender_id_override,
				'warehouse_profile_id' => isset($warehouse_profile['profile_id']) ? sanitize_key((string) $warehouse_profile['profile_id']) : '',
			);

		$enabled_method = $this->find_enabled_method_for_sender_profile($method, $warehouse_profile);
			if (empty($enabled_method)) {
				return $this->operations_error('lp_cargonizer_method_not_enabled_for_sender', 'Valgt fraktmetode er ikke aktivert for valgt senderadresse. Last fraktvalg på nytt etter bytte av sender.', 200, array(
					'message' => 'Valgt fraktmetode er ikke aktivert for valgt senderadresse. Last fraktvalg på nytt etter bytte av sender.',
					'debug' => array(
						'method_key' => isset($method['key']) ? $method['key'] : '',
					'agreement_id' => isset($method['agreement_id']) ? $method['agreement_id'] : '',
					'product_id' => isset($method['product_id']) ? $method['product_id'] : '',
						'sender_profile_id' => isset($warehouse_profile['profile_id']) ? $warehouse_profile['profile_id'] : '',
					),
				));
			}
		$effective_sender_id_override = !empty($enabled_method['sender_id']) ? sanitize_text_field((string) $enabled_method['sender_id']) : $sender_id_override;
		$method = array_merge($enabled_method, array(
			'country' => $method['country'],
			'postcode' => $method['postcode'],
			'city' => $method['city'],
			'address' => $method['address'],
			'sender_id_override' => $effective_sender_id_override,
			'warehouse_profile_id' => isset($warehouse_profile['profile_id']) ? sanitize_key((string) $warehouse_profile['profile_id']) : '',
		));

		if ($order) {
			$recipient_country = $this->resolve_recipient_country_for_context($order, $method);
			$method['country'] = isset($recipient_country['normalized']) && $recipient_country['normalized'] !== '' ? $recipient_country['normalized'] : $method['country'];
			$method['postcode'] = $order->get_shipping_postcode() !== '' ? $order->get_shipping_postcode() : $method['postcode'];
			$method['city'] = $order->get_shipping_city() !== '' ? $order->get_shipping_city() : $method['city'];
			$method['address'] = $order->get_shipping_address_1() !== '' ? $order->get_shipping_address_1() : $method['address'];
		}
		$method_country_resolution = $this->resolve_recipient_country_for_context($order, $method);
		$method['country'] = isset($method_country_resolution['normalized']) ? $method_country_resolution['normalized'] : '';
			if ($method['country'] === '') {
				return $this->operations_error('lp_cargonizer_recipient_country_invalid', 'Ugyldig mottakerland. Land må være en gyldig ISO-2-kode, for eksempel NO.', 200, array(
					'message' => 'Ugyldig mottakerland. Land må være en gyldig ISO-2-kode, for eksempel NO.',
					'debug' => array(
						'recipient_country_raw' => isset($method_country_resolution['raw']) ? $method_country_resolution['raw'] : '',
						'recipient_country_normalized' => '',
					),
				));
			}

		$servicepartner_result = $this->fetch_servicepartner_options($method);

			if (empty($servicepartner_result['success'])) {
				return $this->operations_error('lp_cargonizer_servicepartners_failed', $servicepartner_result['error_message'] !== '' ? $servicepartner_result['error_message'] : 'Henting av servicepartnere feilet.', 200, array(
					'message' => $servicepartner_result['error_message'] !== '' ? $servicepartner_result['error_message'] : 'Henting av servicepartnere feilet.',
					'debug' => $servicepartner_result,
				));
			}

			if (empty($servicepartner_result['options'])) {
				return $this->operations_error('lp_cargonizer_servicepartners_empty', $servicepartner_result['error_message'] !== '' ? $servicepartner_result['error_message'] : 'Ingen servicepartnere returnert fra API.', 200, array(
					'message' => $servicepartner_result['error_message'] !== '' ? $servicepartner_result['error_message'] : 'Ingen servicepartnere returnert fra API.',
					'debug' => $servicepartner_result,
				));
			}

			return array(
				'options' => $servicepartner_result['options'],
				'debug' => $servicepartner_result,
				'normalized_options' => $this->normalize_servicepartner_options(isset($servicepartner_result['options']) && is_array($servicepartner_result['options']) ? $servicepartner_result['options'] : array()),
			);
		}

	public function ajax_get_printers() {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(array('message' => 'Ingen tilgang.'), 403);
		}

		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
			if (!wp_verify_nonce($nonce, self::NONCE_ACTION_PRINTERS)) {
				wp_send_json_error(array('message' => 'Ugyldig nonce.'), 403);
			}

			$result = $this->get_operations_facade()->get_printers(get_current_user_id());
			if (is_wp_error($result)) {
				$error_data = $result->get_error_data();
				$status = is_array($error_data) && isset($error_data['http_status']) ? (int) $error_data['http_status'] : 200;
				$payload = is_array($error_data) && isset($error_data['payload']) && is_array($error_data['payload'])
					? $error_data['payload']
					: array('message' => $result->get_error_message());
				wp_send_json_error($payload, $status);
			}
			wp_send_json_success($result);
		}

		public function operations_get_printers($wordpress_user_id = null) {
			$printer_result = $this->fetch_printers();
			$default_printer_id = $wordpress_user_id ? get_user_meta(absint($wordpress_user_id), 'lp_cargonizer_default_printer_id', true) : '';
			$default_printer_id = is_scalar($default_printer_id) ? sanitize_text_field((string) $default_printer_id) : '';

			if (empty($printer_result['success'])) {
			$error = array(
				'message' => !empty($printer_result['message']) ? (string) $printer_result['message'] : 'Kunne ikke hente printere.',
				'http_status' => isset($printer_result['http_status']) ? (int) $printer_result['http_status'] : 0,
				'raw_excerpt' => '',
			);
				if (array_key_exists('raw', $printer_result)) {
					$error['raw_excerpt'] = substr((string) $printer_result['raw'], 0, 300);
				}
				return $this->operations_error('lp_cargonizer_printers_failed', $error['message'], 200, $error);
			}

			$printers = $this->apply_printer_aliases(isset($printer_result['printers']) && is_array($printer_result['printers']) ? $printer_result['printers'] : array());
			return array(
				'printers' => $printers,
				'normalized_printers' => $this->normalize_printers($printers),
				'default_printer_id' => $default_printer_id,
			);
		}


	public function ajax_run_bulk_estimate() {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(array('message' => 'Ingen tilgang.'), 403);
		}

		$is_baseline_flow = !empty($_POST['baseline_flow']);
		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		$expected_nonce_action = $is_baseline_flow ? self::NONCE_ACTION_ESTIMATE_BASELINE : self::NONCE_ACTION_ESTIMATE;
		if (!wp_verify_nonce($nonce, $expected_nonce_action)) {
			wp_send_json_error(array('message' => 'Ugyldig nonce.'), 403);
		}

		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		$order = $order_id ? wc_get_order($order_id) : false;
		if (!$order) {
			wp_send_json_error(array('message' => 'Ordre ikke funnet.'), 404);
		}

		$packages = isset($_POST['packages']) && is_array($_POST['packages']) ? wp_unslash($_POST['packages']) : array();
		$methods = isset($_POST['methods']) && is_array($_POST['methods']) ? wp_unslash($_POST['methods']) : array();
		$warehouse_profile = $this->resolve_selected_warehouse_profile(isset($_POST['warehouse_profile_id']) ? sanitize_key(wp_unslash($_POST['warehouse_profile_id'])) : '');
		$sender_id_override = isset($warehouse_profile['sender_id']) ? sanitize_text_field((string) $warehouse_profile['sender_id']) : '';
		$enabled_map = $this->get_enabled_method_map();

		if (empty($enabled_map)) {
			wp_send_json_error(array('message' => 'Ingen fraktmetoder er aktivert i Cargonizer-innstillingene.'), 400);
		}

		if (empty($packages) || empty($methods)) {
			wp_send_json_error(array('message' => 'Mangler kolli eller fraktvalg.'), 400);
		}

		$clean_packages = array();
		foreach ($packages as $package) {
			$package_text = $this->sanitize_package_display_text($package);
			$clean_packages[] = array(
				'name' => $package_text,
				'description' => $package_text,
				'weight' => isset($package['weight']) ? (float) $package['weight'] : 0,
				'length' => isset($package['length']) ? (float) $package['length'] : 0,
				'width' => isset($package['width']) ? (float) $package['width'] : 0,
				'height' => isset($package['height']) ? (float) $package['height'] : 0,
			);
		}

		$recipient_country = $this->resolve_recipient_country_for_context($order);
		$recipient = array(
			'name' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
			'address_1' => $order->get_shipping_address_1(),
			'address_2' => $order->get_shipping_address_2(),
			'postcode' => $order->get_shipping_postcode(),
			'city' => $order->get_shipping_city(),
			'country' => isset($recipient_country['normalized']) ? $recipient_country['normalized'] : '',
			'email' => $order->get_billing_email(),
			'phone' => $this->get_order_recipient_phone_for_api($order),
		);

		if ($recipient['name'] === '') {
			$recipient['name'] = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
		}
		if ($recipient['country'] === '') {
			wp_send_json_error(array('message' => 'Ugyldig mottakerland. Land må være en gyldig ISO-2-kode, for eksempel NO.'), 400);
		}

		$results = array();
		$has_allowed_methods = false;
		$method_pricing = $this->get_enabled_method_pricing();
		foreach ($methods as $method) {
			$selected_service_ids = array();
			if (isset($method['selected_service_ids']) && is_array($method['selected_service_ids'])) {
				foreach ($method['selected_service_ids'] as $selected_service_id) {
					$clean_service_id = sanitize_text_field((string) $selected_service_id);
					if ($clean_service_id !== '') {
						$selected_service_ids[] = $clean_service_id;
					}
				}
			}
			$sanitized_method_payload = $this->sanitize_posted_method_payload($method);
			$method_payload = array(
				'key' => isset($method['key']) ? sanitize_text_field($method['key']) : '',
				'sender_profile_id' => isset($method['sender_profile_id']) ? sanitize_key((string) $method['sender_profile_id']) : '',
				'sender_profile_name' => isset($method['sender_profile_name']) ? sanitize_text_field((string) $method['sender_profile_name']) : '',
				'sender_id' => isset($method['sender_id']) ? sanitize_text_field((string) $method['sender_id']) : '',
				'sender_entity_id' => isset($method['sender_entity_id']) ? sanitize_text_field((string) $method['sender_entity_id']) : (isset($warehouse_profile['sender_entity_id']) ? sanitize_text_field((string) $warehouse_profile['sender_entity_id']) : ''),
				'agreement_id' => isset($method['agreement_id']) ? sanitize_text_field($method['agreement_id']) : '',
				'agreement_name' => isset($method['agreement_name']) ? sanitize_text_field($method['agreement_name']) : '',
				'agreement_description' => isset($method['agreement_description']) ? sanitize_text_field($method['agreement_description']) : '',
				'agreement_number' => isset($method['agreement_number']) ? sanitize_text_field($method['agreement_number']) : '',
				'carrier_id' => isset($method['carrier_id']) ? sanitize_text_field($method['carrier_id']) : '',
				'carrier_name' => isset($method['carrier_name']) ? sanitize_text_field($method['carrier_name']) : '',
				'product_id' => isset($method['product_id']) ? sanitize_text_field($method['product_id']) : '',
				'product_name' => isset($method['product_name']) ? sanitize_text_field($method['product_name']) : '',
				'delivery_to_pickup_point' => !empty($method['delivery_to_pickup_point']),
				'delivery_to_home' => !empty($method['delivery_to_home']),
				'servicepartner' => isset($method['servicepartner']) ? sanitize_text_field($method['servicepartner']) : '',
				'servicepartner_customer_number' => isset($method['servicepartner_customer_number']) ? sanitize_text_field($method['servicepartner_customer_number']) : '',
				'servicepartner_selection_source' => isset($method['servicepartner_selection_source']) ? sanitize_text_field($method['servicepartner_selection_source']) : '',
				'servicepartner_user_selected' => !empty($method['servicepartner_user_selected']),
				'servicepartner_selected_option' => isset($sanitized_method_payload['servicepartner_selected_option']) && is_array($sanitized_method_payload['servicepartner_selected_option']) ? $sanitized_method_payload['servicepartner_selected_option'] : array(),
				'use_sms_service' => !empty($method['use_sms_service']),
				'sms_service_id' => isset($method['sms_service_id']) ? sanitize_text_field($method['sms_service_id']) : '',
				'sms_service_name' => isset($method['sms_service_name']) ? sanitize_text_field($method['sms_service_name']) : '',
				'selected_service_ids' => array_values(array_unique($selected_service_ids)),
				'is_manual' => !empty($method['is_manual']),
				'is_manual_norgespakke' => !empty($method['is_manual_norgespakke']),
				'services' => isset($method['services']) && is_array($method['services']) ? $method['services'] : array(),
				'sender_id_override' => !empty($method['sender_id']) ? sanitize_text_field((string) $method['sender_id']) : $sender_id_override,
				'warehouse_profile_id' => isset($warehouse_profile['profile_id']) ? sanitize_key((string) $warehouse_profile['profile_id']) : '',
				'warehouse_profile_name' => isset($warehouse_profile['name']) ? sanitize_text_field((string) $warehouse_profile['name']) : '',
			);
				if ($method_payload['key'] === '') {
					$method_payload['key'] = implode('|', array($method_payload['agreement_id'], $method_payload['product_id']));
				}
				$method_payload['is_manual_norgespakke'] = $this->is_manual_norgespakke_method($method_payload);
				$method_payload['services'] = $this->filter_services_by_warehouse_availability($method_payload['key'], $method_payload['services']);
				$method_payload['selected_service_ids'] = $this->filter_selected_service_ids_by_warehouse_availability($method_payload['key'], $method_payload['selected_service_ids'], $method_payload['services']);
				error_log('LP Cargonizer estimate freight: method context=' . wp_json_encode(array(
					'method_key' => isset($method_payload['key']) ? $method_payload['key'] : '',
					'agreement_id' => isset($method_payload['agreement_id']) ? $method_payload['agreement_id'] : '',
					'product_id' => isset($method_payload['product_id']) ? $method_payload['product_id'] : '',
				'carrier_id' => isset($method_payload['carrier_id']) ? $method_payload['carrier_id'] : '',
				'carrier_name' => isset($method_payload['carrier_name']) ? $method_payload['carrier_name'] : '',
				'product_name' => isset($method_payload['product_name']) ? $method_payload['product_name'] : '',
				'endpoint' => '/consignment_costs.xml',
				'blocked_before_api' => false,
			)));
			$method_key = $this->normalize_method_key_for_sender_profile($method_payload, $warehouse_profile);
			$method_payload['key'] = $method_key;
			if (!isset($enabled_map[$method_key]) || !$this->method_matches_selected_sender_profile($method_payload, $warehouse_profile)) {
				continue;
			}
			$has_allowed_methods = true;
			if ($method_payload['sms_service_id'] === '') {
				$sms_service = $this->find_sms_service_for_method($method);
				$method_payload['sms_service_id'] = $sms_service['service_id'];
				$method_payload['sms_service_name'] = $sms_service['service_name'];
			}

			$pricing_config = isset($method_pricing[$method_key]) && is_array($method_pricing[$method_key]) ? $method_pricing[$method_key] : $this->get_default_method_pricing();
			if ($this->is_manual_norgespakke_method($method_payload)) {
				$pricing_config['price_source'] = 'manual_norgespakke';
			}
			$discount_percent = isset($pricing_config['discount_percent']) ? $this->sanitize_discount_percent($pricing_config['discount_percent']) : 0;
			$fuel_percent = isset($pricing_config['fuel_surcharge']) ? $this->sanitize_non_negative_number($pricing_config['fuel_surcharge']) : 0;
			$toll_surcharge = isset($pricing_config['toll_surcharge']) ? $this->sanitize_non_negative_number($pricing_config['toll_surcharge']) : 0;
			$bring_manual_handling = $this->get_bring_manual_handling_fee($clean_packages, $method_payload);
			$manual_handling_fee = isset($pricing_config['handling_fee']) ? $this->sanitize_non_negative_number($pricing_config['handling_fee']) : 0;
			$bring_manual_handling_fee = isset($bring_manual_handling['fee']) ? $this->sanitize_non_negative_number($bring_manual_handling['fee']) : 0;
			$bring_manual_handling_triggered = !empty($bring_manual_handling['triggered']);
			if (!$bring_manual_handling_triggered) {
				$bring_manual_handling_fee = 0;
			}
			$total_handling_fee = round($manual_handling_fee + $bring_manual_handling_fee, 2);
			$pricing_config['manual_handling_fee'] = $manual_handling_fee;
			$pricing_config['bring_manual_handling_fee'] = $bring_manual_handling_fee;
			$pricing_config['bring_manual_handling_triggered'] = $bring_manual_handling_triggered;
			$pricing_config['bring_manual_handling_package_count'] = isset($bring_manual_handling['package_count']) ? (int) $bring_manual_handling['package_count'] : 0;
			$pricing_config['handling_fee'] = $total_handling_fee;
			$delivery_to_pickup_point = $this->is_method_explicitly_pickup_point($method_payload);
			$delivery_to_home = $this->is_method_explicitly_home_delivery($method_payload);

			$item = array(
				'method_key' => $method_key,
				'key' => $method_key,
				'method_name' => $this->format_method_label($method_payload['agreement_name'], $method_payload['product_name'], $method_payload['carrier_name']),
				'agreement_id' => $method_payload['agreement_id'],
				'agreement_name' => $method_payload['agreement_name'],
				'agreement_description' => $method_payload['agreement_description'],
				'agreement_number' => $method_payload['agreement_number'],
				'carrier_id' => $method_payload['carrier_id'],
				'carrier_name' => $method_payload['carrier_name'],
				'product_id' => $method_payload['product_id'],
				'delivery_to_pickup_point' => $delivery_to_pickup_point,
				'delivery_to_home' => $delivery_to_home,
				'selected_servicepartner' => $method_payload['servicepartner'],
				'selected_servicepartner_customer_number' => isset($method_payload['servicepartner_customer_number']) ? $method_payload['servicepartner_customer_number'] : '',
				'selected_servicepartner_option' => isset($method_payload['servicepartner_selected_option']) && is_array($method_payload['servicepartner_selected_option']) ? $method_payload['servicepartner_selected_option'] : array(),
				'servicepartner_selection_source' => $method_payload['servicepartner'] !== '' ? (isset($method_payload['servicepartner_selection_source']) && $method_payload['servicepartner_selection_source'] !== '' ? $method_payload['servicepartner_selection_source'] : 'manual') : 'none',
				'servicepartner_auto_selected' => $method_payload['servicepartner'] !== '' && isset($method_payload['servicepartner_selection_source']) && in_array($method_payload['servicepartner_selection_source'], array('automatic', 'prefetched'), true),
				'servicepartner_auto_selected_note' => '',
				'auto_selection_reason' => $method_payload['servicepartner'] !== '' ? (isset($method_payload['servicepartner_selection_source']) && $method_payload['servicepartner_selection_source'] === 'prefetched' ? 'prefetched_nearest_or_first_available_option' : 'manual_selection_present') : '',
				'use_sms_service' => $method_payload['use_sms_service'],
				'sms_service_id' => $method_payload['sms_service_id'],
				'sms_service_name' => $method_payload['sms_service_name'],
				'sender_profile_id' => isset($method_payload['warehouse_profile_id']) ? $method_payload['warehouse_profile_id'] : '',
				'sender_profile_name' => isset($method_payload['warehouse_profile_name']) ? $method_payload['warehouse_profile_name'] : '',
				'sender_id' => isset($method_payload['sender_id_override']) ? $method_payload['sender_id_override'] : '',
				'sender_entity_id' => isset($method_payload['sender_entity_id']) ? $method_payload['sender_entity_id'] : '',
				'requires_sms_service' => false,
				'sms_service_missing' => false,
				'sms_service_error' => '',
				'requires_servicepartner' => false,
				'servicepartner_options' => array(),
				'servicepartner_fetch' => array(),
				'estimated_price' => '',
				'estimated_cost' => '',
				'gross_amount' => '',
				'net_amount' => '',
				'fallback_price' => '',
				'selected_price_source' => '',
				'selected_price_value' => '',
				'price_source_fallback_used' => false,
				'price_source_fallback_reason' => '',
				'price_source_priority' => array(),
				'actual_fallback_priority' => array(),
				'fallback_step_used' => 0,
				'original_price' => '',
				'original_list_price' => '',
				'extracted_handling_fee' => '',
				'extracted_toll_surcharge' => '',
				'extracted_fuel_percent' => '',
				'extracted_base_freight' => '',
				'discounted_base_freight' => '',
				'recalculated_fuel_surcharge' => '',
				'discount_percent' => $discount_percent,
				'discounted_base' => '',
				'fuel_surcharge' => number_format($fuel_percent, 2, '.', ''),
				'toll_surcharge' => number_format($toll_surcharge, 2, '.', ''),
				'handling_fee' => number_format($total_handling_fee, 2, '.', ''),
				'price_source_config' => isset($pricing_config['price_source']) ? $pricing_config['price_source'] : 'estimated',
				'configured_price_source_key' => '',
				'vat_percent' => isset($pricing_config['vat_percent']) ? $pricing_config['vat_percent'] : 0,
				'rounding_mode' => isset($pricing_config['rounding_mode']) ? $pricing_config['rounding_mode'] : 'none',
				'manual_handling_fee' => number_format($manual_handling_fee, 2, '.', ''),
				'bring_manual_handling_fee' => number_format($bring_manual_handling_fee, 2, '.', ''),
				'total_handling_fee' => number_format($total_handling_fee, 2, '.', ''),
				'bring_manual_handling_triggered' => $bring_manual_handling_triggered,
				'bring_manual_handling_package_count' => isset($bring_manual_handling['package_count']) ? (int) $bring_manual_handling['package_count'] : 0,
				'base_price' => '',
				'subtotal_ex_vat' => '',
				'price_incl_vat' => '',
				'rounded_price' => '',
				'final_price_ex_vat' => '',
				'status' => 'failed',
				'http_status' => 0,
				'error' => '',
				'error_code' => '',
				'error_type' => '',
				'error_details' => '',
				'parsed_error_message' => '',
				'human_error' => '',
				'raw_response' => '',
				'is_manual_norgespakke' => false,
				'norgespakke_debug' => array(),
				'optimized_partition_used' => false,
				'optimized_shipment_count' => 0,
				'optimized_shipments' => array(),
				'optimization_debug' => array(),
				'optimization_state' => '',
				'request_summary' => array(
					'agreement_id' => $method_payload['agreement_id'],
					'product_id' => $method_payload['product_id'],
					'carrier_id' => $method_payload['carrier_id'],
					'carrier_name' => $method_payload['carrier_name'],
					'product_name' => $method_payload['product_name'],
					'country' => isset($recipient['country']) ? $recipient['country'] : '',
					'recipient_country_raw' => isset($recipient_country['raw']) ? $recipient_country['raw'] : '',
					'recipient_country_normalized' => isset($recipient['country']) ? $recipient['country'] : '',
					'postcode' => isset($recipient['postcode']) ? $recipient['postcode'] : '',
					'number_of_packages' => count($clean_packages),
					'delivery_to_pickup_point' => $delivery_to_pickup_point,
					'delivery_to_home' => $delivery_to_home,
					'packages' => $clean_packages,
					'selected_servicepartner' => $method_payload['servicepartner'],
					'selected_servicepartner_customer_number' => isset($method_payload['servicepartner_customer_number']) ? $method_payload['servicepartner_customer_number'] : '',
					'servicepartner_selection_source' => $method_payload['servicepartner'] !== '' ? 'manual' : 'none',
					'use_sms_service' => $method_payload['use_sms_service'],
					'sender_profile_id' => isset($method_payload['warehouse_profile_id']) ? $method_payload['warehouse_profile_id'] : '',
					'sender_profile_name' => isset($method_payload['warehouse_profile_name']) ? $method_payload['warehouse_profile_name'] : '',
					'sender_id' => isset($method_payload['sender_id_override']) ? $method_payload['sender_id_override'] : '',
					'sender_entity_id' => isset($method_payload['sender_entity_id']) ? $method_payload['sender_entity_id'] : '',
				),
			);

			$auto_servicepartner_attempted = false;
			if ($this->should_attempt_servicepartner_autoselection($method_payload) && $method_payload['servicepartner'] === '') {
				$auto_servicepartner_attempted = true;
				$resolved_selection = $this->resolve_default_servicepartner_selection($method_payload, $recipient);
				$method_payload = $this->apply_servicepartner_resolution_to_method_payload($method_payload, $resolved_selection);
				$item['selected_servicepartner'] = $method_payload['servicepartner'];
				$item['selected_servicepartner_customer_number'] = isset($method_payload['servicepartner_customer_number']) ? $method_payload['servicepartner_customer_number'] : '';
				$item['selected_servicepartner_option'] = isset($method_payload['servicepartner_selected_option']) && is_array($method_payload['servicepartner_selected_option']) ? $method_payload['servicepartner_selected_option'] : array();
				$item['servicepartner_selection_source'] = isset($resolved_selection['servicepartner_selection_source']) ? $resolved_selection['servicepartner_selection_source'] : 'none';
				$item['servicepartner_auto_selected'] = !empty($resolved_selection['servicepartner_auto_selected']);
				$item['auto_selection_reason'] = isset($resolved_selection['auto_selection_reason']) ? $resolved_selection['auto_selection_reason'] : '';
				$item['request_summary']['selected_servicepartner'] = $method_payload['servicepartner'];
				$item['request_summary']['selected_servicepartner_customer_number'] = isset($method_payload['servicepartner_customer_number']) ? $method_payload['servicepartner_customer_number'] : '';
				$item['request_summary']['servicepartner_selection_source'] = $item['servicepartner_selection_source'];
				$item['servicepartner_fetch'] = isset($resolved_selection['servicepartner_fetch']) ? $resolved_selection['servicepartner_fetch'] : array();
				$item['servicepartner_options'] = isset($resolved_selection['servicepartner_options']) ? $resolved_selection['servicepartner_options'] : array();
				if ($item['servicepartner_auto_selected']) {
					$item['servicepartner_auto_selected_note'] = 'Nærmeste servicepartner ble valgt automatisk.';
				}
			}


			if ($this->is_manual_norgespakke_method($method_payload)) {
				$item['is_manual_norgespakke'] = true;
				$item['method_name'] = 'Posten - Norgespakke (manuell)';
				$item['selected_price_source'] = 'manual_norgespakke';
				$item['price_source_config'] = 'manual_norgespakke';
				$item['configured_price_source_key'] = 'manual_norgespakke';
				$item['actual_fallback_priority'] = array('manual_norgespakke');
				$item['price_source_priority'] = array('manual_norgespakke');
				$item['fallback_step_used'] = 1;
				$item['raw_response'] = '';
				$item['http_status'] = 0;
				$manual_calculation = $this->calculate_norgespakke_estimate($clean_packages, $method_payload, $pricing_config);
				$item['status'] = $manual_calculation['status'];
				if (!empty($manual_calculation['error'])) {
					$item['error'] = $manual_calculation['error'];
					$item['parsed_error_message'] = $manual_calculation['error'];
					$item['human_error'] = $manual_calculation['error'];
				} else {
					$item['selected_price_value'] = $manual_calculation['selected_price_value'];
					$item['estimated_price'] = $manual_calculation['selected_price_value'];
					$item['original_price'] = $manual_calculation['selected_price_value'];
					$item['original_list_price'] = $manual_calculation['original_list_price'];
					$item['manual_handling_fee'] = $manual_calculation['manual_handling_fee'];
					$item['bring_manual_handling_fee'] = $manual_calculation['bring_manual_handling_fee'];
					$item['total_handling_fee'] = $manual_calculation['total_handling_fee'];
					$item['bring_manual_handling_triggered'] = !empty($manual_calculation['bring_manual_handling_triggered']);
					$item['bring_manual_handling_package_count'] = isset($manual_calculation['bring_manual_handling_package_count']) ? (int) $manual_calculation['bring_manual_handling_package_count'] : 0;
					$item['base_price'] = $manual_calculation['base_price'];
					$item['discount_percent'] = $manual_calculation['discount_percent'];
					$item['discounted_base'] = $manual_calculation['discounted_base'];
					$item['fuel_surcharge'] = $manual_calculation['fuel_surcharge'];
					$item['recalculated_fuel_surcharge'] = $manual_calculation['recalculated_fuel_surcharge'];
					$item['toll_surcharge'] = $manual_calculation['toll_surcharge'];
					$item['handling_fee'] = $manual_calculation['handling_fee'];
					$item['subtotal_ex_vat'] = $manual_calculation['subtotal_ex_vat'];
					$item['vat_percent'] = $manual_calculation['vat_percent'];
					$item['price_incl_vat'] = $manual_calculation['price_incl_vat'];
					$item['rounded_price'] = $manual_calculation['final_price_ex_vat'];
					$item['final_price_ex_vat'] = $manual_calculation['final_price_ex_vat'];
					$item['norgespakke_debug'] = isset($manual_calculation['norgespakke_debug']) ? $manual_calculation['norgespakke_debug'] : array();
				}
				$results[] = $item;
				continue;
			}


			if ($this->is_dsv_method($method_payload) && count($clean_packages) > 1 && $is_baseline_flow) {
				$baseline_estimate = $this->run_consignment_estimate_for_packages($clean_packages, $recipient, $method_payload, $pricing_config);
				$item = $this->apply_estimate_result_to_item($item, $baseline_estimate, $method_payload, $recipient);
				$item['optimization_debug'] = array(
					'enabled' => false,
					'reason' => 'DSV-optimalisering ikke kjørt ennå',
					'package_count' => count($clean_packages),
					'baseline_estimate_attempted' => true,
					'baseline_estimate_status' => isset($baseline_estimate['status']) ? $baseline_estimate['status'] : 'failed',
					'partitions_tested' => 0,
					'winner_partition_index' => -1,
					'winner_total_final_price_ex_vat' => '',
					'winner_total_rounded_price' => '',
					'winner_shipment_count' => 0,
					'optimization_changed_result' => false,
					'variants' => array($this->build_dsv_baseline_variant($baseline_estimate, $clean_packages)),
				);
				$item['optimization_state'] = 'pending';
				$results[] = $item;
				continue;
			}

			if ($this->is_dsv_method($method_payload) && count($clean_packages) > 1) {
				$baseline_estimate = $this->run_consignment_estimate_for_packages($clean_packages, $recipient, $method_payload, $pricing_config);
				$item = $this->apply_estimate_result_to_item($item, $baseline_estimate, $method_payload, $recipient);
				$dsv_optimization = $this->optimize_dsv_partition_estimates($clean_packages, $recipient, $method_payload, $pricing_config, $baseline_estimate);
				$item['optimization_debug'] = isset($dsv_optimization['debug']) ? $dsv_optimization['debug'] : array();
				$item['optimization_state'] = 'done';
				$winner = isset($dsv_optimization['winner']) && is_array($dsv_optimization['winner']) ? $dsv_optimization['winner'] : array();

				if (!empty($winner) && isset($winner['status']) && $winner['status'] === 'ok' && !empty($dsv_optimization['used'])) {
					$item['optimized_partition_used'] = true;
					$item['optimized_shipment_count'] = isset($winner['shipment_count']) ? (int) $winner['shipment_count'] : 0;
					$item['optimized_shipments'] = isset($winner['groups']) ? $winner['groups'] : array();
					$item['status'] = 'ok';
					$item['selected_price_source'] = 'optimized_partition';
					$item['selected_price_value'] = $winner['total_final_price_ex_vat'];
					$item['estimated_price'] = $winner['total_final_price_ex_vat'];
					$item['original_price'] = $winner['total_final_price_ex_vat'];
					$item['original_list_price'] = $winner['total_final_price_ex_vat'];
					$item['price_source_priority'] = array('optimized_partition');
					$item['actual_fallback_priority'] = array('optimized_partition');
					$item['configured_price_source_key'] = 'optimized_partition';
					$item['fallback_step_used'] = 1;
					$item['subtotal_ex_vat'] = $winner['total_final_price_ex_vat'];
					$item['final_price_ex_vat'] = $winner['total_final_price_ex_vat'];
					$item['rounded_price'] = $winner['total_rounded_price'];
					$item['price_incl_vat'] = $winner['total_rounded_price'];
				}

				$results[] = $item;
				continue;
			}


			$xml = $this->build_estimate_request_xml(array(
				'recipient' => $recipient,
				'packages' => $clean_packages,
				'servicepartner' => $method_payload['servicepartner'],
				'servicepartner_customer_number' => isset($method_payload['servicepartner_customer_number']) ? $method_payload['servicepartner_customer_number'] : '',
				'servicepartner_selected_option' => isset($method_payload['servicepartner_selected_option']) && is_array($method_payload['servicepartner_selected_option']) ? $method_payload['servicepartner_selected_option'] : array(),
				'use_sms_service' => $method_payload['use_sms_service'],
				'sms_service_id' => $method_payload['sms_service_id'],
				'selected_service_ids' => isset($method_payload['selected_service_ids']) && is_array($method_payload['selected_service_ids']) ? $method_payload['selected_service_ids'] : array(),
			), $method_payload);
			if ($xml === '') {
				$xml_build_error = $this->api_service->get_last_xml_build_error();
				error_log('LP Cargonizer estimate freight: method context=' . wp_json_encode(array(
					'method_key' => isset($method_payload['key']) ? $method_payload['key'] : '',
					'agreement_id' => isset($method_payload['agreement_id']) ? $method_payload['agreement_id'] : '',
					'product_id' => isset($method_payload['product_id']) ? $method_payload['product_id'] : '',
					'carrier_id' => isset($method_payload['carrier_id']) ? $method_payload['carrier_id'] : '',
					'carrier_name' => isset($method_payload['carrier_name']) ? $method_payload['carrier_name'] : '',
					'product_name' => isset($method_payload['product_name']) ? $method_payload['product_name'] : '',
					'endpoint' => '/consignment_costs.xml',
					'blocked_before_api' => true,
				)));
				$item['status'] = 'failed';
				$item['reason_code'] = 'estimate_config_invalid';
				$item['error'] = 'Mangler transport agreement. Hent fraktmetoder på nytt og lagre metoden på nytt.';
				$item['parsed_error_message'] = 'estimate_config_invalid: Mangler transport agreement. Hent fraktmetoder på nytt og lagre metoden på nytt.';
				$item['context'] = isset($xml_build_error['context']) && is_array($xml_build_error['context']) ? $xml_build_error['context'] : array();
				$results[] = $item;
				continue;
			}

			$response = wp_remote_post(LP_Cargonizer_Api_Service::build_endpoint_url('/consignment_costs.xml'), array(
				'timeout' => 40,
				'headers' => array_merge($this->get_auth_headers(isset($method_payload['sender_id_override']) ? $method_payload['sender_id_override'] : ''), array('Content-Type' => 'application/xml')),
				'body' => $xml,
			));

			if (is_wp_error($response)) {
				$item['error'] = $response->get_error_message();
				$item['parsed_error_message'] = $item['error'];
				$results[] = $item;
				continue;
			}

			$status = wp_remote_retrieve_response_code($response);
			$item['http_status'] = $status;
			$body = wp_remote_retrieve_body($response);
			$item['raw_response'] = $body;

			if ($status < 200 || $status >= 300) {
				$error_details = $this->parse_response_error_details($body);
				$item['error_code'] = $error_details['code'];
				$item['error_type'] = $error_details['type'];
				$item['parsed_error_message'] = $error_details['message'];
				$item['error_details'] = $error_details['details'];
				$item['error'] = 'HTTP ' . $status;
				if ($item['parsed_error_message'] !== '') {
					$item['error'] .= ': ' . $item['parsed_error_message'];
				}
				$combined_error_text = strtolower(trim($item['error_code'] . ' ' . $item['parsed_error_message'] . ' ' . $item['error_details'] . ' ' . $item['error']));
				if (strpos($combined_error_text, 'product_is_out_of_spec') !== false) {
					$summary = $item['request_summary'];
					$summary_text = 'agreement=' . (isset($summary['agreement_id']) ? $summary['agreement_id'] : '—') . ', product=' . (isset($summary['product_id']) ? $summary['product_id'] : '—') . ', country=' . (isset($summary['country']) ? $summary['country'] : '—') . ', postcode=' . (isset($summary['postcode']) ? $summary['postcode'] : '—') . ', kolli=' . (isset($summary['number_of_packages']) ? $summary['number_of_packages'] : '—') . ', servicepartner=' . (isset($summary['selected_servicepartner']) && $summary['selected_servicepartner'] !== '' ? $summary['selected_servicepartner'] : 'ikke valgt');
					$item['human_error'] = 'Produktet er sannsynligvis utenfor spesifikasjon. Vanlige årsaker er antall kolli, mål, vekt, volum eller manglende obligatoriske felter for valgt produkt. Request: ' . $summary_text;
					$is_pickup_related = $this->is_method_explicitly_pickup_point($method_payload) || strpos($combined_error_text, 'pickup') !== false || strpos($combined_error_text, 'servicepoint') !== false || strpos($combined_error_text, 'service point') !== false || strpos($combined_error_text, 'locker') !== false || strpos($combined_error_text, 'parcel locker') !== false || strpos($combined_error_text, 'pakkeboks') !== false || strpos($combined_error_text, 'hentested') !== false;
					if ($is_pickup_related && $method_payload['servicepartner'] === '') {
						$item['human_error'] .= ' Valgt produkt ser ut til å være pickup point-relatert, og servicepartner er ikke valgt.';
					}
				} elseif ((strpos($combined_error_text, 'mobiltelefon') !== false || strpos($combined_error_text, 'mobile') !== false) && (strpos($combined_error_text, 'mottaker') !== false || strpos($combined_error_text, 'recipient') !== false || strpos($combined_error_text, 'consignee') !== false)) {
					$item['human_error'] = 'Denne metoden krever mobiltelefonnummer på mottaker. Estimatoren sender shipping/freight phone som <mobile>, med billing phone som fallback; legg inn telefonnummer på ordren hvis feltet mangler.';
				} elseif (strpos($combined_error_text, 'servicepartner') !== false && (strpos($combined_error_text, 'må angis') !== false || strpos($combined_error_text, 'must be specified') !== false || strpos($combined_error_text, 'missing') !== false)) {
					$item['human_error'] = 'Denne metoden krever servicepartner. Hent servicepartnere og velg en verdi før du prøver igjen.';
				} elseif ((strpos($combined_error_text, 'kolli') !== false || strpos($combined_error_text, 'package') !== false) && (strpos($combined_error_text, 'max') !== false || strpos($combined_error_text, '1') !== false || strpos($combined_error_text, 'one') !== false)) {
					$item['human_error'] = 'Produktet ser ut til å tillate maks 1 kolli. Reduser antall kolli og prøv igjen.';
				}
				if ($this->estimate_requires_servicepartner($combined_error_text)) {
					$item['requires_servicepartner'] = true;
					if (!$auto_servicepartner_attempted && $this->should_attempt_servicepartner_autoselection($method_payload) && $method_payload['servicepartner'] === '') {
						$auto_servicepartner_attempted = true;
						$resolved_selection = $this->resolve_default_servicepartner_selection($method_payload, $recipient);
						$method_payload = $this->apply_servicepartner_resolution_to_method_payload($method_payload, $resolved_selection);
						$item['selected_servicepartner'] = $method_payload['servicepartner'];
						$item['selected_servicepartner_customer_number'] = isset($method_payload['servicepartner_customer_number']) ? $method_payload['servicepartner_customer_number'] : '';
						$item['selected_servicepartner_option'] = isset($method_payload['servicepartner_selected_option']) && is_array($method_payload['servicepartner_selected_option']) ? $method_payload['servicepartner_selected_option'] : array();
						$item['servicepartner_selection_source'] = isset($resolved_selection['servicepartner_selection_source']) ? $resolved_selection['servicepartner_selection_source'] : 'none';
						$item['servicepartner_auto_selected'] = !empty($resolved_selection['servicepartner_auto_selected']);
						$item['auto_selection_reason'] = isset($resolved_selection['auto_selection_reason']) ? $resolved_selection['auto_selection_reason'] : '';
						$item['servicepartner_fetch'] = isset($resolved_selection['servicepartner_fetch']) ? $resolved_selection['servicepartner_fetch'] : array();
						$item['servicepartner_options'] = isset($resolved_selection['servicepartner_options']) ? $resolved_selection['servicepartner_options'] : array();
					} else {
						$servicepartner_lookup_method = $method_payload;
						$servicepartner_lookup_method['country'] = isset($recipient['country']) ? $recipient['country'] : '';
						$servicepartner_lookup_method['postcode'] = isset($recipient['postcode']) ? $recipient['postcode'] : '';
						$servicepartner_result = $this->fetch_servicepartner_options($servicepartner_lookup_method);
						$item['servicepartner_fetch'] = $servicepartner_result;
						$item['servicepartner_options'] = isset($servicepartner_result['options']) && is_array($servicepartner_result['options']) ? $servicepartner_result['options'] : array();
					}
				}
				if ($this->estimate_requires_sms_service($combined_error_text)) {
					$item['requires_sms_service'] = true;
					if ($item['sms_service_id'] === '') {
						$item['sms_service_missing'] = true;
						$item['sms_service_error'] = 'SMS Varsling ble krevd, men tjenesten ble ikke funnet i transport_agreements for dette produktet.';
					}
				}
				$results[] = $item;
				continue;
			}

			$price_fields = $this->parse_estimate_price_fields($body);
			$item['estimated_cost'] = $price_fields['estimated_cost'];
			$item['gross_amount'] = $price_fields['gross_amount'];
			$item['net_amount'] = $price_fields['net_amount'];
			$item['fallback_price'] = $price_fields['fallback_price'];

			// Ny prisflyt: velg kilde -> tillegg -> mva -> avrunding -> tilbakeføring til eks mva.
			$selected_price = $this->select_estimate_price_value($price_fields, $item['price_source_config']);
			$item['selected_price_source'] = $selected_price['source'];
			$item['selected_price_value'] = $selected_price['value'];
			$item['configured_price_source_key'] = isset($selected_price['configured_key']) ? $selected_price['configured_key'] : '';
			$item['price_source_fallback_used'] = !empty($selected_price['used_fallback']);
			$item['price_source_priority'] = isset($selected_price['fallback_priority']) ? $selected_price['fallback_priority'] : array();
			$item['actual_fallback_priority'] = isset($selected_price['actual_fallback_priority']) ? $selected_price['actual_fallback_priority'] : array();
			$item['fallback_step_used'] = isset($selected_price['fallback_step_used']) ? (int) $selected_price['fallback_step_used'] : 0;
			if ($item['price_source_fallback_used']) {
				$item['price_source_fallback_reason'] = 'Konfigurert kilde (' . (isset($selected_price['configured_key']) ? $selected_price['configured_key'] : 'ukjent') . ') manglet eller var tom. Brukte ' . ($selected_price['source'] !== '' ? $selected_price['source'] : 'ingen kilde') . '.';
			}
			$item['estimated_price'] = $selected_price['value'];
			$item['original_price'] = $selected_price['value'];

			$calculated = $this->calculate_estimate_from_price_source($selected_price, $item);
			$item['status'] = $calculated['status'];
			if ($calculated['error'] !== '') {
				$item['error'] = $calculated['error'];
				if ($item['status'] === 'unknown') {
					$item['parsed_error_message'] = $this->parse_response_error_message($body);
				}
			} else {
				$item['original_price'] = $calculated['original_price'];
				$item['original_list_price'] = $calculated['original_list_price'];
				$item['manual_handling_fee'] = $calculated['manual_handling_fee'];
				$item['bring_manual_handling_fee'] = $calculated['bring_manual_handling_fee'];
				$item['total_handling_fee'] = $calculated['total_handling_fee'];
				$item['bring_manual_handling_triggered'] = !empty($calculated['bring_manual_handling_triggered']);
				$item['bring_manual_handling_package_count'] = isset($calculated['bring_manual_handling_package_count']) ? (int) $calculated['bring_manual_handling_package_count'] : 0;
				$item['extracted_handling_fee'] = $calculated['extracted_handling_fee'];
				$item['extracted_toll_surcharge'] = $calculated['extracted_toll_surcharge'];
				$item['extracted_fuel_percent'] = $calculated['extracted_fuel_percent'];
				$item['extracted_base_freight'] = $calculated['extracted_base_freight'];
				$item['discounted_base_freight'] = $calculated['discounted_base_freight'];
				$item['recalculated_fuel_surcharge'] = $calculated['recalculated_fuel_surcharge'];
				$item['base_price'] = $calculated['base_price'];
				$item['discount_percent'] = $calculated['discount_percent'];
				$item['discounted_base'] = $calculated['discounted_base'];
				$item['fuel_surcharge'] = $calculated['fuel_surcharge'];
				$item['toll_surcharge'] = $calculated['toll_surcharge'];
				$item['handling_fee'] = $calculated['handling_fee'];
				$item['subtotal_ex_vat'] = $calculated['subtotal_ex_vat'];
				$item['price_incl_vat'] = $calculated['price_incl_vat'];
				$item['rounded_price'] = $calculated['rounded_price'];
				$item['final_price_ex_vat'] = $calculated['final_price_ex_vat'];
			}

			$results[] = $item;
		}

		if (!$has_allowed_methods) {
			wp_send_json_error(array('message' => 'Ingen av de valgte fraktmetodene er aktivert i Cargonizer-innstillingene.'), 400);
		}

		wp_send_json_success(array('results' => $results));
	}


	public function ajax_run_bulk_estimate_baseline() {
		$_POST['baseline_flow'] = 1;
		$this->ajax_run_bulk_estimate();
	}

	public function ajax_optimize_dsv_estimates() {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(array('message' => 'Ingen tilgang.'), 403);
		}

		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (!wp_verify_nonce($nonce, self::NONCE_ACTION_OPTIMIZE_DSV)) {
			wp_send_json_error(array('message' => 'Ugyldig nonce.'), 403);
		}

		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		$order = $order_id ? wc_get_order($order_id) : false;
		if (!$order) {
			wp_send_json_error(array('message' => 'Ordre ikke funnet.'), 404);
		}

		$packages = isset($_POST['packages']) && is_array($_POST['packages']) ? wp_unslash($_POST['packages']) : array();
		$methods = isset($_POST['methods']) && is_array($_POST['methods']) ? wp_unslash($_POST['methods']) : array();
		$warehouse_profile = $this->resolve_selected_warehouse_profile(isset($_POST['warehouse_profile_id']) ? sanitize_key(wp_unslash($_POST['warehouse_profile_id'])) : '');
		$sender_id_override = isset($warehouse_profile['sender_id']) ? sanitize_text_field((string) $warehouse_profile['sender_id']) : '';
		$enabled_map = $this->get_enabled_method_map();
		$method_pricing = $this->get_enabled_method_pricing();

		if (empty($enabled_map)) {
			wp_send_json_error(array('message' => 'Ingen fraktmetoder er aktivert i Cargonizer-innstillingene.'), 400);
		}
		if (empty($packages) || empty($methods)) {
			wp_send_json_error(array('message' => 'Mangler kolli eller fraktvalg.'), 400);
		}

		$clean_packages = array();
		foreach ($packages as $package) {
			$package_text = $this->sanitize_package_display_text($package);
			$clean_packages[] = array(
				'name' => $package_text,
				'description' => $package_text,
				'weight' => isset($package['weight']) ? (float) $package['weight'] : 0,
				'length' => isset($package['length']) ? (float) $package['length'] : 0,
				'width' => isset($package['width']) ? (float) $package['width'] : 0,
				'height' => isset($package['height']) ? (float) $package['height'] : 0,
			);
		}

		$recipient_country = $this->resolve_recipient_country_for_context($order);
		$recipient = array(
			'name' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
			'address_1' => $order->get_shipping_address_1(),
			'address_2' => $order->get_shipping_address_2(),
			'postcode' => $order->get_shipping_postcode(),
			'city' => $order->get_shipping_city(),
			'country' => isset($recipient_country['normalized']) ? $recipient_country['normalized'] : '',
			'email' => $order->get_billing_email(),
			'phone' => $this->get_order_recipient_phone_for_api($order),
		);
		if ($recipient['name'] === '') {
			$recipient['name'] = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
		}
		if ($recipient['country'] === '') {
			wp_send_json_error(array('message' => 'Ugyldig mottakerland. Land må være en gyldig ISO-2-kode, for eksempel NO.'), 400);
		}

		$results = array();
		foreach ($methods as $method) {
			$selected_service_ids = array();
			if (isset($method['selected_service_ids']) && is_array($method['selected_service_ids'])) {
				foreach ($method['selected_service_ids'] as $selected_service_id) {
					$clean_service_id = sanitize_text_field((string) $selected_service_id);
					if ($clean_service_id !== '') {
						$selected_service_ids[] = $clean_service_id;
					}
				}
			}
			$sanitized_method_payload = $this->sanitize_posted_method_payload($method);
			$method_payload = array(
				'key' => isset($method['key']) ? sanitize_text_field($method['key']) : '',
				'sender_profile_id' => isset($method['sender_profile_id']) ? sanitize_key((string) $method['sender_profile_id']) : '',
				'sender_profile_name' => isset($method['sender_profile_name']) ? sanitize_text_field((string) $method['sender_profile_name']) : '',
				'sender_id' => isset($method['sender_id']) ? sanitize_text_field((string) $method['sender_id']) : '',
				'sender_entity_id' => isset($method['sender_entity_id']) ? sanitize_text_field((string) $method['sender_entity_id']) : (isset($warehouse_profile['sender_entity_id']) ? sanitize_text_field((string) $warehouse_profile['sender_entity_id']) : ''),
				'agreement_id' => isset($method['agreement_id']) ? sanitize_text_field($method['agreement_id']) : '',
				'agreement_name' => isset($method['agreement_name']) ? sanitize_text_field($method['agreement_name']) : '',
				'agreement_description' => isset($method['agreement_description']) ? sanitize_text_field($method['agreement_description']) : '',
				'agreement_number' => isset($method['agreement_number']) ? sanitize_text_field($method['agreement_number']) : '',
				'carrier_id' => isset($method['carrier_id']) ? sanitize_text_field($method['carrier_id']) : '',
				'carrier_name' => isset($method['carrier_name']) ? sanitize_text_field($method['carrier_name']) : '',
				'product_id' => isset($method['product_id']) ? sanitize_text_field($method['product_id']) : '',
				'product_name' => isset($method['product_name']) ? sanitize_text_field($method['product_name']) : '',
				'delivery_to_pickup_point' => !empty($method['delivery_to_pickup_point']),
				'delivery_to_home' => !empty($method['delivery_to_home']),
				'servicepartner' => isset($method['servicepartner']) ? sanitize_text_field($method['servicepartner']) : '',
				'servicepartner_customer_number' => isset($method['servicepartner_customer_number']) ? sanitize_text_field($method['servicepartner_customer_number']) : '',
				'servicepartner_selected_option' => isset($sanitized_method_payload['servicepartner_selected_option']) && is_array($sanitized_method_payload['servicepartner_selected_option']) ? $sanitized_method_payload['servicepartner_selected_option'] : array(),
				'use_sms_service' => !empty($method['use_sms_service']),
				'sms_service_id' => isset($method['sms_service_id']) ? sanitize_text_field($method['sms_service_id']) : '',
				'sms_service_name' => isset($method['sms_service_name']) ? sanitize_text_field($method['sms_service_name']) : '',
				'selected_service_ids' => array_values(array_unique($selected_service_ids)),
				'is_manual' => !empty($method['is_manual']),
				'services' => isset($method['services']) && is_array($method['services']) ? $method['services'] : array(),
				'sender_id_override' => !empty($method['sender_id']) ? sanitize_text_field((string) $method['sender_id']) : $sender_id_override,
				'warehouse_profile_id' => isset($warehouse_profile['profile_id']) ? sanitize_key((string) $warehouse_profile['profile_id']) : '',
				'warehouse_profile_name' => isset($warehouse_profile['name']) ? sanitize_text_field((string) $warehouse_profile['name']) : '',
				);
				$method_key = $this->normalize_method_key_for_sender_profile($method_payload, $warehouse_profile);
				$method_payload['key'] = $method_key;
				$method_payload['services'] = $this->filter_services_by_warehouse_availability($method_key, $method_payload['services']);
				$method_payload['selected_service_ids'] = $this->filter_selected_service_ids_by_warehouse_availability($method_key, $method_payload['selected_service_ids'], $method_payload['services']);
				if (!isset($enabled_map[$method_key]) || !$this->method_matches_selected_sender_profile($method_payload, $warehouse_profile) || !$this->is_dsv_method($method_payload) || count($clean_packages) <= 1) {
					continue;
				}
			if ($method_payload['sms_service_id'] === '') {
				$sms_service = $this->find_sms_service_for_method($method);
				$method_payload['sms_service_id'] = $sms_service['service_id'];
				$method_payload['sms_service_name'] = $sms_service['service_name'];
			}

			$pricing_config = isset($method_pricing[$method_key]) && is_array($method_pricing[$method_key]) ? $method_pricing[$method_key] : $this->get_default_method_pricing();
			$discount_percent = isset($pricing_config['discount_percent']) ? $this->sanitize_discount_percent($pricing_config['discount_percent']) : 0;
			$fuel_percent = isset($pricing_config['fuel_surcharge']) ? $this->sanitize_non_negative_number($pricing_config['fuel_surcharge']) : 0;
			$toll_surcharge = isset($pricing_config['toll_surcharge']) ? $this->sanitize_non_negative_number($pricing_config['toll_surcharge']) : 0;
			$bring_manual_handling = $this->get_bring_manual_handling_fee($clean_packages, $method_payload);
			$manual_handling_fee = isset($pricing_config['handling_fee']) ? $this->sanitize_non_negative_number($pricing_config['handling_fee']) : 0;
			$bring_manual_handling_fee = isset($bring_manual_handling['fee']) ? $this->sanitize_non_negative_number($bring_manual_handling['fee']) : 0;
			$bring_manual_handling_triggered = !empty($bring_manual_handling['triggered']);
			if (!$bring_manual_handling_triggered) {
				$bring_manual_handling_fee = 0;
			}
			$pricing_config['manual_handling_fee'] = $manual_handling_fee;
			$pricing_config['bring_manual_handling_fee'] = $bring_manual_handling_fee;
			$pricing_config['bring_manual_handling_triggered'] = $bring_manual_handling_triggered;
			$pricing_config['bring_manual_handling_package_count'] = isset($bring_manual_handling['package_count']) ? (int) $bring_manual_handling['package_count'] : 0;
			$pricing_config['handling_fee'] = round($manual_handling_fee + $bring_manual_handling_fee, 2);

			$item = array(
				'method_key' => $method_key,
				'key' => $method_key,
				'method_name' => $this->format_method_label($method_payload['agreement_name'], $method_payload['product_name'], $method_payload['carrier_name']),
				'agreement_id' => $method_payload['agreement_id'],
				'agreement_name' => $method_payload['agreement_name'],
				'agreement_description' => $method_payload['agreement_description'],
				'agreement_number' => $method_payload['agreement_number'],
				'carrier_id' => $method_payload['carrier_id'],
				'carrier_name' => $method_payload['carrier_name'],
				'product_id' => $method_payload['product_id'],
				'delivery_to_pickup_point' => !empty($pricing_config['delivery_to_pickup_point']),
				'delivery_to_home' => !empty($pricing_config['delivery_to_home']),
				'selected_servicepartner' => $method_payload['servicepartner'],
				'selected_servicepartner_customer_number' => isset($method_payload['servicepartner_customer_number']) ? $method_payload['servicepartner_customer_number'] : '',
				'selected_servicepartner_option' => isset($method_payload['servicepartner_selected_option']) && is_array($method_payload['servicepartner_selected_option']) ? $method_payload['servicepartner_selected_option'] : array(),
				'servicepartner_selection_source' => $method_payload['servicepartner'] !== '' ? (isset($method_payload['servicepartner_selection_source']) && $method_payload['servicepartner_selection_source'] !== '' ? $method_payload['servicepartner_selection_source'] : 'manual') : 'none',
				'servicepartner_auto_selected' => $method_payload['servicepartner'] !== '' && isset($method_payload['servicepartner_selection_source']) && in_array($method_payload['servicepartner_selection_source'], array('automatic', 'prefetched'), true),
				'servicepartner_auto_selected_note' => '',
				'auto_selection_reason' => $method_payload['servicepartner'] !== '' ? (isset($method_payload['servicepartner_selection_source']) && $method_payload['servicepartner_selection_source'] === 'prefetched' ? 'prefetched_nearest_or_first_available_option' : 'manual_selection_present') : '',
				'use_sms_service' => $method_payload['use_sms_service'],
				'sms_service_id' => $method_payload['sms_service_id'],
				'sms_service_name' => $method_payload['sms_service_name'],
				'sender_profile_id' => isset($method_payload['warehouse_profile_id']) ? $method_payload['warehouse_profile_id'] : '',
				'sender_profile_name' => isset($method_payload['warehouse_profile_name']) ? $method_payload['warehouse_profile_name'] : '',
				'sender_id' => isset($method_payload['sender_id_override']) ? $method_payload['sender_id_override'] : '',
				'sender_entity_id' => isset($method_payload['sender_entity_id']) ? $method_payload['sender_entity_id'] : '',
				'requires_sms_service' => false,
				'sms_service_missing' => false,
				'sms_service_error' => '',
				'requires_servicepartner' => false,
				'servicepartner_options' => array(),
				'servicepartner_fetch' => array(),
				'estimated_price' => '',
				'estimated_cost' => '',
				'gross_amount' => '',
				'net_amount' => '',
				'fallback_price' => '',
				'selected_price_source' => '',
				'selected_price_value' => '',
				'price_source_fallback_used' => false,
				'price_source_fallback_reason' => '',
				'price_source_priority' => array(),
				'actual_fallback_priority' => array(),
				'fallback_step_used' => 0,
				'original_price' => '',
				'original_list_price' => '',
				'extracted_handling_fee' => '',
				'extracted_toll_surcharge' => '',
				'extracted_fuel_percent' => '',
				'extracted_base_freight' => '',
				'discounted_base_freight' => '',
				'recalculated_fuel_surcharge' => '',
				'discount_percent' => $discount_percent,
				'discounted_base' => '',
				'fuel_surcharge' => number_format($fuel_percent, 2, '.', ''),
				'toll_surcharge' => number_format($toll_surcharge, 2, '.', ''),
				'handling_fee' => number_format($pricing_config['handling_fee'], 2, '.', ''),
				'price_source_config' => isset($pricing_config['price_source']) ? $pricing_config['price_source'] : 'estimated',
				'configured_price_source_key' => '',
				'vat_percent' => isset($pricing_config['vat_percent']) ? $pricing_config['vat_percent'] : 0,
				'rounding_mode' => isset($pricing_config['rounding_mode']) ? $pricing_config['rounding_mode'] : 'none',
				'manual_handling_fee' => number_format($manual_handling_fee, 2, '.', ''),
				'bring_manual_handling_fee' => number_format($bring_manual_handling_fee, 2, '.', ''),
				'total_handling_fee' => number_format($pricing_config['handling_fee'], 2, '.', ''),
				'bring_manual_handling_triggered' => $bring_manual_handling_triggered,
				'bring_manual_handling_package_count' => isset($bring_manual_handling['package_count']) ? (int) $bring_manual_handling['package_count'] : 0,
				'base_price' => '',
				'subtotal_ex_vat' => '',
				'price_incl_vat' => '',
				'rounded_price' => '',
				'final_price_ex_vat' => '',
				'status' => 'failed',
				'http_status' => 0,
				'error' => '',
				'error_code' => '',
				'error_type' => '',
				'error_details' => '',
				'parsed_error_message' => '',
				'human_error' => '',
				'raw_response' => '',
				'norgespakke_debug' => array(),
				'optimized_partition_used' => false,
				'optimized_shipment_count' => 0,
				'optimized_shipments' => array(),
				'optimization_debug' => array(),
				'optimization_state' => 'done',
				'request_summary' => array(
					'agreement_id' => $method_payload['agreement_id'],
					'product_id' => $method_payload['product_id'],
					'carrier_id' => $method_payload['carrier_id'],
					'carrier_name' => $method_payload['carrier_name'],
					'product_name' => $method_payload['product_name'],
					'country' => isset($recipient['country']) ? $recipient['country'] : '',
					'recipient_country_raw' => isset($recipient_country['raw']) ? $recipient_country['raw'] : '',
					'recipient_country_normalized' => isset($recipient['country']) ? $recipient['country'] : '',
					'postcode' => isset($recipient['postcode']) ? $recipient['postcode'] : '',
					'number_of_packages' => count($clean_packages),
					'delivery_to_pickup_point' => !empty($pricing_config['delivery_to_pickup_point']),
					'delivery_to_home' => !empty($pricing_config['delivery_to_home']),
					'packages' => $clean_packages,
					'selected_servicepartner' => $method_payload['servicepartner'],
					'selected_servicepartner_customer_number' => isset($method_payload['servicepartner_customer_number']) ? $method_payload['servicepartner_customer_number'] : '',
					'servicepartner_selection_source' => $method_payload['servicepartner'] !== '' ? 'manual' : 'none',
					'use_sms_service' => $method_payload['use_sms_service'],
				),
			);

			if ($this->should_attempt_servicepartner_autoselection($method_payload) && $method_payload['servicepartner'] === '') {
				$resolved_selection = $this->resolve_default_servicepartner_selection($method_payload, $recipient);
				$method_payload = $this->apply_servicepartner_resolution_to_method_payload($method_payload, $resolved_selection);
				$item['selected_servicepartner'] = $method_payload['servicepartner'];
				$item['selected_servicepartner_customer_number'] = isset($method_payload['servicepartner_customer_number']) ? $method_payload['servicepartner_customer_number'] : '';
				$item['selected_servicepartner_option'] = isset($method_payload['servicepartner_selected_option']) && is_array($method_payload['servicepartner_selected_option']) ? $method_payload['servicepartner_selected_option'] : array();
				$item['servicepartner_selection_source'] = isset($resolved_selection['servicepartner_selection_source']) ? $resolved_selection['servicepartner_selection_source'] : 'none';
				$item['servicepartner_auto_selected'] = !empty($resolved_selection['servicepartner_auto_selected']);
				$item['auto_selection_reason'] = isset($resolved_selection['auto_selection_reason']) ? $resolved_selection['auto_selection_reason'] : '';
				$item['servicepartner_fetch'] = isset($resolved_selection['servicepartner_fetch']) ? $resolved_selection['servicepartner_fetch'] : array();
				$item['servicepartner_options'] = isset($resolved_selection['servicepartner_options']) ? $resolved_selection['servicepartner_options'] : array();
			}

			$baseline_estimate = $this->run_consignment_estimate_for_packages($clean_packages, $recipient, $method_payload, $pricing_config);
			$item = $this->apply_estimate_result_to_item($item, $baseline_estimate, $method_payload, $recipient);

			$dsv_optimization = $this->optimize_dsv_partition_estimates($clean_packages, $recipient, $method_payload, $pricing_config, $baseline_estimate);
			$item['optimization_debug'] = isset($dsv_optimization['debug']) ? $dsv_optimization['debug'] : array();
			$winner = isset($dsv_optimization['winner']) && is_array($dsv_optimization['winner']) ? $dsv_optimization['winner'] : array();

			if (!empty($winner) && isset($winner['status']) && $winner['status'] === 'ok') {
				if (!empty($dsv_optimization['used'])) {
					$item['optimized_partition_used'] = true;
					$item['optimized_shipment_count'] = isset($winner['shipment_count']) ? (int) $winner['shipment_count'] : 0;
					$item['optimized_shipments'] = isset($winner['groups']) ? $winner['groups'] : array();
					$item['status'] = 'ok';
					$item['selected_price_source'] = 'optimized_partition';
					$item['selected_price_value'] = $winner['total_final_price_ex_vat'];
					$item['estimated_price'] = $winner['total_final_price_ex_vat'];
					$item['original_price'] = $winner['total_final_price_ex_vat'];
					$item['original_list_price'] = $winner['total_final_price_ex_vat'];
					$item['price_source_priority'] = array('optimized_partition');
					$item['actual_fallback_priority'] = array('optimized_partition');
					$item['configured_price_source_key'] = 'optimized_partition';
					$item['fallback_step_used'] = 1;
					$item['subtotal_ex_vat'] = $winner['total_final_price_ex_vat'];
					$item['final_price_ex_vat'] = $winner['total_final_price_ex_vat'];
					$item['rounded_price'] = $winner['total_rounded_price'];
					$item['price_incl_vat'] = $winner['total_rounded_price'];
				}
			} elseif ($baseline_estimate['status'] !== 'ok') {
				$item['status'] = 'failed';
				$item['optimization_state'] = 'failed';
				$item['optimization_debug']['enabled'] = false;
				$item['optimization_debug']['optimization_changed_result'] = false;
				$item['optimization_debug']['reason'] = isset($item['optimization_debug']['reason']) && $item['optimization_debug']['reason'] !== ''
					? $item['optimization_debug']['reason']
					: 'Optimalisering feilet og baseline-estimatet var ikke gyldig.';
				if ($item['error'] === '') {
					$item['error'] = isset($item['optimization_debug']['reason']) ? $item['optimization_debug']['reason'] : 'DSV-optimalisering feilet.';
					$item['parsed_error_message'] = $item['error'];
				}
			} else {
				$item['optimization_state'] = 'failed';
				$item['optimization_debug']['enabled'] = false;
				$item['optimization_debug']['optimization_changed_result'] = false;
				$item['optimization_debug']['reason'] = isset($item['optimization_debug']['reason']) && $item['optimization_debug']['reason'] !== ''
					? $item['optimization_debug']['reason']
					: 'Optimalisering feilet, beholdt baseline-resultat.';
			}

			$results[] = $item;
		}

		wp_send_json_success(array('results' => $results));
	}

	private function get_order_recipient_phone_for_api($order) {
		$shipping_phone = $this->get_order_shipping_phone_for_api($order);
		if ($shipping_phone !== '') {
			return $shipping_phone;
		}
		if ($order && is_object($order) && method_exists($order, 'get_billing_phone')) {
			return sanitize_text_field((string) $order->get_billing_phone());
		}

		return '';
	}

	private function get_order_shipping_phone_for_api($order) {
		if (!$order || !is_object($order)) {
			return '';
		}

		$candidates = array();
		if (method_exists($order, 'get_shipping_phone')) {
			$candidates[] = $order->get_shipping_phone();
		}
		if (method_exists($order, 'get_meta')) {
			$candidates[] = $order->get_meta('_shipping_phone', true);
			$candidates[] = $order->get_meta('shipping_phone', true);
		}

		foreach ($candidates as $candidate) {
			$phone = sanitize_text_field((string) $candidate);
			if ($phone !== '') {
				return $phone;
			}
		}

		return '';
	}

}
