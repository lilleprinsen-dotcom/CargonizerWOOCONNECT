<?php
/*
Plugin Name: Lilleprinsen Cargonizer Connector
Description: Egen WooCommerce-adminside for å lagre Cargonizer-autentisering og hente fraktmetoder fra transport_agreements.xml
Version: 1.0.0
Author: Lilleprinsen
*/

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Connector {
	const OPTION_KEY = 'lp_cargonizer_settings';
	const MANUAL_NORGESPAKKE_KEY = 'manual|norgespakke';
	const NONCE_ACTION_SAVE = 'lp_cargonizer_save_settings';
	const NONCE_ACTION_FETCH = 'lp_cargonizer_fetch_methods';
	const NONCE_ACTION_ORDER_DATA = 'lp_cargonizer_get_order_data';
	const NONCE_ACTION_FETCH_OPTIONS = 'lp_cargonizer_fetch_shipping_options';
	const NONCE_ACTION_ESTIMATE = 'lp_cargonizer_run_bulk_estimate';
	const NONCE_ACTION_ESTIMATE_BASELINE = 'lp_cargonizer_run_bulk_estimate_baseline';
	const NONCE_ACTION_OPTIMIZE_DSV = 'lp_cargonizer_optimize_dsv_estimates';
	const NONCE_ACTION_SERVICEPARTNERS = 'lp_cargonizer_fetch_servicepartners';

	public function __construct() {
		add_action('admin_menu', array($this, 'add_admin_menu'), 99);
		add_action('admin_init', array($this, 'register_settings'));
		add_action('woocommerce_admin_order_data_after_order_details', array($this, 'render_order_estimate_button'));
		add_action('admin_footer', array($this, 'render_estimate_modal'));
		add_action('wp_ajax_lp_cargonizer_get_order_estimate_data', array($this, 'ajax_get_order_estimate_data'));
		add_action('wp_ajax_lp_cargonizer_get_shipping_options', array($this, 'ajax_get_shipping_options'));
		add_action('wp_ajax_lp_cargonizer_run_bulk_estimate', array($this, 'ajax_run_bulk_estimate'));
		add_action('wp_ajax_lp_cargonizer_run_bulk_estimate_baseline', array($this, 'ajax_run_bulk_estimate_baseline'));
		add_action('wp_ajax_lp_cargonizer_optimize_dsv_estimates', array($this, 'ajax_optimize_dsv_estimates'));
		add_action('wp_ajax_lp_cargonizer_get_servicepartner_options', array($this, 'ajax_get_servicepartner_options'));
	}

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

	public function sanitize_settings($input) {
		$current = $this->get_settings();
		$available_methods = isset($input['available_methods']) && is_array($input['available_methods'])
			? $input['available_methods']
			: (isset($current['available_methods']) && is_array($current['available_methods']) ? $current['available_methods'] : array());

		$available_methods = $this->ensure_internal_manual_methods($available_methods);

		$output = array(
			'api_key'   => isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '',
			'sender_id' => isset($input['sender_id']) ? sanitize_text_field($input['sender_id']) : '',
			'available_methods' => array(),
			'enabled_methods' => array(),
			'method_discounts' => array(),
			'method_pricing' => array(),
		);

		// Behold eksisterende verdi hvis felt sendes tomt ved et uhell.
		if ($output['api_key'] === '' && !empty($current['api_key'])) {
			$output['api_key'] = $current['api_key'];
		}
		if ($output['sender_id'] === '' && !empty($current['sender_id'])) {
			$output['sender_id'] = $current['sender_id'];
		}


		$available_map = array();
		foreach ($available_methods as $method) {
			if (!is_array($method)) {
				continue;
			}

			$method_key = isset($method['key']) ? sanitize_text_field((string) $method['key']) : '';
			if ($method_key === '') {
				continue;
			}

			$clean_method = array(
				'key' => $method_key,
				'agreement_id' => isset($method['agreement_id']) ? sanitize_text_field((string) $method['agreement_id']) : '',
				'agreement_name' => isset($method['agreement_name']) ? sanitize_text_field((string) $method['agreement_name']) : '',
				'agreement_description' => isset($method['agreement_description']) ? sanitize_text_field((string) $method['agreement_description']) : '',
				'agreement_number' => isset($method['agreement_number']) ? sanitize_text_field((string) $method['agreement_number']) : '',
				'carrier_id' => isset($method['carrier_id']) ? sanitize_text_field((string) $method['carrier_id']) : '',
				'carrier_name' => isset($method['carrier_name']) ? sanitize_text_field((string) $method['carrier_name']) : '',
				'product_id' => isset($method['product_id']) ? sanitize_text_field((string) $method['product_id']) : '',
				'product_name' => isset($method['product_name']) ? sanitize_text_field((string) $method['product_name']) : '',
				'is_manual' => ($method_key === self::MANUAL_NORGESPAKKE_KEY) && !empty($method['is_manual']),
				'is_manual_norgespakke' => ($method_key === self::MANUAL_NORGESPAKKE_KEY),
				'label' => isset($method['label']) ? sanitize_text_field((string) $method['label']) : '',
				'services' => array(),
			);

			if (isset($method['services']) && is_array($method['services'])) {
				foreach ($method['services'] as $service) {
					if (!is_array($service)) {
						continue;
					}
					$service_id = isset($service['service_id']) ? sanitize_text_field((string) $service['service_id']) : '';
					$service_name = isset($service['service_name']) ? sanitize_text_field((string) $service['service_name']) : '';
					if ($service_id === '' && $service_name === '') {
						continue;
					}
					$clean_method['services'][] = array(
						'service_id' => $service_id,
						'service_name' => $service_name,
					);
				}
			}

			$output['available_methods'][] = $clean_method;
			$available_map[$method_key] = true;
		}

		if (isset($input['enabled_methods']) && is_array($input['enabled_methods'])) {
			foreach ($input['enabled_methods'] as $method_key) {
				$clean_key = sanitize_text_field($method_key);
				if ($clean_key !== '' && isset($available_map[$clean_key])) {
					$output['enabled_methods'][] = $clean_key;
				}
			}
		}

		$output['enabled_methods'] = array_values(array_unique($output['enabled_methods']));

		if (isset($input['method_discounts']) && is_array($input['method_discounts'])) {
			$enabled_map = array_fill_keys($output['enabled_methods'], true);
			foreach ($input['method_discounts'] as $method_key => $discount_value) {
				$clean_key = sanitize_text_field((string) $method_key);
				if ($clean_key === '' || !isset($available_map[$clean_key]) || !isset($enabled_map[$clean_key])) {
					continue;
				}

				$output['method_discounts'][$clean_key] = $this->sanitize_discount_percent($discount_value);
			}
		}

		$method_pricing_input = isset($input['method_pricing']) && is_array($input['method_pricing']) ? $input['method_pricing'] : array();
		$enabled_map = array_fill_keys($output['enabled_methods'], true);
		foreach ($method_pricing_input as $method_key => $pricing) {
			$clean_key = sanitize_text_field((string) $method_key);
			if ($clean_key === '' || !isset($available_map[$clean_key]) || !isset($enabled_map[$clean_key])) {
				continue;
			}

			if (!is_array($pricing)) {
				$pricing = array();
			}

			$discount_percent = isset($pricing['discount_percent'])
				? $this->sanitize_discount_percent($pricing['discount_percent'])
				: (isset($output['method_discounts'][$clean_key]) ? $output['method_discounts'][$clean_key] : 0);

			$fuel_percent = $this->sanitize_non_negative_number(isset($pricing['fuel_surcharge']) ? $pricing['fuel_surcharge'] : 0);
			$toll_surcharge = $this->sanitize_non_negative_number(isset($pricing['toll_surcharge']) ? $pricing['toll_surcharge'] : 0);
			$handling_fee = $this->sanitize_non_negative_number(isset($pricing['handling_fee']) ? $pricing['handling_fee'] : 0);

			$output['method_pricing'][$clean_key] = array(
				'discount_percent' => $discount_percent,
				'fuel_surcharge' => $fuel_percent,
				'toll_surcharge' => $toll_surcharge,
				'handling_fee' => $handling_fee,
				'manual_norgespakke_include_handling' => $this->sanitize_checkbox_value(isset($pricing['manual_norgespakke_include_handling']) ? $pricing['manual_norgespakke_include_handling'] : 1),
				'price_source' => $this->sanitize_price_source(isset($pricing['price_source']) ? $pricing['price_source'] : 'estimated'),
				'vat_percent' => $this->sanitize_non_negative_number(isset($pricing['vat_percent']) ? $pricing['vat_percent'] : 0),
				'rounding_mode' => $this->sanitize_rounding_mode(isset($pricing['rounding_mode']) ? $pricing['rounding_mode'] : 'none'),
				'delivery_to_pickup_point' => $this->sanitize_checkbox_value(isset($pricing['delivery_to_pickup_point']) ? $pricing['delivery_to_pickup_point'] : 0),
				'delivery_to_home' => $this->sanitize_checkbox_value(isset($pricing['delivery_to_home']) ? $pricing['delivery_to_home'] : 1),
			);
		}

		foreach ($output['method_discounts'] as $method_key => $discount_value) {
			if (!isset($output['method_pricing'][$method_key])) {
				$output['method_pricing'][$method_key] = array(
					'discount_percent' => $discount_value,
					'fuel_surcharge' => 0,
					'toll_surcharge' => 0,
					'handling_fee' => 0,
					'manual_norgespakke_include_handling' => 1,
					'price_source' => 'estimated',
					'vat_percent' => 0,
					'rounding_mode' => 'none',
					'delivery_to_pickup_point' => 0,
					'delivery_to_home' => 1,
				);
			}
		}

		return $output;
	}

	private function sanitize_discount_percent($value) {
		if (is_string($value)) {
			$value = str_replace(',', '.', $value);
		}

		if (!is_numeric($value)) {
			return 0;
		}

		$discount = (float) $value;
		if ($discount < 0) {
			$discount = 0;
		}
		if ($discount > 100) {
			$discount = 100;
		}

		return round($discount, 2);
	}

	private function sanitize_non_negative_number($value) {
		if (is_string($value)) {
			$value = str_replace(',', '.', $value);
		}

		if (!is_numeric($value)) {
			return 0;
		}

		$number = (float) $value;
		if ($number < 0) {
			$number = 0;
		}

		return round($number, 2);
	}

	private function sanitize_checkbox_value($value) {
		if (is_bool($value)) {
			return $value ? 1 : 0;
		}

		$normalized = strtolower(trim((string) $value));
		$truthy_values = array('1', 'true', 'yes', 'on');

		return in_array($normalized, $truthy_values, true) ? 1 : 0;
	}

	private function sanitize_adjustment_type($value) {
		$type = sanitize_text_field((string) $value);
		return $type === 'percent' ? 'percent' : 'fixed';
	}

	private function sanitize_price_source($value) {
		$source = sanitize_text_field((string) $value);
		$allowed = array('net', 'gross', 'estimated', 'fallback', 'manual_norgespakke');
		return in_array($source, $allowed, true) ? $source : 'estimated';
	}

	private function sanitize_rounding_mode($value) {
		$mode = sanitize_text_field((string) $value);
		$allowed = array('none', 'nearest_1', 'nearest_10', 'price_ending_9');
		return in_array($mode, $allowed, true) ? $mode : 'none';
	}

	private function get_settings() {
		$defaults = array(
			'api_key'   => '',
			'sender_id' => '',
			'available_methods' => array($this->get_manual_norgespakke_method()),
			'enabled_methods' => array(),
			'method_discounts' => array(),
			'method_pricing' => array(),
		);

		$saved = get_option(self::OPTION_KEY, array());

		if (!is_array($saved)) {
			$saved = array();
		}

		return wp_parse_args($saved, $defaults);
	}

	private function get_auth_headers() {
		$settings = $this->get_settings();

		return array(
			'X-Cargonizer-Key'    => $settings['api_key'],
			'X-Cargonizer-Sender' => $settings['sender_id'],
			'Accept'              => 'application/xml',
		);
	}

	private function mask_value($value, $show_last = 4) {
		$value = (string) $value;
		$len = strlen($value);

		if ($len <= $show_last) {
			return str_repeat('*', $len);
		}

		return str_repeat('*', max(0, $len - $show_last)) . substr($value, -$show_last);
	}

	private function fetch_transport_agreements() {
		$url = 'https://api.cargonizer.no/transport_agreements.xml';

		$response = wp_remote_get($url, array(
			'timeout' => 30,
			'headers' => $this->get_auth_headers(),
		));

		if (is_wp_error($response)) {
			return array(
				'success' => false,
				'message' => 'WP Error: ' . $response->get_error_message(),
				'status'  => 0,
				'raw'     => '',
				'data'    => array(),
			);
		}

		$status = wp_remote_retrieve_response_code($response);
		$body   = wp_remote_retrieve_body($response);

		if ($status < 200 || $status >= 300) {
			return array(
				'success' => false,
				'message' => 'Ugyldig respons fra Cargonizer. HTTP-status: ' . $status,
				'status'  => $status,
				'raw'     => $body,
				'data'    => array(),
			);
		}

		if (empty($body)) {
			return array(
				'success' => false,
				'message' => 'Tom respons fra Cargonizer.',
				'status'  => $status,
				'raw'     => '',
				'data'    => array(),
			);
		}

		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($body);

		if ($xml === false) {
			$errors = libxml_get_errors();
			libxml_clear_errors();

			$error_messages = array();
			foreach ($errors as $error) {
				$error_messages[] = trim($error->message);
			}

			return array(
				'success' => false,
				'message' => 'Kunne ikke parse XML-respons: ' . implode(' | ', $error_messages),
				'status'  => $status,
				'raw'     => $body,
				'data'    => array(),
			);
		}

		$parsed = $this->parse_transport_agreements($xml);

		return array(
			'success' => true,
			'message' => 'Autentisering OK og fraktmetoder hentet.',
			'status'  => $status,
			'raw'     => $body,
			'data'    => $parsed,
		);
	}


	private function flatten_shipping_methods($agreements) {
		$options = array();

		if (!is_array($agreements)) {
			return $options;
		}

		foreach ($agreements as $agreement) {
			$agreement_id = isset($agreement['agreement_id']) ? (string) $agreement['agreement_id'] : '';
			$agreement_name = isset($agreement['agreement_name']) ? (string) $agreement['agreement_name'] : '';
			$agreement_description = isset($agreement['agreement_description']) ? (string) $agreement['agreement_description'] : '';
			$agreement_number = isset($agreement['agreement_number']) ? (string) $agreement['agreement_number'] : '';
			$carrier_id = isset($agreement['carrier_id']) ? (string) $agreement['carrier_id'] : '';
			$carrier_name = isset($agreement['carrier_name']) ? (string) $agreement['carrier_name'] : '';
			$display_agreement_name = $agreement_description !== '' ? $agreement_description : $agreement_name;

			if (empty($agreement['products']) || !is_array($agreement['products'])) {
				continue;
			}

			foreach ($agreement['products'] as $product) {
				$product_id = isset($product['product_id']) ? (string) $product['product_id'] : '';
				$product_name = isset($product['product_name']) ? (string) $product['product_name'] : '';
				$key = implode('|', array($agreement_id, $product_id));

				if ($key === '|') {
					continue;
				}

					$options[] = array(
						'key' => $key,
						'agreement_id' => $agreement_id,
						'agreement_name' => $display_agreement_name,
						'agreement_description' => $agreement_description,
						'agreement_number' => $agreement_number,
						'carrier_id' => $carrier_id,
						'carrier_name' => $carrier_name,
						'product_id' => $product_id,
						'product_name' => $product_name,
						'services' => isset($product['services']) && is_array($product['services']) ? $product['services'] : array(),
						'label' => $this->format_method_label($display_agreement_name, $product_name, $carrier_name),
					);
			}
		}

		return $this->ensure_internal_manual_methods($options);
	}


	private function get_manual_norgespakke_method() {
		return array(
			'key' => self::MANUAL_NORGESPAKKE_KEY,
			'agreement_id' => 'manual',
			'agreement_name' => 'Manuell booking',
			'agreement_description' => 'Manuell metode (ingen Cargonizer-estimat)',
			'agreement_number' => '',
			'carrier_id' => 'posten',
			'carrier_name' => 'Posten',
			'product_id' => 'norgespakke',
			'product_name' => 'Norgespakke',
			'services' => array(),
			'is_manual' => true,
			'label' => 'Posten - Norgespakke (manuell)',
		);
	}

	private function ensure_internal_manual_methods($options) {
		if (!is_array($options)) {
			$options = array();
		}

		$manual_norgespakke = $this->get_manual_norgespakke_method();
		$updated_options = array();
		$has_norgespakke = false;

		foreach ($options as $option) {
			if (!is_array($option)) {
				$updated_options[] = $option;
				continue;
			}

			$key = isset($option['key']) ? (string) $option['key'] : '';
			$agreement_id = isset($option['agreement_id']) ? (string) $option['agreement_id'] : '';
			$product_id = isset($option['product_id']) ? (string) $option['product_id'] : '';
			$is_internal_manual = ($key === self::MANUAL_NORGESPAKKE_KEY) || (($agreement_id . '|' . $product_id) === self::MANUAL_NORGESPAKKE_KEY);

			if (!$is_internal_manual) {
				$updated_options[] = $option;
				continue;
			}

			if (!$has_norgespakke) {
				$updated_options[] = array_merge($option, $manual_norgespakke, array('services' => isset($option['services']) && is_array($option['services']) ? $option['services'] : array()));
				$has_norgespakke = true;
			}
		}

		if (!$has_norgespakke) {
			$updated_options[] = $manual_norgespakke;
		}

		return $updated_options;
	}

	private function is_manual_norgespakke_method($method_payload) {
		if (!is_array($method_payload)) {
			return false;
		}

		$explicit_key = isset($method_payload['key']) ? trim((string) $method_payload['key']) : '';
		$key = trim((string) (isset($method_payload['agreement_id']) ? $method_payload['agreement_id'] : '')) . '|' . trim((string) (isset($method_payload['product_id']) ? $method_payload['product_id'] : ''));
		$resolved_key = $explicit_key !== '' ? $explicit_key : $key;

		if ($resolved_key === self::MANUAL_NORGESPAKKE_KEY || $key === self::MANUAL_NORGESPAKKE_KEY) {
			return true;
		}

		if (!empty($method_payload['is_manual_norgespakke']) || !empty($method_payload['is_manual'])) {
			return $resolved_key === self::MANUAL_NORGESPAKKE_KEY || $key === self::MANUAL_NORGESPAKKE_KEY;
		}

		return false;
	}

	private function get_enabled_method_map() {
		$settings = $this->get_settings();
		$enabled = isset($settings['enabled_methods']) && is_array($settings['enabled_methods']) ? $settings['enabled_methods'] : array();
		$map = array();
		foreach ($enabled as $key) {
			$clean_key = sanitize_text_field((string) $key);
			if ($clean_key !== '') {
				$map[$clean_key] = true;
			}
		}
		return $map;
	}

	private function filter_options_by_enabled_methods($options) {
		$enabled_map = $this->get_enabled_method_map();
		if (empty($enabled_map)) {
			return array();
		}

		$filtered = array();
		foreach ($options as $option) {
			$key = isset($option['key']) ? (string) $option['key'] : '';
			if ($key !== '' && isset($enabled_map[$key])) {
				$filtered[] = $option;
			}
		}

		return $filtered;
	}

	private function get_enabled_method_discounts() {
		$settings = $this->get_settings();
		$enabled_map = $this->get_enabled_method_map();
		$discounts = isset($settings['method_discounts']) && is_array($settings['method_discounts']) ? $settings['method_discounts'] : array();
		$clean_discounts = array();

		foreach ($discounts as $method_key => $discount_value) {
			$clean_key = sanitize_text_field((string) $method_key);
			if ($clean_key === '' || !isset($enabled_map[$clean_key])) {
				continue;
			}

			$clean_discounts[$clean_key] = $this->sanitize_discount_percent($discount_value);
		}

		return $clean_discounts;
	}

	private function get_default_method_pricing() {
		return array(
			'discount_percent' => 0,
			'fuel_surcharge' => 0,
			'toll_surcharge' => 0,
			'handling_fee' => 0,
			'manual_norgespakke_include_handling' => 1,
			'price_source' => 'estimated',
			'vat_percent' => 0,
			'rounding_mode' => 'none',
			'delivery_to_pickup_point' => 0,
			'delivery_to_home' => 1,
		);
	}

	private function get_enabled_method_pricing() {
		$settings = $this->get_settings();
		$enabled_map = $this->get_enabled_method_map();
		$pricing_settings = isset($settings['method_pricing']) && is_array($settings['method_pricing']) ? $settings['method_pricing'] : array();
		$discounts = $this->get_enabled_method_discounts();
		$result = array();

		foreach ($enabled_map as $method_key => $_) {
			$default = $this->get_default_method_pricing();
			$default['discount_percent'] = isset($discounts[$method_key]) ? $discounts[$method_key] : 0;
			$raw = isset($pricing_settings[$method_key]) && is_array($pricing_settings[$method_key]) ? $pricing_settings[$method_key] : array();
			$price_source = isset($raw['price_source'])
				? $this->sanitize_price_source($raw['price_source'])
				: 'estimated';
			$fuel_percent = isset($raw['fuel_surcharge'])
				? $this->sanitize_non_negative_number($raw['fuel_surcharge'])
				: 0;
			$toll_surcharge = isset($raw['toll_surcharge'])
				? $this->sanitize_non_negative_number($raw['toll_surcharge'])
				: 0;
			$handling_fee = isset($raw['handling_fee'])
				? $this->sanitize_non_negative_number($raw['handling_fee'])
				: 0;

			$result[$method_key] = array(
				'discount_percent' => isset($raw['discount_percent']) ? $this->sanitize_discount_percent($raw['discount_percent']) : $default['discount_percent'],
				'fuel_surcharge' => round($fuel_percent, 2),
				'toll_surcharge' => round($toll_surcharge, 2),
				'handling_fee' => round($handling_fee, 2),
				'manual_norgespakke_include_handling' => isset($raw['manual_norgespakke_include_handling']) ? $this->sanitize_checkbox_value($raw['manual_norgespakke_include_handling']) : $default['manual_norgespakke_include_handling'],
				'price_source' => $price_source,
				'vat_percent' => isset($raw['vat_percent']) ? $this->sanitize_non_negative_number($raw['vat_percent']) : 0,
				'rounding_mode' => isset($raw['rounding_mode']) ? $this->sanitize_rounding_mode($raw['rounding_mode']) : 'none',
				'delivery_to_pickup_point' => isset($raw['delivery_to_pickup_point']) ? $this->sanitize_checkbox_value($raw['delivery_to_pickup_point']) : $default['delivery_to_pickup_point'],
				'delivery_to_home' => isset($raw['delivery_to_home']) ? $this->sanitize_checkbox_value($raw['delivery_to_home']) : $default['delivery_to_home'],
			);
		}

		return $result;
	}


	private function apply_rounding_mode($value, $mode) {
		$rounded = (float) $value;
		switch ($mode) {
			case 'nearest_1':
				$rounded = round($rounded);
				break;
			case 'nearest_10':
				$rounded = round($rounded / 10) * 10;
				break;
			case 'price_ending_9':
				$rounded = $this->round_up_to_price_ending_9($rounded);
				break;
			case 'none':
			default:
				break;
		}

		return round(max(0, $rounded), 2);
	}

	private function round_up_to_price_ending_9($value) {
		// Strategi for price_ending_9: rund alltid opp til nærmeste pris som slutter på 9.
		$number = (float) $value;
		if ($number <= 9) {
			return 9;
		}

		$candidate = floor($number / 10) * 10 + 9;
		if ($candidate < $number) {
			$candidate += 10;
		}

		return $candidate;
	}

	private function calculate_adjustment_amount($base_price, $type, $value, $max_value = null) {
		$amount = 0.0;
		if ($type === 'percent') {
			$amount = $base_price * ((float) $value) / 100;
			if ($max_value !== null) {
				$amount = min($amount, (float) $max_value);
			}
		} else {
			$amount = (float) $value;
		}

		if ($amount < 0) {
			$amount = 0;
		}

		return round($amount, 2);
	}

	private function parse_price_to_number($price_value) {
		$raw = trim((string) $price_value);
		if ($raw === '') {
			return null;
		}

		$clean = preg_replace('/[^\d,\.\-]/u', '', $raw);
		if ($clean === '' || $clean === '-' || $clean === '.' || $clean === ',') {
			return null;
		}

		$last_dot = strrpos($clean, '.');
		$last_comma = strrpos($clean, ',');
		if ($last_dot !== false && $last_comma !== false) {
			$decimal_separator = $last_dot > $last_comma ? '.' : ',';
			$thousand_separator = $decimal_separator === '.' ? ',' : '.';
			$clean = str_replace($thousand_separator, '', $clean);
			$clean = str_replace($decimal_separator, '.', $clean);
		} elseif ($last_comma !== false) {
			$clean = str_replace(',', '.', $clean);
		}

		if (!is_numeric($clean)) {
			return null;
		}

		return (float) $clean;
	}

	private function parse_transport_agreements($xml) {
		$result = array();

		// Vi prøver å være litt defensive siden XML-strukturen kan variere.
		// Målet her er å hente ut avtaler -> produkter -> tjenester hvis de finnes.
		$agreements = array();

		// Vanligste mønster: root har children som er transport_agreement-noder.
		foreach ($xml->children() as $child) {
			$agreements[] = $child;
		}

		// Hvis root selv representerer én avtale.
		if (empty($agreements) && !empty($xml)) {
			$agreements[] = $xml;
		}

		foreach ($agreements as $agreement) {
			$agreement_id   = $this->xml_value($agreement, array('id', 'transport_agreement_id'));
			$agreement_description = $this->xml_value($agreement, array('description'));
			$agreement_number = $this->xml_value($agreement, array('number'));
			$agreement_name = $agreement_description !== '' ? $agreement_description : $this->xml_value($agreement, array('name', 'title'));
			$carrier_name = $this->xml_value($agreement, array('carrier_name', 'provider_name', 'transporter_name', 'carrier', 'provider', 'transportor', 'transportor_name', 'transportør'));
			$carrier_id = $this->xml_value($agreement, array('carrier_id', 'provider_id', 'transporter_id', 'carrier', 'provider', 'transportor_id'));

			if (isset($agreement->carrier)) {
				if ($carrier_name === '') {
					$carrier_name = $this->xml_value($agreement->carrier, array('name', 'title'));
				}
				if ($carrier_id === '') {
					$carrier_id = $this->xml_value($agreement->carrier, array('id', 'identifier'));
				}
			}

			if (isset($agreement->provider)) {
				if ($carrier_name === '') {
					$carrier_name = $this->xml_value($agreement->provider, array('name', 'title'));
				}
				if ($carrier_id === '') {
					$carrier_id = $this->xml_value($agreement->provider, array('id', 'identifier'));
				}
			}

			$item = array(
				'agreement_id'   => $agreement_id,
				'agreement_name' => $agreement_name,
				'agreement_description' => $agreement_description,
				'agreement_number' => $agreement_number,
				'carrier_id'     => $carrier_id,
				'carrier_name'   => $carrier_name,
				'products'       => array(),
			);

			$product_nodes = array();

			// Sjekk products -> children
			if (isset($agreement->products)) {
				foreach ($agreement->products->children() as $product) {
					$product_nodes[] = $product;
				}
			}

			// Fallback: let etter product direkte under agreement
			if (empty($product_nodes)) {
				foreach ($agreement->children() as $child) {
					if ($child->getName() === 'product') {
						$product_nodes[] = $child;
					}
				}
			}

			foreach ($product_nodes as $product) {
				$product_id   = $this->xml_value($product, array('id', 'identifier', 'product'));
				$product_name = $this->xml_value($product, array('name', 'title'));

				$product_item = array(
					'product_id'   => $product_id,
					'product_name' => $product_name,
					'services'     => array(),
				);

				if (isset($product->services)) {
					foreach ($product->services->children() as $service) {
						$product_item['services'][] = array(
							'service_id'   => $this->xml_value($service, array('id', 'identifier', 'service')),
							'service_name' => $this->xml_value($service, array('name', 'title')),
						);
					}
				}

				$item['products'][] = $product_item;
			}

			$result[] = $item;
		}

		return $result;
	}

	private function format_method_label($agreement_name, $product_name, $carrier_name = '') {
		$agreement_name = trim((string) $agreement_name);
		$product_name = trim((string) $product_name);
		$carrier_name = trim((string) $carrier_name);

		$base_label = trim($agreement_name . ' - ' . $product_name, ' -');
		if ($base_label === '') {
			$base_label = $agreement_name !== '' ? $agreement_name : $product_name;
		}

		if ($carrier_name !== '') {
			return $carrier_name . ' - ' . $base_label;
		}

		return $base_label;
	}

	private function xml_value($node, $possible_keys = array()) {
		foreach ($possible_keys as $key) {
			if (isset($node->{$key}) && trim((string) $node->{$key}) !== '') {
				return trim((string) $node->{$key});
			}
		}
		return '';
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

		echo '<p style="margin-top:12px;"><button type="button" class="button lp-cargonizer-estimate-open" data-order-id="' . esc_attr($order->get_id()) . '">Estimer fraktkostnad</button></p>';
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
				<h2 style="margin-top:0;">Estimer fraktkostnad</h2>
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
					<div style="display:flex;justify-content:space-between;gap:8px;margin-top:16px;align-items:center;">
						<button type="button" class="button button-primary" id="lp-cargonizer-run-estimate">Estimer fraktpris</button>
						<button type="button" class="button" id="lp-cargonizer-close-bottom">Lukk</button>
					</div>
				</div>
			</div>
		</div>

		<script>
		(function(){
			function getOrderIdFromCurrentUrl() {
				try {
					var url = new URL(window.location.href);
					var orderId = url.searchParams.get('post') || url.searchParams.get('id') || '';
					return orderId ? String(orderId) : '';
				} catch (e) {
					return '';
				}
			}

			var modal = document.getElementById('lp-cargonizer-estimate-modal');
			if (!modal) { return; }
			var loading = document.getElementById('lp-cargonizer-estimate-loading');
			var errorBox = document.getElementById('lp-cargonizer-estimate-error');
			var content = document.getElementById('lp-cargonizer-estimate-content');
			var overview = document.getElementById('lp-cargonizer-estimate-overview');
			var recipient = document.getElementById('lp-cargonizer-estimate-recipient');
			var lines = document.getElementById('lp-cargonizer-estimate-lines');
			var colliList = document.getElementById('lp-cargonizer-colli-list');
			var colliValidation = document.getElementById('lp-cargonizer-colli-validation');
			var addBtn = document.getElementById('lp-cargonizer-add-colli');
			var closeBottomBtn = document.getElementById('lp-cargonizer-close-bottom');
			var shippingOptionsList = document.getElementById('lp-cargonizer-shipping-options-list');
			var selectAllShippingBtn = document.getElementById('lp-cargonizer-select-all-shipping');
			var resultsContent = document.getElementById('lp-cargonizer-results-content');
			var runEstimateBtn = document.getElementById('lp-cargonizer-run-estimate');
			var currentOrderId = null;
			var currentRecipient = {};
			var latestEstimateResults = [];

			function esc(s){
				s = (s === null || s === undefined) ? '' : String(s);
				return s.replace(/[&<>"']/g, function(m){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]; });
			}

			function toNum(v){
				var n = parseFloat(v);
				return isNaN(n) ? 0 : n;
			}

			function validateNumberField(input){
				var raw = String(input.value || '').trim();
				var hasValue = raw !== '';
				var value = parseFloat(raw);
				var isValid = !hasValue || (!isNaN(value) && value >= 0);
				input.style.borderColor = isValid ? '' : '#b32d2e';
				input.style.backgroundColor = isValid ? '' : '#fff6f6';
				if (!isValid) {
					input.setAttribute('aria-invalid', 'true');
				} else {
					input.removeAttribute('aria-invalid');
				}
				return isValid;
			}

			function validateColliRow(row){
				var fields = row.querySelectorAll('[data-colli-field="weight"],[data-colli-field="length"],[data-colli-field="width"],[data-colli-field="height"]');
				var valid = true;
				fields.forEach(function(field){
					if (!validateNumberField(field)) {
						valid = false;
					}
				});
				return valid;
			}

			function volumeHtml(row){
				var l = toNum(row.querySelector('[data-colli-field="length"]').value);
				var w = toNum(row.querySelector('[data-colli-field="width"]').value);
				var h = toNum(row.querySelector('[data-colli-field="height"]').value);
				return ((l * w * h) / 1000).toFixed(3);
			}

			function bindVolume(row){
				var out = row.querySelector('.lp-volume');
				var fields = row.querySelectorAll('[data-colli-field="length"],[data-colli-field="width"],[data-colli-field="height"]');
				fields.forEach(function(el){
					el.addEventListener('input', function(){ out.textContent = volumeHtml(row); });
				});
				out.textContent = volumeHtml(row);
			}

			function collectColliData(){
				var rows = colliList.querySelectorAll('.lp-colli-row');
				var allValid = true;
				var packages = Array.prototype.map.call(rows, function(row, index){
					if (!validateColliRow(row)) {
						allValid = false;
					}
					var name = row.querySelector('[data-colli-field="name"]').value.trim();
					var description = row.querySelector('[data-colli-field="description"]').value.trim();
					var weight = toNum(row.querySelector('[data-colli-field="weight"]').value);
					var length = toNum(row.querySelector('[data-colli-field="length"]').value);
					var width = toNum(row.querySelector('[data-colli-field="width"]').value);
					var height = toNum(row.querySelector('[data-colli-field="height"]').value);
					var volume = toNum(((length * width * height) / 1000).toFixed(3));
					return {
						index: index,
						name: name,
						description: description,
						weight: weight,
						length: length,
						width: width,
						height: height,
						volume: volume
					};
				});

				var payload = {
					order_id: currentOrderId,
					packages: packages
				};

				modal.setAttribute('data-colli-payload', JSON.stringify(payload));
				if (!allValid) {
					colliValidation.textContent = 'Én eller flere kolli-rader har ugyldige mål/vekt. Bruk numeriske verdier som er 0 eller høyere.';
					colliValidation.style.display = 'block';
				} else {
					colliValidation.style.display = 'none';
				}

				return {
					isValid: allValid,
					payload: payload
				};
			}

			function createColli(pkg){
				var row = document.createElement('div');
				row.className = 'lp-colli-row';
				row.style.cssText = 'border:1px solid #dcdcde;padding:10px 12px;margin-bottom:8px;background:#fff;box-shadow:0 1px 1px rgba(0,0,0,.03);';
				row.innerHTML = '' +
					'<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">' +
					'<strong class="lp-colli-title">Kolli</strong><button type="button" class="button-link-delete lp-remove-colli">Fjern</button></div>' +
					'<div style="display:grid;grid-template-columns:repeat(2,minmax(180px,1fr)) repeat(4,minmax(110px,1fr));gap:8px;margin-top:8px;align-items:end;">' +
					'<label style="display:flex;flex-direction:column;gap:4px;">Navn<input type="text" class="regular-text lp-colli-name" data-colli-field="name" style="width:100%;" value="'+esc(pkg.name || '')+'"></label>' +
					'<label style="display:flex;flex-direction:column;gap:4px;">Beskrivelse<input type="text" class="regular-text lp-colli-description" data-colli-field="description" style="width:100%;" value="'+esc(pkg.description || '')+'"></label>' +
					'<label style="display:flex;flex-direction:column;gap:4px;">Vekt (kg)<input type="number" step="0.01" min="0" class="small-text lp-colli-weight" data-colli-field="weight" style="width:100%;" value="'+esc(pkg.weight || '')+'"></label>' +
					'<label style="display:flex;flex-direction:column;gap:4px;">Lengde (cm)<input type="number" step="0.01" min="0" class="small-text lp-colli-length" data-colli-field="length" style="width:100%;" value="'+esc(pkg.length || '')+'"></label>' +
					'<label style="display:flex;flex-direction:column;gap:4px;">Bredde (cm)<input type="number" step="0.01" min="0" class="small-text lp-colli-width" data-colli-field="width" style="width:100%;" value="'+esc(pkg.width || '')+'"></label>' +
					'<label style="display:flex;flex-direction:column;gap:4px;">Høyde (cm)<input type="number" step="0.01" min="0" class="small-text lp-colli-height" data-colli-field="height" style="width:100%;" value="'+esc(pkg.height || '')+'"></label>' +
					'</div>' +
					'<div style="margin-top:8px;"><strong>Volum:</strong> <span class="lp-volume" data-colli-field="volume">0.000</span> dm³</div>';
				row.querySelector('.lp-remove-colli').addEventListener('click', function(){ row.remove(); refreshColliRowTitles(); collectColliData(); });
				row.querySelectorAll('[data-colli-field="weight"],[data-colli-field="length"],[data-colli-field="width"],[data-colli-field="height"]').forEach(function(input){
					input.addEventListener('input', function(){
						validateNumberField(input);
						collectColliData();
					});
					input.addEventListener('blur', function(){ validateNumberField(input); });
				});
				row.querySelectorAll('[data-colli-field="name"],[data-colli-field="description"]').forEach(function(input){
					input.addEventListener('input', collectColliData);
				});
				bindVolume(row);
				validateColliRow(row);
				colliList.appendChild(row);
				refreshColliRowTitles();
				collectColliData();
			}

			function refreshColliRowTitles(){
				var rows = colliList.querySelectorAll('.lp-colli-row');
				rows.forEach(function(row, idx){
					var title = row.querySelector('.lp-colli-title');
					if (title) {
						title.textContent = formatColliTitle(idx + 1);
					}
				});
			}


			function renderShippingOptions(options){
				if (!Array.isArray(options) || !options.length) {
					shippingOptionsList.innerHTML = '<em>Ingen fraktvalg funnet.</em>';
					if (selectAllShippingBtn) {
						selectAllShippingBtn.textContent = 'Velg alle';
					}
					return;
				}

				var html = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px;">';
				options.forEach(function(option){
					var label = option.label || ((option.agreement_name || '') + ' - ' + (option.product_name || ''));
					var agreementText = option.agreement_description || option.agreement_name || option.agreement_id || '—';
					var productText = option.product_name || option.product_id || '—';
					var isManualNorgespakke = !!option.is_manual_norgespakke || (((option.agreement_id || '') + '|' + (option.product_id || '')) === 'manual|norgespakke');
					var isManual = !!option.is_manual;
					var deliveryToPickupPoint = !!option.delivery_to_pickup_point;
					var deliveryToHome = !!option.delivery_to_home;
					var deliveryTypes = [];
					if (deliveryToPickupPoint) { deliveryTypes.push('HENTESTED'); }
					if (deliveryToHome) { deliveryTypes.push('HJEMLEVERING'); }
					var deliveryTypeText = deliveryTypes.length ? deliveryTypes.join(' / ') : 'Ikke satt';
					var smsServiceId = '';
					var smsServiceName = '';
					if (Array.isArray(option.services)) {
						option.services.forEach(function(service){
							var serviceName = (service && service.service_name) ? String(service.service_name) : '';
							var lowerServiceName = serviceName.toLowerCase();
							if (!smsServiceId && (lowerServiceName.indexOf('sms varsling') !== -1 || lowerServiceName.indexOf('sms varsel') !== -1 || lowerServiceName.indexOf('sms notification') !== -1)) {
								smsServiceId = (service && service.service_id) ? String(service.service_id) : '';
								smsServiceName = serviceName;
							}
						});
					}
					html += '<label style="display:flex;gap:10px;align-items:flex-start;padding:10px;border:1px solid #dcdcde;background:#fff;line-height:1.35;">' +
						'<input type="checkbox" class="lp-shipping-option" data-method-key="'+esc(option.key || '')+'" data-agreement-id="'+esc(option.agreement_id || '')+'" data-agreement-name="'+esc(option.agreement_name || '')+'" data-agreement-description="'+esc(option.agreement_description || '')+'" data-agreement-number="'+esc(option.agreement_number || '')+'" data-carrier-id="'+esc(option.carrier_id || '')+'" data-carrier-name="'+esc(option.carrier_name || '')+'" data-product-id="'+esc(option.product_id || '')+'" data-product-name="'+esc(option.product_name || '')+'" data-is-manual="'+(isManual ? '1' : '')+'" data-is-manual-norgespakke="'+(isManualNorgespakke ? '1' : '')+'" data-delivery-to-pickup-point="'+(deliveryToPickupPoint ? '1' : '')+'" data-delivery-to-home="'+(deliveryToHome ? '1' : '')+'" data-sms-service-id="'+esc(smsServiceId)+'" data-sms-service-name="'+esc(smsServiceName)+'">' +
						'<span style="display:flex;flex-direction:column;gap:3px;">' +
							'<strong>'+esc(label)+(isManual ? ' <span style="font-weight:400;color:#646970;">(manuell)</span>' : '')+'</strong>' +
							'<span style="color:#646970;">Transportør: '+esc(option.carrier_name || '—')+'</span>' +
							'<span style="color:#646970;">Fraktavtale: '+esc(agreementText)+'</span>' +
							'<span style="color:#646970;">Produkt: '+esc(productText)+'</span>' +
							'<span style="color:#646970;">Levering: '+esc(deliveryTypeText)+'</span>' +
						'</span>' +
					'</label>';
				});
				html += '</div>';
				shippingOptionsList.innerHTML = html;
				if (selectAllShippingBtn) {
					selectAllShippingBtn.textContent = 'Velg alle';
				}
			}

			function toggleSelectAllShippingOptions(){
				var checkboxes = shippingOptionsList.querySelectorAll('.lp-shipping-option');
				if (!checkboxes.length) { return; }
				var allSelected = true;
				checkboxes.forEach(function(input){
					if (!input.checked) {
						allSelected = false;
					}
				});
				var shouldSelect = !allSelected;
				checkboxes.forEach(function(input){
					input.checked = shouldSelect;
				});
				if (selectAllShippingBtn) {
					selectAllShippingBtn.textContent = shouldSelect ? 'Fjern alle' : 'Velg alle';
				}
			}

			function getSelectedMethods(){
				var selected = [];
				shippingOptionsList.querySelectorAll('.lp-shipping-option:checked').forEach(function(input){
					selected.push({
						key: input.getAttribute('data-method-key') || '',
						agreement_id: input.getAttribute('data-agreement-id') || '',
						agreement_name: input.getAttribute('data-agreement-name') || '',
						agreement_description: input.getAttribute('data-agreement-description') || '',
						agreement_number: input.getAttribute('data-agreement-number') || '',
						carrier_id: input.getAttribute('data-carrier-id') || '',
						carrier_name: input.getAttribute('data-carrier-name') || '',
						product_id: input.getAttribute('data-product-id') || '',
						product_name: input.getAttribute('data-product-name') || '',
						is_manual: input.getAttribute('data-is-manual') === '1',
						is_manual_norgespakke: input.getAttribute('data-is-manual-norgespakke') === '1',
						sms_service_id: input.getAttribute('data-sms-service-id') || '',
						sms_service_name: input.getAttribute('data-sms-service-name') || '',
						delivery_to_pickup_point: input.getAttribute('data-delivery-to-pickup-point') === '1',
						delivery_to_home: input.getAttribute('data-delivery-to-home') === '1'
					});
				});
				return selected;
			}


			function methodKeyForRow(row){
				return (row && row.agreement_id ? row.agreement_id : '') + '|' + (row && row.product_id ? row.product_id : '');
			}

			function needsSmsService(row){
				if (!row) { return false; }
				if (row.requires_sms_service) { return true; }
				var errorText = (row.error || '').toLowerCase();
				return errorText.indexOf('sms varsling') !== -1 || errorText.indexOf('sms varsel') !== -1 || errorText.indexOf('sms notification') !== -1 || errorText.indexOf('requires sms') !== -1;
			}

			function needsServicepartner(row){
				if (!row) { return false; }
				if (row.requires_servicepartner) { return true; }
				var errorText = ((row.error || '') + ' ' + (row.parsed_error_message || '') + ' ' + (row.error_code || '')).toLowerCase();
				return errorText.indexOf('servicepartner må angis') !== -1 || errorText.indexOf('servicepartner maa angis') !== -1 || errorText.indexOf('servicepartner must be specified') !== -1 || errorText.indexOf('missing servicepartner') !== -1;
			}

			function shortRaw(text, maxLen){
				var value = String(text || '').trim();
				var max = maxLen || 350;
				if (!value) { return ''; }
				return value.length > max ? (value.slice(0, max) + '...') : value;
			}

			function isManualNorgespakkeRow(row){
				if (!row) { return false; }
				if (row.is_manual_norgespakke) { return true; }
				var key = (row.agreement_id || '') + '|' + (row.product_id || '');
				return key === 'manual|norgespakke';
			}

			function getManualNorgespakkePackages(row){
				if (!isManualNorgespakkeRow(row) || row.selected_price_source !== 'manual_norgespakke') { return []; }
				var debug = row.norgespakke_debug || {};
				if (!Array.isArray(debug.packages)) { return []; }
				return debug.packages;
			}

			function formatColliTitle(indexOrNumber){
				var parsed = parseInt(indexOrNumber, 10);
				var number = (!isNaN(parsed) && parsed > 0) ? parsed : 1;
				return 'Kolli ' + number;
			}

			function buildColliLineHtml(pkg, displayNumber){
				var packageData = pkg || {};
				var colliTitle = formatColliTitle(displayNumber);
				var nameOrDescription = packageData.name || packageData.description || colliTitle;
				var weight = (packageData.weight !== undefined && packageData.weight !== null && packageData.weight !== '') ? packageData.weight : '0';
				var length = (packageData.length !== undefined && packageData.length !== null && packageData.length !== '') ? packageData.length : '0';
				var width = (packageData.width !== undefined && packageData.width !== null && packageData.width !== '') ? packageData.width : '0';
				var height = (packageData.height !== undefined && packageData.height !== null && packageData.height !== '') ? packageData.height : '0';
				return '<li style="margin-bottom:4px;">' +
					esc(colliTitle) + ': ' + esc(nameOrDescription) + ', ' + esc(weight) + ' kg, ' + esc(length) + 'x' + esc(width) + 'x' + esc(height) + ' cm' +
				'</li>';
			}

			function renderOptimizedShipmentBreakdown(row){
				if (!row || row.optimized_partition_used !== true) { return ''; }
				if (!Array.isArray(row.optimized_shipments) || !row.optimized_shipments.length) { return ''; }
				if (!row.optimized_shipment_count || row.optimized_shipment_count <= 1) { return ''; }

				var shipmentSections = row.optimized_shipments.map(function(shipment, shipmentIdx){
					var shipmentData = shipment || {};
					var packagesSummary = Array.isArray(shipmentData.packages_summary) ? shipmentData.packages_summary : [];
					var packageIndexes = Array.isArray(shipmentData.package_indexes) ? shipmentData.package_indexes : [];
					var priceText = shipmentData.final_price_ex_vat !== undefined && shipmentData.final_price_ex_vat !== null && shipmentData.final_price_ex_vat !== ''
						? shipmentData.final_price_ex_vat
						: (shipmentData.rounded_price !== undefined && shipmentData.rounded_price !== null && shipmentData.rounded_price !== '' ? shipmentData.rounded_price : '');
					var shipmentHeader = '<div style="font-weight:600;color:#1d2327;">Delsendelse ' + esc(shipmentIdx + 1) + (priceText !== '' ? ' <span style="font-weight:400;color:#646970;">(' + esc(priceText) + ' kr)</span>' : '') + '</div>';
					var packageLines = '';
					if (packagesSummary.length) {
						packageLines = packagesSummary.map(function(pkg, pkgIdx){
							var globalNumber = null;
							if (packageIndexes.length > pkgIdx) {
								var parsedIndex = parseInt(packageIndexes[pkgIdx], 10);
								if (!isNaN(parsedIndex) && parsedIndex >= 0) {
									globalNumber = parsedIndex + 1;
								}
							}
							var displayNumber = globalNumber !== null ? globalNumber : (pkgIdx + 1);
							return buildColliLineHtml(pkg, displayNumber);
						}).join('');
						packageLines = '<ul style="margin:4px 0 0 18px;">' + packageLines + '</ul>';
					} else if (packageIndexes.length) {
						var indexesText = packageIndexes.map(function(packageIndex){
							var parsedIndex = parseInt(packageIndex, 10);
							if (isNaN(parsedIndex) || parsedIndex < 0) {
								return '';
							}
							return formatColliTitle(parsedIndex + 1);
						}).filter(function(item){ return !!item; }).join(', ');
						packageLines = indexesText
							? '<div style="margin-top:4px;color:#50575e;">' + esc(indexesText) + '</div>'
							: '<div style="margin-top:4px;color:#50575e;">Ingen kollidetaljer tilgjengelig.</div>';
					} else {
						packageLines = '<div style="margin-top:4px;color:#50575e;">Ingen kollidetaljer tilgjengelig.</div>';
					}

					return '<div style="margin-top:6px;padding-top:6px;border-top:1px dashed #dcdcde;">' + shipmentHeader + packageLines + '</div>';
				}).join('');

				if (!shipmentSections) { return ''; }

				return '<details style="margin-top:6px;">' +
					'<summary style="cursor:pointer;">Vis kollioppdeling</summary>' +
					'<div style="margin-top:6px;padding:8px 10px;border:1px solid #dcdcde;background:#f6f7f7;border-radius:4px;font-size:12px;line-height:1.5;">' + shipmentSections + '</div>' +
				'</details>';
			}

			function renderManualNorgespakkeSummary(row, options){
				var packages = getManualNorgespakkePackages(row);
				if (!packages.length) { return ''; }
				var opts = options || {};
				var compact = !!opts.compact;
				var title = opts.title || 'Kollioversikt';
				var wrapperStyle = compact
					? 'margin-top:6px;padding:8px 10px;border:1px solid #dcdcde;background:#f6f7f7;border-radius:4px;font-size:12px;line-height:1.5;'
					: 'margin-top:8px;padding:10px 12px;border:1px solid #dcdcde;background:#f6f7f7;border-radius:4px;line-height:1.5;';
				var lines = packages.map(function(pkg, idx){
					var number = idx + 1;
					var weight = (pkg.weight !== undefined && pkg.weight !== null && pkg.weight !== '') ? pkg.weight : '0';
					var length = (pkg.length !== undefined && pkg.length !== null && pkg.length !== '') ? pkg.length : '0';
					var width = (pkg.width !== undefined && pkg.width !== null && pkg.width !== '') ? pkg.width : '0';
					var height = (pkg.height !== undefined && pkg.height !== null && pkg.height !== '') ? pkg.height : '0';
					var hasSize = parseFloat(length) > 0 || parseFloat(width) > 0 || parseFloat(height) > 0;
					var sizeText = hasSize ? (' – ' + length + 'x' + width + 'x' + height + ' cm') : '';
					return '<li style="margin-bottom:4px;">' + esc(formatColliTitle(number)) + ' – ' + esc(weight) + ' kg' + esc(sizeText) + '</li>';
				}).join('');
				return '<div style="' + wrapperStyle + '">' +
					'<div style="font-weight:600;margin-bottom:4px;">' + esc(title) + ': ' + esc(packages.length) + ' kolli</div>' +
					'<ul style="margin:0 0 0 18px;">' + lines + '</ul>' +
				'</div>';
			}

			function renderEstimateDebug(row, options){
				if (!row) { return ''; }
				var opts = options || {};
				var isManualNorgespakke = isManualNorgespakkeRow(row) && row.selected_price_source === 'manual_norgespakke';
				if (isManualNorgespakke) {
					var debug = row.norgespakke_debug || {};
					var packageRows = getManualNorgespakkePackages(row);
					var packageHtml = packageRows.map(function(pkg, idx){
						var packageNumber = parseInt(pkg.package_number, 10);
						if (isNaN(packageNumber) || packageNumber < 1) {
							packageNumber = idx + 1;
						}
						return '<li style="margin-bottom:6px;">' +
							esc(formatColliTitle(packageNumber)) + ': ' + esc(pkg.name || pkg.description || '—') +
							' | vekt ' + esc(pkg.weight || '0') + ' kg' +
							' | LxBxH ' + esc(pkg.length || '0') + 'x' + esc(pkg.width || '0') + 'x' + esc(pkg.height || '0') + ' cm' +
							' | grunnpris ' + esc(pkg.base_price || '0') + ' kr' +
							' | håndtering ' + (pkg.handling_triggered ? 'ja' : 'nei') +
							' (' + esc(pkg.handling_fee || '0') + ' kr)' +
							' | kolli-sum ' + esc(pkg.package_total || '0') + ' kr' +
							' | årsak: ' + esc(pkg.handling_reason || '—') +
						'</li>';
					}).join('');
					var lines = [
						'Metode: manuell Norgespakke',
						'Ingen Logistra/Cargonizer-kall brukt: ja',
						'Antall kolli: ' + (debug.number_of_packages !== undefined ? debug.number_of_packages : '—'),
						'Total grunnfrakt: ' + (debug.total_base_freight || '—'),
						'Total rabatt: ' + (debug.total_discount || '—'),
						'Total håndtering: ' + (debug.total_handling || '—'),
						'Drivstoff %: ' + (debug.fuel_percent || row.fuel_surcharge || '—'),
						'Drivstoff (kr): ' + (debug.fuel_amount || row.recalculated_fuel_surcharge || '—'),
						'Bomtillegg (kr): ' + (debug.toll_surcharge || row.toll_surcharge || '—'),
						'MVA %: ' + (debug.vat_percent || row.vat_percent || '—'),
						'Avrunding: ' + (debug.rounding_mode || row.rounding_mode || '—'),
						'Sluttpris eks mva: ' + (debug.final_price_ex_vat || row.final_price_ex_vat || '—')
					];
					var html = renderManualNorgespakkeSummary(row, { title: 'Kollioversikt for lager' }) +
						'<div style="margin-top:4px;color:#646970;">' + esc(lines.join(' | ')) + '</div>' +
						(packageHtml ? '<ul style="margin:8px 0 0 18px;">' + packageHtml + '</ul>' : '');
					if (opts.asDetails === false) {
						return html;
					}
					return '<details style="margin-top:6px;"><summary>'+esc(opts.summaryTitle || 'Debug')+'</summary>' + html + '</details>';
				}
				var asDetails = opts.asDetails !== false;
				var summaryTitle = opts.summaryTitle || 'Debug';
				var summary = row.request_summary || {};
				var packages = Array.isArray(summary.packages) ? summary.packages : [];
				var packageText = packages.map(function(pkg, idx){
					return (idx + 1) + ': ' +
						'w=' + (pkg.weight || 0) + 'kg, ' +
						'LxBxH=' + (pkg.length || 0) + 'x' + (pkg.width || 0) + 'x' + (pkg.height || 0) + 'cm';
				}).join(' | ');
				var source = row.selected_price_source || 'ingen';
				var selectedValue = row.selected_price_value || '—';
				var alternatives = [];
				if (row.net_amount) { alternatives.push('net_amount=' + row.net_amount); }
				if (row.gross_amount) { alternatives.push('gross_amount=' + row.gross_amount); }
				if (row.estimated_cost) { alternatives.push('estimated_cost=' + row.estimated_cost); }
				if (row.fallback_price) { alternatives.push('fallback_price=' + row.fallback_price); }
				var formulaText = [
					'base_freight = (list_price - total_handling_fee - toll_surcharge) / (1 + fuel_percent / 100)',
					'discounted_base = base_freight * (1 - discount_percent / 100)',
					'recalculated_fuel_surcharge = discounted_base * fuel_percent / 100',
					'subtotal_ex_vat = discounted_base + recalculated_fuel_surcharge + toll_surcharge + total_handling_fee'
				].join(' | ');
				var fields = [
					'HTTP: ' + (row.http_status || '—'),
					'Error code: ' + (row.error_code || '—'),
					'Error type: ' + (row.error_type || '—'),
					'Melding: ' + (row.parsed_error_message || row.error || '—'),
					'Detaljer: ' + (row.error_details || '—'),
					'Prisfelt brukt som listepris/grunnlag: ' + source + ' = ' + selectedValue,
					'Alternative prisfelt i respons: ' + (alternatives.length ? alternatives.join(', ') : 'ingen'),
					'price_source_config: ' + (row.price_source_config || '—'),
					'configured_price_source_key: ' + (row.configured_price_source_key || '—'),
					'selected_price_source: ' + (row.selected_price_source || '—'),
					'selected_price_value: ' + (row.selected_price_value || '—'),
					'actual_fallback_priority: ' + (Array.isArray(row.actual_fallback_priority) ? row.actual_fallback_priority.join(' -> ') : '—'),
					'fallback_step_used: ' + (row.fallback_step_used || '—'),
					'price_source_fallback_used: ' + (row.price_source_fallback_used ? 'ja' : 'nei'),
					'price_source_fallback_reason: ' + (row.price_source_fallback_reason || '—'),
					'rounding_mode: ' + (row.rounding_mode || '—'),
					'original_price: ' + (row.original_price || '—'),
					'original_list_price: ' + (row.original_list_price || '—'),
					'utledet_grunnfrakt: ' + (row.extracted_base_freight || '—'),
					'beregningsgrunnlag_etter_utleding: ' + (row.base_price || '—'),
					'discount_percent: ' + (row.discount_percent || '—'),
					'discounted_base: ' + (row.discounted_base || '—'),
					'fuel_percent: ' + (row.fuel_surcharge || '—') + '%',
					'recalculated_fuel_surcharge: ' + (row.recalculated_fuel_surcharge || '—'),
					'toll_surcharge: ' + (row.toll_surcharge || '—'),
					'handling_fee: ' + (row.handling_fee || '—'),
					'manual_handling_fee: ' + (row.manual_handling_fee || '—'),
					'bring_manual_handling_fee: ' + (row.bring_manual_handling_fee || '—'),
					'total_handling_fee: ' + (row.total_handling_fee || '—'),
					'bring_manual_handling_triggered: ' + (row.bring_manual_handling_triggered ? 'ja' : 'nei'),
					'bring_manual_handling_package_count: ' + (row.bring_manual_handling_package_count !== undefined ? row.bring_manual_handling_package_count : '—'),
					'subtotal_ex_vat: ' + (row.subtotal_ex_vat || '—'),
					'vat_percent: ' + (row.vat_percent || '—'),
					'price_incl_vat: ' + (row.price_incl_vat || '—'),
					'leveringstype: ' + formatDeliveryTypeText(row),
					'rounded_price: ' + (row.rounded_price || '—'),
					'final_price_ex_vat: ' + (row.final_price_ex_vat || '—'),
					'estimated_cost: ' + (row.estimated_cost || '—'),
					'gross_amount: ' + (row.gross_amount || '—'),
					'net_amount: ' + (row.net_amount || '—'),
					'fallback_price: ' + (row.fallback_price || '—'),
					'Agreement: ' + (summary.agreement_id || row.agreement_id || '—'),
					'Produkt: ' + (summary.product_id || row.product_id || '—'),
					'Kolli: ' + (summary.number_of_packages !== undefined ? summary.number_of_packages : '—'),
					'Servicepartner: ' + (summary.selected_servicepartner || row.selected_servicepartner || '—'),
					'SMS service valgt: ' + ((summary.use_sms_service || row.use_sms_service) ? 'Ja' : 'Nei'),
					'Pakker: ' + (packageText || '—'),
					'Formel: ' + formulaText
				];
				var optimization = row.optimization_debug || null;
				var optimizationHtml = '';
				var optimizationStateText = '';
				if (row.optimization_state === 'pending') {
					optimizationStateText = 'Baseline vist. Optimaliserer kombinasjoner...';
				} else if (row.optimization_state === 'done') {
					optimizationStateText = (optimization && optimization.optimization_changed_result) ? 'Optimalisering fant bedre løsning' : 'Optimalisering fant ikke bedre løsning';
				} else if (row.optimization_state === 'failed') {
					optimizationStateText = 'Optimalisering feilet, baseline beholdt';
				}
				if (optimization && (optimization.enabled || optimization.reason || Array.isArray(optimization.variants))) {
					var variants = Array.isArray(optimization.variants) ? optimization.variants : [];
					var variantRows = variants.map(function(variant){
						var groups = Array.isArray(variant.groups) ? variant.groups : [];
						var groupText = groups.map(function(group){
							return '[' + (Array.isArray(group.package_indexes) ? group.package_indexes.join(',') : '—') + '] status=' + (group.status || '—') + ', http=' + (group.http_status || '—') + ', source=' + (group.selected_price_source || '—') + ', val=' + (group.selected_price_value || '—') + ', avrundet=' + (group.rounded_price || '—') + ', eks mva=' + (group.final_price_ex_vat || '—') + (group.error_code ? ', code=' + group.error_code : '') + (group.error ? ', feil=' + group.error : '');
						}).join(' | ');
						var baselineLabel = variant.is_baseline ? 'baseline/samlet' : ('partition #' + variant.partition_index);
						return '<li style="margin-bottom:6px;">Variant ' + esc(baselineLabel) + (variant.is_winner ? ' (vinner)' : '') + ': delsendelser=' + esc(variant.shipment_count || 0) + ', status=' + esc(variant.status || '—') + ', total avrundet=' + esc(variant.total_rounded_price || '—') + ', total eks mva=' + esc(variant.total_final_price_ex_vat || '—') + (variant.error ? ', feil=' + esc(variant.error) : '') + (groupText ? '<div style="margin-top:4px;color:#646970;">' + esc(groupText) + '</div>' : '') + '</li>';
					}).join('');
					var changedText = optimization.optimization_changed_result ? 'ja' : 'nei';
					optimizationHtml = '<div style="margin-top:8px;padding:8px;border:1px solid #dcdcde;background:#f6f7f7;">'
						+ '<strong>DSV-optimalisering</strong>: '
						+ (optimizationStateText ? '<div style="margin-top:4px;font-weight:600;">' + esc(optimizationStateText) + '</div>' : '')
						+ 'enabled=' + esc(optimization.enabled ? 'ja' : 'nei')
						+ ', baseline_attempted=' + esc(optimization.baseline_estimate_attempted ? 'ja' : 'nei')
						+ ', baseline_status=' + esc(optimization.baseline_estimate_status || '—')
						+ ', reason=' + esc(optimization.reason || '—')
						+ ', partitions_tested=' + esc(optimization.partitions_tested !== undefined ? optimization.partitions_tested : '—')
						+ ', winner=' + esc(optimization.winner_partition_index !== undefined ? optimization.winner_partition_index : '—')
						+ ', winner_final_ex_vat=' + esc(optimization.winner_total_final_price_ex_vat !== undefined ? optimization.winner_total_final_price_ex_vat : '—')
						+ ', winner_rounded=' + esc(optimization.winner_total_rounded_price !== undefined ? optimization.winner_total_rounded_price : '—')
						+ ', winner shipments=' + esc(optimization.winner_shipment_count !== undefined ? optimization.winner_shipment_count : '—')
						+ ', changed_result=' + esc(changedText)
						+ '<div style="margin-top:4px;font-weight:600;">Samlet DSV-estimat ble forsøkt først. Optimalisering endret resultat: ' + esc(changedText) + '.</div>'
						+ (variantRows ? '<ul style="margin:8px 0 0 18px;">' + variantRows + '</ul>' : '')
						+ '</div>';
				}
				var rawXml = shortRaw(row.raw_response || '', 1200);
				if (!rawXml && !alternatives.length && !row.selected_price_source && !row.parsed_error_message && !row.error && !optimizationHtml) {
					return '';
				}
				var debugContent = '<div style="margin-top:4px;color:#646970;">'+esc(fields.join(' | '))+'</div>' + optimizationHtml +
					(rawXml ? '<pre style="white-space:pre-wrap;max-height:160px;overflow:auto;background:#f6f7f7;padding:8px;border:1px solid #dcdcde;margin-top:6px;">'+esc(rawXml)+'</pre>' : '');
				if (!asDetails) {
					return debugContent;
				}
				return '<details style="margin-top:6px;"><summary>'+esc(summaryTitle)+'</summary>' + debugContent + '</details>';
			}

			function renderServicepartnerControls(row){
				var needsPartner = needsServicepartner(row);
				var needsSms = needsSmsService(row);
				if (!needsPartner && !needsSms) {
					var baseMessage = row.human_error ? (row.error ? row.error + ' — ' + row.human_error : row.human_error) : (row.error || 'OK');
					return '<span style="color:'+(row.error ? '#b32d2e' : '#2271b1')+';">'+esc(baseMessage)+'</span>' + renderEstimateDebug(row);
				}
				var methodKey = methodKeyForRow(row);
				var options = Array.isArray(row.servicepartner_options) ? row.servicepartner_options : [];
				var currentValue = row.selected_servicepartner || '';
				var optionsHtml = '<option value="">Velg servicepartner…</option>';
				if (options.length) {
					options.forEach(function(opt){
						var value = (opt && opt.value) ? String(opt.value) : '';
						var label = (opt && opt.label) ? String(opt.label) : value;
						if (!value) { return; }
						optionsHtml += '<option value="'+esc(value)+'" '+(currentValue === value ? 'selected' : '')+'>'+esc(label)+'</option>';
					});
				}
				var infoParts = [];
				var partnerDebug = row.servicepartner_fetch || {};
				if (needsPartner) {
					var infoText = 'Denne metoden krever servicepartner. Velg servicepartner og prøv igjen.';
					if (!options.length) { infoText += ' Ingen valg lastet enda.'; }
					if (partnerDebug && partnerDebug.error_message) {
						infoText += ' Feil ved henting: ' + partnerDebug.error_message;
					}
					if (partnerDebug && partnerDebug.success && !options.length) {
						infoText += ' Ingen servicepartnere returnert fra API.';
					}
					infoParts.push('<div style="color:#b32d2e;">'+esc(infoText)+'</div>');
					if (partnerDebug && (partnerDebug.http_status || partnerDebug.error_message || partnerDebug.raw_response_body || partnerDebug.request_url)) {
						var spDebug = 'HTTP: ' + (partnerDebug.http_status || '—') +
							' | Melding: ' + (partnerDebug.error_message || '—') +
							' | URL: ' + (partnerDebug.request_url || '—');
						if (partnerDebug.raw_response_body) {
							spDebug += ' | Kort respons: ' + shortRaw(partnerDebug.raw_response_body, 250);
						}
						infoParts.push('<div style="color:#646970;">'+esc(spDebug)+'</div>');
					}
					if (partnerDebug && partnerDebug.custom_params_debug) {
						var customDebug = [];
						Object.keys(partnerDebug.custom_params_debug).forEach(function(key){
							var row = partnerDebug.custom_params_debug[key] || {};
							customDebug.push(key + '=' + (row.value || '—') + ' (' + (row.source || 'unknown') + ')');
						});
						if (customDebug.length) {
							infoParts.push('<div style="color:#646970;">Custom params brukt: '+esc(customDebug.join(' | '))+'</div>');
						}
					}
				}
				if (needsSms) {
					var smsMissing = row.sms_service_missing;
					var smsInfo = smsMissing ? (row.sms_service_error || 'SMS Varsling ble krevd, men tjenesten ble ikke funnet i transport_agreements for dette produktet.') : 'Denne metoden krever SMS Varsling. Kryss av og prøv igjen.';
					infoParts.push('<label style="display:flex;gap:6px;align-items:center;"><input type="checkbox" class="lp-sms-service-toggle" data-method-key="'+esc(methodKey)+'" '+((row.use_sms_service && !smsMissing) ? 'checked' : '')+' '+(smsMissing ? 'disabled' : '')+'>Bruk SMS Varsling'+(row.sms_service_name ? ' ('+esc(row.sms_service_name)+')' : '')+'</label>');
					infoParts.push('<div style="color:'+(smsMissing ? '#b32d2e' : '#646970')+';">'+esc(smsInfo)+'</div>');
				}
				return '' +
					'<div style="display:flex;flex-direction:column;gap:6px;">' +
						infoParts.join('') +
						(needsPartner ? '<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;"><select class="lp-servicepartner-select" data-method-key="'+esc(methodKey)+'" style="min-width:220px;">'+optionsHtml+'</select><button type="button" class="button button-small lp-servicepartner-refresh" data-method-key="'+esc(methodKey)+'">Hent servicepartnere</button></div>' : '') +
						'<button type="button" class="button button-primary button-small lp-method-retry" data-method-key="'+esc(methodKey)+'">Prøv igjen</button>' +
						(row.error ? '<div style="color:#646970;">Siste feil: '+esc(row.error)+'</div>' : '') +
						renderEstimateDebug(row) +
					'</div>';
			}

			function mergeResultByMethod(updatedRow){
				var key = methodKeyForRow(updatedRow);
				if (!key) { return; }
				var replaced = false;
				latestEstimateResults = latestEstimateResults.map(function(row){
					if (methodKeyForRow(row) === key) {
						replaced = true;
						return updatedRow;
					}
					return row;
				});
				if (!replaced) {
					latestEstimateResults.push(updatedRow);
				}
			}

			function getMethodDataByKey(methodKey){
				var parts = String(methodKey || '').split('|');
				if (parts.length < 2) { return null; }
				var selected = getSelectedMethods();
				for (var i = 0; i < selected.length; i++) {
					if ((selected[i].agreement_id || '') === parts[0] && (selected[i].product_id || '') === parts[1]) {
						return selected[i];
					}
				}
				for (var j = 0; j < latestEstimateResults.length; j++) {
					if (methodKeyForRow(latestEstimateResults[j]) === methodKey) {
						return {
							agreement_id: latestEstimateResults[j].agreement_id || '',
							agreement_name: latestEstimateResults[j].agreement_name || '',
							agreement_description: latestEstimateResults[j].agreement_description || '',
							agreement_number: latestEstimateResults[j].agreement_number || '',
								carrier_id: latestEstimateResults[j].carrier_id || '',
								carrier_name: latestEstimateResults[j].carrier_name || '',
								product_id: latestEstimateResults[j].product_id || '',
								product_name: latestEstimateResults[j].product_name || '',
								sms_service_id: latestEstimateResults[j].sms_service_id || '',
								sms_service_name: latestEstimateResults[j].sms_service_name || ''
							};
					}
				}
				return null;
			}

			function fetchServicepartnersForMethod(methodKey){
				var methodData = getMethodDataByKey(methodKey);
				if (!methodData) { return Promise.resolve([]); }
				var form = new FormData();
				form.append('action', 'lp_cargonizer_get_servicepartner_options');
				form.append('nonce', '<?php echo esc_js(wp_create_nonce(self::NONCE_ACTION_SERVICEPARTNERS)); ?>');
				form.append('order_id', currentOrderId || '');
				form.append('agreement_id', methodData.agreement_id || '');
				form.append('product_id', methodData.product_id || '');
				form.append('carrier_id', methodData.carrier_id || '');
				form.append('carrier_name', methodData.carrier_name || '');
				form.append('product_name', methodData.product_name || '');
				form.append('recipient_country', (currentRecipient && currentRecipient.country) ? currentRecipient.country : '');
				form.append('recipient_postcode', (currentRecipient && currentRecipient.postcode) ? currentRecipient.postcode : '');
				return fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: form })
					.then(function(res){ return res.json(); })
					.then(function(res){
						var debug = (res && res.data && res.data.debug) ? res.data.debug : {};
						var options = (res && res.success && res.data && Array.isArray(res.data.options)) ? res.data.options : [];
						latestEstimateResults = latestEstimateResults.map(function(row){
							if (methodKeyForRow(row) === methodKey) {
								row.servicepartner_options = options;
								row.servicepartner_fetch = debug;
								if (!res.success) {
									row.error = (res.data && res.data.message) ? res.data.message : (row.error || 'Henting av servicepartnere feilet.');
								}
							}
							return row;
						});
						renderEstimateResults(latestEstimateResults);
						return options;
					})
					.catch(function(){
						latestEstimateResults = latestEstimateResults.map(function(row){
							if (methodKeyForRow(row) === methodKey) {
								row.servicepartner_fetch = { success:false, http_status:0, error_message:'Teknisk feil ved henting av servicepartnere.', raw_response_body:'', request_url:'' };
								row.error = 'Teknisk feil ved henting av servicepartnere.';
							}
							return row;
						});
						renderEstimateResults(latestEstimateResults);
						return [];
					});
			}

			function runEstimateForSingleMethod(methodKey, selectedServicepartner, useSmsService){
				var colli = collectColliData();
				if (!colli.isValid || !colli.payload.packages || !colli.payload.packages.length) { return; }
				var methodData = getMethodDataByKey(methodKey);
				if (!methodData) { return; }
				var isDsvMethod = ((methodData.carrier_id || '') + ' ' + (methodData.carrier_name || '')).toLowerCase().indexOf('dsv') !== -1;
				var shouldRunProgressiveDsv = isDsvMethod && colli.payload.packages.length > 1;
				var form = new FormData();
				form.append('action', shouldRunProgressiveDsv ? 'lp_cargonizer_run_bulk_estimate_baseline' : 'lp_cargonizer_run_bulk_estimate');
				form.append('nonce', shouldRunProgressiveDsv
					? '<?php echo esc_js(wp_create_nonce(self::NONCE_ACTION_ESTIMATE_BASELINE)); ?>'
					: '<?php echo esc_js(wp_create_nonce(self::NONCE_ACTION_ESTIMATE)); ?>');
				form.append('order_id', currentOrderId);
				colli.payload.packages.forEach(function(pkg, idx){
					Object.keys(pkg).forEach(function(key){ form.append('packages['+idx+']['+key+']', pkg[key]); });
				});
				Object.keys(methodData).forEach(function(key){ form.append('methods[0]['+key+']', methodData[key]); });
				if (selectedServicepartner) {
					form.append('methods[0][servicepartner]', selectedServicepartner);
					methodData.servicepartner = selectedServicepartner;
				}
				if (useSmsService) {
					form.append('methods[0][use_sms_service]', '1');
					methodData.use_sms_service = true;
				} else {
					methodData.use_sms_service = false;
				}
				fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: form })
					.then(function(res){ return res.json(); })
					.then(function(res){
						if (!res || !res.success || !res.data || !Array.isArray(res.data.results) || !res.data.results.length) {
							return;
						}
						var row = res.data.results[0];
						mergeResultByMethod(row);
						renderEstimateResults(latestEstimateResults);
						if (shouldRunProgressiveDsv && row && row.optimization_state === 'pending') {
							optimizeDsvMethod(methodData, colli.payload.packages);
						}
					});
			}

			function formatDeliveryTypeText(row){
				if (!row) { return 'Ikke satt'; }
				var types = [];
				if (row.delivery_to_pickup_point) { types.push('HENTESTED'); }
				if (row.delivery_to_home) { types.push('HJEMLEVERING'); }
				return types.length ? types.join(' / ') : 'Ikke satt';
			}

			function formatDeliveryFlag(value){
				return value ? 'Ja' : 'Nei';
			}

			function renderEstimateResults(results){
				function parsePriceNumber(value) {
					if (value === null || value === undefined || value === '') {
						return NaN;
					}
					var normalized = String(value).trim().replace(/\s+/g, '').replace(/[^0-9,.-]/g, '');
					if (!normalized) {
						return NaN;
					}
					var hasComma = normalized.indexOf(',') !== -1;
					var hasDot = normalized.indexOf('.') !== -1;
					if (hasComma && hasDot) {
						if (normalized.lastIndexOf(',') > normalized.lastIndexOf('.')) {
							normalized = normalized.replace(/\./g, '').replace(',', '.');
						} else {
							normalized = normalized.replace(/,/g, '');
						}
					} else if (hasComma) {
						normalized = normalized.replace(',', '.');
					}
					var parsed = parseFloat(normalized);
					return isNaN(parsed) ? NaN : parsed;
				}

				if (!Array.isArray(results) || !results.length) {
					latestEstimateResults = [];
					resultsContent.innerHTML = '<em>Ingen resultater å vise.</em>';
					return;
				}
				latestEstimateResults = results.slice();

				var okResults = [];
				var failedResults = [];
				results.forEach(function(row){
					var status = (row && row.status) ? String(row.status).toLowerCase() : '';
					if (status === 'ok') {
						okResults.push(row);
					} else {
						failedResults.push(row);
					}
				});

				okResults.sort(function(a, b){
					var aValue = parsePriceNumber(a && a.rounded_price !== undefined && a.rounded_price !== '' ? a.rounded_price : (a ? a.final_price_ex_vat : ''));
					var bValue = parsePriceNumber(b && b.rounded_price !== undefined && b.rounded_price !== '' ? b.rounded_price : (b ? b.final_price_ex_vat : ''));
					var aMissing = isNaN(aValue);
					var bMissing = isNaN(bValue);
					if (aMissing && bMissing) { return 0; }
					if (aMissing) { return 1; }
					if (bMissing) { return -1; }
					return aValue - bValue;
				});

				function formatDeliveryMode(deliveryRow){
					var hasPickup = !!deliveryRow.delivery_to_pickup_point;
					var hasHome = !!deliveryRow.delivery_to_home;
					if (hasPickup && hasHome) { return 'Hentested + hjemlevering'; }
					if (hasPickup) { return 'Hentested'; }
					if (hasHome) { return 'Hjemlevering'; }
					return '—';
				}

				function renderOkRow(row){
					var toText = function(value){
						return value !== '' && value !== undefined && value !== null ? value : '—';
					};
					var listPriceText = row.original_list_price !== '' && row.original_list_price !== undefined
						? row.original_list_price
						: (row.selected_price_value || '—');
					var discountPercentText = toText(row.discount_percent);
					var fuelPercentText = toText(row.fuel_surcharge);
					var fuelAmountText = toText(row.recalculated_fuel_surcharge);
					var tollSurchargeText = toText(row.toll_surcharge);
					var handlingFeeText = toText(row.total_handling_fee !== '' && row.total_handling_fee !== undefined ? row.total_handling_fee : row.handling_fee);
					var actualPriceText = row.rounded_price !== '' && row.rounded_price !== undefined
						? row.rounded_price
						: (row.final_price_ex_vat !== '' && row.final_price_ex_vat !== undefined ? row.final_price_ex_vat : '—');
					var statusText = row.status || 'unknown';
					var packageSummaryHtml = renderManualNorgespakkeSummary(row, { compact: true, title: 'Kolli' });
					var multiShipmentInfo = (row.optimized_partition_used && (row.optimized_shipment_count || 0) > 1)
						? '<div style="margin-top:6px;color:#8a4b00;font-weight:600;">Optimalisert som ' + esc(row.optimized_shipment_count) + ' separate delsendelser (må bookes separat).</div>'
						: '';
					var optimizationInfo = '';
					if (row.optimization_state === 'pending') {
						optimizationInfo = '<div style="margin-top:6px;color:#125228;font-weight:600;">Baseline vist. Optimaliserer kombinasjoner...</div>';
					} else if (row.optimization_state === 'done') {
						optimizationInfo = '<div style="margin-top:6px;color:#125228;font-weight:600;">' + esc((row.optimization_debug && row.optimization_debug.optimization_changed_result) ? 'Optimalisering fant bedre løsning' : 'Optimalisering fant ikke bedre løsning') + '</div>';
					} else if (row.optimization_state === 'failed') {
						optimizationInfo = '<div style="margin-top:6px;color:#b32d2e;font-weight:600;">Optimalisering feilet, baseline beholdt.</div>';
					}
					var showOptimizedBreakdown = row.optimized_partition_used === true &&
						(row.optimized_shipment_count || 0) > 1 &&
						Array.isArray(row.optimized_shipments) &&
						row.optimized_shipments.length > 0 &&
						row.optimization_state === 'done' &&
						!!(row.optimization_debug && row.optimization_debug.optimization_changed_result);
					var optimizedBreakdownHtml = showOptimizedBreakdown ? renderOptimizedShipmentBreakdown(row) : '';
					var detailsHtml = '<details><summary>Vis beregning</summary>' + renderEstimateDebug(row, { asDetails: false }) + '</details>';
					return '<tr>' +
					'<td>'+esc(row.method_name || row.product_id || 'Ukjent metode') + packageSummaryHtml + multiShipmentInfo + optimizationInfo + optimizedBreakdownHtml + '</td>' +
					'<td>'+esc(formatDeliveryMode(row))+'</td>' +
					'<td>'+esc(listPriceText)+'</td>' +
					'<td>'+esc(discountPercentText)+'</td>' +
						'<td>'+esc(fuelPercentText)+'</td>' +
						'<td>'+esc(fuelAmountText)+'</td>' +
						'<td>'+esc(tollSurchargeText)+'</td>' +
						'<td>'+esc(handlingFeeText)+'</td>' +
						'<td style="font-weight:700;background:#e7f6ec;color:#125228;border-left:3px solid #1d7f45;">'+esc(actualPriceText)+'</td>' +
						'<td>'+esc(statusText)+'</td>' +
						'<td>'+detailsHtml+'</td>' +
					'</tr>';
				}

				function renderFailedRow(row){
					var debugFields = [];
					if (row.error_code) { debugFields.push('Kode: ' + row.error_code); }
					if (row.error_type) { debugFields.push('Type: ' + row.error_type); }
					if (row.error_details) { debugFields.push('Detaljer: ' + row.error_details); }
					if (row.http_status) { debugFields.push('HTTP: ' + row.http_status); }
					if (row.human_error) { debugFields.push('Forklaring: ' + row.human_error); }
					if (row.parsed_error_message && row.parsed_error_message !== row.error) { debugFields.push('Parsed: ' + row.parsed_error_message); }
					var debugText = debugFields.length ? debugFields.join(' | ') : '—';
					return '<tr>' +
					'<td>'+esc(row.method_name || row.product_id || 'Ukjent metode')+'</td>' +
					'<td>'+esc(formatDeliveryMode(row))+'</td>' +
					'<td>'+esc(row.status || 'failed')+'</td>' +
					'<td>'+esc(row.error || row.parsed_error_message || 'Ukjent feil')+'</td>' +
					'<td>'+esc(debugText)+'</td>' +
				'</tr>';
			}

				var okRows = okResults.map(renderOkRow).join('');
				var okTableHtml = '<table class="widefat striped"><thead><tr><th>Fraktmetode</th><th>Leveringsmåte</th><th>Listepris/grunnlag</th><th>Rabatt %</th><th>Drivstoff %</th><th>Drivstoff (kr)</th><th>Bomtillegg (kr)</th><th>Håndteringstillegg (kr)</th><th>Faktisk pris</th><th>Status</th><th>Beregning/debug</th></tr></thead><tbody>' +
					(okRows || '<tr><td colspan="11"><em>Ingen vellykkede metoder.</em></td></tr>') +
					'</tbody></table>';

				var failedSectionHtml = '';
				if (failedResults.length) {
					var failedRows = failedResults.map(renderFailedRow).join('');
					failedSectionHtml = '<details style="margin-top:12px;"><summary>Vis metoder som feilet (' + failedResults.length + ')</summary>' +
						'<div style="margin-top:8px;">' +
						'<table class="widefat striped"><thead><tr><th>Fraktmetode</th><th>Leveringsmåte</th><th>Status</th><th>Feilmelding</th><th>Debug/details</th></tr></thead><tbody>' + failedRows + '</tbody></table>' +
						'</div>' +
					'</details>';
				}

				resultsContent.innerHTML = okTableHtml + failedSectionHtml;
			}


			function fetchShippingOptions(){
				shippingOptionsList.innerHTML = '<em>Laster fraktvalg...</em>';
				var form = new FormData();
				form.append('action', 'lp_cargonizer_get_shipping_options');
				form.append('nonce', '<?php echo esc_js(wp_create_nonce(self::NONCE_ACTION_FETCH_OPTIONS)); ?>');
				return fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: form })
					.then(function(res){ return res.json(); })
					.then(function(res){
						if (!res || !res.success || !res.data) {
							var message = (res && res.data && res.data.message) ? res.data.message : 'Kunne ikke hente fraktvalg.';
							shippingOptionsList.innerHTML = '<span style="color:#b32d2e;">' + esc(message) + '</span>';
							return;
						}
						renderShippingOptions(res.data.options || []);
					})
					.catch(function(){
						shippingOptionsList.innerHTML = '<span style="color:#b32d2e;">Teknisk feil ved henting av fraktvalg.</span>';
					});
			}

			function validateBeforeEstimate(){
				var colli = collectColliData();
				if (!colli.payload.packages || !colli.payload.packages.length) {
					colliValidation.textContent = 'Du må legge til minst ett kolli.';
					colliValidation.style.display = 'block';
					return null;
				}
				if (!colli.isValid) {
					return null;
				}
				var methods = getSelectedMethods();
				if (!methods.length) {
					resultsContent.innerHTML = '<span style="color:#b32d2e;">Velg minst én fraktmetode før estimering.</span>';
					return null;
				}
				return { packages: colli.payload.packages, methods: methods };
			}

			function appendEstimatePayload(form, packages, methods){
				form.append('order_id', currentOrderId);
				packages.forEach(function(pkg, idx){
					Object.keys(pkg).forEach(function(key){
						form.append('packages['+idx+']['+key+']', pkg[key]);
					});
				});
				methods.forEach(function(method, idx){
					Object.keys(method).forEach(function(key){
						form.append('methods['+idx+']['+key+']', method[key]);
					});
				});
			}

			function optimizeDsvMethod(method, packages){
				var form = new FormData();
				form.append('action', 'lp_cargonizer_optimize_dsv_estimates');
				form.append('nonce', '<?php echo esc_js(wp_create_nonce(self::NONCE_ACTION_OPTIMIZE_DSV)); ?>');
				appendEstimatePayload(form, packages, [method]);
				return fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: form })
					.then(function(res){ return res.json(); })
					.then(function(res){
						if (!res || !res.success || !res.data || !Array.isArray(res.data.results) || !res.data.results.length) {
							throw new Error('optimize_failed');
						}
						var updatedRow = res.data.results[0];
						updatedRow.optimization_state = 'done';
						mergeResultByMethod(updatedRow);
						renderEstimateResults(latestEstimateResults);
					})
					.catch(function(){
						var methodKey = (method.agreement_id || '') + '|' + (method.product_id || '');
						latestEstimateResults = latestEstimateResults.map(function(row){
							if (methodKeyForRow(row) === methodKey) {
								row.optimization_state = 'failed';
								row.optimization_debug = row.optimization_debug || {};
								row.optimization_debug.enabled = false;
								row.optimization_debug.optimization_changed_result = false;
								row.optimization_debug.reason = 'Optimalisering feilet, beholdt baseline-resultat.';
							}
							return row;
						});
						renderEstimateResults(latestEstimateResults);
					});
			}

			function runEstimate(){
				var validData = validateBeforeEstimate();
				if (!validData) { return; }
				runEstimateBtn.disabled = true;
				runEstimateBtn.textContent = 'Estimerer...';
				resultsContent.innerHTML = '<em>Henter estimater...</em>';

				var form = new FormData();
				form.append('action', 'lp_cargonizer_run_bulk_estimate_baseline');
				form.append('nonce', '<?php echo esc_js(wp_create_nonce(self::NONCE_ACTION_ESTIMATE_BASELINE)); ?>');
				appendEstimatePayload(form, validData.packages, validData.methods);

				fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: form })
					.then(function(res){ return res.json(); })
					.then(function(res){
						if (!res || !res.success || !res.data) {
							resultsContent.innerHTML = '<span style="color:#b32d2e;">Estimering feilet.</span>';
							return;
						}
						latestEstimateResults = (res.data.results || []).slice();
						renderEstimateResults(latestEstimateResults);

						validData.methods.forEach(function(method){
							var methodKey = (method.agreement_id || '') + '|' + (method.product_id || '');
							for (var i = 0; i < latestEstimateResults.length; i++) {
								if (methodKeyForRow(latestEstimateResults[i]) === methodKey && latestEstimateResults[i].optimization_state === 'pending') {
									optimizeDsvMethod(method, validData.packages);
									break;
								}
							}
						});
					})
					.catch(function(){
						resultsContent.innerHTML = '<span style="color:#b32d2e;">Teknisk feil ved estimering.</span>';
					})
					.finally(function(){
						runEstimateBtn.disabled = false;
						runEstimateBtn.textContent = 'Estimer fraktpris';
					});
			}
			function openModal(){ modal.style.display = 'block'; }
			function closeModal(){ modal.style.display = 'none'; }

			modal.addEventListener('click', function(e){
				if (e.target === modal || e.target.classList.contains('lp-cargonizer-close')) { closeModal(); }
			});

			if (closeBottomBtn) {
				closeBottomBtn.addEventListener('click', closeModal);
			}

			addBtn.addEventListener('click', function(){ createColli({}); });
			runEstimateBtn.addEventListener('click', runEstimate);
			if (selectAllShippingBtn) {
				selectAllShippingBtn.addEventListener('click', toggleSelectAllShippingOptions);
			}


			resultsContent.addEventListener('click', function(e){
				var refreshBtn = e.target.closest('.lp-servicepartner-refresh');
				if (refreshBtn) {
					e.preventDefault();
					fetchServicepartnersForMethod(refreshBtn.getAttribute('data-method-key') || '');
					return;
				}
				var retryBtn = e.target.closest('.lp-method-retry');
				if (retryBtn) {
					e.preventDefault();
					var methodKey = retryBtn.getAttribute('data-method-key') || '';
					var select = resultsContent.querySelector('.lp-servicepartner-select[data-method-key="'+methodKey+'"]');
					var selectedServicepartner = select ? (select.value || '') : '';
					var smsToggle = resultsContent.querySelector('.lp-sms-service-toggle[data-method-key="'+methodKey+'"]');
					var useSmsService = smsToggle ? !!smsToggle.checked : false;
					runEstimateForSingleMethod(methodKey, selectedServicepartner, useSmsService);
				}
			});

			resultsContent.addEventListener('change', function(e){
				var smsToggle = e.target.closest('.lp-sms-service-toggle');
				if (smsToggle) {
					var smsMethodKey = smsToggle.getAttribute('data-method-key') || '';
					latestEstimateResults = latestEstimateResults.map(function(row){
						if (methodKeyForRow(row) === smsMethodKey) {
							row.use_sms_service = !!smsToggle.checked;
						}
						return row;
					});
					return;
				}

				var select = e.target.closest('.lp-servicepartner-select');
				if (!select) { return; }
				var methodKey = select.getAttribute('data-method-key') || '';
				latestEstimateResults = latestEstimateResults.map(function(row){
					if (methodKeyForRow(row) === methodKey) {
						row.selected_servicepartner = select.value || '';
					}
					return row;
				});
			});

			document.addEventListener('click', function(e){
				var btn = e.target.closest('.lp-cargonizer-estimate-open');
				if (!btn) { return; }
				e.preventDefault();
				currentOrderId = btn.getAttribute('data-order-id') || getOrderIdFromCurrentUrl();
				openModal();
				loading.style.display = 'block';
				errorBox.style.display = 'none';
				content.style.display = 'none';
				colliList.innerHTML = '';
				colliValidation.style.display = 'none';
				latestEstimateResults = [];
				currentRecipient = {};
				resultsContent.innerHTML = 'Ingen estimater kjørt enda.';
				shippingOptionsList.innerHTML = '<em>Laster fraktvalg...</em>';

				var form = new FormData();
				form.append('action', 'lp_cargonizer_get_order_estimate_data');
				form.append('nonce', '<?php echo esc_js(wp_create_nonce(self::NONCE_ACTION_ORDER_DATA)); ?>');
				form.append('order_id', currentOrderId);

				fetch(ajaxurl, { method:'POST', credentials:'same-origin', body: form })
					.then(function(res){ return res.json(); })
					.then(function(res){
						loading.style.display = 'none';
						if (!res || !res.success || !res.data) {
							var serverMessage = res && res.data && res.data.message ? res.data.message : '';
							errorBox.textContent = serverMessage || 'Kunne ikke hente ordredata.';
							errorBox.style.display = 'block';
							return;
						}

						var d = res.data;
						currentRecipient = d.recipient || {};
						overview.innerHTML = '<h3 style="margin:0 0 8px 0;">Oversikt over sendingen</h3>' +
							'<div><strong>Ordre:</strong> #' + esc(d.order.number) + '</div>' +
							'<div><strong>Dato:</strong> ' + esc(d.order.date) + '</div>' +
							'<div><strong>Total:</strong> ' + esc(d.order.total) + '</div>';

						recipient.innerHTML = '<h3 style="margin:0 0 8px 0;">Kunde / mottaker</h3>' +
							'<div><strong>Navn:</strong> ' + esc(d.recipient.name) + '</div>' +
							'<div><strong>Adresse:</strong> ' + esc(d.recipient.address_1) + ' ' + esc(d.recipient.address_2) + ', ' + esc(d.recipient.postcode) + ' ' + esc(d.recipient.city) + ', ' + esc(d.recipient.country) + '</div>' +
							'<div><strong>E-post:</strong> ' + esc(d.recipient.email) + '</div>' +
							'<div><strong>Telefon:</strong> ' + esc(d.recipient.phone) + '</div>';

						var rows = d.items.map(function(item){
							return '<tr><td>'+esc(item.name)+'</td><td>'+esc(item.quantity)+'</td><td>'+esc(item.sku)+'</td></tr>';
						}).join('');
						lines.innerHTML = '<h3 style="margin:0 0 8px 0;">Ordrelinjer</h3><table class="widefat striped"><thead><tr><th>Produkt</th><th>Antall</th><th>SKU</th></tr></thead><tbody>' + (rows || '<tr><td colspan="3">Ingen ordrelinjer.</td></tr>') + '</tbody></table>';

						if (Array.isArray(d.packages) && d.packages.length) {
							d.packages.forEach(function(pkg){ createColli(pkg); });
						} else {
							createColli({});
						}

						collectColliData();
						fetchShippingOptions();

						content.style.display = 'block';
					})
					.catch(function(){
						loading.style.display = 'none';
						errorBox.textContent = 'Teknisk feil ved henting av ordredata.';
						errorBox.style.display = 'block';
					});
			});
		})();
		</script>
		<?php
	}

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
		);

		if ($data['recipient']['name'] === '') {
			$data['recipient']['name'] = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
		}

		wp_send_json_success($data);
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

	private function build_estimate_request_xml($payload, $method) {
		$recipient = isset($payload['recipient']) && is_array($payload['recipient']) ? $payload['recipient'] : array();
		$packages = isset($payload['packages']) && is_array($payload['packages']) ? $payload['packages'] : array();
		$servicepartner = isset($payload['servicepartner']) ? sanitize_text_field((string) $payload['servicepartner']) : '';
		$use_sms_service = !empty($payload['use_sms_service']);
		$sms_service_id = isset($payload['sms_service_id']) ? sanitize_text_field((string) $payload['sms_service_id']) : '';
		$package_count = count($packages);

		$xml = new SimpleXMLElement('<consignments/>');
		$consignment = $xml->addChild('consignment');
		$consignment->addAttribute('transport_agreement', isset($method['agreement_id']) ? (string) $method['agreement_id'] : '');
		$consignment->addChild('product', (string) (isset($method['product_id']) ? $method['product_id'] : ''));
		if ($servicepartner !== '') {
			$consignment->addChild('servicepartner', (string) $servicepartner);
		}
		if ($use_sms_service && $sms_service_id !== '') {
			$services_node = $consignment->addChild('services');
			$services_node->addChild('service', (string) $sms_service_id);
		}
		$consignment->addChild('number_of_packages', (string) max(1, $package_count));

		$parts = $consignment->addChild('parts');
		$consignee = $parts->addChild('consignee');
		$consignee->addChild('name', (string) (isset($recipient['name']) ? $recipient['name'] : ''));
		$consignee->addChild('address1', (string) (isset($recipient['address_1']) ? $recipient['address_1'] : ''));
		$consignee->addChild('address2', (string) (isset($recipient['address_2']) ? $recipient['address_2'] : ''));
		$consignee->addChild('postcode', (string) (isset($recipient['postcode']) ? $recipient['postcode'] : ''));
		$consignee->addChild('city', (string) (isset($recipient['city']) ? $recipient['city'] : ''));
		$consignee->addChild('country', (string) (isset($recipient['country']) ? $recipient['country'] : ''));

		$items = $consignment->addChild('items');
		if (empty($packages)) {
			$packages[] = array();
		}

		foreach ($packages as $package) {
			$weight = isset($package['weight']) ? (float) $package['weight'] : 0;
			$length_cm = isset($package['length']) ? (float) $package['length'] : 0;
			$width_cm = isset($package['width']) ? (float) $package['width'] : 0;
			$height_cm = isset($package['height']) ? (float) $package['height'] : 0;
			$volume_dm3 = ($length_cm * $width_cm * $height_cm) / 1000;
			$description = isset($package['description']) ? trim((string) $package['description']) : '';
			$length_xml = $this->normalize_positive_decimal_for_xml(isset($package['length']) ? $package['length'] : null);
			$width_xml = $this->normalize_positive_decimal_for_xml(isset($package['width']) ? $package['width'] : null);
			$height_xml = $this->normalize_positive_decimal_for_xml(isset($package['height']) ? $package['height'] : null);

			$item = $items->addChild('item');
			$item->addAttribute('type', 'package');
			$item->addAttribute('amount', '1');
			$item->addChild('description', (string) $description);
			$item->addChild('weight', (string) max(0, $weight));
			$item->addChild('volume', (string) max(0, $volume_dm3));
			if ($length_xml !== '') {
				$item->addChild('length', $length_xml);
			}
			if ($width_xml !== '') {
				$item->addChild('width', $width_xml);
			}
			if ($height_xml !== '') {
				$item->addChild('height', $height_xml);
			}

			$this->log_estimate_package_dimensions(array(
				'package_name' => isset($package['name']) ? sanitize_text_field((string) $package['name']) : '',
				'description' => $description,
				'weight' => (string) max(0, $weight),
				'volume' => (string) max(0, $volume_dm3),
				'length' => $length_xml,
				'width' => $width_xml,
				'height' => $height_xml,
			));
		}

		return $xml->asXML();
	}

	private function normalize_positive_decimal_for_xml($value) {
		if (is_string($value)) {
			$value = str_replace(',', '.', $value);
		}

		if (!is_numeric($value)) {
			return '';
		}

		$number = (float) $value;
		if ($number <= 0) {
			return '';
		}

		$formatted = number_format($number, 3, '.', '');
		$formatted = rtrim(rtrim($formatted, '0'), '.');

		return $formatted;
	}

	private function log_estimate_package_dimensions($data) {
		if (!function_exists('wc_get_logger')) {
			return;
		}

		$logger = wc_get_logger();
		if (!$logger) {
			return;
		}

		$logger->debug('Estimate package dimensions sent to Cargonizer: ' . wp_json_encode($data), array('source' => 'lp-cargonizer-estimate'));
	}


	private function estimate_requires_servicepartner($error_message) {
		$error_message = strtolower(trim((string) $error_message));
		if ($error_message === '') {
			return false;
		}

		$needles = array(
			'servicepartner må angis',
			'servicepartner maa angis',
			'servicepartner must be specified',
			'missing servicepartner',
		);

		foreach ($needles as $needle) {
			if (strpos($error_message, $needle) !== false) {
				return true;
			}
		}

		return false;
	}

	private function estimate_requires_sms_service($error_message) {
		$error_message = strtolower(trim((string) $error_message));
		if ($error_message === '') {
			return false;
		}

		$needles = array(
			'sms varsling',
			'sms varsel',
			'sms notification',
			'service sms',
			'requires sms',
		);

		foreach ($needles as $needle) {
			if (strpos($error_message, $needle) !== false) {
				return true;
			}
		}

		return false;
	}

	private function find_sms_service_for_method($method) {
		$services = isset($method['services']) && is_array($method['services']) ? $method['services'] : array();
		foreach ($services as $service) {
			if (!is_array($service)) {
				continue;
			}
			$service_id = isset($service['service_id']) ? sanitize_text_field((string) $service['service_id']) : '';
			$service_name = isset($service['service_name']) ? sanitize_text_field((string) $service['service_name']) : '';
			$service_name_lc = strtolower($service_name);
			if ($service_name_lc !== '' && (strpos($service_name_lc, 'sms varsling') !== false || strpos($service_name_lc, 'sms varsel') !== false || strpos($service_name_lc, 'sms notification') !== false)) {
				return array(
					'service_id' => $service_id,
					'service_name' => $service_name,
				);
			}
		}

		return array(
			'service_id' => '',
			'service_name' => '',
		);
	}


	private function sanitize_country_code($value) {
		$country = strtoupper(sanitize_text_field((string) $value));
		if ($country === '' || strlen($country) > 2) {
			return '';
		}
		return $country;
	}

	private function sanitize_postcode($value) {
		return preg_replace('/[^A-Za-z0-9\- ]/', '', sanitize_text_field((string) $value));
	}

	private function detect_servicepartner_custom_params($method) {
		$carrier_id = strtolower(sanitize_text_field(isset($method['carrier_id']) ? (string) $method['carrier_id'] : ''));
		$carrier_name = strtolower(sanitize_text_field(isset($method['carrier_name']) ? (string) $method['carrier_name'] : ''));
		$product_id = strtolower(sanitize_text_field(isset($method['product_id']) ? (string) $method['product_id'] : ''));
		$product_name = strtolower(sanitize_text_field(isset($method['product_name']) ? (string) $method['product_name'] : ''));

		$params = array();
		$debug = array();

		$is_bring = strpos($carrier_id, 'bring') !== false || strpos($carrier_name, 'bring') !== false;
		$is_postnord = strpos($carrier_id, 'postnord') !== false || strpos($carrier_name, 'postnord') !== false;

		if ($is_bring) {
			$value = 'pickup_point';
			if (strpos($product_name, 'locker') !== false || strpos($product_id, 'locker') !== false) {
				$value = 'locker';
			}
			$params['pickupPointType'] = $value;
			$debug['pickupPointType'] = array(
				'value' => $value,
				'source' => 'auto_detected_from_carrier_product',
			);
		}

		if ($is_postnord) {
			$value = 'pickup';
			if (strpos($product_name, 'service') !== false || strpos($product_id, 'service') !== false) {
				$value = 'service_point';
			}
			$params['typeId'] = $value;
			$debug['typeId'] = array(
				'value' => $value,
				'source' => 'auto_detected_from_carrier_product',
			);
		}

		return array(
			'params' => $params,
			'debug' => $debug,
		);
	}


	private function fetch_servicepartner_options($method) {
		$agreement_id = isset($method['agreement_id']) ? sanitize_text_field((string) $method['agreement_id']) : '';
		$product_id = isset($method['product_id']) ? sanitize_text_field((string) $method['product_id']) : '';
		$country = isset($method['country']) ? $this->sanitize_country_code($method['country']) : '';
		$postcode = isset($method['postcode']) ? $this->sanitize_postcode($method['postcode']) : '';

		$result = array(
			'success' => false,
			'http_status' => 0,
			'error_message' => '',
			'raw_response_body' => '',
			'request_url' => '',
			'options' => array(),
			'custom_params_debug' => array(),
		);

		if ($agreement_id === '' || $product_id === '') {
			$result['error_message'] = 'Mangler agreement_id eller product_id.';
			return $result;
		}

		$query = array(
			'transport_agreement_id' => $agreement_id,
			'product' => $product_id,
		);

		if ($country !== '') {
			$query['country'] = $country;
		}
		if ($postcode !== '') {
			$query['postcode'] = $postcode;
		}

		$custom = $this->detect_servicepartner_custom_params($method);
		if (!empty($custom['params']) && is_array($custom['params'])) {
			foreach ($custom['params'] as $custom_key => $custom_value) {
				$query['custom[params][' . $custom_key . ']'] = $custom_value;
			}
			$result['custom_params_debug'] = $custom['debug'];
		}

		$request_url = add_query_arg($query, 'https://api.cargonizer.no/service_partners.xml');
		$result['request_url'] = $request_url;

		$response = wp_remote_get($request_url, array(
			'timeout' => 30,
			'headers' => $this->get_auth_headers(),
		));

		if (is_wp_error($response)) {
			$result['error_message'] = $response->get_error_message();
			return $result;
		}

		$status = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		$result['http_status'] = $status;
		$result['raw_response_body'] = $body;

		if ($status < 200 || $status >= 300 || $body === '') {
			$error_details = $this->parse_response_error_details($body);
			$result['error_message'] = $error_details['message'] !== '' ? $error_details['message'] : ($body === '' ? 'Tom respons fra API.' : 'Uventet API-respons.');
			return $result;
		}

		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($body);
		if ($xml === false) {
			libxml_clear_errors();
			$result['error_message'] = 'Kunne ikke parse XML-respons fra servicepartner-endepunktet.';
			return $result;
		}

		$options = array();
		$nodes = $xml->xpath('//servicepartner') ?: array();
		foreach ($nodes as $node) {
			$value = trim((string) $this->xml_value($node, array('id', 'code', 'number', 'value')));
			$label = trim((string) $this->xml_value($node, array('name', 'title', 'description', 'display_name')));
			if ($value === '') {
				continue;
			}
			if ($label === '') {
				$label = $value;
			}
			$options[] = array('value' => $value, 'label' => $label);
		}

		if (empty($options)) {
			$fallback_nodes = $xml->xpath('//option') ?: array();
			foreach ($fallback_nodes as $node) {
				$value = trim((string) $this->xml_value($node, array('id', 'code', 'value')));
				$label = trim((string) $this->xml_value($node, array('name', 'title', 'label')));
				if ($value === '') {
					continue;
				}
				if ($label === '') {
					$label = $value;
				}
				$options[] = array('value' => $value, 'label' => $label);
			}
		}

		$result['success'] = true;
		$result['options'] = $options;
		if (empty($options)) {
			$result['error_message'] = 'Ingen servicepartnere returnert fra API for denne kombinasjonen av agreement, product, country og postcode';
		}

		return $result;
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
		);

		if ($order) {
			$method['country'] = $order->get_shipping_country() !== '' ? $order->get_shipping_country() : $method['country'];
			$method['postcode'] = $order->get_shipping_postcode() !== '' ? $order->get_shipping_postcode() : $method['postcode'];
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
				'message' => $servicepartner_result['error_message'] !== '' ? $servicepartner_result['error_message'] : 'Ingen servicepartnere returnert fra API for denne kombinasjonen av agreement, product, country og postcode',
				'debug' => $servicepartner_result,
			), 200);
		}

		wp_send_json_success(array(
			'options' => $servicepartner_result['options'],
			'debug' => $servicepartner_result,
		));
	}


	private function parse_response_error_details($body) {
		$details = array(
			'code' => '',
			'type' => '',
			'message' => '',
			'details' => '',
		);

		if (empty($body)) {
			return $details;
		}

		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($body);
		if ($xml === false) {
			libxml_clear_errors();
			return $details;
		}

		$map = array(
			'code' => array('//error/code', '//errors/error/code', '//code', '//error/id'),
			'type' => array('//error/type', '//errors/error/type', '//type', '//error/category'),
			'message' => array('//error/message', '//errors/error/message', '//message', '//errors/error', '//error'),
			'details' => array('//error/details', '//errors/error/details', '//details', '//error/detail', '//errors/error/detail'),
		);

		foreach ($map as $key => $paths) {
			foreach ($paths as $path) {
				$found = $xml->xpath($path);
				if (!empty($found)) {
					$value = trim((string) $found[0]);
					if ($value !== '') {
						$details[$key] = $value;
						break;
					}
				}
			}
		}

		return $details;
	}



	private function parse_response_error_message($body) {
		if (empty($body)) {
			return '';
		}

		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($body);
		if ($xml === false) {
			libxml_clear_errors();
			return '';
		}

		$paths = array(
			'//error/message',
			'//message',
			'//errors/error',
			'//error',
		);

		foreach ($paths as $path) {
			$found = $xml->xpath($path);
			if (!empty($found)) {
				$value = trim((string) $found[0]);
				if ($value !== '') {
					return $value;
				}
			}
		}

		return '';
	}

	private function parse_estimate_price_fields($body) {
		$fields = array(
			'estimated_cost' => '',
			'gross_amount' => '',
			'net_amount' => '',
			'fallback_price' => '',
		);

		if (empty($body)) {
			return $fields;
		}

		libxml_use_internal_errors(true);
		$xml = simplexml_load_string($body);
		if ($xml === false) {
			libxml_clear_errors();
			return $fields;
		}

		$primary_paths = array(
			'estimated_cost' => array('//estimated-cost'),
			'gross_amount' => array('//gross-amount'),
			'net_amount' => array('//net-amount'),
		);

		foreach ($primary_paths as $key => $paths) {
			foreach ($paths as $path) {
				$found = $xml->xpath($path);
				if (!empty($found)) {
					$value = trim((string) $found[0]);
					if ($value !== '') {
						$fields[$key] = $value;
						break;
					}
				}
			}
		}

		if ($fields['estimated_cost'] === '' && $fields['gross_amount'] === '' && $fields['net_amount'] === '') {
			$fallback_paths = array(
				'//estimated_price',
				'//price',
				'//amount',
				'//total',
			);

			foreach ($fallback_paths as $path) {
				$found = $xml->xpath($path);
				if (!empty($found)) {
					$value = trim((string) $found[0]);
					if ($value !== '') {
						$fields['fallback_price'] = $value;
						break;
					}
				}
			}
		}

		return $fields;
	}

	private function select_estimate_price_value($price_fields, $configured_source = 'estimated') {
		$selected = array(
			'source' => '',
			'value' => '',
			'configured_source' => $this->sanitize_price_source($configured_source),
			'configured_key' => '',
			'fallback_priority' => array(),
			'actual_fallback_priority' => array(),
			'fallback_step_used' => 0,
			'used_fallback' => false,
		);

		if (!is_array($price_fields)) {
			return $selected;
		}

		$priority_data = $this->get_price_source_priority($selected['configured_source']);
		$selected['configured_key'] = $priority_data['configured_key'];
		$selected['fallback_priority'] = $priority_data['priority'];
		$selected['actual_fallback_priority'] = $priority_data['priority'];

		foreach ($priority_data['priority'] as $index => $source) {
			$value = isset($price_fields[$source]) ? trim((string) $price_fields[$source]) : '';
			if ($value !== '') {
				$selected['source'] = $source;
				$selected['value'] = $value;
				$selected['fallback_step_used'] = $index + 1;
				$selected['used_fallback'] = $source !== $priority_data['configured_key'];
				return $selected;
			}
		}

		$selected['used_fallback'] = true;
		return $selected;
	}

	private function get_price_source_priority($configured_source = 'estimated') {
		$source_priority_map = array(
			'estimated' => array('estimated_cost', 'gross_amount', 'net_amount', 'fallback_price'),
			'gross' => array('gross_amount', 'net_amount', 'estimated_cost', 'fallback_price'),
			'net' => array('net_amount', 'gross_amount', 'estimated_cost', 'fallback_price'),
			'fallback' => array('fallback_price', 'estimated_cost', 'gross_amount', 'net_amount'),
		);

		$configured = $this->sanitize_price_source($configured_source);
		$priority = isset($source_priority_map[$configured]) ? $source_priority_map[$configured] : $source_priority_map['estimated'];
		$configured_key = $priority[0];

		// Fallback-prioriteten er eksplisitt for enklere feilsøking og mer forutsigbar prisvisning.
		$priority = array_values(array_unique($priority));

		return array(
			'configured_source' => $configured,
			'configured_key' => $configured_key,
			'priority' => $priority,
		);
	}

	private function is_bring_method($method_payload) {
		if (!is_array($method_payload)) {
			return false;
		}

		$carrier_id = isset($method_payload['carrier_id']) ? strtolower((string) $method_payload['carrier_id']) : '';
		$carrier_name = isset($method_payload['carrier_name']) ? strtolower((string) $method_payload['carrier_name']) : '';

		return strpos($carrier_id, 'bring') !== false || strpos($carrier_name, 'bring') !== false;
	}

	private function is_dsv_method($method_payload) {
		if (!is_array($method_payload)) {
			return false;
		}

		$carrier_id = isset($method_payload['carrier_id']) ? strtolower((string) $method_payload['carrier_id']) : '';
		$carrier_name = isset($method_payload['carrier_name']) ? strtolower((string) $method_payload['carrier_name']) : '';

		return strpos($carrier_id, 'dsv') !== false || strpos($carrier_name, 'dsv') !== false;
	}

	private function generate_package_index_partitions($package_indexes) {
		$unique = array_values(array_unique(array_map('intval', is_array($package_indexes) ? $package_indexes : array())));
		sort($unique);
		$result = array();
		$this->build_package_index_partitions_recursive($unique, 0, array(), $result);
		return $result;
	}

	private function build_package_index_partitions_recursive($indexes, $position, $current_partition, &$all_partitions) {
		if ($position >= count($indexes)) {
			$normalized = $this->normalize_package_partition($current_partition);
			if (!empty($normalized)) {
				$all_partitions[] = $normalized;
			}
			return;
		}

		$index = $indexes[$position];
		$group_count = count($current_partition);
		for ($i = 0; $i < $group_count; $i++) {
			$next = $current_partition;
			$next[$i][] = $index;
			$this->build_package_index_partitions_recursive($indexes, $position + 1, $next, $all_partitions);
		}

		$next = $current_partition;
		$next[] = array($index);
		$this->build_package_index_partitions_recursive($indexes, $position + 1, $next, $all_partitions);
	}

	private function normalize_package_partition($partition) {
		$normalized = array();
		if (!is_array($partition)) {
			return $normalized;
		}
		foreach ($partition as $group) {
			$clean_group = array_values(array_unique(array_map('intval', is_array($group) ? $group : array())));
			sort($clean_group);
			if (!empty($clean_group)) {
				$normalized[] = $clean_group;
			}
		}
		usort($normalized, function ($a, $b) {
			$a_key = implode(',', $a);
			$b_key = implode(',', $b);
			return strcmp($a_key, $b_key);
		});
		return $normalized;
	}

	private function package_triggers_bring_manual_handling($package) {
		return $this->package_triggers_manual_handling($package);
	}


	private function package_triggers_manual_handling($package) {
		if (!is_array($package)) {
			return false;
		}

		$length = isset($package['length']) ? (float) $package['length'] : 0;
		$width = isset($package['width']) ? (float) $package['width'] : 0;
		$height = isset($package['height']) ? (float) $package['height'] : 0;

		$dimensions = array($length, $width, $height);
		$over_60_count = 0;
		foreach ($dimensions as $dimension) {
			if ($dimension > 120) {
				return true;
			}
			if ($dimension > 60) {
				$over_60_count++;
			}
		}

		return $over_60_count >= 2;
	}

	private function get_bring_manual_handling_fee($packages, $method_payload) {
		$result = array(
			'fee' => 0,
			'triggered' => false,
			'package_count' => 0,
		);

		if (!$this->is_bring_method($method_payload) || !is_array($packages)) {
			return $result;
		}

		foreach ($packages as $package) {
			if ($this->package_triggers_manual_handling($package)) {
				$result['package_count']++;
			}
		}

		// 164 kr eks. mva per kolli som matcher Bring-regelen.
		$result['fee'] = round($result['package_count'] * 164, 2);
		$result['triggered'] = $result['package_count'] > 0;

		return $result;
	}


	private function calculate_norgespakke_estimate($packages, $method_payload, $pricing_config) {
		$result = array(
			'status' => 'failed',
			'error' => '',
			'selected_price_source' => 'manual_norgespakke',
			'selected_price_value' => '',
			'original_list_price' => '',
			'manual_handling_fee' => '0.00',
			'bring_manual_handling_fee' => '0.00',
			'total_handling_fee' => '0.00',
			'bring_manual_handling_triggered' => false,
			'bring_manual_handling_package_count' => 0,
			'base_price' => '',
			'discount_percent' => '',
			'discounted_base' => '',
			'fuel_surcharge' => '',
			'recalculated_fuel_surcharge' => '',
			'toll_surcharge' => '',
			'handling_fee' => '',
			'subtotal_ex_vat' => '',
			'vat_percent' => '',
			'price_incl_vat' => '',
			'rounded_price' => '',
			'final_price_ex_vat' => '',
			'norgespakke_debug' => array(),
		);

		if (!is_array($packages) || empty($packages)) {
			$result['error'] = 'Norgespakke krever minst ett kolli.';
			return $result;
		}

		$discount_percent = isset($pricing_config['discount_percent']) ? $this->sanitize_discount_percent($pricing_config['discount_percent']) : 0;
		$fuel_percent = isset($pricing_config['fuel_surcharge']) ? $this->sanitize_non_negative_number($pricing_config['fuel_surcharge']) : 0;
		$toll_surcharge = isset($pricing_config['toll_surcharge']) ? $this->sanitize_non_negative_number($pricing_config['toll_surcharge']) : 0;
		$vat_percent = isset($pricing_config['vat_percent']) ? $this->sanitize_non_negative_number($pricing_config['vat_percent']) : 0;
		$rounding_mode = isset($pricing_config['rounding_mode']) ? $this->sanitize_rounding_mode($pricing_config['rounding_mode']) : 'none';
		$include_handling_fee = isset($pricing_config['manual_norgespakke_include_handling'])
			? (bool) $this->sanitize_checkbox_value($pricing_config['manual_norgespakke_include_handling'])
			: true;

		$package_rows = array();
		$total_base = 0.0;
		$total_handling = 0.0;
		$handling_count = 0;

		foreach ($packages as $idx => $package) {
			$weight = isset($package['weight']) ? (float) $package['weight'] : 0;
			$length = isset($package['length']) ? (float) $package['length'] : 0;
			$width = isset($package['width']) ? (float) $package['width'] : 0;
			$height = isset($package['height']) ? (float) $package['height'] : 0;
			$name = isset($package['name']) && (string) $package['name'] !== '' ? (string) $package['name'] : (isset($package['description']) ? (string) $package['description'] : 'Kolli ' . ($idx + 1));
			$description = isset($package['description']) ? (string) $package['description'] : '';

			if ($weight <= 0) {
				$result['error'] = 'Norgespakke-kolli ' . ($idx + 1) . ' har ugyldig eller manglende vekt. Vekt må være over 0 kg.';
				return $result;
			}
			if ($weight > 35) {
				$result['error'] = 'Norgespakke-kolli ' . ($idx + 1) . ' veier ' . number_format($weight, 2, '.', '') . ' kg og overskrider maksgrensen på 35 kg.';
				return $result;
			}

			if ($weight <= 10) {
				$base_price = 112.0;
			} elseif ($weight <= 25) {
				$base_price = 200.8;
			} else {
				$base_price = 268.0;
			}

			$handling_triggered = $this->package_triggers_manual_handling($package);
			$handling_fee = ($include_handling_fee && $handling_triggered) ? 164.0 : 0.0;
			if ($include_handling_fee && $handling_triggered) {
				$handling_count++;
			}

			$total_base += $base_price;
			$total_handling += $handling_fee;
			$package_rows[] = array(
				'package_number' => $idx + 1,
				'name' => $name,
				'description' => $description,
				'weight' => number_format($weight, 2, '.', ''),
				'length' => number_format($length, 2, '.', ''),
				'width' => number_format($width, 2, '.', ''),
				'height' => number_format($height, 2, '.', ''),
				'base_price' => number_format($base_price, 2, '.', ''),
				'handling_triggered' => $handling_triggered,
				'handling_reason' => $handling_triggered ? 'Én side over 120 cm eller minst to sider over 60 cm.' : 'Ingen håndteringstrigger.',
				'handling_fee' => number_format($handling_fee, 2, '.', ''),
				'package_total' => number_format($base_price + $handling_fee, 2, '.', ''),
			);
		}

		$discount_amount = $total_base * ($discount_percent / 100);
		$discounted_base = $total_base - $discount_amount;
		$fuel_amount = $discounted_base * ($fuel_percent / 100);
		$subtotal_ex_vat = $discounted_base + $fuel_amount + $toll_surcharge + $total_handling;
		$price_incl_vat = $subtotal_ex_vat * (1 + ($vat_percent / 100));
		$rounded_price = $this->apply_rounding_mode($price_incl_vat, $rounding_mode);
		$final_price_ex_vat = $vat_percent > 0 ? $rounded_price / (1 + ($vat_percent / 100)) : $rounded_price;

		$result['status'] = 'ok';
		$result['selected_price_value'] = number_format($total_base, 2, '.', '');
		$result['original_list_price'] = number_format($total_base, 2, '.', '');
		$result['base_price'] = number_format($total_base, 2, '.', '');
		$result['discount_percent'] = number_format($discount_percent, 2, '.', '');
		$result['discounted_base'] = number_format($discounted_base, 2, '.', '');
		$result['fuel_surcharge'] = number_format($fuel_percent, 2, '.', '');
		$result['recalculated_fuel_surcharge'] = number_format($fuel_amount, 2, '.', '');
		$result['toll_surcharge'] = number_format($toll_surcharge, 2, '.', '');
		$result['handling_fee'] = number_format($total_handling, 2, '.', '');
		$result['total_handling_fee'] = number_format($total_handling, 2, '.', '');
		$result['bring_manual_handling_fee'] = number_format($total_handling, 2, '.', '');
		$result['bring_manual_handling_triggered'] = $handling_count > 0;
		$result['bring_manual_handling_package_count'] = $handling_count;
		$result['subtotal_ex_vat'] = number_format($subtotal_ex_vat, 2, '.', '');
		$result['vat_percent'] = number_format($vat_percent, 2, '.', '');
		$result['price_incl_vat'] = number_format($price_incl_vat, 2, '.', '');
		$result['rounded_price'] = number_format($rounded_price, 2, '.', '');
		$result['final_price_ex_vat'] = number_format($final_price_ex_vat, 2, '.', '');
		$result['norgespakke_debug'] = array(
			'method_type' => 'manual',
			'api_calls_used' => false,
			'handling_fee_enabled' => $include_handling_fee,
			'number_of_packages' => count($packages),
			'packages' => $package_rows,
			'total_base_freight' => number_format($total_base, 2, '.', ''),
			'total_discount' => number_format($discount_amount, 2, '.', ''),
			'total_handling' => number_format($total_handling, 2, '.', ''),
			'fuel_percent' => number_format($fuel_percent, 2, '.', ''),
			'fuel_amount' => number_format($fuel_amount, 2, '.', ''),
			'toll_surcharge' => number_format($toll_surcharge, 2, '.', ''),
			'vat_percent' => number_format($vat_percent, 2, '.', ''),
			'rounding_mode' => $rounding_mode,
			'final_price_ex_vat' => number_format($final_price_ex_vat, 2, '.', ''),
		);

		return $result;
	}

	private function calculate_estimate_from_price_source($selected_price, $pricing_config) {
		$result = array(
			'status' => 'unknown',
			'error' => '',
			'original_price' => '',
			'original_list_price' => '',
			'manual_handling_fee' => '',
			'bring_manual_handling_fee' => '',
			'total_handling_fee' => '',
			'bring_manual_handling_triggered' => false,
			'bring_manual_handling_package_count' => 0,
			'extracted_handling_fee' => '',
			'extracted_toll_surcharge' => '',
			'extracted_fuel_percent' => '',
			'extracted_base_freight' => '',
			'discounted_base_freight' => '',
			'recalculated_fuel_surcharge' => '',
			'base_price' => '',
			'discount_percent' => '',
			'discounted_base' => '',
			'fuel_surcharge' => '',
			'toll_surcharge' => '',
			'handling_fee' => '',
			'subtotal_ex_vat' => '',
			'price_incl_vat' => '',
			'rounded_price' => '',
			'final_price_ex_vat' => '',
		);

		$source = isset($selected_price['source']) ? (string) $selected_price['source'] : '';
		$value = isset($selected_price['value']) ? (string) $selected_price['value'] : '';

		if ($value === '') {
			$result['error'] = 'Fikk svar, men fant ingen prisfelt (net_amount, gross_amount, estimated_cost eller fallback_price) i responsen.';
			return $result;
		}

		$original_list_price = $this->parse_price_to_number($value);
		if ($original_list_price === null) {
			$result['status'] = 'price_parse_failed';
			$result['error'] = 'Kunne ikke tolke valgt prisfelt (' . $source . ') som et tall. Viser kun original respons.';
			return $result;
		}

		$discount_percent = isset($pricing_config['discount_percent']) ? $this->sanitize_discount_percent($pricing_config['discount_percent']) : 0;
		// fuel_surcharge-feltet lagres historisk under samme nøkkel, men behandles som prosent.
		$fuel_percent = isset($pricing_config['fuel_surcharge']) ? $this->sanitize_non_negative_number($pricing_config['fuel_surcharge']) : 0;
		$extracted_toll_surcharge = isset($pricing_config['toll_surcharge']) ? $this->sanitize_non_negative_number($pricing_config['toll_surcharge']) : 0;
		$manual_handling_fee = isset($pricing_config['manual_handling_fee'])
			? $this->sanitize_non_negative_number($pricing_config['manual_handling_fee'])
			: (isset($pricing_config['handling_fee']) ? $this->sanitize_non_negative_number($pricing_config['handling_fee']) : 0);
		$bring_manual_handling_fee = isset($pricing_config['bring_manual_handling_fee']) ? $this->sanitize_non_negative_number($pricing_config['bring_manual_handling_fee']) : 0;
		$bring_manual_handling_triggered = !empty($pricing_config['bring_manual_handling_triggered']);
		if (!$bring_manual_handling_triggered) {
			$bring_manual_handling_fee = 0;
		}
		$total_handling_fee = round($manual_handling_fee + $bring_manual_handling_fee, 2);
		$bring_manual_handling_package_count = isset($pricing_config['bring_manual_handling_package_count']) ? max(0, (int) $pricing_config['bring_manual_handling_package_count']) : 0;
		$vat_percent = isset($pricing_config['vat_percent']) ? (float) $pricing_config['vat_percent'] : 0;
		$rounding_mode = isset($pricing_config['rounding_mode']) ? $this->sanitize_rounding_mode($pricing_config['rounding_mode']) : 'none';

		// selected_price/value tolkes som listepris inkl. drivstoff, bom og håndtering.
		$list_minus_fixed_fees = $original_list_price - $total_handling_fee - $extracted_toll_surcharge;
		if ($list_minus_fixed_fees < 0) {
			$list_minus_fixed_fees = 0;
		}

		$fuel_multiplier = 1 + ($fuel_percent / 100);
		if ($fuel_multiplier <= 0) {
			$fuel_multiplier = 1;
		}

		$extracted_base_freight = $list_minus_fixed_fees / $fuel_multiplier;
		$discounted_base_freight = $extracted_base_freight - ($extracted_base_freight * $discount_percent / 100);
		$recalculated_fuel_surcharge = $discounted_base_freight * ($fuel_percent / 100);
		$subtotal_ex_vat = $discounted_base_freight + $recalculated_fuel_surcharge + $extracted_toll_surcharge + $total_handling_fee;
		$price_incl_vat = $subtotal_ex_vat * (1 + ($vat_percent / 100));
		$rounded_price = $this->apply_rounding_mode($price_incl_vat, $rounding_mode);
		$final_price_ex_vat = $vat_percent > 0 ? ($rounded_price / (1 + ($vat_percent / 100))) : $rounded_price;

		$result['status'] = 'ok';
		$result['original_price'] = number_format($original_list_price, 2, '.', '');
		$result['original_list_price'] = number_format($original_list_price, 2, '.', '');
		$result['manual_handling_fee'] = number_format($manual_handling_fee, 2, '.', '');
		$result['bring_manual_handling_fee'] = number_format($bring_manual_handling_fee, 2, '.', '');
		$result['total_handling_fee'] = number_format($total_handling_fee, 2, '.', '');
		$result['bring_manual_handling_triggered'] = $bring_manual_handling_triggered;
		$result['bring_manual_handling_package_count'] = $bring_manual_handling_package_count;
		$result['extracted_handling_fee'] = number_format($total_handling_fee, 2, '.', '');
		$result['extracted_toll_surcharge'] = number_format($extracted_toll_surcharge, 2, '.', '');
		$result['extracted_fuel_percent'] = number_format($fuel_percent, 2, '.', '');
		$result['extracted_base_freight'] = number_format($extracted_base_freight, 2, '.', '');
		$result['discounted_base_freight'] = number_format($discounted_base_freight, 2, '.', '');
		$result['recalculated_fuel_surcharge'] = number_format($recalculated_fuel_surcharge, 2, '.', '');
		$result['base_price'] = number_format($extracted_base_freight, 2, '.', '');
		$result['discount_percent'] = number_format($discount_percent, 2, '.', '');
		$result['discounted_base'] = number_format($discounted_base_freight, 2, '.', '');
		// Behold historisk nøkkelnavn for bakoverkompatibilitet, men verdien er prosentsats.
		$result['fuel_surcharge'] = number_format($fuel_percent, 2, '.', '');
		$result['toll_surcharge'] = number_format($extracted_toll_surcharge, 2, '.', '');
		$result['handling_fee'] = number_format($total_handling_fee, 2, '.', '');
		$result['subtotal_ex_vat'] = number_format($subtotal_ex_vat, 2, '.', '');
		$result['price_incl_vat'] = number_format($price_incl_vat, 2, '.', '');
		$result['rounded_price'] = number_format($rounded_price, 2, '.', '');
		$result['final_price_ex_vat'] = number_format($final_price_ex_vat, 2, '.', '');

		return $result;
	}


	private function build_packages_summary($packages) {
		$summary = array();
		if (!is_array($packages)) {
			return $summary;
		}
		foreach ($packages as $package) {
			$summary[] = array(
				'name' => isset($package['name']) ? (string) $package['name'] : '',
				'description' => isset($package['description']) ? (string) $package['description'] : '',
				'weight' => isset($package['weight']) ? (float) $package['weight'] : 0,
				'length' => isset($package['length']) ? (float) $package['length'] : 0,
				'width' => isset($package['width']) ? (float) $package['width'] : 0,
				'height' => isset($package['height']) ? (float) $package['height'] : 0,
			);
		}
		return $summary;
	}

	private function run_consignment_estimate_for_packages($packages, $recipient, $method_payload, $pricing_config) {
		$result = array(
			'status' => 'failed',
			'http_status' => 0,
			'error' => '',
			'error_code' => '',
			'error_type' => '',
			'error_details' => '',
			'parsed_error_message' => '',
			'raw_response' => '',
			'estimated_cost' => '',
			'gross_amount' => '',
			'net_amount' => '',
			'fallback_price' => '',
			'selected_price_source' => '',
			'selected_price_value' => '',
			'configured_price_source_key' => '',
			'price_source_fallback_used' => false,
			'price_source_fallback_reason' => '',
			'price_source_priority' => array(),
			'actual_fallback_priority' => array(),
			'fallback_step_used' => 0,
			'calculated' => array(),
		);

		$xml = $this->build_estimate_request_xml(array(
			'recipient' => $recipient,
			'packages' => $packages,
			'servicepartner' => isset($method_payload['servicepartner']) ? $method_payload['servicepartner'] : '',
			'use_sms_service' => !empty($method_payload['use_sms_service']),
			'sms_service_id' => isset($method_payload['sms_service_id']) ? $method_payload['sms_service_id'] : '',
		), $method_payload);

		$response = wp_remote_post('https://api.cargonizer.no/consignment_costs.xml', array(
			'timeout' => 40,
			'headers' => array_merge($this->get_auth_headers(), array('Content-Type' => 'application/xml')),
			'body' => $xml,
		));

		if (is_wp_error($response)) {
			$result['error'] = $response->get_error_message();
			$result['parsed_error_message'] = $result['error'];
			return $result;
		}

		$status = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		$result['http_status'] = $status;
		$result['raw_response'] = $body;

		if ($status < 200 || $status >= 300) {
			$error_details = $this->parse_response_error_details($body);
			$result['error_code'] = $error_details['code'];
			$result['error_type'] = $error_details['type'];
			$result['parsed_error_message'] = $error_details['message'];
			$result['error_details'] = $error_details['details'];
			$result['error'] = 'HTTP ' . $status;
			if ($result['parsed_error_message'] !== '') {
				$result['error'] .= ': ' . $result['parsed_error_message'];
			}
			return $result;
		}

		$price_fields = $this->parse_estimate_price_fields($body);
		$result['estimated_cost'] = $price_fields['estimated_cost'];
		$result['gross_amount'] = $price_fields['gross_amount'];
		$result['net_amount'] = $price_fields['net_amount'];
		$result['fallback_price'] = $price_fields['fallback_price'];

		$source_config = isset($pricing_config['price_source']) ? $pricing_config['price_source'] : 'estimated';
		$selected_price = $this->select_estimate_price_value($price_fields, $source_config);
		$result['selected_price_source'] = $selected_price['source'];
		$result['selected_price_value'] = $selected_price['value'];
		$result['configured_price_source_key'] = isset($selected_price['configured_key']) ? $selected_price['configured_key'] : '';
		$result['price_source_fallback_used'] = !empty($selected_price['used_fallback']);
		$result['price_source_priority'] = isset($selected_price['fallback_priority']) ? $selected_price['fallback_priority'] : array();
		$result['actual_fallback_priority'] = isset($selected_price['actual_fallback_priority']) ? $selected_price['actual_fallback_priority'] : array();
		$result['fallback_step_used'] = isset($selected_price['fallback_step_used']) ? (int) $selected_price['fallback_step_used'] : 0;
		if ($result['price_source_fallback_used']) {
			$result['price_source_fallback_reason'] = 'Konfigurert kilde (' . $result['configured_price_source_key'] . ') manglet eller var tom. Brukte ' . ($result['selected_price_source'] !== '' ? $result['selected_price_source'] : 'ingen kilde') . '.';
		}

		$calculate_payload = array_merge($pricing_config, array(
			'carrier_id' => isset($method_payload['carrier_id']) ? $method_payload['carrier_id'] : '',
			'carrier_name' => isset($method_payload['carrier_name']) ? $method_payload['carrier_name'] : '',
			'packages' => $packages,
		));
		$calculated = $this->calculate_estimate_from_price_source($selected_price, $calculate_payload);
		$result['status'] = $calculated['status'];
		if ($calculated['error'] !== '') {
			$result['error'] = $calculated['error'];
			if ($result['status'] === 'unknown') {
				$result['parsed_error_message'] = $this->parse_response_error_message($body);
			}
			return $result;
		}

		$result['calculated'] = $calculated;
		return $result;
	}

	private function apply_estimate_result_to_item($item, $estimate_result, $method_payload, $recipient) {
		$item['http_status'] = isset($estimate_result['http_status']) ? $estimate_result['http_status'] : 0;
		$item['raw_response'] = isset($estimate_result['raw_response']) ? $estimate_result['raw_response'] : '';
		$item['status'] = isset($estimate_result['status']) ? $estimate_result['status'] : 'failed';
		$item['error'] = isset($estimate_result['error']) ? $estimate_result['error'] : '';
		$item['error_code'] = isset($estimate_result['error_code']) ? $estimate_result['error_code'] : '';
		$item['error_type'] = isset($estimate_result['error_type']) ? $estimate_result['error_type'] : '';
		$item['error_details'] = isset($estimate_result['error_details']) ? $estimate_result['error_details'] : '';
		$item['parsed_error_message'] = isset($estimate_result['parsed_error_message']) ? $estimate_result['parsed_error_message'] : '';
		$item['estimated_cost'] = isset($estimate_result['estimated_cost']) ? $estimate_result['estimated_cost'] : '';
		$item['gross_amount'] = isset($estimate_result['gross_amount']) ? $estimate_result['gross_amount'] : '';
		$item['net_amount'] = isset($estimate_result['net_amount']) ? $estimate_result['net_amount'] : '';
		$item['fallback_price'] = isset($estimate_result['fallback_price']) ? $estimate_result['fallback_price'] : '';
		$item['selected_price_source'] = isset($estimate_result['selected_price_source']) ? $estimate_result['selected_price_source'] : '';
		$item['selected_price_value'] = isset($estimate_result['selected_price_value']) ? $estimate_result['selected_price_value'] : '';
		$item['configured_price_source_key'] = isset($estimate_result['configured_price_source_key']) ? $estimate_result['configured_price_source_key'] : '';
		$item['price_source_fallback_used'] = !empty($estimate_result['price_source_fallback_used']);
		$item['price_source_fallback_reason'] = isset($estimate_result['price_source_fallback_reason']) ? $estimate_result['price_source_fallback_reason'] : '';
		$item['price_source_priority'] = isset($estimate_result['price_source_priority']) ? $estimate_result['price_source_priority'] : array();
		$item['actual_fallback_priority'] = isset($estimate_result['actual_fallback_priority']) ? $estimate_result['actual_fallback_priority'] : array();
		$item['fallback_step_used'] = isset($estimate_result['fallback_step_used']) ? (int) $estimate_result['fallback_step_used'] : 0;

		if (!empty($estimate_result['calculated'])) {
			$calculated = $estimate_result['calculated'];
			$item['status'] = 'ok';
			$item['estimated_price'] = isset($estimate_result['selected_price_value']) ? $estimate_result['selected_price_value'] : '';
			$item['original_price'] = isset($calculated['original_price']) ? $calculated['original_price'] : '';
			$item['original_list_price'] = isset($calculated['original_list_price']) ? $calculated['original_list_price'] : '';
			$item['manual_handling_fee'] = isset($calculated['manual_handling_fee']) ? $calculated['manual_handling_fee'] : $item['manual_handling_fee'];
			$item['bring_manual_handling_fee'] = isset($calculated['bring_manual_handling_fee']) ? $calculated['bring_manual_handling_fee'] : $item['bring_manual_handling_fee'];
			$item['total_handling_fee'] = isset($calculated['total_handling_fee']) ? $calculated['total_handling_fee'] : $item['total_handling_fee'];
			$item['bring_manual_handling_triggered'] = !empty($calculated['bring_manual_handling_triggered']);
			$item['bring_manual_handling_package_count'] = isset($calculated['bring_manual_handling_package_count']) ? (int) $calculated['bring_manual_handling_package_count'] : 0;
			$item['base_price'] = isset($calculated['base_price']) ? $calculated['base_price'] : '';
			$item['discount_percent'] = isset($calculated['discount_percent']) ? $calculated['discount_percent'] : '';
			$item['discounted_base'] = isset($calculated['discounted_base']) ? $calculated['discounted_base'] : '';
			$item['fuel_surcharge'] = isset($calculated['fuel_surcharge']) ? $calculated['fuel_surcharge'] : '';
			$item['recalculated_fuel_surcharge'] = isset($calculated['recalculated_fuel_surcharge']) ? $calculated['recalculated_fuel_surcharge'] : '';
			$item['toll_surcharge'] = isset($calculated['toll_surcharge']) ? $calculated['toll_surcharge'] : '';
			$item['handling_fee'] = isset($calculated['handling_fee']) ? $calculated['handling_fee'] : '';
			$item['subtotal_ex_vat'] = isset($calculated['subtotal_ex_vat']) ? $calculated['subtotal_ex_vat'] : '';
			$item['price_incl_vat'] = isset($calculated['price_incl_vat']) ? $calculated['price_incl_vat'] : '';
			$item['rounded_price'] = isset($calculated['rounded_price']) ? $calculated['rounded_price'] : '';
			$item['final_price_ex_vat'] = isset($calculated['final_price_ex_vat']) ? $calculated['final_price_ex_vat'] : '';
			$item['error'] = '';
			$item['parsed_error_message'] = '';
			$item['error_code'] = '';
			$item['error_type'] = '';
			$item['error_details'] = '';
			$item['human_error'] = '';
			return $item;
		}

		$combined_error_text = strtolower(trim($item['error_code'] . ' ' . $item['parsed_error_message'] . ' ' . $item['error_details'] . ' ' . $item['error']));
		if (strpos($combined_error_text, 'product_is_out_of_spec') !== false) {
			$summary = isset($item['request_summary']) ? $item['request_summary'] : array();
			$summary_text = 'agreement=' . (isset($summary['agreement_id']) ? $summary['agreement_id'] : '—') . ', product=' . (isset($summary['product_id']) ? $summary['product_id'] : '—') . ', country=' . (isset($summary['country']) ? $summary['country'] : '—') . ', postcode=' . (isset($summary['postcode']) ? $summary['postcode'] : '—') . ', kolli=' . (isset($summary['number_of_packages']) ? $summary['number_of_packages'] : '—') . ', servicepartner=' . (isset($summary['selected_servicepartner']) && $summary['selected_servicepartner'] !== '' ? $summary['selected_servicepartner'] : 'ikke valgt');
			$item['human_error'] = 'Produktet er sannsynligvis utenfor spesifikasjon. Vanlige årsaker er antall kolli, mål, vekt, volum eller manglende obligatoriske felter for valgt produkt. Request: ' . $summary_text;
			$is_pickup_related = strpos($combined_error_text, 'pickup') !== false || strpos($combined_error_text, 'servicepoint') !== false || strpos($combined_error_text, 'service point') !== false || strpos(strtolower($method_payload['product_name']), 'pickup') !== false || strpos(strtolower($method_payload['product_name']), 'locker') !== false || strpos(strtolower($method_payload['product_name']), 'service point') !== false;
			if ($is_pickup_related && isset($method_payload['servicepartner']) && $method_payload['servicepartner'] === '') {
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

		return $item;
	}

	private function evaluate_dsv_partition($partition, $all_packages, $recipient, $method_payload, $pricing_config, $partition_index) {
		$variant = array(
			'partition_index' => (int) $partition_index,
			'shipment_count' => count($partition),
			'status' => 'ok',
			'is_winner' => false,
			'is_baseline' => false,
			'total_final_price_ex_vat' => '',
			'total_rounded_price' => '',
			'error' => '',
			'groups' => array(),
		);
		$total_final = 0.0;
		$total_rounded = 0.0;

		foreach ($partition as $group_indexes) {
			$group_packages = array();
			foreach ($group_indexes as $package_index) {
				if (isset($all_packages[$package_index])) {
					$group_packages[] = $all_packages[$package_index];
				}
			}
			$group_bring = $this->get_bring_manual_handling_fee($group_packages, $method_payload);
			$group_manual_fee = isset($pricing_config['manual_handling_fee']) ? $this->sanitize_non_negative_number($pricing_config['manual_handling_fee']) : 0;
			$group_bring_fee = isset($group_bring['fee']) ? $this->sanitize_non_negative_number($group_bring['fee']) : 0;
			$group_pricing = $pricing_config;
			$group_pricing['bring_manual_handling_fee'] = $group_bring_fee;
			$group_pricing['bring_manual_handling_triggered'] = !empty($group_bring['triggered']);
			$group_pricing['bring_manual_handling_package_count'] = isset($group_bring['package_count']) ? (int) $group_bring['package_count'] : 0;
			$group_pricing['handling_fee'] = round($group_manual_fee + $group_bring_fee, 2);

			$group_result = $this->run_consignment_estimate_for_packages($group_packages, $recipient, $method_payload, $group_pricing);
			$group_debug = array(
				'package_indexes' => $group_indexes,
				'packages_summary' => $this->build_packages_summary($group_packages),
				'status' => $group_result['status'],
				'http_status' => $group_result['http_status'],
				'selected_price_source' => $group_result['selected_price_source'],
				'selected_price_value' => $group_result['selected_price_value'],
				'final_price_ex_vat' => '',
				'rounded_price' => '',
				'raw_response' => $group_result['raw_response'],
				'parsed_error_message' => $group_result['parsed_error_message'],
				'error_code' => $group_result['error_code'],
				'error_type' => $group_result['error_type'],
				'error_details' => $group_result['error_details'],
				'error' => $group_result['error'],
			);

			if (!empty($group_result['calculated'])) {
				$group_debug['final_price_ex_vat'] = isset($group_result['calculated']['final_price_ex_vat']) ? $group_result['calculated']['final_price_ex_vat'] : '';
				$group_debug['rounded_price'] = isset($group_result['calculated']['rounded_price']) ? $group_result['calculated']['rounded_price'] : '';
				$total_final += (float) $group_debug['final_price_ex_vat'];
				$total_rounded += (float) $group_debug['rounded_price'];
			} else {
				$variant['status'] = 'failed';
				$variant['error'] = $group_result['error'] !== '' ? $group_result['error'] : 'Kunne ikke beregne pris for en delsendelse.';
			}

			$variant['groups'][] = $group_debug;
		}

		if ($variant['status'] === 'ok') {
			$variant['total_final_price_ex_vat'] = number_format($total_final, 2, '.', '');
			$variant['total_rounded_price'] = number_format($total_rounded, 2, '.', '');
		}

		return $variant;
	}

	private function build_dsv_baseline_variant($estimate_result, $packages) {
		$indexes = array();
		if (is_array($packages) && count($packages) > 0) {
			$indexes = range(0, count($packages) - 1);
		}

		$group = array(
			'package_indexes' => $indexes,
			'packages_summary' => $this->build_packages_summary($packages),
			'status' => isset($estimate_result['status']) ? $estimate_result['status'] : 'failed',
			'http_status' => isset($estimate_result['http_status']) ? $estimate_result['http_status'] : 0,
			'selected_price_source' => isset($estimate_result['selected_price_source']) ? $estimate_result['selected_price_source'] : '',
			'selected_price_value' => isset($estimate_result['selected_price_value']) ? $estimate_result['selected_price_value'] : '',
			'final_price_ex_vat' => '',
			'rounded_price' => '',
			'raw_response' => isset($estimate_result['raw_response']) ? $estimate_result['raw_response'] : '',
			'parsed_error_message' => isset($estimate_result['parsed_error_message']) ? $estimate_result['parsed_error_message'] : '',
			'error_code' => isset($estimate_result['error_code']) ? $estimate_result['error_code'] : '',
			'error_type' => isset($estimate_result['error_type']) ? $estimate_result['error_type'] : '',
			'error_details' => isset($estimate_result['error_details']) ? $estimate_result['error_details'] : '',
			'error' => isset($estimate_result['error']) ? $estimate_result['error'] : '',
		);

		$variant = array(
			'partition_index' => -1,
			'shipment_count' => 1,
			'status' => isset($estimate_result['status']) ? $estimate_result['status'] : 'failed',
			'is_winner' => false,
			'is_baseline' => true,
			'total_final_price_ex_vat' => '',
			'total_rounded_price' => '',
			'error' => isset($estimate_result['error']) ? $estimate_result['error'] : '',
			'groups' => array($group),
		);

		if (!empty($estimate_result['calculated'])) {
			$final_price = isset($estimate_result['calculated']['final_price_ex_vat']) ? (string) $estimate_result['calculated']['final_price_ex_vat'] : '';
			$rounded_price = isset($estimate_result['calculated']['rounded_price']) ? (string) $estimate_result['calculated']['rounded_price'] : '';
			$variant['status'] = 'ok';
			$variant['total_final_price_ex_vat'] = $final_price;
			$variant['total_rounded_price'] = $rounded_price;
			$variant['groups'][0]['final_price_ex_vat'] = $final_price;
			$variant['groups'][0]['rounded_price'] = $rounded_price;
		}

		return $variant;
	}

	private function compare_dsv_variants($left, $right) {
		$left_price = isset($left['total_final_price_ex_vat']) ? (float) $left['total_final_price_ex_vat'] : INF;
		$right_price = isset($right['total_final_price_ex_vat']) ? (float) $right['total_final_price_ex_vat'] : INF;
		if ($left_price < $right_price) {
			return -1;
		}
		if ($left_price > $right_price) {
			return 1;
		}

		$left_shipments = isset($left['shipment_count']) ? (int) $left['shipment_count'] : PHP_INT_MAX;
		$right_shipments = isset($right['shipment_count']) ? (int) $right['shipment_count'] : PHP_INT_MAX;
		if ($left_shipments < $right_shipments) {
			return -1;
		}
		if ($left_shipments > $right_shipments) {
			return 1;
		}

		$left_all_together = $left_shipments === 1;
		$right_all_together = $right_shipments === 1;
		if ($left_all_together && !$right_all_together) {
			return -1;
		}
		if (!$left_all_together && $right_all_together) {
			return 1;
		}

		$left_partition = isset($left['partition_index']) ? (int) $left['partition_index'] : PHP_INT_MAX;
		$right_partition = isset($right['partition_index']) ? (int) $right['partition_index'] : PHP_INT_MAX;
		if ($left_partition < $right_partition) {
			return -1;
		}
		if ($left_partition > $right_partition) {
			return 1;
		}

		return 0;
	}

	private function optimize_dsv_partition_estimates($packages, $recipient, $method_payload, $pricing_config, $baseline_estimate_result) {
		$max_full_partition_packages = 5;
		$package_count = is_array($packages) ? count($packages) : 0;
		$baseline_variant = $this->build_dsv_baseline_variant($baseline_estimate_result, $packages);
		$debug = array(
			'enabled' => false,
			'reason' => '',
			'package_count' => $package_count,
			'baseline_estimate_attempted' => true,
			'baseline_estimate_status' => isset($baseline_variant['status']) ? $baseline_variant['status'] : 'failed',
			'partitions_tested' => 0,
			'winner_partition_index' => ($baseline_variant['status'] === 'ok') ? (int) $baseline_variant['partition_index'] : -1,
			'winner_total_final_price_ex_vat' => ($baseline_variant['status'] === 'ok') ? $baseline_variant['total_final_price_ex_vat'] : '',
			'winner_total_rounded_price' => ($baseline_variant['status'] === 'ok') ? $baseline_variant['total_rounded_price'] : '',
			'winner_shipment_count' => ($baseline_variant['status'] === 'ok') ? (int) $baseline_variant['shipment_count'] : 0,
			'optimization_changed_result' => false,
			'variants' => array($baseline_variant),
		);
		$result = array('used' => false, 'winner' => $baseline_variant, 'debug' => $debug);
		$winner = $baseline_variant['status'] === 'ok' ? $baseline_variant : null;

		if (!$this->is_dsv_method($method_payload)) {
			$result['debug']['reason'] = 'Hoppet over: metoden er ikke DSV.';
			$result['debug']['winner_partition_index'] = isset($baseline_variant['partition_index']) ? (int) $baseline_variant['partition_index'] : -1;
			return $result;
		}
		if ($package_count <= 1) {
			$result['debug']['reason'] = 'Hoppet over: krever mer enn 1 kolli.';
			$result['debug']['winner_partition_index'] = isset($baseline_variant['partition_index']) ? (int) $baseline_variant['partition_index'] : -1;
			return $result;
		}
		if ($package_count > $max_full_partition_packages) {
			$result['debug']['reason'] = 'Hoppet over: antall kolli (' . $package_count . ') overstiger sikkerhetsgrense på ' . $max_full_partition_packages . ' for full partition-testing.';
			$result['debug']['winner_partition_index'] = isset($baseline_variant['partition_index']) ? (int) $baseline_variant['partition_index'] : -1;
			return $result;
		}

		$partitions = $this->generate_package_index_partitions(range(0, $package_count - 1));
		$result['debug']['enabled'] = true;
		$result['debug']['reason'] = 'DSV-optimalisering kjørt med samlet estimat som baseline.';
		foreach ($partitions as $partition_index => $partition) {
			if (count($partition) === 1 && isset($partition[0]) && count($partition[0]) === $package_count) {
				continue;
			}
			$result['debug']['partitions_tested']++;
			$variant = $this->evaluate_dsv_partition($partition, $packages, $recipient, $method_payload, $pricing_config, $partition_index);
			$result['debug']['variants'][] = $variant;
			if ($variant['status'] !== 'ok') {
				continue;
			}
			if ($winner === null || $this->compare_dsv_variants($variant, $winner) < 0) {
				$winner = $variant;
			}
		}

		if ($winner === null) {
			$result['debug']['reason'] = 'DSV-optimalisering feilet: verken samlet estimat eller partitions ga gyldig resultat.';
			return $result;
		}

		$result['winner'] = $winner;
		$result['used'] = isset($winner['partition_index']) && (int) $winner['partition_index'] >= 0;
		$result['debug']['optimization_changed_result'] = $result['used'];
		foreach ($result['debug']['variants'] as $idx => $variant) {
			$is_same_partition = (int) $variant['partition_index'] === (int) $winner['partition_index'];
			$is_same_baseline = !empty($variant['is_baseline']) === !empty($winner['is_baseline']);
			$result['debug']['variants'][$idx]['is_winner'] = $is_same_partition && $is_same_baseline;
		}
		$result['debug']['winner_partition_index'] = (int) $winner['partition_index'];
		$result['debug']['winner_total_final_price_ex_vat'] = $winner['total_final_price_ex_vat'];
		$result['debug']['winner_total_rounded_price'] = $winner['total_rounded_price'];
		$result['debug']['winner_shipment_count'] = (int) $winner['shipment_count'];
		return $result;
	}

	private function parse_estimated_price($body) {
		$price_fields = $this->parse_estimate_price_fields($body);
		$selected = $this->select_estimate_price_value($price_fields);
		return $selected['value'];
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
					$is_pickup_related = strpos($combined_error_text, 'pickup') !== false || strpos($combined_error_text, 'servicepoint') !== false || strpos($combined_error_text, 'service point') !== false || strpos(strtolower($method_payload['product_name']), 'pickup') !== false || strpos(strtolower($method_payload['product_name']), 'locker') !== false || strpos(strtolower($method_payload['product_name']), 'service point') !== false;
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

	public function render_admin_page() {
		if (!current_user_can('manage_woocommerce')) {
			return;
		}

		$settings = $this->get_settings();
		$settings['available_methods'] = $this->ensure_internal_manual_methods(isset($settings['available_methods']) ? $settings['available_methods'] : array());
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
				'available_methods' => isset($settings['available_methods']) && is_array($settings['available_methods']) ? $settings['available_methods'] : array(),
				'enabled_methods' => is_array($posted_enabled_methods)
					? $posted_enabled_methods
					: (isset($settings['enabled_methods']) && is_array($settings['enabled_methods']) ? $settings['enabled_methods'] : array()),
				'method_discounts' => isset($_POST['lp_cargonizer_method_discounts']) && is_array($_POST['lp_cargonizer_method_discounts']) ? wp_unslash($_POST['lp_cargonizer_method_discounts']) : array(),
				'method_pricing' => isset($_POST['lp_cargonizer_method_pricing']) && is_array($_POST['lp_cargonizer_method_pricing']) ? wp_unslash($_POST['lp_cargonizer_method_pricing']) : array(),
			);

			$new_settings = $this->sanitize_settings($new_settings);
			update_option(self::OPTION_KEY, $new_settings);
			$settings = $this->get_settings();

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


					<h2>Tilgjengelige fraktmetoder i kalkulator</h2>
					<p>Kun valgte metoder vises for admin ved estimering av fraktkostnad. Prismodellen per metode bruker valgt prisfelt, rabatt, drivstofftillegg, bomtillegg, håndteringstillegg, mva og avrunding.</p>

					<?php if (!empty($settings['available_methods']) && is_array($settings['available_methods'])) : ?>
						<div style="max-height:260px;overflow:auto;border:1px solid #dcdcde;padding:12px;background:#fff;">
							<?php foreach ($settings['available_methods'] as $method) : ?>
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
								<div style="display:flex;gap:8px;align-items:flex-start;padding:6px 0;border-bottom:1px solid #f0f0f1;">
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
					<?php else : ?>
						<p><em>Ingen fraktmetoder hentet ennå. Klikk "Oppdater liste over fraktmetoder" først.</em></p>
					<?php endif; ?>
					<script>
					(function(){
						var container = document.currentScript ? document.currentScript.previousElementSibling : null;
						if (!container) { return; }
						container.querySelectorAll('.lp-cargonizer-method-toggle').forEach(function(toggle){
							var row = toggle.closest('div');
							if (!row) { return; }
							var pricingInputs = row.querySelectorAll('.lp-cargonizer-method-input');
							var sync = function(){
								pricingInputs.forEach(function(input){ input.disabled = !toggle.checked; });
							};
							toggle.addEventListener('change', sync);
							sync();
						});
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

new LP_Cargonizer_Connector();
