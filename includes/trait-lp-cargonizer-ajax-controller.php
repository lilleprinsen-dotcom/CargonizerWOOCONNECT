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

			if (!$product) {
				continue;
			}

			$separate = get_post_meta($product->get_id(), '_wildrobot_separate_package_for_product', true);
			if ($separate !== 'yes') {
				continue;
			}

			$package_name = get_post_meta($product->get_id(), '_wildrobot_separate_package_for_product_name', true);
			if ($package_name === '') {
				$package_name = $item->get_name();
			}

			$base_package = array(
				'name' => $package_name,
				'description' => $item->get_name(),
				'weight' => get_post_meta($product->get_id(), '_weight', true),
				'length' => get_post_meta($product->get_id(), '_length', true),
				'width' => get_post_meta($product->get_id(), '_width', true),
				'height' => get_post_meta($product->get_id(), '_height', true),
			);

			for ($i = 0; $i < max(1, $quantity); $i++) {
				$packages[] = $base_package;
			}
		}

		$settings = $this->get_settings();
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
				'country' => $order->get_shipping_country(),
				'email' => $order->get_billing_email(),
				'phone' => $order->get_billing_phone(),
			),
			'items' => $items,
			'packages' => $packages,
			'booking_state' => $this->load_order_booking_state($order),
			'booking_defaults' => array(
				'notify_email_to_consignee' => isset($settings['booking_email_notification_default']) ? (int) $this->sanitize_checkbox_value($settings['booking_email_notification_default']) : 1,
			),
		);

		if ($data['recipient']['name'] === '') {
			$data['recipient']['name'] = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
		}

		wp_send_json_success($data);
	}

	private function get_booking_state_meta_key() {
		return '_lp_cargonizer_booking_state';
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
			'servicepartner' => '',
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
						$normalized['print'][$print_key] = is_bool($print_default)
							? (bool) $print[$print_key]
							: sanitize_text_field((string) $print[$print_key]);
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

	private function save_order_booking_state($order, $state) {
		if (!$order || !is_a($order, 'WC_Order')) {
			return;
		}

		$order->update_meta_data($this->get_booking_state_meta_key(), $this->normalize_booking_state($state));
		$order->save();
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

	private function sanitize_posted_packages($packages) {
		$clean_packages = array();
		foreach ($packages as $package) {
			$clean_packages[] = array(
				'name' => isset($package['name']) ? sanitize_text_field($package['name']) : '',
				'description' => isset($package['description']) ? sanitize_text_field($package['description']) : '',
				'weight' => isset($package['weight']) ? (float) $package['weight'] : 0,
				'length' => isset($package['length']) ? (float) $package['length'] : 0,
				'width' => isset($package['width']) ? (float) $package['width'] : 0,
				'height' => isset($package['height']) ? (float) $package['height'] : 0,
			);
		}
		return $clean_packages;
	}

	private function sanitize_posted_method_payload($method) {
		$selected_service_ids = array();
		if (isset($method['selected_service_ids']) && is_array($method['selected_service_ids'])) {
			foreach ($method['selected_service_ids'] as $selected_service_id) {
				$clean_service_id = sanitize_text_field((string) $selected_service_id);
				if ($clean_service_id !== '') {
					$selected_service_ids[] = $clean_service_id;
				}
			}
		}

		$payload = array(
			'key' => isset($method['key']) ? sanitize_text_field($method['key']) : '',
			'agreement_id' => isset($method['agreement_id']) ? sanitize_text_field($method['agreement_id']) : '',
			'agreement_name' => isset($method['agreement_name']) ? sanitize_text_field($method['agreement_name']) : '',
			'agreement_description' => isset($method['agreement_description']) ? sanitize_text_field($method['agreement_description']) : '',
			'agreement_number' => isset($method['agreement_number']) ? sanitize_text_field($method['agreement_number']) : '',
			'carrier_id' => isset($method['carrier_id']) ? sanitize_text_field($method['carrier_id']) : '',
			'carrier_name' => isset($method['carrier_name']) ? sanitize_text_field($method['carrier_name']) : '',
			'product_id' => isset($method['product_id']) ? sanitize_text_field($method['product_id']) : '',
			'product_name' => isset($method['product_name']) ? sanitize_text_field($method['product_name']) : '',
			'servicepartner' => isset($method['servicepartner']) ? sanitize_text_field($method['servicepartner']) : '',
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

		return $payload;
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
			'use_sms_service' => !empty($method_payload['use_sms_service']),
			'sms_service_id' => isset($method_payload['sms_service_id']) ? $method_payload['sms_service_id'] : '',
			'selected_service_ids' => isset($method_payload['selected_service_ids']) && is_array($method_payload['selected_service_ids']) ? $method_payload['selected_service_ids'] : array(),
		), $method_payload);

		if ($xml === '') {
			return $empty_result;
		}

		$response = wp_remote_post('https://api.cargonizer.no/consignment_costs.xml', array(
			'timeout' => 40,
			'headers' => array_merge($this->get_auth_headers(), array('Content-Type' => 'application/xml')),
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
		$method_payload = $this->sanitize_posted_method_payload($methods[0]);
		$notify_email_to_consignee = isset($_POST['notify_email_to_consignee']) ? (bool) $this->sanitize_checkbox_value(wp_unslash($_POST['notify_email_to_consignee'])) : false;
		$method_key = implode('|', array($method_payload['agreement_id'], $method_payload['product_id']));
		if (!isset($enabled_map[$method_key])) {
			wp_send_json_error(array('message' => 'Valgt fraktmetode er ikke aktivert i Cargonizer-innstillingene.'), 400);
		}

		if ($method_payload['sms_service_id'] === '') {
			$sms_service = $this->find_sms_service_for_method($methods[0]);
			$method_payload['sms_service_id'] = $sms_service['service_id'];
			$method_payload['sms_service_name'] = $sms_service['service_name'];
		}

		$recipient = array(
			'name' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
			'address_1' => $order->get_shipping_address_1(),
			'address_2' => $order->get_shipping_address_2(),
			'postcode' => $order->get_shipping_postcode(),
			'city' => $order->get_shipping_city(),
			'country' => $order->get_shipping_country(),
			'email' => $order->get_billing_email(),
			'phone' => $order->get_billing_phone(),
		);
		if ($recipient['name'] === '') {
			$recipient['name'] = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
		}
		if ($notify_email_to_consignee && trim((string) $recipient['email']) === '') {
			wp_send_json_error(array('message' => 'Mottaker mangler e-postadresse, så e-postvarsling kan ikke brukes for denne bookingen.'), 400);
		}

		$xml = $this->build_booking_consignment_xml(array(
			'recipient' => $recipient,
			'packages' => $clean_packages,
			'order_number' => $order->get_order_number(),
			'servicepartner' => $method_payload['servicepartner'],
			'use_sms_service' => $method_payload['use_sms_service'],
			'sms_service_id' => $method_payload['sms_service_id'],
			'selected_service_ids' => isset($method_payload['selected_service_ids']) && is_array($method_payload['selected_service_ids']) ? $method_payload['selected_service_ids'] : array(),
			'notify_email_to_consignee' => $notify_email_to_consignee,
		), $method_payload, array(
			'transfer' => true,
			'booking_request' => false,
		));

		if ($xml === '') {
			wp_send_json_error(array('message' => 'Kunne ikke bygge booking-XML.'), 500);
		}

		$booking_result = $this->create_booking_consignment($xml);
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
		$booking_state['servicepartner'] = $method_payload['servicepartner'];
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
		if ($printer_id !== '') {
			$booking_state['print']['attempted'] = true;
			$booking_state['print']['printer_id'] = $printer_id;
			$booking_state['print']['printer_label'] = $printer_id;
			if ($booking_state['consignment_pdf_url'] === '') {
				$booking_state['print']['success'] = false;
				$booking_state['print']['message'] = 'Mangler consignment PDF-URL fra bookingrespons.';
			} else {
				$pdf_fetch_result = $this->fetch_authenticated_binary_url($booking_state['consignment_pdf_url']);
				if (empty($pdf_fetch_result['success'])) {
					$booking_state['print']['success'] = false;
					$booking_state['print']['message'] = isset($pdf_fetch_result['error']) ? (string) $pdf_fetch_result['error'] : 'Henting av PDF feilet.';
					$booking_state['print']['raw_response'] = isset($pdf_fetch_result['raw_response']) ? (string) $pdf_fetch_result['raw_response'] : '';
				} else {
					$print_result = $this->print_pdf_to_printer($printer_id, isset($pdf_fetch_result['binary']) ? $pdf_fetch_result['binary'] : '');
					$booking_state['print']['success'] = !empty($print_result['success']);
					$booking_state['print']['message'] = !empty($print_result['success']) ? 'PDF sendt til printer.' : (isset($print_result['error']) ? (string) $print_result['error'] : 'Print feilet.');
					$booking_state['print']['raw_response'] = isset($print_result['raw_response']) ? (string) $print_result['raw_response'] : '';
				}
			}
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
		$creator_name = $booking_state['created_by_display_name'] !== '' ? $booking_state['created_by_display_name'] : $booking_state['created_by_user_login'];
		$tracking_link = $booking_state['tracking_url'] !== ''
			? '<a href="' . esc_url($booking_state['tracking_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($booking_state['tracking_url']) . '</a>'
			: 'ikke tilgjengelig';
		$order_note_lines = array(
			'Cargonizer booking opprettet',
			'Opprettet av: ' . ($creator_name !== '' ? $creator_name : 'ukjent bruker'),
			'Consignment: ' . ($booking_state['consignment_number'] !== '' ? $booking_state['consignment_number'] : 'ukjent'),
			'Fraktmetode: ' . $this->format_method_label($method_payload['agreement_name'], $method_payload['product_name'], $method_payload['carrier_name']),
			'Estimert fraktpris: ' . $booking_state['estimated_shipping_price'],
			'Tracking: ' . $tracking_link,
		);
		if (!empty($booking_state['print']['attempted'])) {
			$print_status = !empty($booking_state['print']['success']) ? 'OK' : 'Feilet';
			$print_message = isset($booking_state['print']['message']) ? trim((string) $booking_state['print']['message']) : '';
			if ($print_message !== '') {
				$print_status .= ' - ' . $print_message;
			}
			$order_note_lines[] = 'Utskrift: ' . $print_status;
		}
		$order->add_order_note(implode("\n", $order_note_lines));
		$booking_count = $this->get_booking_count_from_state($booking_state);

		wp_send_json_success(array(
			'message' => 'Shipment booked successfully.',
			'booking' => $booking_state,
			'booking_count' => $booking_count,
			'has_previous_bookings' => $booking_count > 1,
		));
	}


	public function ajax_get_shipping_options() {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(array('message' => 'Ingen tilgang.'), 403);
		}

		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (!wp_verify_nonce($nonce, self::NONCE_ACTION_FETCH_OPTIONS)) {
			wp_send_json_error(array('message' => 'Ugyldig nonce.'), 403);
		}

		$settings = $this->get_settings();
		$available_methods = isset($settings['available_methods']) && is_array($settings['available_methods']) ? $settings['available_methods'] : array();
		$available_methods = $this->ensure_internal_manual_methods($available_methods);

		$allowed_options = $this->filter_options_by_enabled_methods($available_methods);
		if (empty($allowed_options)) {
			wp_send_json_error(array('message' => 'Ingen fraktmetoder er aktivert. Gå til WooCommerce → Cargonizer og aktiver minst én fraktmetode.'), 400);
		}

		$method_pricing = $this->get_enabled_method_pricing();
		foreach ($allowed_options as &$option) {
			$method_key = isset($option['key']) ? (string) $option['key'] : '';
			$pricing = isset($method_pricing[$method_key]) && is_array($method_pricing[$method_key]) ? $method_pricing[$method_key] : $this->get_default_method_pricing();
			$option['delivery_to_pickup_point'] = !empty($pricing['delivery_to_pickup_point']);
			$option['delivery_to_home'] = !empty($pricing['delivery_to_home']);
		}
		unset($option);

		wp_send_json_success(array('options' => $allowed_options));
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
		$order = $order_id ? wc_get_order($order_id) : false;

		$method = array(
			'agreement_id' => isset($_POST['agreement_id']) ? sanitize_text_field(wp_unslash($_POST['agreement_id'])) : '',
			'product_id' => isset($_POST['product_id']) ? sanitize_text_field(wp_unslash($_POST['product_id'])) : '',
			'carrier_id' => isset($_POST['carrier_id']) ? sanitize_text_field(wp_unslash($_POST['carrier_id'])) : '',
			'carrier_name' => isset($_POST['carrier_name']) ? sanitize_text_field(wp_unslash($_POST['carrier_name'])) : '',
			'product_name' => isset($_POST['product_name']) ? sanitize_text_field(wp_unslash($_POST['product_name'])) : '',
			'country' => isset($_POST['recipient_country']) ? sanitize_text_field(wp_unslash($_POST['recipient_country'])) : '',
			'postcode' => isset($_POST['recipient_postcode']) ? sanitize_text_field(wp_unslash($_POST['recipient_postcode'])) : '',
			'city' => isset($_POST['recipient_city']) ? sanitize_text_field(wp_unslash($_POST['recipient_city'])) : '',
			'address' => isset($_POST['recipient_address_1']) ? sanitize_text_field(wp_unslash($_POST['recipient_address_1'])) : '',
		);

		if ($order) {
			$method['country'] = $order->get_shipping_country() !== '' ? $order->get_shipping_country() : $method['country'];
			$method['postcode'] = $order->get_shipping_postcode() !== '' ? $order->get_shipping_postcode() : $method['postcode'];
			$method['city'] = $order->get_shipping_city() !== '' ? $order->get_shipping_city() : $method['city'];
			$method['address'] = $order->get_shipping_address_1() !== '' ? $order->get_shipping_address_1() : $method['address'];
		}

		$servicepartner_result = $this->fetch_servicepartner_options($method);

		if (empty($servicepartner_result['success'])) {
			wp_send_json_error(array(
				'message' => $servicepartner_result['error_message'] !== '' ? $servicepartner_result['error_message'] : 'Henting av servicepartnere feilet.',
				'debug' => $servicepartner_result,
			), 200);
		}

		if (empty($servicepartner_result['options'])) {
			wp_send_json_error(array(
				'message' => $servicepartner_result['error_message'] !== '' ? $servicepartner_result['error_message'] : 'Ingen servicepartnere returnert fra API.',
				'debug' => $servicepartner_result,
			), 200);
		}

		wp_send_json_success(array(
			'options' => $servicepartner_result['options'],
			'debug' => $servicepartner_result,
		));
	}

	public function ajax_get_printers() {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(array('message' => 'Ingen tilgang.'), 403);
		}

		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (!wp_verify_nonce($nonce, self::NONCE_ACTION_PRINTERS)) {
			wp_send_json_error(array('message' => 'Ugyldig nonce.'), 403);
		}

		$printer_result = $this->fetch_printers();
		$default_printer_id = get_user_meta(get_current_user_id(), 'lp_cargonizer_default_printer_id', true);
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
			wp_send_json_error($error, 200);
		}

		wp_send_json_success(array(
			'printers' => isset($printer_result['printers']) && is_array($printer_result['printers']) ? $printer_result['printers'] : array(),
			'default_printer_id' => $default_printer_id,
		));
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
		$enabled_map = $this->get_enabled_method_map();

		if (empty($enabled_map)) {
			wp_send_json_error(array('message' => 'Ingen fraktmetoder er aktivert i Cargonizer-innstillingene.'), 400);
		}

		if (empty($packages) || empty($methods)) {
			wp_send_json_error(array('message' => 'Mangler kolli eller fraktvalg.'), 400);
		}

		$clean_packages = array();
		foreach ($packages as $package) {
			$clean_packages[] = array(
				'name' => isset($package['name']) ? sanitize_text_field($package['name']) : '',
				'description' => isset($package['description']) ? sanitize_text_field($package['description']) : '',
				'weight' => isset($package['weight']) ? (float) $package['weight'] : 0,
				'length' => isset($package['length']) ? (float) $package['length'] : 0,
				'width' => isset($package['width']) ? (float) $package['width'] : 0,
				'height' => isset($package['height']) ? (float) $package['height'] : 0,
			);
		}

		$recipient = array(
			'name' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
			'address_1' => $order->get_shipping_address_1(),
			'address_2' => $order->get_shipping_address_2(),
			'postcode' => $order->get_shipping_postcode(),
			'city' => $order->get_shipping_city(),
			'country' => $order->get_shipping_country(),
		);

		if ($recipient['name'] === '') {
			$recipient['name'] = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
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
			$method_payload = array(
				'key' => isset($method['key']) ? sanitize_text_field($method['key']) : '',
				'agreement_id' => isset($method['agreement_id']) ? sanitize_text_field($method['agreement_id']) : '',
				'agreement_name' => isset($method['agreement_name']) ? sanitize_text_field($method['agreement_name']) : '',
				'agreement_description' => isset($method['agreement_description']) ? sanitize_text_field($method['agreement_description']) : '',
				'agreement_number' => isset($method['agreement_number']) ? sanitize_text_field($method['agreement_number']) : '',
				'carrier_id' => isset($method['carrier_id']) ? sanitize_text_field($method['carrier_id']) : '',
				'carrier_name' => isset($method['carrier_name']) ? sanitize_text_field($method['carrier_name']) : '',
				'product_id' => isset($method['product_id']) ? sanitize_text_field($method['product_id']) : '',
				'product_name' => isset($method['product_name']) ? sanitize_text_field($method['product_name']) : '',
				'servicepartner' => isset($method['servicepartner']) ? sanitize_text_field($method['servicepartner']) : '',
				'use_sms_service' => !empty($method['use_sms_service']),
				'sms_service_id' => isset($method['sms_service_id']) ? sanitize_text_field($method['sms_service_id']) : '',
				'sms_service_name' => isset($method['sms_service_name']) ? sanitize_text_field($method['sms_service_name']) : '',
				'selected_service_ids' => array_values(array_unique($selected_service_ids)),
				'is_manual' => !empty($method['is_manual']),
				'is_manual_norgespakke' => !empty($method['is_manual_norgespakke']),
			);
			if ($method_payload['key'] === '') {
				$method_payload['key'] = implode('|', array($method_payload['agreement_id'], $method_payload['product_id']));
			}
			$method_payload['is_manual_norgespakke'] = $this->is_manual_norgespakke_method($method_payload);
			$method_key = implode('|', array($method_payload['agreement_id'], $method_payload['product_id']));
			if (!isset($enabled_map[$method_key])) {
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

			$item = array(
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
				'use_sms_service' => $method_payload['use_sms_service'],
				'sms_service_id' => $method_payload['sms_service_id'],
				'sms_service_name' => $method_payload['sms_service_name'],
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
					'postcode' => isset($recipient['postcode']) ? $recipient['postcode'] : '',
					'number_of_packages' => count($clean_packages),
					'delivery_to_pickup_point' => !empty($pricing_config['delivery_to_pickup_point']),
					'delivery_to_home' => !empty($pricing_config['delivery_to_home']),
					'packages' => $clean_packages,
					'selected_servicepartner' => $method_payload['servicepartner'],
					'use_sms_service' => $method_payload['use_sms_service'],
				),
			);


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
					$item['rounded_price'] = $manual_calculation['rounded_price'];
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
				'use_sms_service' => $method_payload['use_sms_service'],
				'sms_service_id' => $method_payload['sms_service_id'],
				'selected_service_ids' => isset($method_payload['selected_service_ids']) && is_array($method_payload['selected_service_ids']) ? $method_payload['selected_service_ids'] : array(),
			), $method_payload);

			$response = wp_remote_post('https://api.cargonizer.no/consignment_costs.xml', array(
				'timeout' => 40,
				'headers' => array_merge($this->get_auth_headers(), array('Content-Type' => 'application/xml')),
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
				} elseif (strpos($combined_error_text, 'servicepartner') !== false && (strpos($combined_error_text, 'må angis') !== false || strpos($combined_error_text, 'must be specified') !== false || strpos($combined_error_text, 'missing') !== false)) {
					$item['human_error'] = 'Denne metoden krever servicepartner. Hent servicepartnere og velg en verdi før du prøver igjen.';
				} elseif ((strpos($combined_error_text, 'kolli') !== false || strpos($combined_error_text, 'package') !== false) && (strpos($combined_error_text, 'max') !== false || strpos($combined_error_text, '1') !== false || strpos($combined_error_text, 'one') !== false)) {
					$item['human_error'] = 'Produktet ser ut til å tillate maks 1 kolli. Reduser antall kolli og prøv igjen.';
				}
				if ($this->estimate_requires_servicepartner($combined_error_text)) {
					$item['requires_servicepartner'] = true;
					$servicepartner_lookup_method = $method_payload;
					$servicepartner_lookup_method['country'] = isset($recipient['country']) ? $recipient['country'] : '';
					$servicepartner_lookup_method['postcode'] = isset($recipient['postcode']) ? $recipient['postcode'] : '';
					$servicepartner_result = $this->fetch_servicepartner_options($servicepartner_lookup_method);
					$item['servicepartner_fetch'] = $servicepartner_result;
					$item['servicepartner_options'] = isset($servicepartner_result['options']) && is_array($servicepartner_result['options']) ? $servicepartner_result['options'] : array();
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
			$clean_packages[] = array(
				'name' => isset($package['name']) ? sanitize_text_field($package['name']) : '',
				'description' => isset($package['description']) ? sanitize_text_field($package['description']) : '',
				'weight' => isset($package['weight']) ? (float) $package['weight'] : 0,
				'length' => isset($package['length']) ? (float) $package['length'] : 0,
				'width' => isset($package['width']) ? (float) $package['width'] : 0,
				'height' => isset($package['height']) ? (float) $package['height'] : 0,
			);
		}

		$recipient = array(
			'name' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
			'address_1' => $order->get_shipping_address_1(),
			'address_2' => $order->get_shipping_address_2(),
			'postcode' => $order->get_shipping_postcode(),
			'city' => $order->get_shipping_city(),
			'country' => $order->get_shipping_country(),
		);
		if ($recipient['name'] === '') {
			$recipient['name'] = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
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
			$method_payload = array(
				'agreement_id' => isset($method['agreement_id']) ? sanitize_text_field($method['agreement_id']) : '',
				'agreement_name' => isset($method['agreement_name']) ? sanitize_text_field($method['agreement_name']) : '',
				'agreement_description' => isset($method['agreement_description']) ? sanitize_text_field($method['agreement_description']) : '',
				'agreement_number' => isset($method['agreement_number']) ? sanitize_text_field($method['agreement_number']) : '',
				'carrier_id' => isset($method['carrier_id']) ? sanitize_text_field($method['carrier_id']) : '',
				'carrier_name' => isset($method['carrier_name']) ? sanitize_text_field($method['carrier_name']) : '',
				'product_id' => isset($method['product_id']) ? sanitize_text_field($method['product_id']) : '',
				'product_name' => isset($method['product_name']) ? sanitize_text_field($method['product_name']) : '',
				'servicepartner' => isset($method['servicepartner']) ? sanitize_text_field($method['servicepartner']) : '',
				'use_sms_service' => !empty($method['use_sms_service']),
				'sms_service_id' => isset($method['sms_service_id']) ? sanitize_text_field($method['sms_service_id']) : '',
				'sms_service_name' => isset($method['sms_service_name']) ? sanitize_text_field($method['sms_service_name']) : '',
				'selected_service_ids' => array_values(array_unique($selected_service_ids)),
				'is_manual' => !empty($method['is_manual']),
			);
			$method_key = implode('|', array($method_payload['agreement_id'], $method_payload['product_id']));
			if (!isset($enabled_map[$method_key]) || !$this->is_dsv_method($method_payload) || count($clean_packages) <= 1) {
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
				'use_sms_service' => $method_payload['use_sms_service'],
				'sms_service_id' => $method_payload['sms_service_id'],
				'sms_service_name' => $method_payload['sms_service_name'],
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
					'postcode' => isset($recipient['postcode']) ? $recipient['postcode'] : '',
					'number_of_packages' => count($clean_packages),
					'delivery_to_pickup_point' => !empty($pricing_config['delivery_to_pickup_point']),
					'delivery_to_home' => !empty($pricing_config['delivery_to_home']),
					'packages' => $clean_packages,
					'selected_servicepartner' => $method_payload['servicepartner'],
					'use_sms_service' => $method_payload['use_sms_service'],
				),
			);

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

}
