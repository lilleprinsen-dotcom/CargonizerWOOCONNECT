<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Checkout_Pickup_Compatibility_Layer {
	const AJAX_ACTION_GET = 'lp_cargonizer_get_checkout_pickup_points';
	/** @var LP_Cargonizer_Settings_Service */
	private $settings_service;
	/** @var LP_Cargonizer_Api_Service */
	private $api_service;

	public function __construct() {
		$this->settings_service = new LP_Cargonizer_Settings_Service(LP_Cargonizer_Connector::OPTION_KEY, LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY);
		$this->api_service = new LP_Cargonizer_Api_Service(function () {
			return $this->settings_service->get_settings();
		});
	}

	public function register_hooks() {
		add_action('wp_ajax_' . self::AJAX_ACTION_GET, array($this, 'ajax_get_checkout_pickup_points'));
		add_action('wp_ajax_nopriv_' . self::AJAX_ACTION_GET, array($this, 'ajax_get_checkout_pickup_points'));
		add_filter('woocommerce_package_rates', array($this, 'enrich_package_rates_with_compatibility_meta'), 20, 2);
	}

	public function enrich_package_rates_with_compatibility_meta($rates, $package) {
		if (!is_array($rates)) {
			return $rates;
		}

		$enriched_count = 0;
		$session_map = $this->get_pickup_selection_session_map();
		foreach ($rates as $rate_id => $rate) {
			if (!is_a($rate, 'WC_Shipping_Rate')) {
				continue;
			}

			$normalized_rate_id = (string) $rate->get_id();
			$pickup_points = LP_Cargonizer_Krokedil_Pickup_Meta_Helper::decode_pickup_points_meta($this->rate_meta($rate, 'krokedil_pickup_points'));
			if ((!is_array($pickup_points) || empty($pickup_points)) && $normalized_rate_id !== '' && isset($session_map[$normalized_rate_id]['pickup_points']) && is_array($session_map[$normalized_rate_id]['pickup_points'])) {
				$pickup_points = $session_map[$normalized_rate_id]['pickup_points'];
			}
			if (!is_array($pickup_points) || empty($pickup_points)) {
				continue;
			}

			$selected_id = (string) $this->rate_meta($rate, 'krokedil_selected_pickup_point_id');
			if ($normalized_rate_id !== '' && isset($session_map[$normalized_rate_id]['id'])) {
				$session_selected_id = sanitize_text_field((string) $session_map[$normalized_rate_id]['id']);
				if ($session_selected_id !== '') {
					$selected_id = $session_selected_id;
				}
			}

			$selection = $this->resolve_selection_for_payload($pickup_points, $selected_id);
			$normalized = array(
				'pickup_points' => array_values($pickup_points),
				'selected_pickup_point_id' => $selection['selected_pickup_point_id'],
				'selected_pickup_point' => $selection['selected_pickup_point'],
				'rate_id' => $normalized_rate_id,
				'rate_context' => $this->extract_minimal_rate_context($rate, $normalized_rate_id),
			);

			$rate->add_meta_data('lp_cargonizer_pickup_point_context', $normalized, true);
			$enriched_count++;
		}
		$this->log_pickup_compatibility_debug('Enriched package rates with pickup compatibility metadata.', array(
			'enriched_count' => $enriched_count,
		));

		return $rates;
	}

	public function ajax_get_checkout_pickup_points() {
		try {
			check_ajax_referer($this->get_pickup_nonce_action(), 'nonce');
			if (!$this->is_checkout_or_order_pay_context_request()) {
				wp_send_json_success(array('items' => array()));
			}

			$packages = $this->get_shipping_packages_from_session();
			$payload = array();
			$session_map = $this->get_pickup_selection_session_map();
			$chosen_methods = $this->get_chosen_shipping_methods();

			foreach ($packages as $package_index => $shipping_package) {
				$destination = isset($shipping_package['destination']) && is_array($shipping_package['destination']) ? $shipping_package['destination'] : array();
				$chosen_for_package = isset($chosen_methods[$package_index]) ? (string) $chosen_methods[$package_index] : '';
				$rates = isset($shipping_package['rates']) && is_array($shipping_package['rates']) ? $shipping_package['rates'] : array();
				foreach ($rates as $rate) {
					if (!is_a($rate, 'WC_Shipping_Rate')) {
						continue;
					}

					$rate_id = (string) $rate->get_id();
					if ($rate_id === '' || $rate_id !== $chosen_for_package) {
						continue;
					}
					if (!$this->is_pickup_capable_rate($rate)) {
						continue;
					}
					$pickup_lookup = $this->get_pickup_points_for_rate($rate, $rate_id, $destination, $session_map);
					$pickup_points = isset($pickup_lookup['points']) && is_array($pickup_lookup['points']) ? $pickup_lookup['points'] : array();
					$pickup_state = isset($pickup_lookup['state']) ? (string) $pickup_lookup['state'] : 'unavailable';
					$pickup_message = isset($pickup_lookup['message']) ? (string) $pickup_lookup['message'] : '';
					$rate_context = $this->extract_minimal_rate_context($rate, $rate_id);

					if (!is_array($pickup_points) || empty($pickup_points)) {
						if ($rate_id !== '') {
							$existing_source = isset($session_map[$rate_id]['source']) ? $session_map[$rate_id]['source'] : 'auto_nearest';
							$session_map[$rate_id] = array(
								'id' => '',
								'point' => array(),
								'pickup_points' => array(),
								'source' => $existing_source,
								'rate_context' => $rate_context,
							);
						}
						if ($pickup_state !== 'loading') {
							$this->log_pickup_state_reason($pickup_state, $rate_id, $pickup_message, $rate);
						}
						$this->set_generic_krokedil_selection_session(array(), '');
						$payload[] = array(
							'package_index' => (int) $package_index,
							'rate_id' => $rate_id,
							'method_id' => method_exists($rate, 'get_method_id') ? (string) $rate->get_method_id() : '',
							'pickup_points' => array(),
							'krokedil_pickup_points' => array(),
							'selected_pickup_point_id' => '',
							'krokedil_selected_pickup_point_id' => '',
							'selected_pickup_point' => array(),
							'krokedil_selected_pickup_point' => array(),
							'chosen_rate_id' => $chosen_for_package,
							'rate_context' => $rate_context,
							'state' => $pickup_state,
							'unavailable' => ($pickup_state === 'unavailable'),
							'error' => ($pickup_state === 'error'),
							'message' => $pickup_message,
						);
						continue;
					}
					$selected_id = (string) $this->rate_meta($rate, 'krokedil_selected_pickup_point_id');
					if ($rate_id !== '' && isset($session_map[$rate_id]['id'])) {
						$session_selected_id = sanitize_text_field((string) $session_map[$rate_id]['id']);
						if ($session_selected_id !== '') {
							$selected_id = $session_selected_id;
						}
					}
					$selection = $this->resolve_selection_for_payload($pickup_points, $selected_id);
					$selected_id = $selection['selected_pickup_point_id'];
					$selected_point = $selection['selected_pickup_point'];
					if (!empty($selection['fell_back'])) {
						$this->log_pickup_compatibility_debug('Previously selected pickup point was unavailable in compatibility payload and was replaced with deterministic fallback.', array(
							'rate_id' => $rate_id,
							'requested_pickup_point_id' => isset($selection['requested_pickup_point_id']) ? (string) $selection['requested_pickup_point_id'] : '',
							'fallback_pickup_point_id' => $selected_id,
						));
					}
					if ($rate_id !== '') {
						$session_map[$rate_id] = array(
							'id' => $selected_id,
							'point' => $selected_point,
							'pickup_points' => array_values($pickup_points),
							'source' => isset($session_map[$rate_id]['source']) ? $session_map[$rate_id]['source'] : 'auto_nearest',
							'rate_context' => $rate_context,
						);
					}
					$this->set_generic_krokedil_selection_session($selected_point, $selected_id);

					$payload[] = array(
						'package_index' => (int) $package_index,
						'rate_id' => $rate_id,
						'method_id' => method_exists($rate, 'get_method_id') ? (string) $rate->get_method_id() : '',
						'pickup_points' => array_values($pickup_points),
						'krokedil_pickup_points' => array_values($pickup_points),
						'selected_pickup_point_id' => $selected_id,
						'krokedil_selected_pickup_point_id' => $selected_id,
						'selected_pickup_point' => $selected_point,
						'krokedil_selected_pickup_point' => $selected_point,
						'chosen_rate_id' => $chosen_for_package,
						'rate_context' => $rate_context,
						'state' => 'loaded',
						'unavailable' => false,
						'error' => false,
						'message' => '',
					);
				}
			}
			$this->set_pickup_selection_session_map($session_map);
			$this->log_pickup_compatibility_debug('Generated pickup compatibility payload.', array(
				'items' => count($payload),
				'packages' => count($packages),
			));

			wp_send_json_success(array(
				'items' => $payload,
			));
		} catch (Throwable $throwable) {
			$this->log_pickup_compatibility_debug('Pickup compatibility payload generation failed.', array(
				'error' => $throwable->getMessage(),
			), 'error');
			wp_send_json_success(array('items' => array()));
		}
	}

	private function resolve_selection_for_payload($pickup_points, $selected_id) {
		$requested_id = sanitize_text_field((string) $selected_id);
		$selected_point = $this->resolve_point_by_id($pickup_points, $requested_id);
		$resolved_id = isset($selected_point['id']) ? sanitize_text_field((string) $selected_point['id']) : '';
		$fell_back = ($requested_id !== '' && $resolved_id !== '' && $requested_id !== $resolved_id);
		return array(
			'selected_pickup_point_id' => $resolved_id,
			'selected_pickup_point' => $selected_point,
			'requested_pickup_point_id' => $requested_id,
			'fell_back' => $fell_back,
		);
	}

	private function extract_minimal_rate_context($rate, $rate_id) {
		$context = $this->rate_meta($rate, 'lp_cargonizer_pickup_rate_context');
		if (!is_array($context)) {
			$context = array();
		}
		return array(
			'rate_id' => (string) $rate_id,
			'transport_agreement_id' => isset($context['transport_agreement_id']) ? sanitize_text_field((string) $context['transport_agreement_id']) : '',
			'carrier_id' => isset($context['carrier_id']) ? sanitize_text_field((string) $context['carrier_id']) : '',
			'product_id' => isset($context['product_id']) ? sanitize_text_field((string) $context['product_id']) : '',
			'method_key' => isset($context['method_key']) ? sanitize_text_field((string) $context['method_key']) : '',
		);
	}

	private function get_pickup_points_for_rate($rate, $rate_id, $destination, $session_map = array()) {
		$result = array(
			'points' => array(),
			'state' => 'unavailable',
			'message' => '',
		);
		$pickup_points = LP_Cargonizer_Krokedil_Pickup_Meta_Helper::decode_pickup_points_meta($this->rate_meta($rate, 'krokedil_pickup_points'));
		if (!empty($pickup_points)) {
			$result['points'] = array_values($pickup_points);
			$result['state'] = 'loaded';
			return $result;
		}
		if ($rate_id !== '' && isset($session_map[$rate_id]['pickup_points']) && is_array($session_map[$rate_id]['pickup_points']) && !empty($session_map[$rate_id]['pickup_points'])) {
			$result['points'] = array_values($session_map[$rate_id]['pickup_points']);
			$result['state'] = 'loaded';
			return $result;
		}
		if (!$this->is_pickup_capable_rate($rate)) {
			$result['message'] = 'Rate is not pickup capable.';
			return $result;
		}
		if (!$this->has_minimum_destination_for_pickup_points($destination)) {
			$result['message'] = 'Destination is not complete enough for pickup points.';
			return $result;
		}
		$context = $this->rate_meta($rate, 'lp_cargonizer_pickup_rate_context');
		if (!is_array($context)) {
			$result['state'] = 'error';
			$result['message'] = 'Missing pickup lookup context.';
			return $result;
		}
		$lookup_method = array(
			'agreement_id' => isset($context['transport_agreement_id']) ? sanitize_text_field((string) $context['transport_agreement_id']) : '',
			'carrier_id' => isset($context['carrier_id']) ? sanitize_text_field((string) $context['carrier_id']) : '',
			'product_id' => isset($context['product_id']) ? sanitize_text_field((string) $context['product_id']) : '',
			'country' => $this->api_service->sanitize_country_code(isset($destination['country']) ? $destination['country'] : ''),
			'postcode' => $this->api_service->sanitize_postcode(isset($destination['postcode']) ? $destination['postcode'] : ''),
			'city' => sanitize_text_field(isset($destination['city']) ? (string) $destination['city'] : ''),
			'address' => sanitize_text_field((string) (isset($destination['address']) ? $destination['address'] : (isset($destination['address_1']) ? $destination['address_1'] : ''))),
		);
		$live_settings = $this->get_live_checkout_settings();
		$pickup_timeout = isset($live_settings['pickup_point_timeout_seconds']) ? (float) $live_settings['pickup_point_timeout_seconds'] : 8.0;
		if ($pickup_timeout <= 0) {
			$pickup_timeout = 8.0;
		}
		$pickup_timeout = max(1.0, min(30.0, $pickup_timeout));
		$lookup_method['request_timeout_seconds'] = $pickup_timeout;
		$cache_ttl = isset($live_settings['pickup_point_cache_ttl_seconds']) ? max(0, (int) $live_settings['pickup_point_cache_ttl_seconds']) : 300;
		$custom = $this->api_service->detect_servicepartner_custom_params($lookup_method);
		$cache_key = 'lp_carg_pickup_' . md5(wp_json_encode(array(
			'transport_agreement_id' => $lookup_method['agreement_id'],
			'carrier' => $lookup_method['carrier_id'],
			'product' => $lookup_method['product_id'],
			'country' => $lookup_method['country'],
			'postcode' => $lookup_method['postcode'],
			'city' => $lookup_method['city'],
			'address' => $lookup_method['address'],
			'request_timeout_seconds' => $pickup_timeout,
			'custom' => isset($custom['params']) ? $custom['params'] : array(),
		)));
		if ($cache_ttl > 0) {
			$cached = get_transient($cache_key);
			if (is_array($cached)) {
				$result['points'] = array_values($cached);
				$result['state'] = empty($result['points']) ? 'unavailable' : 'loaded';
				if (empty($result['points'])) {
					$result['message'] = 'No pickup points returned for selected rate.';
				}
				return $result;
			}
		}

		$api_result = $this->api_service->fetch_servicepartner_options($lookup_method);
		$options = isset($api_result['options']) && is_array($api_result['options']) ? $api_result['options'] : array();
		$lookup_result = array(
			'points' => array(),
			'state' => 'unavailable',
			'message' => '',
		);
		if (empty($api_result['success'])) {
			$lookup_result['state'] = 'error';
			$lookup_result['message'] = isset($api_result['error_message']) ? (string) $api_result['error_message'] : 'Pickup point lookup failed.';
			if ($cache_ttl > 0) {
				set_transient($cache_key, array(), $cache_ttl);
			}
			return $lookup_result;
		}
		$points = array();
		foreach ($options as $option) {
			if (!is_array($option)) {
				continue;
			}
			$point_id = isset($option['value']) ? sanitize_text_field((string) $option['value']) : '';
			$raw = isset($option['raw']) && is_array($option['raw']) ? $option['raw'] : array();
			if ($point_id === '') {
				continue;
			}
			$points[] = array(
				'id' => $point_id,
				'name' => isset($raw['name']) ? (string) $raw['name'] : '',
				'address1' => isset($raw['address1']) ? (string) $raw['address1'] : '',
				'address2' => isset($raw['address2']) ? (string) $raw['address2'] : '',
				'postcode' => isset($raw['postcode']) ? (string) $raw['postcode'] : '',
				'city' => isset($raw['city']) ? (string) $raw['city'] : '',
				'country' => isset($raw['country']) ? (string) $raw['country'] : $lookup_method['country'],
				'customer_number' => isset($option['customer_number']) ? (string) $option['customer_number'] : '',
				'distance_meters' => isset($option['distance_meters']) && is_numeric($option['distance_meters']) ? (float) $option['distance_meters'] : null,
				'label' => isset($option['label']) ? (string) $option['label'] : $point_id,
			);
		}
		$points = $this->sort_pickup_points_deterministically($points);
		if ($cache_ttl > 0) {
			set_transient($cache_key, $points, $cache_ttl);
		}
		$lookup_result['points'] = $points;
		$lookup_result['state'] = empty($points) ? 'unavailable' : 'loaded';
		$lookup_result['message'] = empty($points) ? 'No pickup points returned for selected rate.' : '';
		return $lookup_result;
	}

	private function resolve_point_by_id($pickup_points, $point_id) {
		$point_id = sanitize_text_field((string) $point_id);
		if ($point_id === '') {
			$first = reset($pickup_points);
			return is_array($first) ? $first : array();
		}

		foreach ($pickup_points as $point) {
			if (!is_array($point)) {
				continue;
			}
			$current_id = isset($point['id']) ? sanitize_text_field((string) $point['id']) : '';
			if ($current_id === $point_id) {
				return $point;
			}
		}

		$first = reset($pickup_points);
		return is_array($first) ? $first : array();
	}

	private function get_shipping_packages_from_session() {
		if (!$this->has_wc_session()) {
			return array();
		}

		$package_count = 1;
		if (isset(WC()->cart) && WC()->cart && method_exists(WC()->cart, 'get_shipping_packages')) {
			$packages = WC()->cart->get_shipping_packages();
			if (is_array($packages) && !empty($packages)) {
				$package_count = count($packages);
			}
		}

		$result = array();
		for ($i = 0; $i < $package_count; $i++) {
			$package_data = WC()->session->get('shipping_for_package_' . $i, array());
			if (is_array($package_data)) {
				$result[] = $package_data;
			}
		}

		return $result;
	}

	private function get_pickup_selection_session_map() {
		if (!$this->has_wc_session()) {
			return array();
		}
		$value = WC()->session->get($this->get_pickup_session_key(), array());
		return is_array($value) ? $value : array();
	}

	private function set_pickup_selection_session_map($map) {
		if (!$this->has_wc_session()) {
			$this->log_pickup_compatibility_debug('Skipped pickup compatibility session write: WC session unavailable.');
			return;
		}
		WC()->session->set($this->get_pickup_session_key(), $this->sanitize_pickup_session_map($map));
	}

	private function sanitize_pickup_session_map($map) {
		if (!is_array($map)) {
			return array();
		}
		$normalized = array();
		foreach ($map as $rate_id => $row) {
			$rate_id = sanitize_text_field((string) $rate_id);
			if ($rate_id === '' || !is_array($row)) {
				continue;
			}
			$pickup_points = isset($row['pickup_points']) && is_array($row['pickup_points']) ? array_values($row['pickup_points']) : array();
			if (count($pickup_points) > 20) {
				$pickup_points = array_slice($pickup_points, 0, 20);
			}
			$normalized[$rate_id] = array(
				'id' => isset($row['id']) ? sanitize_text_field((string) $row['id']) : '',
				'point' => isset($row['point']) && is_array($row['point']) ? $row['point'] : array(),
				'pickup_points' => $pickup_points,
				'source' => isset($row['source']) ? sanitize_key((string) $row['source']) : 'auto_nearest',
				'rate_context' => isset($row['rate_context']) && is_array($row['rate_context']) ? $row['rate_context'] : array(),
			);
		}
		if (count($normalized) > 30) {
			$normalized = array_slice($normalized, -30, null, true);
		}
		return $normalized;
	}

	private function get_chosen_shipping_methods() {
		if (!$this->has_wc_session()) {
			return array();
		}
		$chosen = WC()->session->get('chosen_shipping_methods', array());
		return is_array($chosen) ? $chosen : array();
	}

	private function has_wc_session() {
		return function_exists('WC') && WC() && isset(WC()->session) && WC()->session;
	}

	private function set_generic_krokedil_selection_session($selected_pickup_point, $selected_pickup_point_id) {
		if (!$this->has_wc_session()) {
			return;
		}
		$selected_pickup_point = is_array($selected_pickup_point) ? $selected_pickup_point : array();
		$selected_pickup_point_id = sanitize_text_field((string) $selected_pickup_point_id);
		WC()->session->set('krokedil_selected_pickup_point', LP_Cargonizer_Krokedil_Pickup_Meta_Helper::encode_pickup_point_for_meta($selected_pickup_point));
		WC()->session->set('krokedil_selected_pickup_point_id', $selected_pickup_point_id);
	}

	private function get_pickup_session_key() {
		return class_exists('LP_Cargonizer_Checkout_Pickup_Controller')
			? LP_Cargonizer_Checkout_Pickup_Controller::SESSION_KEY
			: 'lp_cargonizer_checkout_pickup_selection_map';
	}

	private function get_pickup_nonce_action() {
		return class_exists('LP_Cargonizer_Checkout_Pickup_Controller')
			? LP_Cargonizer_Checkout_Pickup_Controller::NONCE_ACTION
			: 'lp_cargonizer_checkout_pickup_point';
	}

	private function get_live_checkout_settings() {
		$settings = $this->settings_service->get_settings();
		return isset($settings['live_checkout']) && is_array($settings['live_checkout']) ? $settings['live_checkout'] : array();
	}

	private function is_checkout_or_order_pay_context_request() {
		if (function_exists('is_checkout_pay_page') && is_checkout_pay_page()) {
			return true;
		}
		if (function_exists('is_checkout') && is_checkout()) {
			return true;
		}
		if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
			$ajax_action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['action'])) : '';
			if ($ajax_action === 'woocommerce_update_order_review') {
				return true;
			}
		}
		$request_path = isset($_SERVER['REQUEST_URI']) ? wp_parse_url(wp_unslash((string) $_SERVER['REQUEST_URI']), PHP_URL_PATH) : '';
		$request_path = is_string($request_path) ? sanitize_text_field($request_path) : '';
		return $request_path !== '' && (strpos($request_path, '/wc/store/checkout') !== false || strpos($request_path, '/wc/store/v1/checkout') !== false);
	}

	private function is_pickup_capable_rate($rate) {
		return !empty($this->rate_meta($rate, 'lp_cargonizer_pickup_capable'));
	}

	private function log_pickup_state_reason($state, $rate_id, $reason, $rate) {
		if (!$this->should_log_pickup_state()) {
			return;
		}
		if (!function_exists('wc_get_logger')) {
			return;
		}
		$logger = wc_get_logger();
		if (!$logger) {
			return;
		}
		$method_id = method_exists($rate, 'get_method_id') ? (string) $rate->get_method_id() : '';
		$message = 'Checkout pickup selector state update.';
		if ($state === 'error') {
			$message = 'Checkout pickup selector entered error state.';
		} elseif ($state === 'unavailable') {
			$message = 'Checkout pickup selector entered unavailable state.';
		}
		$logger->debug($message, array(
			'source' => 'lp-cargonizer-live-checkout',
			'context' => array(
				'rate_id' => (string) $rate_id,
				'method_id' => $method_id,
				'state' => (string) $state,
				'reason' => (string) $reason,
			),
		));
	}

	private function should_log_pickup_state() {
		$live_settings = $this->get_live_checkout_settings();
		return !empty($live_settings['debug_logging']);
	}

	private function has_minimum_destination_for_pickup_points($destination) {
		$country = $this->api_service->sanitize_country_code(isset($destination['country']) ? $destination['country'] : '');
		$postcode = $this->api_service->sanitize_postcode(isset($destination['postcode']) ? $destination['postcode'] : '');
		$city = sanitize_text_field(isset($destination['city']) ? (string) $destination['city'] : '');
		$address = sanitize_text_field((string) (isset($destination['address']) ? $destination['address'] : (isset($destination['address_1']) ? $destination['address_1'] : '')));
		return $country === 'NO' && $postcode !== '' && $city !== '' && $address !== '';
	}

	private function sort_pickup_points_deterministically($pickup_points) {
		$pickup_points = is_array($pickup_points) ? array_values($pickup_points) : array();
		usort($pickup_points, function ($left, $right) {
			$left_has_distance = isset($left['distance_meters']) && is_numeric($left['distance_meters']);
			$right_has_distance = isset($right['distance_meters']) && is_numeric($right['distance_meters']);
			if ($left_has_distance && $right_has_distance) {
				$left_distance = (float) $left['distance_meters'];
				$right_distance = (float) $right['distance_meters'];
				if ($left_distance !== $right_distance) {
					return ($left_distance < $right_distance) ? -1 : 1;
				}
			} elseif ($left_has_distance !== $right_has_distance) {
				return $left_has_distance ? -1 : 1;
			}
			$left_label = isset($left['label']) ? (string) $left['label'] : '';
			$right_label = isset($right['label']) ? (string) $right['label'] : '';
			$cmp = strcmp($left_label, $right_label);
			if ($cmp !== 0) {
				return $cmp;
			}
			return strcmp(isset($left['id']) ? (string) $left['id'] : '', isset($right['id']) ? (string) $right['id'] : '');
		});
		return $pickup_points;
	}

	private function rate_meta($rate, $key) {
		if (!is_a($rate, 'WC_Shipping_Rate')) {
			return null;
		}
		if (method_exists($rate, 'get_meta')) {
			return $rate->get_meta($key, true);
		}
		$meta_data = method_exists($rate, 'get_meta_data') ? $rate->get_meta_data() : array();
		if (is_array($meta_data) && isset($meta_data[$key])) {
			return $meta_data[$key];
		}
		return null;
	}

	private function log_pickup_compatibility_debug($message, $context = array(), $level = 'debug') {
		if (!$this->should_log_pickup_state()) {
			return;
		}
		if (!function_exists('wc_get_logger')) {
			return;
		}
		$logger = wc_get_logger();
		if (!$logger) {
			return;
		}
		$method = method_exists($logger, $level) ? $level : 'debug';
		$logger->$method((string) $message, array(
			'source' => 'lp-cargonizer-live-checkout',
			'context' => is_array($context) ? $context : array(),
		));
	}
}
