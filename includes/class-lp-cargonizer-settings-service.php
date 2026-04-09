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
			'booking_email_notification_default' => 1,
			'available_methods' => array($this->get_manual_norgespakke_method()),
			'enabled_methods' => array(),
			'method_discounts' => array(),
			'method_pricing' => array(),
			'live_checkout' => $this->get_live_checkout_defaults(),
			'shipping_profiles' => $this->get_shipping_profiles_defaults(),
			'package_resolution' => $this->get_package_resolution_defaults(),
			'checkout_method_rules' => $this->get_checkout_method_rules_defaults(),
			'checkout_fallback' => $this->get_checkout_fallback_defaults(),
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
			'booking_email_notification_default' => array_key_exists('booking_email_notification_default', $input)
				? $this->sanitize_checkbox_value($input['booking_email_notification_default'])
				: (isset($current['booking_email_notification_default']) ? $this->sanitize_checkbox_value($current['booking_email_notification_default']) : 1),
			'available_methods' => array(),
			'enabled_methods' => array(),
			'method_discounts' => array(),
			'method_pricing' => array(),
			'live_checkout' => $this->sanitize_live_checkout_settings(
				isset($input['live_checkout']) && is_array($input['live_checkout']) ? $input['live_checkout'] : array(),
				isset($current['live_checkout']) && is_array($current['live_checkout']) ? $current['live_checkout'] : array()
			),
			'shipping_profiles' => $this->sanitize_shipping_profiles_settings(
				isset($input['shipping_profiles']) && is_array($input['shipping_profiles']) ? $input['shipping_profiles'] : array(),
				isset($current['shipping_profiles']) && is_array($current['shipping_profiles']) ? $current['shipping_profiles'] : array()
			),
			'package_resolution' => $this->sanitize_package_resolution_settings(
				isset($input['package_resolution']) && is_array($input['package_resolution']) ? $input['package_resolution'] : array(),
				isset($current['package_resolution']) && is_array($current['package_resolution']) ? $current['package_resolution'] : array()
			),
			'checkout_method_rules' => $this->sanitize_checkout_method_rules_settings(
				isset($input['checkout_method_rules']) && is_array($input['checkout_method_rules']) ? $input['checkout_method_rules'] : array(),
				isset($current['checkout_method_rules']) && is_array($current['checkout_method_rules']) ? $current['checkout_method_rules'] : array()
			),
			'checkout_fallback' => $this->sanitize_checkout_fallback_settings(
				$this->prepare_checkout_fallback_input(
					isset($input['checkout_fallback']) && is_array($input['checkout_fallback']) ? $input['checkout_fallback'] : array(),
					isset($input['live_checkout']) && is_array($input['live_checkout']) ? $input['live_checkout'] : array()
				),
				isset($current['checkout_fallback']) && is_array($current['checkout_fallback']) ? $current['checkout_fallback'] : array()
			),
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
						'attributes' => $this->sanitize_service_attributes(isset($service['attributes']) && is_array($service['attributes']) ? $service['attributes'] : array()),
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

		$output = $this->validate_live_pricing_method_coverage($output);

		return $output;
	}

	private function validate_live_pricing_method_coverage($settings) {
		$settings = is_array($settings) ? $settings : array();
		$enabled_methods = isset($settings['enabled_methods']) && is_array($settings['enabled_methods']) ? $settings['enabled_methods'] : array();
		$live_checkout = isset($settings['live_checkout']) && is_array($settings['live_checkout']) ? $settings['live_checkout'] : array();
		$rules = isset($settings['checkout_method_rules']['rules']) && is_array($settings['checkout_method_rules']['rules']) ? $settings['checkout_method_rules']['rules'] : array();
		$overrides = array();
		foreach ($rules as $rule) {
			if (!is_array($rule)) {
				continue;
			}
			$method_key = isset($rule['method_key']) ? sanitize_text_field((string) $rule['method_key']) : '';
			if ($method_key === '') {
				continue;
			}
			$action = isset($rule['action']) ? sanitize_key((string) $rule['action']) : 'allow';
			if ($action !== 'decorate') {
				continue;
			}
			$overrides[$method_key] = array(
				'allow_low_price' => !isset($rule['allow_low_price']) || !empty($rule['allow_low_price']),
				'allow_free_shipping' => !isset($rule['allow_free_shipping']) || !empty($rule['allow_free_shipping']),
			);
		}

		$has_low_price_candidate = false;
		$has_free_candidate = false;
		foreach ($enabled_methods as $method_key) {
			$method_key = sanitize_text_field((string) $method_key);
			if ($method_key === '') {
				continue;
			}
			$rule = isset($overrides[$method_key]) ? $overrides[$method_key] : array(
				'allow_low_price' => true,
				'allow_free_shipping' => true,
			);
			if (!empty($rule['allow_low_price'])) {
				$has_low_price_candidate = true;
			}
			if (!empty($rule['allow_free_shipping'])) {
				$has_free_candidate = true;
			}
		}

		if ((isset($live_checkout['low_price_strategy']) ? (string) $live_checkout['low_price_strategy'] : '') !== 'disabled' && !$has_low_price_candidate && !empty($enabled_methods)) {
			$settings['live_checkout']['low_price_strategy'] = 'disabled';
		}
		if ((isset($live_checkout['free_shipping_strategy']) ? (string) $live_checkout['free_shipping_strategy'] : '') !== 'disabled' && !$has_free_candidate && !empty($enabled_methods)) {
			$settings['live_checkout']['free_shipping_strategy'] = 'disabled';
		}

		return $settings;
	}

	private function prepare_checkout_fallback_input($fallback_input, $live_checkout_input) {
		$prepared = is_array($fallback_input) ? $fallback_input : array();
		$legacy_behavior = isset($live_checkout_input['quote_fallback_behavior']) ? sanitize_text_field((string) $live_checkout_input['quote_fallback_behavior']) : '';
		if ($legacy_behavior !== '' && empty($prepared['on_quote_failure'])) {
			$prepared['on_quote_failure'] = $legacy_behavior;
		}
		return $prepared;
	}

	private function get_live_checkout_defaults() {
		return array(
			'enabled' => 0,
			'norway_only_enabled' => 1,
			'show_prices_including_vat' => 1,
			'free_shipping_threshold' => 1500,
			'low_price_option_amount' => 69,
			'low_price_strategy' => 'cheapest_eligible_live',
			'free_shipping_strategy' => 'cheapest_standard_eligible',
			'quote_timeout_seconds' => 5,
			'quote_cache_ttl_seconds' => 300,
			'pickup_point_cache_ttl_seconds' => 300,
			'debug_logging' => 0,
		);
	}

	private function get_shipping_profiles_defaults() {
		return array(
			'default_profile_slug' => 'default',
			'shipping_class_map' => array(),
			'category_map' => array(),
			'value_rules' => array(),
			'profiles' => array(
				array(
					'slug' => 'default',
					'label' => 'Standard',
					'default_weight' => 1,
					'default_dimensions' => array(
						'length' => 30,
						'width' => 20,
						'height' => 10,
					),
					'flags' => array(
						'pickup_capable' => 1,
						'mailbox_capable' => 0,
						'bulky' => 0,
						'high_value_secure' => 0,
						'force_separate_package' => 0,
					),
				),
			),
		);
	}

	private function get_package_resolution_defaults() {
		return array(
			'package_build_mode' => 'combined_single',
			'fallback_sources' => array(
				'product_dimensions',
				'product_override',
				'shipping_class_profile',
				'category_profile',
				'value_rule',
				'default_profile',
			),
		);
	}

	private function get_checkout_method_rules_defaults() {
		return array(
			'schema_version' => 2,
			'rules' => array(),
		);
	}

	private function get_checkout_fallback_defaults() {
		return array(
			'on_quote_failure' => 'safe_fallback_rate',
			'safe_fallback_rates' => array(
				array(
					'method_key' => 'fallback_standard',
					'label' => 'Standard frakt',
					'price' => 69,
				),
			),
			'allow_checkout_with_fallback' => 1,
		);
	}

	private function sanitize_live_checkout_settings($input, $current) {
		$base = wp_parse_args(is_array($current) ? $current : array(), $this->get_live_checkout_defaults());
		$output = array(
			'enabled' => isset($input['enabled']) ? $this->sanitize_checkbox_value($input['enabled']) : $this->sanitize_checkbox_value($base['enabled']),
			'norway_only_enabled' => isset($input['norway_only_enabled']) ? $this->sanitize_checkbox_value($input['norway_only_enabled']) : $this->sanitize_checkbox_value($base['norway_only_enabled']),
			'show_prices_including_vat' => isset($input['show_prices_including_vat']) ? $this->sanitize_checkbox_value($input['show_prices_including_vat']) : $this->sanitize_checkbox_value($base['show_prices_including_vat']),
			'free_shipping_threshold' => isset($input['free_shipping_threshold']) ? $this->sanitize_non_negative_number($input['free_shipping_threshold']) : $this->sanitize_non_negative_number($base['free_shipping_threshold']),
			'low_price_option_amount' => isset($input['low_price_option_amount']) ? $this->sanitize_non_negative_number($input['low_price_option_amount']) : $this->sanitize_non_negative_number($base['low_price_option_amount']),
			'low_price_strategy' => isset($input['low_price_strategy']) ? sanitize_text_field((string) $input['low_price_strategy']) : sanitize_text_field((string) $base['low_price_strategy']),
			'free_shipping_strategy' => isset($input['free_shipping_strategy']) ? sanitize_text_field((string) $input['free_shipping_strategy']) : sanitize_text_field((string) $base['free_shipping_strategy']),
			'quote_timeout_seconds' => isset($input['quote_timeout_seconds']) ? $this->sanitize_non_negative_number($input['quote_timeout_seconds']) : $this->sanitize_non_negative_number($base['quote_timeout_seconds']),
			'quote_cache_ttl_seconds' => isset($input['quote_cache_ttl_seconds']) ? $this->sanitize_non_negative_number($input['quote_cache_ttl_seconds']) : $this->sanitize_non_negative_number($base['quote_cache_ttl_seconds']),
			'pickup_point_cache_ttl_seconds' => isset($input['pickup_point_cache_ttl_seconds']) ? $this->sanitize_non_negative_number($input['pickup_point_cache_ttl_seconds']) : $this->sanitize_non_negative_number($base['pickup_point_cache_ttl_seconds']),
			'debug_logging' => isset($input['debug_logging']) ? $this->sanitize_checkbox_value($input['debug_logging']) : $this->sanitize_checkbox_value($base['debug_logging']),
		);

		$allowed_low_price = array('cheapest_eligible_live', 'disabled');
		if (!in_array($output['low_price_strategy'], $allowed_low_price, true)) {
			$output['low_price_strategy'] = 'cheapest_eligible_live';
		}

		$allowed_free_shipping = array('cheapest_standard_eligible', 'disabled');
		if (!in_array($output['free_shipping_strategy'], $allowed_free_shipping, true)) {
			$output['free_shipping_strategy'] = 'cheapest_standard_eligible';
		}

		return $output;
	}

	private function sanitize_shipping_profiles_settings($input, $current) {
		$base = wp_parse_args(is_array($current) ? $current : array(), $this->get_shipping_profiles_defaults());
		$output = array(
			'default_profile_slug' => isset($input['default_profile_slug']) ? sanitize_key((string) $input['default_profile_slug']) : sanitize_key((string) $base['default_profile_slug']),
			'shipping_class_map' => array(),
			'category_map' => array(),
			'value_rules' => array(),
			'profiles' => array(),
		);

		$profiles = isset($input['profiles']) && is_array($input['profiles']) ? $input['profiles'] : (isset($base['profiles']) && is_array($base['profiles']) ? $base['profiles'] : array());
		foreach ($profiles as $profile) {
			if (!is_array($profile)) {
				continue;
			}

			$slug = isset($profile['slug']) ? sanitize_key((string) $profile['slug']) : '';
			if ($slug === '') {
				continue;
			}

			$output['profiles'][] = array(
				'slug' => $slug,
				'label' => isset($profile['label']) ? sanitize_text_field((string) $profile['label']) : $slug,
				'default_weight' => isset($profile['default_weight']) ? $this->sanitize_non_negative_number($profile['default_weight']) : 0,
				'default_dimensions' => array(
					'length' => isset($profile['default_dimensions']['length']) ? $this->sanitize_non_negative_number($profile['default_dimensions']['length']) : 0,
					'width' => isset($profile['default_dimensions']['width']) ? $this->sanitize_non_negative_number($profile['default_dimensions']['width']) : 0,
					'height' => isset($profile['default_dimensions']['height']) ? $this->sanitize_non_negative_number($profile['default_dimensions']['height']) : 0,
				),
				'flags' => array(
					'pickup_capable' => isset($profile['flags']['pickup_capable']) ? $this->sanitize_checkbox_value($profile['flags']['pickup_capable']) : 0,
					'mailbox_capable' => isset($profile['flags']['mailbox_capable']) ? $this->sanitize_checkbox_value($profile['flags']['mailbox_capable']) : 0,
					'bulky' => isset($profile['flags']['bulky']) ? $this->sanitize_checkbox_value($profile['flags']['bulky']) : 0,
					'high_value_secure' => isset($profile['flags']['high_value_secure']) ? $this->sanitize_checkbox_value($profile['flags']['high_value_secure']) : 0,
					'force_separate_package' => isset($profile['flags']['force_separate_package']) ? $this->sanitize_checkbox_value($profile['flags']['force_separate_package']) : 0,
				),
			);
		}

		if (empty($output['profiles'])) {
			$output['profiles'] = $this->get_shipping_profiles_defaults()['profiles'];
		}

		$profile_slugs = array();
		foreach ($output['profiles'] as $profile) {
			$profile_slugs[$profile['slug']] = true;
		}
		if ($output['default_profile_slug'] === '' || !isset($profile_slugs[$output['default_profile_slug']])) {
			$output['default_profile_slug'] = isset($output['profiles'][0]['slug']) ? $output['profiles'][0]['slug'] : 'default';
		}

		$raw_shipping_class_map = isset($input['shipping_class_map']) && is_array($input['shipping_class_map'])
			? $input['shipping_class_map']
			: (isset($base['shipping_class_map']) && is_array($base['shipping_class_map']) ? $base['shipping_class_map'] : array());
		foreach ($raw_shipping_class_map as $shipping_class_slug => $profile_slug) {
			$shipping_class_slug = sanitize_key((string) $shipping_class_slug);
			$profile_slug = sanitize_key((string) $profile_slug);
			if ($shipping_class_slug === '' || $profile_slug === '' || !isset($profile_slugs[$profile_slug])) {
				continue;
			}
			$output['shipping_class_map'][$shipping_class_slug] = $profile_slug;
		}

		$raw_category_map = isset($input['category_map']) && is_array($input['category_map'])
			? $input['category_map']
			: (isset($base['category_map']) && is_array($base['category_map']) ? $base['category_map'] : array());
		foreach ($raw_category_map as $category_slug => $profile_slug) {
			$category_slug = sanitize_key((string) $category_slug);
			$profile_slug = sanitize_key((string) $profile_slug);
			if ($category_slug === '' || $profile_slug === '' || !isset($profile_slugs[$profile_slug])) {
				continue;
			}
			$output['category_map'][$category_slug] = $profile_slug;
		}

		$raw_value_rules = isset($input['value_rules']) && is_array($input['value_rules'])
			? $input['value_rules']
			: (isset($base['value_rules']) && is_array($base['value_rules']) ? $base['value_rules'] : array());
		foreach ($raw_value_rules as $value_rule) {
			if (!is_array($value_rule)) {
				continue;
			}
			$profile_slug = isset($value_rule['profile_slug']) ? sanitize_key((string) $value_rule['profile_slug']) : '';
			if ($profile_slug === '' || !isset($profile_slugs[$profile_slug])) {
				continue;
			}
			$output['value_rules'][] = array(
				'profile_slug' => $profile_slug,
				'min_total' => isset($value_rule['min_total']) ? $this->sanitize_non_negative_number($value_rule['min_total']) : 0,
				'max_total' => isset($value_rule['max_total']) ? $this->sanitize_non_negative_number($value_rule['max_total']) : 0,
				'min_quantity' => isset($value_rule['min_quantity']) ? max(0, (int) $value_rule['min_quantity']) : 0,
				'max_quantity' => isset($value_rule['max_quantity']) ? max(0, (int) $value_rule['max_quantity']) : 0,
			);
		}

		return $output;
	}

	private function sanitize_package_resolution_settings($input, $current) {
		$base = wp_parse_args(is_array($current) ? $current : array(), $this->get_package_resolution_defaults());
		$output = array(
			'package_build_mode' => isset($input['package_build_mode'])
				? sanitize_key((string) $input['package_build_mode'])
				: sanitize_key((string) (isset($base['package_build_mode']) ? $base['package_build_mode'] : 'combined_single')),
			'fallback_sources' => array(),
		);
		if (!in_array($output['package_build_mode'], array('combined_single', 'split_by_profile', 'separate_bulky_profiles'), true)) {
			$output['package_build_mode'] = 'combined_single';
		}
		$sources = isset($input['fallback_sources']) && is_array($input['fallback_sources']) ? $input['fallback_sources'] : (isset($base['fallback_sources']) && is_array($base['fallback_sources']) ? $base['fallback_sources'] : array());
		$allowed = array(
			'product_dimensions',
			'product_override',
			'shipping_class_profile',
			'category_profile',
			'value_rule',
			'default_profile',
		);
		foreach ($sources as $source) {
			$clean = sanitize_key((string) $source);
			if (in_array($clean, $allowed, true)) {
				$output['fallback_sources'][] = $clean;
			}
		}
		$output['fallback_sources'] = array_values(array_unique($output['fallback_sources']));
		if (empty($output['fallback_sources'])) {
			$output['fallback_sources'] = $this->get_package_resolution_defaults()['fallback_sources'];
		}
		return $output;
	}

	private function sanitize_checkout_method_rules_settings($input, $current) {
		$base = wp_parse_args(is_array($current) ? $current : array(), $this->get_checkout_method_rules_defaults());
		$output = array(
			'schema_version' => 2,
			'rules' => array(),
		);
		$rules = isset($input['rules']) && is_array($input['rules']) ? $input['rules'] : (isset($base['rules']) && is_array($base['rules']) ? $base['rules'] : array());

		foreach ($rules as $rule) {
			if (!is_array($rule)) {
				continue;
			}

			$method_key = isset($rule['method_key']) ? sanitize_text_field((string) $rule['method_key']) : '';
			if ($method_key === '') {
				continue;
			}

			$action = isset($rule['action']) ? sanitize_key((string) $rule['action']) : 'allow';
			if (!in_array($action, array('allow', 'deny', 'decorate'), true)) {
				$action = 'allow';
			}

			$conditions_groups = array();
			if (isset($rule['conditions_groups']) && is_array($rule['conditions_groups'])) {
				foreach ($rule['conditions_groups'] as $group_conditions) {
					if (!is_array($group_conditions)) {
						continue;
					}
					$clean_group = $this->sanitize_method_rule_conditions($group_conditions);
					if (!empty($clean_group)) {
						$conditions_groups[] = $clean_group;
					}
				}
			}
			$legacy_conditions = isset($rule['conditions']) && is_array($rule['conditions']) ? $this->sanitize_method_rule_conditions($rule['conditions']) : array();
			if (empty($conditions_groups) && !empty($legacy_conditions)) {
				$conditions_groups[] = $legacy_conditions;
			}

			$output['rules'][] = array(
				'method_key' => $method_key,
				'action' => $action,
				'enabled' => isset($rule['enabled']) ? $this->sanitize_checkbox_value($rule['enabled']) : 1,
				'customer_title' => isset($rule['customer_title']) ? sanitize_text_field((string) $rule['customer_title']) : '',
				'allow_low_price' => isset($rule['allow_low_price']) ? $this->sanitize_checkbox_value($rule['allow_low_price']) : 1,
				'allow_free_shipping' => isset($rule['allow_free_shipping']) ? $this->sanitize_checkbox_value($rule['allow_free_shipping']) : 1,
				'conditions' => $legacy_conditions,
				'conditions_groups' => $conditions_groups,
				'group_label' => isset($rule['group_label']) ? sanitize_text_field((string) $rule['group_label']) : '',
				'embedded_label' => isset($rule['embedded_label']) ? sanitize_text_field((string) $rule['embedded_label']) : '',
			);
		}

		return $output;
	}

	private function sanitize_method_rule_conditions($conditions) {
		$output = array();
		$allowed_text_conditions = array(
			'profile_slug',
			'category_slug',
			'security_level',
		);
		foreach ($allowed_text_conditions as $key) {
			if (isset($conditions[$key])) {
				$output[$key] = sanitize_text_field((string) $conditions[$key]);
			}
		}
		$allowed_tristate_conditions = array(
			'has_separate_package',
			'has_missing_dimensions',
			'has_high_value_secure',
			'mailbox_capable',
			'pickup_capable',
			'bulky',
		);
		foreach ($allowed_tristate_conditions as $key) {
			if (!isset($conditions[$key])) {
				continue;
			}
			$value = sanitize_key((string) $conditions[$key]);
			$output[$key] = in_array($value, array('any', 'yes', 'no'), true) ? $value : 'any';
		}

		$allowed_numeric_conditions = array(
			'min_weight',
			'max_weight',
			'min_order_value',
			'max_order_value',
			'min_total_weight',
			'max_total_weight',
		);
		foreach ($allowed_numeric_conditions as $key) {
			if (isset($conditions[$key])) {
				$output[$key] = $this->sanitize_non_negative_number($conditions[$key]);
			}
		}

		$allowed_checkbox_conditions = array(
			'require_separate_package',
			'require_high_value',
			'require_security',
		);
		foreach ($allowed_checkbox_conditions as $key) {
			if (isset($conditions[$key])) {
				$output[$key] = $this->sanitize_checkbox_value($conditions[$key]);
			}
		}
		foreach (array('profile_slugs', 'category_slugs') as $list_key) {
			if (!isset($conditions[$list_key]) || !is_array($conditions[$list_key])) {
				continue;
			}
			$clean_list = array();
			foreach ($conditions[$list_key] as $item) {
				$item = sanitize_key((string) $item);
				if ($item !== '') {
					$clean_list[] = $item;
				}
			}
			if (!empty($clean_list)) {
				$output[$list_key] = array_values(array_unique($clean_list));
			}
		}

		return $output;
	}

	private function sanitize_checkout_fallback_settings($input, $current) {
		$base = wp_parse_args(is_array($current) ? $current : array(), $this->get_checkout_fallback_defaults());
		$output = array(
			'on_quote_failure' => isset($input['on_quote_failure']) ? sanitize_text_field((string) $input['on_quote_failure']) : sanitize_text_field((string) $base['on_quote_failure']),
			'safe_fallback_rates' => array(),
			'allow_checkout_with_fallback' => isset($input['allow_checkout_with_fallback']) ? $this->sanitize_checkbox_value($input['allow_checkout_with_fallback']) : $this->sanitize_checkbox_value($base['allow_checkout_with_fallback']),
		);

		$rates = isset($input['safe_fallback_rates']) && is_array($input['safe_fallback_rates']) ? $input['safe_fallback_rates'] : (isset($base['safe_fallback_rates']) && is_array($base['safe_fallback_rates']) ? $base['safe_fallback_rates'] : array());
		foreach ($rates as $rate) {
			if (!is_array($rate)) {
				continue;
			}
			$method_key = isset($rate['method_key']) ? sanitize_text_field((string) $rate['method_key']) : '';
			if ($method_key === '') {
				continue;
			}
			$output['safe_fallback_rates'][] = array(
				'method_key' => $method_key,
				'label' => isset($rate['label']) ? sanitize_text_field((string) $rate['label']) : '',
				'price' => isset($rate['price']) ? $this->sanitize_non_negative_number($rate['price']) : 0,
			);
		}

		if (empty($output['safe_fallback_rates'])) {
			$output['safe_fallback_rates'] = $this->get_checkout_fallback_defaults()['safe_fallback_rates'];
		}

		$allowed_failure_modes = array('safe_fallback_rate', 'block_checkout', 'hide_live_checkout', 'use_last_known_rate');
		if (!in_array($output['on_quote_failure'], $allowed_failure_modes, true)) {
			$output['on_quote_failure'] = 'safe_fallback_rate';
		}

		return $output;
	}

	private function sanitize_service_attributes($attributes) {
		$clean_attributes = array();
		if (!is_array($attributes)) {
			return $clean_attributes;
		}

		foreach ($attributes as $attribute) {
			if (!is_array($attribute)) {
				continue;
			}

			$identifier = isset($attribute['identifier']) ? sanitize_text_field((string) $attribute['identifier']) : '';
			$type = isset($attribute['type']) ? sanitize_text_field((string) $attribute['type']) : '';
			$required = isset($attribute['required']) ? sanitize_text_field((string) $attribute['required']) : '';
			$min = isset($attribute['min']) ? sanitize_text_field((string) $attribute['min']) : '';
			$max = isset($attribute['max']) ? sanitize_text_field((string) $attribute['max']) : '';

			$clean_values = array();
			$values = isset($attribute['values']) && is_array($attribute['values']) ? $attribute['values'] : array();
			foreach ($values as $value_item) {
				if (!is_array($value_item)) {
					continue;
				}
				$value_value = isset($value_item['value']) ? sanitize_text_field((string) $value_item['value']) : '';
				$value_description = isset($value_item['description']) ? sanitize_text_field((string) $value_item['description']) : '';
				if ($value_value === '' && $value_description === '') {
					continue;
				}
				$clean_values[] = array(
					'value' => $value_value,
					'description' => $value_description,
				);
			}

			if ($identifier === '' && $type === '' && $required === '' && $min === '' && $max === '' && empty($clean_values)) {
				continue;
			}

			$clean_attributes[] = array(
				'identifier' => $identifier,
				'type' => $type,
				'required' => $required,
				'min' => $min,
				'max' => $max,
				'values' => $clean_values,
			);
		}

		return $clean_attributes;
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
