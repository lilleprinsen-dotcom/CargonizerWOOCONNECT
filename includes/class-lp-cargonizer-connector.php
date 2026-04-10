<?php

if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/trait-lp-cargonizer-admin-page.php';
require_once __DIR__ . '/trait-lp-cargonizer-ajax-controller.php';

class LP_Cargonizer_Connector {
	use LP_Cargonizer_Admin_Page_Trait;
	use LP_Cargonizer_Ajax_Controller_Trait;

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
	const NONCE_ACTION_PRINTERS = 'lp_cargonizer_get_printers';
	const NONCE_ACTION_ADMIN_SERVICEPOINT_DIAGNOSTIC = 'lp_cargonizer_admin_servicepoint_diagnostic';
	const NONCE_ACTION_BOOK = 'lp_cargonizer_book_shipment';
	/** @var LP_Cargonizer_Settings_Service */
	private $settings_service;
	/** @var LP_Cargonizer_Api_Service */
	private $api_service;
	/** @var LP_Cargonizer_Estimator_Service */
	private $estimator_service;
	/** @var LP_Cargonizer_Package_Resolution_Service */
	private $package_resolution_service;
	/** @var LP_Cargonizer_Shipping_Profile_Resolver */
	private $shipping_profile_resolver;
	/** @var LP_Cargonizer_Package_Builder */
	private $package_builder_service;
	/** @var LP_Cargonizer_Method_Rule_Engine */
	private $method_rule_engine_service;

	public function __construct() {
		$this->settings_service = new LP_Cargonizer_Settings_Service(self::OPTION_KEY, self::MANUAL_NORGESPAKKE_KEY);
		$this->api_service = new LP_Cargonizer_Api_Service(function () {
			return $this->get_settings();
		});
		$this->estimator_service = new LP_Cargonizer_Estimator_Service(array(
			'sanitize_price_source' => function ($value) {
				return $this->sanitize_price_source($value);
			},
			'sanitize_rounding_mode' => function ($value) {
				return $this->sanitize_rounding_mode($value);
			},
			'sanitize_discount_percent' => function ($value) {
				return $this->sanitize_discount_percent($value);
			},
			'sanitize_non_negative_number' => function ($value) {
				return $this->sanitize_non_negative_number($value);
			},
			'sanitize_checkbox_value' => function ($value) {
				return $this->sanitize_checkbox_value($value);
			},
			'run_consignment_estimate_for_packages' => function () {
				return call_user_func_array(array($this, 'run_consignment_estimate_for_packages'), func_get_args());
			},
		));
		$this->package_resolution_service = new LP_Cargonizer_Package_Resolution_Service(function () {
			return $this->get_settings();
		});
		$this->shipping_profile_resolver = new LP_Cargonizer_Shipping_Profile_Resolver(function () {
			return $this->get_settings();
		}, $this->package_resolution_service);
		$this->package_builder_service = new LP_Cargonizer_Package_Builder($this->shipping_profile_resolver, function () {
			return $this->get_settings();
		});
		$this->method_rule_engine_service = new LP_Cargonizer_Method_Rule_Engine(function () {
			return $this->get_settings();
		});
	}

	public function register_hooks() {
		add_action('admin_menu', array($this, 'add_admin_menu'), 99);
		add_action('admin_init', array($this, 'register_settings'));
		add_action('admin_enqueue_scripts', array($this, 'enqueue_estimate_modal_assets'));
		add_action('woocommerce_admin_order_data_after_order_details', array($this, 'render_order_estimate_button'));
		add_action('admin_footer', array($this, 'render_estimate_modal'));
		add_action('wp_ajax_lp_cargonizer_get_order_estimate_data', array($this, 'ajax_get_order_estimate_data'));
		add_action('wp_ajax_lp_cargonizer_get_shipping_options', array($this, 'ajax_get_shipping_options'));
		add_action('wp_ajax_lp_cargonizer_run_bulk_estimate', array($this, 'ajax_run_bulk_estimate'));
		add_action('wp_ajax_lp_cargonizer_run_bulk_estimate_baseline', array($this, 'ajax_run_bulk_estimate_baseline'));
		add_action('wp_ajax_lp_cargonizer_optimize_dsv_estimates', array($this, 'ajax_optimize_dsv_estimates'));
		add_action('wp_ajax_lp_cargonizer_get_servicepartner_options', array($this, 'ajax_get_servicepartner_options'));
		add_action('wp_ajax_lp_cargonizer_get_printers', array($this, 'ajax_get_printers'));
		add_action('wp_ajax_lp_cargonizer_book_shipment', array($this, 'ajax_book_shipment'));
		add_action('woocommerce_product_options_shipping', array($this, 'render_product_profile_override_fields'));
		add_action('woocommerce_process_product_meta', array($this, 'save_product_profile_override_fields'));
	}

