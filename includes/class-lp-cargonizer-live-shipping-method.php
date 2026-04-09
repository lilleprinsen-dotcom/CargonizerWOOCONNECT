<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Live_Shipping_Method extends WC_Shipping_Method {
	const METHOD_ID = 'lp_cargonizer_live';

	/** @var LP_Cargonizer_Settings_Service */
	private $settings_service;
	/** @var LP_Cargonizer_Api_Service */
	private $api_service;
	/** @var LP_Cargonizer_Estimator_Service */
	private $estimator_service;
	/** @var LP_Cargonizer_Package_Builder */
	private $package_builder;
	/** @var LP_Cargonizer_Method_Rule_Engine */
	private $method_rule_engine;

	public function __construct($instance_id = 0) {
		$this->id = self::METHOD_ID;
		$this->instance_id = absint($instance_id);
		$this->method_title = __('Cargonizer Live', 'lp-cargonizer');
		$this->method_description = __('Live shipping rates from Cargonizer transport agreements.', 'lp-cargonizer');
		$this->supports = array(
			'shipping-zones',
			'instance-settings',
			'instance-settings-modal',
		);

		$this->settings_service = new LP_Cargonizer_Settings_Service(LP_Cargonizer_Connector::OPTION_KEY, LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY);
		$this->api_service = new LP_Cargonizer_Api_Service(function () {
			return $this->settings_service->get_settings();
		});
		$this->estimator_service = new LP_Cargonizer_Estimator_Service(array(
			'sanitize_price_source' => array($this->settings_service, 'sanitize_price_source'),
			'sanitize_rounding_mode' => array($this->settings_service, 'sanitize_rounding_mode'),
			'sanitize_discount_percent' => array($this->settings_service, 'sanitize_discount_percent'),
			'sanitize_non_negative_number' => array($this->settings_service, 'sanitize_non_negative_number'),
			'sanitize_checkbox_value' => array($this->settings_service, 'sanitize_checkbox_value'),
		));

		$package_resolution_service = new LP_Cargonizer_Package_Resolution_Service(function () {
			return $this->settings_service->get_settings();
		});
		$shipping_profile_resolver = new LP_Cargonizer_Shipping_Profile_Resolver(function () {
			return $this->settings_service->get_settings();
		}, $package_resolution_service);
		$this->package_builder = new LP_Cargonizer_Package_Builder($shipping_profile_resolver);
		$this->method_rule_engine = new LP_Cargonizer_Method_Rule_Engine(function () {
			return $this->settings_service->get_settings();
		});

		$this->init();
	}

	public function init() {
		$this->instance_form_fields = array(
			'enabled' => array(
				'title' => __('Enable', 'lp-cargonizer'),
				'type' => 'checkbox',
				'label' => __('Enable Cargonizer Live shipping', 'lp-cargonizer'),
				'default' => 'yes',
			),
			'title' => array(
				'title' => __('Method title', 'lp-cargonizer'),
				'type' => 'text',
				'description' => __('Shown at checkout before carrier-specific labels.', 'lp-cargonizer'),
				'default' => __('Cargonizer Live', 'lp-cargonizer'),
				'desc_tip' => true,
			),
		);
		$this->init_settings();
		$this->enabled = $this->get_option('enabled', 'yes');
		$this->title = $this->get_option('title', __('Cargonizer Live', 'lp-cargonizer'));
		add_action('woocommerce_update_options_shipping_' . $this->id, array($this, 'process_admin_options'));
	}

	public function calculate_shipping($package = array()) {
		if ($this->enabled !== 'yes') {
			return;
		}

		$settings = $this->settings_service->get_settings();
		$live_settings = isset($settings['live_checkout']) && is_array($settings['live_checkout']) ? $settings['live_checkout'] : array();
		if (empty($live_settings['enabled'])) {
			return;
		}

		$destination = isset($package['destination']) && is_array($package['destination']) ? $package['destination'] : array();
		$country = strtoupper(isset($destination['country']) ? (string) $destination['country'] : '');
		if (!empty($live_settings['norway_only_enabled']) && $country !== 'NO') {
			return;
		}

		$methods = $this->get_enabled_live_methods($settings);
		if (empty($methods)) {
			return;
		}

		$package_result = $this->build_package_result($package);
		$order_value = $this->get_order_value($package);
		$eligibility = $this->method_rule_engine->evaluate_methods($methods, $package_result, $order_value);
		$candidates = isset($eligibility['eligible_methods']) ? $eligibility['eligible_methods'] : array();
		if (empty($candidates)) {
			return;
		}

		$quotes = $this->collect_method_quotes($candidates, $package_result, $destination, $settings, $live_settings);
		if (empty($quotes)) {
			$this->add_fallback_rates_if_needed($settings, $live_settings);
			return;
		}

		usort($quotes, function ($left, $right) {
			$left_live = isset($left['live_price']) ? (float) $left['live_price'] : INF;
			$right_live = isset($right['live_price']) ? (float) $right['live_price'] : INF;
			if ($left_live === $right_live) {
				return strcmp((string) $left['method_key'], (string) $right['method_key']);
			}
			return ($left_live < $right_live) ? -1 : 1;
		});

		$rules_by_method = $this->get_rule_overrides_by_method($settings);
		$this->apply_checkout_price_adjustments($quotes, $order_value, $live_settings, $rules_by_method);

		foreach ($quotes as $quote) {
			$rate_id = $this->id . ':' . $this->instance_id . ':' . sanitize_title($quote['method_key']);
			$meta_data = array(
				'transport_agreement_id' => $quote['agreement_id'],
				'carrier_id' => $quote['carrier_id'],
				'product_id' => $quote['product_id'],
				'method_key' => $quote['method_key'],
			);

			if (!empty($quote['pickup_capable'])) {
				$pickup_points = $this->get_pickup_points_for_rate($quote, $destination, $live_settings);
				if (empty($pickup_points)) {
					continue;
				}
				$selected = $this->resolve_selected_pickup_point($rate_id, $pickup_points);
				$meta_data['krokedil_pickup_points'] = $pickup_points;
				$meta_data['krokedil_selected_pickup_point'] = $selected['point'];
				$meta_data['krokedil_selected_pickup_point_id'] = $selected['id'];
			}

			$this->add_rate(array(
				'id' => $rate_id,
				'label' => $quote['label'],
				'cost' => $quote['display_cost'],
				'taxes' => false,
				'meta_data' => $meta_data,
			));
		}
	}

	private function collect_method_quotes($candidates, $package_result, $destination, $settings, $live_settings) {
		$quotes = array();
		foreach ($candidates as $method) {
			$quote = $this->build_quote_for_method($method, $package_result, $destination, $settings, $live_settings);
			if (!empty($quote['success'])) {
				$quotes[] = $quote;
			}
		}
		return $quotes;
	}

	private function build_quote_for_method($method, $package_result, $destination, $settings, $live_settings) {
		$method_key = isset($method['key']) ? (string) $method['key'] : '';
		$method_pricing = $this->resolve_method_pricing($method_key, $settings);
		$recipient = array(
			'name' => trim((string) (isset($destination['first_name']) ? $destination['first_name'] : '') . ' ' . (isset($destination['last_name']) ? $destination['last_name'] : '')),
			'address_1' => isset($destination['address']) ? sanitize_text_field((string) $destination['address']) : (isset($destination['address_1']) ? sanitize_text_field((string) $destination['address_1']) : ''),
			'address_2' => isset($destination['address_2']) ? sanitize_text_field((string) $destination['address_2']) : '',
			'postcode' => $this->api_service->sanitize_postcode(isset($destination['postcode']) ? $destination['postcode'] : ''),
			'city' => sanitize_text_field(isset($destination['city']) ? (string) $destination['city'] : ''),
			'country' => $this->api_service->sanitize_country_code(isset($destination['country']) ? $destination['country'] : ''),
		);
		$packages = isset($package_result['packages']) && is_array($package_result['packages']) ? $package_result['packages'] : array();
		if (empty($recipient['postcode']) || $recipient['country'] === '') {
			return array('success' => false);
		}

		$cache_ttl = isset($live_settings['quote_cache_ttl_seconds']) ? max(0, (int) $live_settings['quote_cache_ttl_seconds']) : 0;
		$cache_key = 'lp_carg_quote_' . md5(wp_json_encode(array($method_key, $recipient, $packages, $method_pricing)));
		if ($cache_ttl > 0) {
			$cached = get_transient($cache_key);
			if (is_array($cached) && !empty($cached['success'])) {
				return $cached;
			}
		}

		$xml = $this->api_service->build_estimate_request_xml(array(
			'recipient' => $recipient,
			'packages' => $packages,
			'selected_service_ids' => array(),
		), $method);
		if ($xml === '') {
			return array('success' => false);
		}

		$timeout = isset($live_settings['quote_timeout_seconds']) ? (float) $live_settings['quote_timeout_seconds'] : 5;
		if ($timeout <= 0) {
			$timeout = 5;
		}
		$response = wp_remote_post('https://api.cargonizer.no/consignment_costs.xml', array(
			'timeout' => $timeout,
			'headers' => array_merge($this->api_service->get_auth_headers(), array(
				'Accept' => 'application/xml',
				'Content-Type' => 'application/xml',
			)),
			'body' => (string) $xml,
		));
		if (is_wp_error($response)) {
			return array('success' => false);
		}

		$status = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);
		if ($status < 200 || $status >= 300 || $body === '') {
			return array('success' => false);
		}

		$price_fields = $this->api_service->parse_estimate_price_fields($body);
		$selected = $this->estimator_service->select_estimate_price_value($price_fields, isset($method_pricing['price_source']) ? $method_pricing['price_source'] : 'estimated');
		$live_price = $this->estimator_service->parse_price_to_number(isset($selected['value']) ? $selected['value'] : '');
		if ($live_price === null) {
			return array('success' => false);
		}

		$bring_handling = $this->estimator_service->get_bring_manual_handling_fee($packages, $method);
		$calc = $this->estimator_service->calculate_estimate_from_price_source(array(
			'source' => isset($selected['source']) ? $selected['source'] : '',
			'value' => (string) $live_price,
		), array(
			'discount_percent' => isset($method_pricing['discount_percent']) ? $method_pricing['discount_percent'] : 0,
			'fuel_surcharge' => isset($method_pricing['fuel_surcharge']) ? $method_pricing['fuel_surcharge'] : 0,
			'toll_surcharge' => isset($method_pricing['toll_surcharge']) ? $method_pricing['toll_surcharge'] : 0,
			'handling_fee' => isset($method_pricing['handling_fee']) ? $method_pricing['handling_fee'] : 0,
			'bring_manual_handling_fee' => isset($bring_handling['fee']) ? $bring_handling['fee'] : 0,
			'bring_manual_handling_triggered' => !empty($bring_handling['triggered']),
			'bring_manual_handling_package_count' => isset($bring_handling['package_count']) ? $bring_handling['package_count'] : 0,
			'vat_percent' => isset($method_pricing['vat_percent']) ? $method_pricing['vat_percent'] : 0,
			'rounding_mode' => isset($method_pricing['rounding_mode']) ? $method_pricing['rounding_mode'] : 'none',
		));
		if (!is_array($calc) || (isset($calc['status']) && $calc['status'] !== 'ok')) {
			return array('success' => false);
		}

		$show_vat = !empty($live_settings['show_prices_including_vat']);
		$display_cost = $show_vat ? (float) $calc['rounded_price'] : (float) $calc['final_price_ex_vat'];
		$customer_title = $this->resolve_customer_title($method, $settings);
		$quote = array(
			'success' => true,
			'method_key' => $method_key,
			'agreement_id' => isset($method['agreement_id']) ? (string) $method['agreement_id'] : '',
			'carrier_id' => isset($method['carrier_id']) ? (string) $method['carrier_id'] : '',
			'product_id' => isset($method['product_id']) ? (string) $method['product_id'] : '',
			'label' => $customer_title,
			'live_price' => (float) $live_price,
			'display_cost' => round(max(0, $display_cost), 2),
			'pickup_capable' => $this->api_service->is_method_explicitly_pickup_point($method),
			'method_payload' => $method,
		);

		if ($cache_ttl > 0) {
			set_transient($cache_key, $quote, $cache_ttl);
		}

		return $quote;
	}

	private function apply_checkout_price_adjustments(&$quotes, $order_value, $live_settings, $rules_by_method) {
		if (empty($quotes)) {
			return;
		}

		$threshold = isset($live_settings['free_shipping_threshold']) ? (float) $live_settings['free_shipping_threshold'] : 1500;
		if ($order_value < $threshold) {
			$low_price = isset($live_settings['low_price_option_amount']) ? (float) $live_settings['low_price_option_amount'] : 69;
			foreach ($quotes as &$quote) {
				$allow = !isset($rules_by_method[$quote['method_key']]['allow_low_price']) || !empty($rules_by_method[$quote['method_key']]['allow_low_price']);
				if (!$allow) {
					continue;
				}
				$quote['display_cost'] = round(max(0, $low_price), 2);
				break;
			}
			unset($quote);
			return;
		}

		$free_strategy = isset($live_settings['free_shipping_strategy']) ? (string) $live_settings['free_shipping_strategy'] : 'cheapest_standard_eligible';
		if ($free_strategy !== 'cheapest_standard_eligible') {
			return;
		}

		foreach ($quotes as &$quote) {
			$allow = !isset($rules_by_method[$quote['method_key']]['allow_free_shipping']) || !empty($rules_by_method[$quote['method_key']]['allow_free_shipping']);
			if (!$allow) {
				continue;
			}
			$quote['display_cost'] = 0.0;
			break;
		}
		unset($quote);
	}

	private function resolve_customer_title($method, $settings) {
		$method_key = isset($method['key']) ? (string) $method['key'] : '';
		$rules = isset($settings['checkout_method_rules']['rules']) && is_array($settings['checkout_method_rules']['rules'])
			? $settings['checkout_method_rules']['rules']
			: array();
		foreach ($rules as $rule) {
			if (!is_array($rule)) {
				continue;
			}
			if ((string) (isset($rule['method_key']) ? $rule['method_key'] : '') !== $method_key) {
				continue;
			}
			$title = isset($rule['customer_title']) ? trim((string) $rule['customer_title']) : '';
			if ($title !== '') {
				return $title;
			}
		}
		return isset($method['label']) ? (string) $method['label'] : $this->title;
	}

	private function resolve_method_pricing($method_key, $settings) {
		$defaults = $this->settings_service->get_default_method_pricing();
		$all = isset($settings['method_pricing']) && is_array($settings['method_pricing']) ? $settings['method_pricing'] : array();
		$current = isset($all[$method_key]) && is_array($all[$method_key]) ? $all[$method_key] : array();
		return wp_parse_args($current, $defaults);
	}

	private function get_enabled_live_methods($settings) {
		$available = isset($settings['available_methods']) && is_array($settings['available_methods']) ? $settings['available_methods'] : array();
		$available = $this->settings_service->ensure_internal_manual_methods($available);
		$enabled_map = $this->settings_service->get_enabled_method_map();
		$methods = array();
		foreach ($available as $method) {
			if (!is_array($method)) {
				continue;
			}
			$key = isset($method['key']) ? sanitize_text_field((string) $method['key']) : '';
			if ($key === '' || !isset($enabled_map[$key])) {
				continue;
			}
			if ($this->settings_service->is_manual_norgespakke_method($method)) {
				continue;
			}
			$methods[] = $method;
		}
		return $methods;
	}

	private function build_package_result($package) {
		$lines = array();
		$contents = isset($package['contents']) && is_array($package['contents']) ? $package['contents'] : array();
		foreach ($contents as $item) {
			$product = isset($item['data']) && is_object($item['data']) ? $item['data'] : null;
			if (!$product) {
				continue;
			}
			$lines[] = array(
				'product' => $product,
				'quantity' => isset($item['quantity']) ? max(1, (int) $item['quantity']) : 1,
				'line_total' => isset($item['line_total']) ? (float) $item['line_total'] : 0,
				'line_name' => method_exists($product, 'get_name') ? (string) $product->get_name() : '',
			);
		}
		return $this->package_builder->build_from_lines($lines);
	}

	private function get_order_value($package) {
		if (function_exists('WC') && WC() && isset(WC()->cart) && is_object(WC()->cart)) {
			return (float) WC()->cart->get_displayed_subtotal();
		}
		return isset($package['contents_cost']) ? (float) $package['contents_cost'] : 0;
	}

	private function get_rule_overrides_by_method($settings) {
		$rules = isset($settings['checkout_method_rules']['rules']) && is_array($settings['checkout_method_rules']['rules'])
			? $settings['checkout_method_rules']['rules']
			: array();
		$result = array();
		foreach ($rules as $rule) {
			if (!is_array($rule)) {
				continue;
			}
			$method_key = isset($rule['method_key']) ? sanitize_text_field((string) $rule['method_key']) : '';
			if ($method_key === '') {
				continue;
			}
			$result[$method_key] = array(
				'allow_low_price' => !isset($rule['allow_low_price']) || !empty($rule['allow_low_price']),
				'allow_free_shipping' => !isset($rule['allow_free_shipping']) || !empty($rule['allow_free_shipping']),
			);
		}
		return $result;
	}

	private function add_fallback_rates_if_needed($settings, $live_settings) {
		$fallback_behavior = isset($live_settings['quote_fallback_behavior']) ? (string) $live_settings['quote_fallback_behavior'] : 'safe_fallback_rate';
		if ($fallback_behavior !== 'safe_fallback_rate') {
			return;
		}

		$fallback = isset($settings['checkout_fallback']) && is_array($settings['checkout_fallback']) ? $settings['checkout_fallback'] : array();
		$rates = isset($fallback['safe_fallback_rates']) && is_array($fallback['safe_fallback_rates']) ? $fallback['safe_fallback_rates'] : array();
		foreach ($rates as $index => $rate) {
			if (!is_array($rate)) {
				continue;
			}
			$label = isset($rate['label']) ? trim((string) $rate['label']) : '';
			if ($label === '') {
				$label = $this->title;
			}
			$price = isset($rate['price']) ? (float) $rate['price'] : 0;
			$this->add_rate(array(
				'id' => $this->id . ':' . $this->instance_id . ':fallback_' . $index,
				'label' => $label,
				'cost' => round(max(0, $price), 2),
				'taxes' => false,
			));
		}
	}

	private function get_pickup_points_for_rate($quote, $destination, $live_settings) {
		$method = isset($quote['method_payload']) && is_array($quote['method_payload']) ? $quote['method_payload'] : array();
		$country = $this->api_service->sanitize_country_code(isset($destination['country']) ? $destination['country'] : '');
		$postcode = $this->api_service->sanitize_postcode(isset($destination['postcode']) ? $destination['postcode'] : '');
		$city = sanitize_text_field(isset($destination['city']) ? (string) $destination['city'] : '');
		$address = sanitize_text_field((string) (isset($destination['address']) ? $destination['address'] : (isset($destination['address_1']) ? $destination['address_1'] : '')));

		if ($country === '' || $postcode === '') {
			return array();
		}

		$lookup_method = array_merge($method, array(
			'agreement_id' => isset($quote['agreement_id']) ? $quote['agreement_id'] : '',
			'carrier_id' => isset($quote['carrier_id']) ? $quote['carrier_id'] : '',
			'product_id' => isset($quote['product_id']) ? $quote['product_id'] : '',
			'country' => $country,
			'postcode' => $postcode,
			'city' => $city,
			'address' => $address,
		));

		$custom = $this->api_service->detect_servicepartner_custom_params($lookup_method);
		$cache_ttl = isset($live_settings['pickup_point_cache_ttl_seconds']) ? max(0, (int) $live_settings['pickup_point_cache_ttl_seconds']) : 300;
		$cache_key = 'lp_carg_pickup_' . md5(wp_json_encode(array(
			'transport_agreement_id' => isset($lookup_method['agreement_id']) ? (string) $lookup_method['agreement_id'] : '',
			'carrier' => isset($lookup_method['carrier_id']) ? (string) $lookup_method['carrier_id'] : '',
			'product' => isset($lookup_method['product_id']) ? (string) $lookup_method['product_id'] : '',
			'country' => $country,
			'postcode' => $postcode,
			'address' => $address,
			'custom' => isset($custom['params']) ? $custom['params'] : array(),
		)));
		if ($cache_ttl > 0) {
			$cached = get_transient($cache_key);
			if (is_array($cached) && !empty($cached)) {
				return $cached;
			}
		}

		$result = $this->api_service->fetch_servicepartner_options($lookup_method);
		$options = isset($result['options']) && is_array($result['options']) ? $result['options'] : array();
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
				'country' => isset($raw['country']) ? (string) $raw['country'] : $country,
				'customer_number' => isset($option['customer_number']) ? (string) $option['customer_number'] : '',
				'distance_meters' => isset($option['distance_meters']) && is_numeric($option['distance_meters']) ? (float) $option['distance_meters'] : null,
				'label' => isset($option['label']) ? (string) $option['label'] : $point_id,
			);
		}

		if ($cache_ttl > 0) {
			set_transient($cache_key, $points, $cache_ttl);
		}

		return $points;
	}

	private function resolve_selected_pickup_point($rate_id, $pickup_points) {
		$first = reset($pickup_points);
		if (!is_array($first)) {
			return array('id' => '', 'point' => array());
		}

		$selected_id = (string) $first['id'];
		$selected_point = $first;
		$session_map = $this->get_pickup_selection_session_map();
		if (isset($session_map[$rate_id]) && is_array($session_map[$rate_id])) {
			$stored_id = isset($session_map[$rate_id]['id']) ? sanitize_text_field((string) $session_map[$rate_id]['id']) : '';
			if ($stored_id !== '') {
				foreach ($pickup_points as $point) {
					$point_id = isset($point['id']) ? (string) $point['id'] : '';
					if ($point_id === $stored_id) {
						$selected_id = $point_id;
						$selected_point = $point;
						break;
					}
				}
			}
		}

		if ($selected_id !== '') {
			$session_map[$rate_id] = array(
				'id' => $selected_id,
				'point' => $selected_point,
			);
			$this->set_pickup_selection_session_map($session_map);
		}

		return array(
			'id' => $selected_id,
			'point' => $selected_point,
		);
	}

	private function get_pickup_selection_session_map() {
		if (!function_exists('WC') || !WC() || !isset(WC()->session) || !WC()->session) {
			return array();
		}
		$value = WC()->session->get('lp_cargonizer_checkout_pickup_selection_map', array());
		return is_array($value) ? $value : array();
	}

	private function set_pickup_selection_session_map($map) {
		if (!function_exists('WC') || !WC() || !isset(WC()->session) || !WC()->session) {
			return;
		}
		WC()->session->set('lp_cargonizer_checkout_pickup_selection_map', is_array($map) ? $map : array());
	}
}
