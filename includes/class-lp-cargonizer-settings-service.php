<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Settings_Service {
	/** @var string */
	private $option_key;

	/** @var string */
	private $manual_norgespakke_key;

	public function __construct($option_key, $manual_norgespakke_key) {
		$this->option_key = (string) $option_key;
		$this->manual_norgespakke_key = (string) $manual_norgespakke_key;
	}

	public function get_settings() {
		$defaults = array(
			'api_key'   => '',
			'sender_id' => '',
			'available_methods' => array($this->get_manual_norgespakke_method()),
			'enabled_methods' => array(),
			'method_discounts' => array(),
			'method_pricing' => array(),
		);

		$saved = get_option($this->option_key, array());

		if (!is_array($saved)) {
			$saved = array();
		}

		return wp_parse_args($saved, $defaults);
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
				'is_manual' => ($method_key === $this->manual_norgespakke_key) && !empty($method['is_manual']),
				'is_manual_norgespakke' => ($method_key === $this->manual_norgespakke_key),
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

	public function sanitize_discount_percent($value) {
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

	public function sanitize_non_negative_number($value) {
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

	public function sanitize_checkbox_value($value) {
		if (is_bool($value)) {
			return $value ? 1 : 0;
		}

		$normalized = strtolower(trim((string) $value));
		$truthy_values = array('1', 'true', 'yes', 'on');

		return in_array($normalized, $truthy_values, true) ? 1 : 0;
	}

	public function sanitize_price_source($value) {
		$source = sanitize_text_field((string) $value);
		$allowed = array('net', 'gross', 'estimated', 'fallback', 'manual_norgespakke');
		return in_array($source, $allowed, true) ? $source : 'estimated';
	}

	public function sanitize_rounding_mode($value) {
		$mode = sanitize_text_field((string) $value);
		$allowed = array('none', 'nearest_1', 'nearest_10', 'price_ending_9');
		return in_array($mode, $allowed, true) ? $mode : 'none';
	}

	public function get_default_method_pricing() {
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

	public function get_enabled_method_map() {
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

	public function get_enabled_method_discounts() {
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

	public function get_enabled_method_pricing() {
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

	public function get_manual_norgespakke_method() {
		return array(
			'key' => $this->manual_norgespakke_key,
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

	public function ensure_internal_manual_methods($options) {
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
			$is_internal_manual = ($key === $this->manual_norgespakke_key) || (($agreement_id . '|' . $product_id) === $this->manual_norgespakke_key);

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

	public function is_manual_norgespakke_method($method_payload) {
		if (!is_array($method_payload)) {
			return false;
		}

		$explicit_key = isset($method_payload['key']) ? trim((string) $method_payload['key']) : '';
		$key = trim((string) (isset($method_payload['agreement_id']) ? $method_payload['agreement_id'] : '')) . '|' . trim((string) (isset($method_payload['product_id']) ? $method_payload['product_id'] : ''));
		$resolved_key = $explicit_key !== '' ? $explicit_key : $key;

		if ($resolved_key === $this->manual_norgespakke_key || $key === $this->manual_norgespakke_key) {
			return true;
		}

		if (!empty($method_payload['is_manual_norgespakke']) || !empty($method_payload['is_manual'])) {
			return $resolved_key === $this->manual_norgespakke_key || $key === $this->manual_norgespakke_key;
		}

		return false;
	}
}
