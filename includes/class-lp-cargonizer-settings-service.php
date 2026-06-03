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
			'warehouse_profiles' => $this->get_warehouse_profiles_defaults(),
			'booking_email_notification_default' => 1,
			'booking_estimator_top_count' => 3,
			'booking_pickup_autoselect_mode' => 'nearest',
			'booking_order_status_after_created' => '',
			'printer_aliases' => array(),
			'available_methods' => array($this->get_manual_norgespakke_method()),
			'enabled_methods' => array(),
			'method_discounts' => array(),
			'method_pricing' => array(),
			'method_extra_services' => array(),
			'transport_agreement_fetch_results' => array(),
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
			'warehouse_profiles' => $this->sanitize_warehouse_profiles_settings(
				isset($input['warehouse_profiles']) && is_array($input['warehouse_profiles']) ? $input['warehouse_profiles'] : array(),
				isset($current['warehouse_profiles']) && is_array($current['warehouse_profiles']) ? $current['warehouse_profiles'] : array(),
				isset($input['sender_id']) ? sanitize_text_field($input['sender_id']) : ''
			),
			'booking_email_notification_default' => array_key_exists('booking_email_notification_default', $input)
				? $this->sanitize_checkbox_value($input['booking_email_notification_default'])
				: (isset($current['booking_email_notification_default']) ? $this->sanitize_checkbox_value($current['booking_email_notification_default']) : 1),
			'booking_estimator_top_count' => isset($input['booking_estimator_top_count'])
				? $this->sanitize_booking_estimator_top_count($input['booking_estimator_top_count'])
				: $this->sanitize_booking_estimator_top_count(isset($current['booking_estimator_top_count']) ? $current['booking_estimator_top_count'] : 3),
			'booking_pickup_autoselect_mode' => isset($input['booking_pickup_autoselect_mode'])
				? $this->sanitize_booking_pickup_autoselect_mode($input['booking_pickup_autoselect_mode'])
				: $this->sanitize_booking_pickup_autoselect_mode(isset($current['booking_pickup_autoselect_mode']) ? $current['booking_pickup_autoselect_mode'] : 'nearest'),
			'booking_order_status_after_created' => isset($input['booking_order_status_after_created'])
				? $this->sanitize_booking_order_status_after_created($input['booking_order_status_after_created'])
				: $this->sanitize_booking_order_status_after_created(isset($current['booking_order_status_after_created']) ? $current['booking_order_status_after_created'] : ''),
			'printer_aliases' => array(),
			'available_methods' => array(),
			'enabled_methods' => array(),
			'method_discounts' => array(),
			'method_pricing' => array(),
			'method_extra_services' => array(),
			'transport_agreement_fetch_results' => array(),
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

		$printer_aliases_input = isset($input['printer_aliases']) && is_array($input['printer_aliases'])
			? $input['printer_aliases']
			: (isset($current['printer_aliases']) && is_array($current['printer_aliases']) ? $current['printer_aliases'] : array());
		foreach ($printer_aliases_input as $printer_id => $alias_label) {
			$clean_printer_id = sanitize_text_field((string) $printer_id);
			$clean_alias_label = sanitize_text_field((string) $alias_label);
			if ($clean_printer_id === '' || $clean_alias_label === '') {
				continue;
			}
			$output['printer_aliases'][$clean_printer_id] = $clean_alias_label;
		}

		$available_map = array();
		$legacy_method_key_map = array();
		$legacy_method_default_key_map = array();
		$legacy_method_single_key_map = array();
		$available_legacy_key_by_method = array();
		$available_service_ids_by_method = array();
		$sender_alias_method_key_map = array();
		$default_warehouse_profile_id = isset($output['warehouse_profiles']['default_profile_id']) ? sanitize_key((string) $output['warehouse_profiles']['default_profile_id']) : '';
		foreach ($available_methods as $method) {
			if (!is_array($method)) {
				continue;
			}

			$method_key = isset($method['key']) ? sanitize_text_field((string) $method['key']) : '';
			if ($method_key === '') {
				continue;
			}

			$agreement_id = isset($method['agreement_id']) ? sanitize_text_field((string) $method['agreement_id']) : '';
			$product_id = isset($method['product_id']) ? sanitize_text_field((string) $method['product_id']) : '';
			$legacy_key = isset($method['legacy_key']) ? sanitize_text_field((string) $method['legacy_key']) : implode('|', array($agreement_id, $product_id));
			$sender_profile_id = isset($method['sender_profile_id']) ? sanitize_key((string) $method['sender_profile_id']) : '';

			$clean_method = array(
				'key' => $method_key,
				'legacy_key' => $legacy_key,
				'sender_profile_id' => $sender_profile_id,
				'sender_profile_name' => isset($method['sender_profile_name']) ? sanitize_text_field((string) $method['sender_profile_name']) : '',
				'sender_id' => isset($method['sender_id']) ? sanitize_text_field((string) $method['sender_id']) : '',
				'sender_entity_id' => isset($method['sender_entity_id']) ? sanitize_text_field((string) $method['sender_entity_id']) : '',
				'agreement_id' => $agreement_id,
				'agreement_name' => isset($method['agreement_name']) ? sanitize_text_field((string) $method['agreement_name']) : '',
				'agreement_description' => isset($method['agreement_description']) ? sanitize_text_field((string) $method['agreement_description']) : '',
				'agreement_number' => isset($method['agreement_number']) ? sanitize_text_field((string) $method['agreement_number']) : '',
				'carrier_id' => isset($method['carrier_id']) ? sanitize_text_field((string) $method['carrier_id']) : '',
				'carrier_name' => isset($method['carrier_name']) ? sanitize_text_field((string) $method['carrier_name']) : '',
				'product_id' => $product_id,
				'product_name' => isset($method['product_name']) ? sanitize_text_field((string) $method['product_name']) : '',
				'is_manual' => ($method_key === $this->manual_norgespakke_key) && !empty($method['is_manual']),
				'is_manual_norgespakke' => ($method_key === $this->manual_norgespakke_key),
				'label' => isset($method['label']) ? sanitize_text_field((string) $method['label']) : '',
				'services' => array(),
			);

			$available_service_ids_by_method[$method_key] = array();
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
					if ($service_id !== '') {
						$available_service_ids_by_method[$method_key][] = $service_id;
					}
				}
			}

			$output['available_methods'][] = $clean_method;
			$available_map[$method_key] = true;
			$available_legacy_key_by_method[$method_key] = $legacy_key;
			if ($legacy_key !== '') {
				foreach ($this->build_method_sender_identity_aliases($clean_method, $output['warehouse_profiles']) as $sender_alias) {
					$sender_alias_method_key_map[$sender_alias . '::' . $legacy_key] = $method_key;
				}
			}
			if ($legacy_key !== '') {
				if (!isset($legacy_method_key_map[$legacy_key])) {
					$legacy_method_key_map[$legacy_key] = array();
				}
				$legacy_method_key_map[$legacy_key][] = $method_key;
				if ($sender_profile_id === '' || ($default_warehouse_profile_id !== '' && $sender_profile_id === $default_warehouse_profile_id)) {
					$legacy_method_default_key_map[$legacy_key] = $method_key;
				}
			}
			$available_service_ids_by_method[$method_key] = array_values(array_unique($available_service_ids_by_method[$method_key]));
		}

		foreach ($legacy_method_key_map as $legacy_key => $mapped_keys) {
			$mapped_keys = array_values(array_unique($mapped_keys));
			if (count($mapped_keys) === 1) {
				$legacy_method_single_key_map[$legacy_key] = $mapped_keys[0];
			}
		}

		$method_extra_services_input = isset($input['method_extra_services']) && is_array($input['method_extra_services'])
			? $input['method_extra_services']
			: (isset($current['method_extra_services']) && is_array($current['method_extra_services']) ? $current['method_extra_services'] : array());
		foreach ($available_service_ids_by_method as $method_key => $available_service_ids) {
			if (empty($available_service_ids)) {
				continue;
			}
			$available_service_map = array_fill_keys($available_service_ids, true);
			$legacy_method_key = isset($available_legacy_key_by_method[$method_key]) ? $available_legacy_key_by_method[$method_key] : '';
			$has_explicit_service_input = is_array($method_extra_services_input) && array_key_exists($method_key, $method_extra_services_input);
			$alias_service_input_key = '';
			if (!$has_explicit_service_input && $legacy_method_key !== '' && is_array($method_extra_services_input)) {
				foreach ($output['available_methods'] as $candidate_method) {
					if (!is_array($candidate_method) || (isset($candidate_method['key']) ? (string) $candidate_method['key'] : '') !== (string) $method_key) {
						continue;
					}
					foreach ($this->build_method_sender_identity_aliases($candidate_method, $output['warehouse_profiles']) as $sender_alias) {
						$candidate_input_key = $sender_alias . '::' . $legacy_method_key;
						if (array_key_exists($candidate_input_key, $method_extra_services_input)) {
							$alias_service_input_key = $candidate_input_key;
							break 2;
						}
					}
				}
			}
			$has_alias_service_input = $alias_service_input_key !== '';
			$has_legacy_service_input = !$has_explicit_service_input && !$has_alias_service_input && $legacy_method_key !== '' && is_array($method_extra_services_input) && array_key_exists($legacy_method_key, $method_extra_services_input);
			$selected_service_ids = $has_explicit_service_input ? $method_extra_services_input[$method_key] : ($has_alias_service_input ? $method_extra_services_input[$alias_service_input_key] : ($has_legacy_service_input ? $method_extra_services_input[$legacy_method_key] : $available_service_ids));
			$selected_service_ids = is_array($selected_service_ids) ? $selected_service_ids : array();
			$output['method_extra_services'][$method_key] = array();
			foreach ($selected_service_ids as $selected_service_id) {
				$clean_service_id = sanitize_text_field((string) $selected_service_id);
				if ($clean_service_id !== '' && isset($available_service_map[$clean_service_id])) {
					$output['method_extra_services'][$method_key][] = $clean_service_id;
				}
			}
			$output['method_extra_services'][$method_key] = array_values(array_unique($output['method_extra_services'][$method_key]));
		}

		$fetch_results_input = isset($input['transport_agreement_fetch_results']) && is_array($input['transport_agreement_fetch_results'])
			? $input['transport_agreement_fetch_results']
			: (isset($current['transport_agreement_fetch_results']) && is_array($current['transport_agreement_fetch_results']) ? $current['transport_agreement_fetch_results'] : array());
		foreach ($fetch_results_input as $fetch_result) {
			if (!is_array($fetch_result)) {
				continue;
			}
			$profile_id = isset($fetch_result['profile_id']) ? sanitize_key((string) $fetch_result['profile_id']) : '';
			$sender_id = isset($fetch_result['sender_id']) ? sanitize_text_field((string) $fetch_result['sender_id']) : '';
			if ($profile_id === '' && $sender_id === '') {
				continue;
			}
			$output['transport_agreement_fetch_results'][] = array(
				'profile_id' => $profile_id,
				'profile_name' => isset($fetch_result['profile_name']) ? sanitize_text_field((string) $fetch_result['profile_name']) : '',
				'sender_id' => $sender_id,
				'sender_entity_id' => isset($fetch_result['sender_entity_id']) ? sanitize_text_field((string) $fetch_result['sender_entity_id']) : '',
				'effective_sender_id' => isset($fetch_result['effective_sender_id']) ? sanitize_text_field((string) $fetch_result['effective_sender_id']) : '',
				'success' => !empty($fetch_result['success']) ? 1 : 0,
				'message' => isset($fetch_result['message']) ? sanitize_text_field((string) $fetch_result['message']) : '',
				'status' => isset($fetch_result['status']) ? absint($fetch_result['status']) : 0,
				'agreement_count' => isset($fetch_result['agreement_count']) ? absint($fetch_result['agreement_count']) : 0,
				'method_count' => isset($fetch_result['method_count']) ? absint($fetch_result['method_count']) : 0,
				'fetched_at_gmt' => isset($fetch_result['fetched_at_gmt']) ? sanitize_text_field((string) $fetch_result['fetched_at_gmt']) : '',
			);
		}

		if (isset($input['enabled_methods']) && is_array($input['enabled_methods'])) {
			foreach ($input['enabled_methods'] as $method_key) {
				$clean_key = sanitize_text_field($method_key);
				$resolved_key = $this->resolve_available_method_key($clean_key, $available_map, $sender_alias_method_key_map, $legacy_method_default_key_map, $legacy_method_single_key_map);
				if ($resolved_key !== '') {
					$output['enabled_methods'][] = $resolved_key;
				}
			}
		}

		$output['enabled_methods'] = array_values(array_unique($output['enabled_methods']));

		if (isset($input['method_discounts']) && is_array($input['method_discounts'])) {
			$enabled_map = array_fill_keys($output['enabled_methods'], true);
			foreach ($input['method_discounts'] as $method_key => $discount_value) {
				$clean_key = sanitize_text_field((string) $method_key);
				$clean_key = $this->resolve_available_method_key($clean_key, $available_map, $sender_alias_method_key_map, $legacy_method_default_key_map, $legacy_method_single_key_map);
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
			$clean_key = $this->resolve_available_method_key($clean_key, $available_map, $sender_alias_method_key_map, $legacy_method_default_key_map, $legacy_method_single_key_map);
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
			'free_shipping_threshold_basis' => 'subtotal_incl_vat',
			'free_shipping_threshold' => 1500,
			'low_price_option_amount' => 69,
			'low_price_strategy' => 'cheapest_eligible_live',
			'free_shipping_strategy' => 'cheapest_standard_eligible',
			'quote_timing_mode' => 'checkout_only',
			'quote_timeout_seconds' => 3,
			'quote_cache_ttl_seconds' => 300,
			'pickup_point_timeout_seconds' => 8,
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
			'separate_package_strategy' => 'keep_separate_colli',
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
			'free_shipping_threshold_basis' => isset($input['free_shipping_threshold_basis']) ? sanitize_key((string) $input['free_shipping_threshold_basis']) : sanitize_key((string) $base['free_shipping_threshold_basis']),
			'free_shipping_threshold' => isset($input['free_shipping_threshold']) ? $this->sanitize_non_negative_number($input['free_shipping_threshold']) : $this->sanitize_non_negative_number($base['free_shipping_threshold']),
			'low_price_option_amount' => isset($input['low_price_option_amount']) ? $this->sanitize_non_negative_number($input['low_price_option_amount']) : $this->sanitize_non_negative_number($base['low_price_option_amount']),
			'low_price_strategy' => isset($input['low_price_strategy']) ? sanitize_text_field((string) $input['low_price_strategy']) : sanitize_text_field((string) $base['low_price_strategy']),
			'free_shipping_strategy' => isset($input['free_shipping_strategy']) ? sanitize_text_field((string) $input['free_shipping_strategy']) : sanitize_text_field((string) $base['free_shipping_strategy']),
			'quote_timing_mode' => isset($input['quote_timing_mode']) ? sanitize_key((string) $input['quote_timing_mode']) : sanitize_key((string) $base['quote_timing_mode']),
			'quote_timeout_seconds' => isset($input['quote_timeout_seconds']) ? $this->sanitize_non_negative_number($input['quote_timeout_seconds']) : $this->sanitize_non_negative_number($base['quote_timeout_seconds']),
			'quote_cache_ttl_seconds' => isset($input['quote_cache_ttl_seconds']) ? $this->sanitize_non_negative_number($input['quote_cache_ttl_seconds']) : $this->sanitize_non_negative_number($base['quote_cache_ttl_seconds']),
			'pickup_point_timeout_seconds' => isset($input['pickup_point_timeout_seconds']) ? $this->sanitize_non_negative_number($input['pickup_point_timeout_seconds']) : $this->sanitize_non_negative_number($base['pickup_point_timeout_seconds']),
			'pickup_point_cache_ttl_seconds' => isset($input['pickup_point_cache_ttl_seconds']) ? $this->sanitize_non_negative_number($input['pickup_point_cache_ttl_seconds']) : $this->sanitize_non_negative_number($base['pickup_point_cache_ttl_seconds']),
			'debug_logging' => isset($input['debug_logging']) ? $this->sanitize_checkbox_value($input['debug_logging']) : $this->sanitize_checkbox_value($base['debug_logging']),
		);

		$allowed_threshold_basis = array('subtotal_incl_vat', 'subtotal_excl_vat');
		if (!in_array($output['free_shipping_threshold_basis'], $allowed_threshold_basis, true)) {
			$output['free_shipping_threshold_basis'] = 'subtotal_incl_vat';
		}

		$allowed_low_price = array('cheapest_eligible_live', 'disabled');
		if (!in_array($output['low_price_strategy'], $allowed_low_price, true)) {
			$output['low_price_strategy'] = 'cheapest_eligible_live';
		}

		$allowed_free_shipping = array('cheapest_standard_eligible', 'disabled');
		if (!in_array($output['free_shipping_strategy'], $allowed_free_shipping, true)) {
			$output['free_shipping_strategy'] = 'cheapest_standard_eligible';
		}

		$allowed_quote_timing_modes = array('checkout_only', 'cart_and_checkout');
		if (!in_array($output['quote_timing_mode'], $allowed_quote_timing_modes, true)) {
			$output['quote_timing_mode'] = 'checkout_only';
		}

		return $output;
	}

	private function sanitize_booking_estimator_top_count($value) {
		$count = absint($value);
		if ($count < 3) {
			$count = 3;
		}
		if ($count > 5) {
			$count = 5;
		}
		return $count;
	}

	private function sanitize_booking_pickup_autoselect_mode($value) {
		$mode = sanitize_key((string) $value);
		return in_array($mode, array('nearest', 'none'), true) ? $mode : 'nearest';
	}

	private function sanitize_booking_order_status_after_created($value) {
		$status = sanitize_key((string) $value);
		if (strpos($status, 'wc-') === 0) {
			$status = substr($status, 3);
		}
		if ($status === '') {
			return '';
		}

		if (function_exists('wc_get_order_statuses')) {
			$allowed_statuses = array();
			foreach (wc_get_order_statuses() as $status_key => $status_label) {
				$clean_key = sanitize_key((string) $status_key);
				if (strpos($clean_key, 'wc-') === 0) {
					$clean_key = substr($clean_key, 3);
				}
				if ($clean_key !== '') {
					$allowed_statuses[$clean_key] = true;
				}
			}
			if (!isset($allowed_statuses[$status])) {
				return '';
			}
		}

		return $status;
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
			'separate_package_strategy' => isset($input['separate_package_strategy'])
				? sanitize_key((string) $input['separate_package_strategy'])
				: sanitize_key((string) (isset($base['separate_package_strategy']) ? $base['separate_package_strategy'] : 'keep_separate_colli')),
			'fallback_sources' => array(),
		);
		if (!in_array($output['package_build_mode'], array('combined_single', 'split_by_profile', 'separate_bulky_profiles'), true)) {
			$output['package_build_mode'] = 'combined_single';
		}
		if (!in_array($output['separate_package_strategy'], array('keep_separate_colli', 'merge_non_separate_into_first_separate'), true)) {
			$output['separate_package_strategy'] = 'keep_separate_colli';
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
			$aliases[] = sanitize_key('sender_' . $sender_id);
		}
		if ($sender_entity_id !== '') {
			$aliases[] = $sender_entity_id;
			$aliases[] = sanitize_key('sender_' . $sender_entity_id);
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

	private function build_method_sender_identity_aliases($method, $warehouse_profiles) {
		$method = is_array($method) ? $method : array();
		$warehouse_profiles = is_array($warehouse_profiles) ? $warehouse_profiles : array();
		$method_aliases = $this->build_sender_identity_aliases(
			isset($method['sender_profile_id']) ? $method['sender_profile_id'] : '',
			isset($method['sender_id']) ? $method['sender_id'] : '',
			isset($method['sender_entity_id']) ? $method['sender_entity_id'] : ''
		);

		$method_alias_map = array_fill_keys($method_aliases, true);
		$profiles = isset($warehouse_profiles['profiles']) && is_array($warehouse_profiles['profiles']) ? $warehouse_profiles['profiles'] : array();
		foreach ($profiles as $profile) {
			if (!is_array($profile)) {
				continue;
			}
			$profile_aliases = $this->build_sender_identity_aliases(
				isset($profile['profile_id']) ? $profile['profile_id'] : '',
				isset($profile['sender_id']) ? $profile['sender_id'] : '',
				isset($profile['sender_entity_id']) ? $profile['sender_entity_id'] : ''
			);
			$profile_matches_method = false;
			foreach ($profile_aliases as $profile_alias) {
				if (isset($method_alias_map[$profile_alias])) {
					$profile_matches_method = true;
					break;
				}
			}
			if (!$profile_matches_method) {
				continue;
			}
			foreach ($profile_aliases as $profile_alias) {
				$method_alias_map[$profile_alias] = true;
			}
		}

		return array_keys($method_alias_map);
	}

	private function extract_legacy_method_key($method_key) {
		$method_key = sanitize_text_field((string) $method_key);
		if (strpos($method_key, '::') === false) {
			return $method_key;
		}
		$key_parts = explode('::', $method_key, 2);
		return isset($key_parts[1]) ? sanitize_text_field((string) $key_parts[1]) : '';
	}

	private function resolve_available_method_key($method_key, $available_map, $sender_alias_method_key_map, $legacy_method_default_key_map, $legacy_method_single_key_map) {
		$method_key = sanitize_text_field((string) $method_key);
		if ($method_key === '') {
			return '';
		}
		if (isset($available_map[$method_key])) {
			return $method_key;
		}
		if (isset($sender_alias_method_key_map[$method_key])) {
			return $sender_alias_method_key_map[$method_key];
		}

		$legacy_key = $this->extract_legacy_method_key($method_key);
		if ($legacy_key !== '' && isset($legacy_method_default_key_map[$legacy_key])) {
			return $legacy_method_default_key_map[$legacy_key];
		}
		if ($legacy_key !== '' && isset($legacy_method_single_key_map[$legacy_key])) {
			return $legacy_method_single_key_map[$legacy_key];
		}

		return '';
	}

	private function get_warehouse_profiles_defaults() {
		return array(
			'default_profile_id' => '',
			'profiles' => array(),
			'methods_by_profile' => array(),
		);
	}

	private function sanitize_warehouse_profiles_settings($input, $current, $fallback_sender_id = '') {
		$output = $this->get_warehouse_profiles_defaults();
		$profiles = isset($input['profiles']) && is_array($input['profiles']) ? $input['profiles'] : (isset($current['profiles']) && is_array($current['profiles']) ? $current['profiles'] : array());
		foreach ($profiles as $profile) {
			if (!is_array($profile)) { continue; }
			$profile_id = isset($profile['profile_id']) ? sanitize_key((string)$profile['profile_id']) : '';
			if ($profile_id==='') { $profile_id='wh_'.wp_generate_password(8,false,false); }
			$sender_id = isset($profile['sender_id']) ? sanitize_text_field((string)$profile['sender_id']) : '';
			if ($sender_id==='') { continue; }
			$output['profiles'][] = array(
				'profile_id'=>$profile_id,'name'=>isset($profile['name'])?sanitize_text_field((string)$profile['name']):$sender_id,'sender_id'=>$sender_id,
				'sender_entity_id'=>isset($profile['sender_entity_id'])?sanitize_text_field((string)$profile['sender_entity_id']):'',
				'company'=>isset($profile['company'])?sanitize_text_field((string)$profile['company']):'', 'address1'=>isset($profile['address1'])?sanitize_text_field((string)$profile['address1']):'',
				'address2'=>isset($profile['address2'])?sanitize_text_field((string)$profile['address2']):'', 'postcode'=>isset($profile['postcode'])?sanitize_text_field((string)$profile['postcode']):'',
				'city'=>isset($profile['city'])?sanitize_text_field((string)$profile['city']):'', 'country'=>isset($profile['country'])?sanitize_text_field((string)$profile['country']):'',
				'email'=>isset($profile['email'])?sanitize_email((string)$profile['email']):'', 'phone'=>isset($profile['phone'])?sanitize_text_field((string)$profile['phone']):'',
				'default_printer_id'=>isset($profile['default_printer_id'])?sanitize_text_field((string)$profile['default_printer_id']):'',
				'active'=>!empty($profile['active'])?1:0,'use_as_pickup_address'=>!empty($profile['use_as_pickup_address'])?1:0,'use_as_return_address'=>!empty($profile['use_as_return_address'])?1:0,
			);
		}
		$default_profile_id = isset($input['default_profile_id']) ? sanitize_key((string)$input['default_profile_id']) : (isset($current['default_profile_id']) ? sanitize_key((string)$current['default_profile_id']) : '');
		if ($default_profile_id!=='') { $output['default_profile_id']=$default_profile_id; }
		if (empty($output['profiles'])) {
			$legacy_sender = $fallback_sender_id !== '' ? $fallback_sender_id : (isset($current['sender_id']) ? sanitize_text_field((string)$current['sender_id']) : '');
			if ($legacy_sender!=='') {
				$output['profiles'][] = array('profile_id'=>'default_sender','name'=>'Default sender','sender_id'=>$legacy_sender,'sender_entity_id'=>'','company'=>'','address1'=>'','address2'=>'','postcode'=>'','city'=>'','country'=>'','email'=>'','phone'=>'','default_printer_id'=>'','active'=>1,'use_as_pickup_address'=>0,'use_as_return_address'=>0);
				$output['default_profile_id'] = 'default_sender';
			}
		}
		if ($output['default_profile_id']==='' && !empty($output['profiles'])) { $output['default_profile_id'] = $output['profiles'][0]['profile_id']; }
		if (isset($input['methods_by_profile']) && is_array($input['methods_by_profile'])) {
			foreach ($input['methods_by_profile'] as $pid => $keys) {
				$pid=sanitize_key((string)$pid); if($pid===''){continue;}
				$output['methods_by_profile'][$pid]=array_values(array_unique(array_filter(array_map('sanitize_text_field', is_array($keys)?$keys:array()))));
			}
		}
		return $output;
	}

}