	public function render_product_profile_override_fields() {
		if (!function_exists('woocommerce_wp_text_input') || !function_exists('woocommerce_wp_select')) {
			return;
		}

		global $post;
		$product_id = isset($post->ID) ? (int) $post->ID : 0;
		if ($product_id < 1) {
			return;
		}

		$settings = $this->get_settings();
		$profiles = isset($settings['shipping_profiles']['profiles']) && is_array($settings['shipping_profiles']['profiles'])
			? $settings['shipping_profiles']['profiles']
			: array();
		$options = array('' => __('Use resolver/default', 'lp-cargonizer'));
		foreach ($profiles as $profile) {
			if (!is_array($profile)) {
				continue;
			}
			$slug = isset($profile['slug']) ? sanitize_key((string) $profile['slug']) : '';
			if ($slug === '') {
				continue;
			}
			$label = isset($profile['label']) ? sanitize_text_field((string) $profile['label']) : $slug;
			$options[$slug] = $label . ' (' . $slug . ')';
		}

		echo '<div class="options_group">';
		echo '<p><strong>' . esc_html__('Cargonizer profile overrides', 'lp-cargonizer') . '</strong></p>';
		woocommerce_wp_select(array(
			'id' => '_lp_cargonizer_profile_slug',
			'label' => __('Profile override', 'lp-cargonizer'),
			'desc_tip' => true,
			'description' => __('Optional. Overrides the resolver-selected profile for this product.', 'lp-cargonizer'),
			'options' => $options,
			'value' => get_post_meta($product_id, '_lp_cargonizer_profile_slug', true),
		));
		foreach (array(
			'_lp_cargonizer_profile_weight' => __('Profile weight override (kg)', 'lp-cargonizer'),
			'_lp_cargonizer_profile_length' => __('Profile length override (cm)', 'lp-cargonizer'),
			'_lp_cargonizer_profile_width' => __('Profile width override (cm)', 'lp-cargonizer'),
			'_lp_cargonizer_profile_height' => __('Profile height override (cm)', 'lp-cargonizer'),
		) as $meta_key => $label) {
			woocommerce_wp_text_input(array(
				'id' => $meta_key,
				'label' => $label,
				'desc_tip' => true,
				'description' => __('Optional. Leave empty to use profile/default fallback.', 'lp-cargonizer'),
				'type' => 'text',
				'value' => get_post_meta($product_id, $meta_key, true),
			));
		}
		echo '</div>';
	}

	public function save_product_profile_override_fields($product_id) {
		if (!current_user_can('edit_post', $product_id)) {
			return;
		}

		$profile_slug = isset($_POST['_lp_cargonizer_profile_slug']) ? sanitize_key(wp_unslash($_POST['_lp_cargonizer_profile_slug'])) : '';
		if ($profile_slug === '') {
			delete_post_meta($product_id, '_lp_cargonizer_profile_slug');
		} else {
			update_post_meta($product_id, '_lp_cargonizer_profile_slug', $profile_slug);
		}

		foreach (array(
			'_lp_cargonizer_profile_weight',
			'_lp_cargonizer_profile_length',
			'_lp_cargonizer_profile_width',
			'_lp_cargonizer_profile_height',
		) as $meta_key) {
			$raw = isset($_POST[$meta_key]) ? wp_unslash($_POST[$meta_key]) : '';
			$clean = $this->sanitize_non_negative_number($raw);
			if ($clean > 0) {
				update_post_meta($product_id, $meta_key, $clean);
			} else {
				delete_post_meta($product_id, $meta_key);
			}
		}
	}

	public function sanitize_settings($input) {
		return $this->settings_service->sanitize_settings($input);
	}

	private function sanitize_discount_percent($value) {
		return $this->settings_service->sanitize_discount_percent($value);
	}

	private function sanitize_non_negative_number($value) {
		return $this->settings_service->sanitize_non_negative_number($value);
	}

	private function sanitize_checkbox_value($value) {
		return $this->settings_service->sanitize_checkbox_value($value);
	}

	private function sanitize_adjustment_type($value) {
		$type = sanitize_text_field((string) $value);
		return $type === 'percent' ? 'percent' : 'fixed';
	}

	private function sanitize_price_source($value) {
		return $this->settings_service->sanitize_price_source($value);
	}

	private function sanitize_rounding_mode($value) {
		return $this->settings_service->sanitize_rounding_mode($value);
	}

	private function get_settings() {
		return $this->settings_service->get_settings();
	}

	private function get_auth_headers() {
		return $this->api_service->get_auth_headers();
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
		return $this->api_service->fetch_transport_agreements();
	}

	private function fetch_printers() {
		return $this->api_service->fetch_printers();
	}

	private function parse_printers_response($body) {
		return $this->api_service->parse_printers_response($body);
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
		return $this->settings_service->get_manual_norgespakke_method();
	}

	private function ensure_internal_manual_methods($options) {
		return $this->settings_service->ensure_internal_manual_methods($options);
	}

	private function is_manual_norgespakke_method($method_payload) {
		return $this->settings_service->is_manual_norgespakke_method($method_payload);
	}

	private function get_enabled_method_map() {
		return $this->settings_service->get_enabled_method_map();
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
		return $this->settings_service->get_enabled_method_discounts();
	}

	private function get_default_method_pricing() {
		return $this->settings_service->get_default_method_pricing();
	}

	private function get_enabled_method_pricing() {
		return $this->settings_service->get_enabled_method_pricing();
	}


	private function apply_rounding_mode($value, $mode) {
		return $this->estimator_service->apply_rounding_mode($value, $mode);
	}

	private function round_up_to_price_ending_9($value) {
		return $this->estimator_service->round_up_to_price_ending_9($value);
	}

	private function calculate_adjustment_amount($base_price, $type, $value, $max_value = null) {
		return $this->estimator_service->calculate_adjustment_amount($base_price, $type, $value, $max_value);
	}

	private function parse_price_to_number($price_value) {
		return $this->estimator_service->parse_price_to_number($price_value);
	}

	private function parse_transport_agreements($xml) {
		return $this->api_service->parse_transport_agreements($xml);
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
		return $this->api_service->xml_value($node, $possible_keys);
	}

	private function build_estimate_request_xml($payload, $method) {
		return $this->api_service->build_estimate_request_xml($payload, $method);
	}

	private function build_booking_consignment_xml($payload, $method, $options = array()) {
		return $this->api_service->build_booking_consignment_xml($payload, $method, $options);
	}

	private function get_last_xml_build_error() {
		return $this->api_service->get_last_xml_build_error();
	}

	private function create_booking_consignment($xml) {
		return $this->api_service->create_booking_consignment($xml);
	}

	private function fetch_authenticated_binary_url($url) {
		return $this->api_service->fetch_authenticated_binary_url($url);
	}

	private function print_pdf_to_printer($printer_id, $pdf_binary) {
		return $this->api_service->print_pdf_to_printer($printer_id, $pdf_binary);
	}

	private function normalize_positive_decimal_for_xml($value) {
		return $this->api_service->normalize_positive_decimal_for_xml($value);
	}

	private function log_estimate_package_dimensions($data) {
		$this->api_service->log_estimate_package_dimensions($data);
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
		return $this->api_service->sanitize_country_code($value);
	}

	private function sanitize_postcode($value) {
		return $this->api_service->sanitize_postcode($value);
	}

	private function detect_servicepartner_custom_params($method) {
		return $this->api_service->detect_servicepartner_custom_params($method);
	}

	private function is_method_explicitly_pickup_point($method) {
		return $this->api_service->is_method_explicitly_pickup_point($method);
	}

	private function is_method_explicitly_home_delivery($method) {
		return $this->api_service->is_method_explicitly_home_delivery($method);
	}

	private function method_requires_servicepartner_for_estimate($method) {
		return $this->api_service->method_requires_servicepartner_for_estimate($method);
	}

	private function resolve_default_servicepartner_selection($method_payload, $recipient) {
		return $this->api_service->resolve_default_servicepartner_selection($method_payload, $recipient);
	}


	private function fetch_servicepartner_options($method) {
		return $this->api_service->fetch_servicepartner_options($method);
	}

	private function parse_response_error_details($body) {
		return $this->api_service->parse_response_error_details($body);
	}



	private function parse_response_error_message($body) {
		return $this->api_service->parse_response_error_message($body);
	}

	private function parse_estimate_price_fields($body) {
		return $this->api_service->parse_estimate_price_fields($body);
	}

	private function select_estimate_price_value($price_fields, $configured_source = 'estimated') {
		return $this->estimator_service->select_estimate_price_value($price_fields, $configured_source);
	}

	private function get_price_source_priority($configured_source = 'estimated') {
		return $this->estimator_service->get_price_source_priority($configured_source);
	}

	private function is_bring_method($method_payload) {
		return $this->estimator_service->is_bring_method($method_payload);
	}

	private function is_dsv_method($method_payload) {
		return $this->estimator_service->is_dsv_method($method_payload);
	}

	private function generate_package_index_partitions($package_indexes) {
		return $this->estimator_service->generate_package_index_partitions($package_indexes);
	}

	private function build_package_index_partitions_recursive($indexes, $position, $current_partition, &$all_partitions) {
		$this->estimator_service->build_package_index_partitions_recursive($indexes, $position, $current_partition, $all_partitions);
	}

	private function normalize_package_partition($partition) {
		return $this->estimator_service->normalize_package_partition($partition);
	}

	private function package_triggers_bring_manual_handling($package) {
		return $this->package_triggers_manual_handling($package);
	}


	private function package_triggers_manual_handling($package) {
		return $this->estimator_service->package_triggers_manual_handling($package);
	}

	private function get_bring_manual_handling_fee($packages, $method_payload) {
		return $this->estimator_service->get_bring_manual_handling_fee($packages, $method_payload);
	}


	private function calculate_norgespakke_estimate($packages, $method_payload, $pricing_config) {
		return $this->estimator_service->calculate_norgespakke_estimate($packages, $method_payload, $pricing_config);
	}

	private function calculate_estimate_from_price_source($selected_price, $pricing_config) {
		return $this->estimator_service->calculate_estimate_from_price_source($selected_price, $pricing_config);
	}


	private function build_packages_summary($packages) {
		return $this->estimator_service->build_packages_summary($packages);
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
			'selected_service_ids' => isset($method_payload['selected_service_ids']) && is_array($method_payload['selected_service_ids']) ? $method_payload['selected_service_ids'] : array(),
		), $method_payload);
		if ($xml === '') {
			$xml_build_error = $this->get_last_xml_build_error();
			$result['error'] = isset($xml_build_error['message']) && $xml_build_error['message'] !== '' ? (string) $xml_build_error['message'] : 'Kunne ikke bygge estimate-XML.';
			$result['parsed_error_message'] = $result['error'];
			$result['error_details'] = isset($xml_build_error['context']) && is_array($xml_build_error['context']) ? wp_json_encode($xml_build_error['context']) : '';
			return $result;
		}

		$response = wp_remote_post(LP_Cargonizer_Api_Service::build_endpoint_url('/consignment_costs.xml'), array(
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
		return $this->estimator_service->evaluate_dsv_partition($partition, $all_packages, $recipient, $method_payload, $pricing_config, $partition_index);
	}

	private function build_dsv_baseline_variant($estimate_result, $packages) {
		return $this->estimator_service->build_dsv_baseline_variant($estimate_result, $packages);
	}

	private function compare_dsv_variants($left, $right) {
		return $this->estimator_service->compare_dsv_variants($left, $right);
	}

	private function optimize_dsv_partition_estimates($packages, $recipient, $method_payload, $pricing_config, $baseline_estimate_result) {
		return $this->estimator_service->optimize_dsv_partition_estimates($packages, $recipient, $method_payload, $pricing_config, $baseline_estimate_result);
	}

	private function parse_estimated_price($body) {
		$price_fields = $this->parse_estimate_price_fields($body);
		$selected = $this->select_estimate_price_value($price_fields);
		return $selected['value'];
	}

	private function build_reusable_packages_from_order($order) {
		return $this->package_builder_service->build_from_order($order);
	}

	private function build_reusable_packages_from_cart($cart) {
		return $this->package_builder_service->build_from_cart($cart);
	}

	private function evaluate_reusable_method_eligibility($methods, $package_result, $order_value) {
		return $this->method_rule_engine_service->evaluate_methods($methods, $package_result, $order_value);
	}

}
