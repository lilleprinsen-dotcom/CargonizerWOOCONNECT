<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Live_Shipping_Method extends WC_Shipping_Method {
	const METHOD_ID = LP_Cargonizer_Live_Checkout::METHOD_ID;
	const LAST_NO_RATES_STATUS_TRANSIENT = 'lp_cargonizer_last_no_rates_status';

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
		$this->package_builder = new LP_Cargonizer_Package_Builder($shipping_profile_resolver, function () {
			return $this->settings_service->get_settings();
		});
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
		try {
			if ($this->enabled !== 'yes') {
				return;
			}

			$settings = $this->settings_service->get_settings();
			$live_settings = isset($settings['live_checkout']) && is_array($settings['live_checkout']) ? $settings['live_checkout'] : array();
			if (empty($live_settings['enabled'])) {
				return;
			}

			$request_context = $this->resolve_live_quote_request_context($live_settings);
			if (empty($request_context['allow_remote_quotes'])) {
				if (!empty($request_context['use_cart_placeholder_rate'])) {
					$this->add_cart_placeholder_rate();
				}
				return;
			}

			$destination = isset($package['destination']) && is_array($package['destination']) ? $package['destination'] : array();
			$destination_context = $this->build_destination_debug_context($destination);
			$country = strtoupper(isset($destination['country']) ? (string) $destination['country'] : '');
			if (!empty($live_settings['norway_only_enabled']) && $country !== 'NO') {
				return;
			}
			if (!$this->has_minimum_destination_for_quotes($destination)) {
				$this->record_last_no_rates_status('destination_incomplete', 'destination', 'Destination is incomplete for live quotes.', array(
					'destination' => $destination_context,
					'request_context' => $request_context,
				));
				$this->add_checkout_destination_incomplete_notice($destination);
				return;
			}
			$this->clear_last_no_rates_status();

			$auth_headers = $this->api_service->get_auth_headers();
			$api_key = isset($auth_headers['X-Cargonizer-Key']) ? trim((string) $auth_headers['X-Cargonizer-Key']) : '';
			$sender_id = isset($auth_headers['X-Cargonizer-Sender']) ? trim((string) $auth_headers['X-Cargonizer-Sender']) : '';
			if ($api_key === '' || $sender_id === '') {
				$fallback_behavior = $this->resolve_quote_fallback_behavior($settings, $live_settings);
				$allow_checkout_with_fallback = $this->should_allow_checkout_with_fallback($settings);
				$fallback_result = $this->add_fallback_rates_if_needed($settings, $live_settings, $fallback_behavior, $allow_checkout_with_fallback);
				$this->record_last_no_rates_status('auth_or_config_problem', 'configuration', 'Missing API key or sender ID for live checkout requests.', array(
					'request_context' => $request_context,
					'destination' => $destination_context,
					'missing_api_key' => $api_key === '',
					'missing_sender_id' => $sender_id === '',
					'fallback' => $fallback_result,
				));
				return;
			}

			$methods = $this->get_enabled_live_methods($settings);
			if (empty($methods)) {
				$this->record_last_no_rates_status('auth_or_config_problem', 'configuration', 'No enabled live methods are available for quoting.', array(
					'destination' => $destination_context,
					'request_context' => $request_context,
				));
				return;
			}

			$package_result = $this->build_package_result($package);
			$order_value = $this->get_order_value($package, $live_settings);
			$eligibility = $this->method_rule_engine->evaluate_methods($methods, $package_result, $order_value);
			$candidates = isset($eligibility['eligible_methods']) ? $eligibility['eligible_methods'] : array();
			$candidate_count = count($candidates);
			if ($candidate_count < 1) {
				$this->record_last_no_rates_status('rules_filtered_all', 'rules', 'Method rules filtered out all candidate methods.', array(
					'destination' => $destination_context,
					'candidate_count' => 0,
					'enabled_method_count' => count($methods),
					'rule_context' => isset($eligibility['context']) ? $eligibility['context'] : array(),
				));
				return;
			}
			$pruned = $this->prune_quote_candidates_for_package_capabilities($candidates, $package_result);
			$candidates = isset($pruned['candidates']) ? $pruned['candidates'] : array();
			$pruned_count = isset($pruned['pruned_count']) ? (int) $pruned['pruned_count'] : 0;
			if (empty($candidates)) {
				$this->record_last_no_rates_status('rules_filtered_all', 'rules', 'All candidate methods were pruned due to package capability constraints.', array(
					'destination' => $destination_context,
					'candidate_count' => $candidate_count,
					'pruned_count' => $pruned_count,
				));
				$this->log_live_checkout_event('debug', 'No live checkout quote candidates remained after local pruning.', array(
					'request_context' => $request_context,
					'destination' => $destination_context,
					'considered_count' => $candidate_count,
					'rule_eligible_count' => $candidate_count,
					'pruned_count' => $pruned_count,
					'quoted_count' => 0,
					'returned_count' => 0,
				));
				return;
			}

			$fallback_behavior = $this->resolve_quote_fallback_behavior($settings, $live_settings);
			$allow_checkout_with_fallback = $this->should_allow_checkout_with_fallback($settings);
			$quote_result = $this->collect_method_quotes($candidates, $package_result, $destination, $settings, $live_settings, $fallback_behavior);
			$quotes = isset($quote_result['quotes']) && is_array($quote_result['quotes']) ? $quote_result['quotes'] : array();
			$quote_diagnostics = isset($quote_result['diagnostics']) && is_array($quote_result['diagnostics']) ? $quote_result['diagnostics'] : array();
			$quoted_count = count($candidates);
			$successful_quote_count = count($quotes);
			if (empty($quotes)) {
				$fallback_result = $this->add_fallback_rates_if_needed($settings, $live_settings, $fallback_behavior, $allow_checkout_with_fallback);
				$reason_code = !empty($quote_diagnostics['auth_failure_detected']) ? 'auth_or_config_problem' : 'quote_api_failure';
				$reason_group = $reason_code === 'auth_or_config_problem' ? 'configuration' : 'api';
				$this->record_last_no_rates_status($reason_code, $reason_group, 'Live quote requests completed without usable rates.', array(
					'request_context' => $request_context,
					'destination' => $destination_context,
					'candidate_count' => $candidate_count,
					'quoted_count' => $quoted_count,
					'successful_quote_count' => 0,
					'quote_failures' => isset($quote_diagnostics['failures']) ? $quote_diagnostics['failures'] : array(),
					'fallback' => $fallback_result,
				));
				$this->log_live_checkout_event('debug', 'Live checkout quoting completed with no usable quotes.', array(
					'request_context' => $request_context,
					'destination' => $destination_context,
					'considered_count' => $candidate_count,
					'rule_eligible_count' => $candidate_count,
					'pruned_count' => $pruned_count,
					'quoted_count' => $quoted_count,
					'successful_quote_count' => $successful_quote_count,
					'returned_count' => 0,
					'quote_failures' => isset($quote_diagnostics['failures']) ? $quote_diagnostics['failures'] : array(),
					'fallback_mode' => $fallback_behavior,
				));
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

			$added_rates = 0;
			$pickup_required_pending_async_points = 0;
			foreach ($quotes as $quote) {
				$rate_id = $this->build_checkout_rate_id($quote);
				$meta_data = array(
					'transport_agreement_id' => $quote['agreement_id'],
					'carrier_id' => $quote['carrier_id'],
					'product_id' => $quote['product_id'],
					'method_key' => $quote['method_key'],
				);

				if (!empty($quote['pickup_capable'])) {
					$meta_data['lp_cargonizer_pickup_capable'] = 1;
					$meta_data['lp_cargonizer_pickup_rate_context'] = array(
						'transport_agreement_id' => $quote['agreement_id'],
						'carrier_id' => $quote['carrier_id'],
						'product_id' => $quote['product_id'],
						'method_key' => $quote['method_key'],
					);
				}

				if (!empty($quote['pickup_required'])) {
					$pickup_points = $this->get_cached_pickup_points_for_rate($quote, $destination, $live_settings);
					if (empty($pickup_points)) {
						$pickup_required_pending_async_points++;
					} else {
						$selected_pickup = $this->resolve_selected_pickup_point($rate_id, $pickup_points);
						$selected_pickup_point = isset($selected_pickup['point']) && is_array($selected_pickup['point']) ? $selected_pickup['point'] : array();
						$meta_data['krokedil_pickup_points'] = LP_Cargonizer_Krokedil_Pickup_Meta_Helper::encode_pickup_points_for_meta($pickup_points);
						$meta_data['krokedil_selected_pickup_point'] = LP_Cargonizer_Krokedil_Pickup_Meta_Helper::encode_pickup_point_for_meta($selected_pickup_point);
						$meta_data['krokedil_selected_pickup_point_id'] = isset($selected_pickup['id']) ? (string) $selected_pickup['id'] : '';
					}
				}

				$this->add_rate(array(
					'id' => $rate_id,
					'label' => $quote['label'],
					'cost' => $quote['display_cost'],
					'meta_data' => $meta_data,
				));
				$added_rates++;
			}
			$this->log_live_checkout_event('debug', 'Calculated live shipping package rates.', array(
				'request_context' => $request_context,
				'destination' => $destination_context,
				'considered_count' => $candidate_count,
				'rule_eligible_count' => $candidate_count,
				'pruned_count' => $pruned_count,
				'quoted_count' => $quoted_count,
				'successful_quote_count' => $successful_quote_count,
				'pickup_required_pending_async_points' => $pickup_required_pending_async_points,
				'returned_count' => $added_rates,
				'fallback_mode' => $fallback_behavior,
			));

			if ($added_rates < 1) {
				$fallback_result = $this->add_fallback_rates_if_needed($settings, $live_settings, $fallback_behavior, $allow_checkout_with_fallback);
				$reason_code = 'quote_api_failure';
				$reason_group = 'api';
				$this->record_last_no_rates_status($reason_code, $reason_group, 'No checkout rates were added after quote processing.', array(
					'request_context' => $request_context,
					'destination' => $destination_context,
					'candidate_count' => $candidate_count,
					'quoted_count' => $quoted_count,
					'successful_quote_count' => $successful_quote_count,
					'pickup_required_pending_async_points' => $pickup_required_pending_async_points,
					'fallback' => $fallback_result,
				));
				return;
			}
			$this->clear_last_no_rates_status();
		} catch (Throwable $throwable) {
			$this->log_live_checkout_event('error', 'Live shipping calculation failed unexpectedly.', array(
				'error' => $throwable->getMessage(),
			));
			return;
		}
	}

	private function add_cart_placeholder_rate() {
		$label = __('Frakt beregnes i kassen', 'lp-cargonizer');
		$this->add_rate(array(
			'id' => $this->id . ':' . $this->instance_id . ':cart_placeholder',
			'label' => $label,
			'cost' => 0,
			'meta_data' => array(
				'lp_cargonizer_cart_placeholder' => 1,
			),
		));
	}

	private function collect_method_quotes($candidates, $package_result, $destination, $settings, $live_settings, $fallback_behavior) {
		$quotes = array();
		$diagnostics = array(
			'failures' => array(),
			'auth_failure_detected' => false,
		);
		$contexts = array();
		$uncached_contexts = array();
		$cached_quotes = array();

		foreach ($candidates as $index => $method) {
			$context = $this->prepare_quote_request_context($method, $package_result, $destination, $settings, $live_settings, $fallback_behavior, $index);
			$contexts[] = $context;
			if (!empty($context['error_result'])) {
				$failure_entry = $this->build_quote_failure_entry($context['error_result'], $method);
				if ($this->is_auth_related_quote_failure($failure_entry)) {
					$diagnostics['auth_failure_detected'] = true;
				}
				$diagnostics['failures'][] = $failure_entry;
				continue;
			}
			if (!empty($context['cached_quote'])) {
				$cached_quotes[$index] = $context['cached_quote'];
				continue;
			}
			$uncached_contexts[] = $context;
		}

		$remote_results = array();
		if (!empty($uncached_contexts)) {
			$remote_results = $this->execute_uncached_quote_requests($uncached_contexts, $live_settings);
		}

		foreach ($contexts as $context) {
			$index = isset($context['quote_index']) ? (int) $context['quote_index'] : 0;
			if (!empty($context['error_result'])) {
				continue;
			}
			if (isset($cached_quotes[$index]) && is_array($cached_quotes[$index])) {
				$quotes[] = $cached_quotes[$index];
				continue;
			}
			$remote_result = isset($remote_results[$index]) && is_array($remote_results[$index]) ? $remote_results[$index] : array(
				'type' => 'wp_remote',
				'wp_error' => 'Missing remote quote result.',
			);
			$quote = $this->build_quote_from_remote_result($context, $remote_result);
			if (!empty($quote['success'])) {
				$quotes[] = $quote;
				continue;
			}
			$failure_entry = $this->build_quote_failure_entry($quote, isset($context['method']) ? $context['method'] : array());
			if ($this->is_auth_related_quote_failure($failure_entry)) {
				$diagnostics['auth_failure_detected'] = true;
			}
			$diagnostics['failures'][] = $failure_entry;
		}

		return array(
			'quotes' => $quotes,
			'diagnostics' => $diagnostics,
		);
	}

	private function prepare_quote_request_context($method, $package_result, $destination, $settings, $live_settings, $fallback_behavior, $quote_index) {
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
		$package_summary = isset($package_result['summary']) && is_array($package_result['summary']) ? $package_result['summary'] : array();
		if (empty($recipient['postcode']) || $recipient['country'] === '') {
			return array(
				'quote_index' => (int) $quote_index,
				'method' => $method,
				'error_result' => array(
					'success' => false,
					'method_key' => $method_key,
					'reason_code' => 'destination_incomplete',
				),
			);
		}

		$cache_ttl = isset($live_settings['quote_cache_ttl_seconds']) ? max(0, (int) $live_settings['quote_cache_ttl_seconds']) : 0;
		$cache_key = 'lp_carg_quote_' . md5(wp_json_encode(array(
			'method_key' => $method_key,
			'agreement_id' => isset($method['agreement_id']) ? (string) $method['agreement_id'] : '',
			'carrier_id' => isset($method['carrier_id']) ? (string) $method['carrier_id'] : '',
			'product_id' => isset($method['product_id']) ? (string) $method['product_id'] : '',
			'recipient' => $recipient,
			'packages' => $packages,
			'package_summary' => $package_summary,
			'method_pricing' => $method_pricing,
			'method_context' => array(
				'delivery_to_pickup_point' => !empty($method['delivery_to_pickup_point']) ? 1 : 0,
				'delivery_to_home' => !empty($method['delivery_to_home']) ? 1 : 0,
				'mailbox_like' => $this->is_method_mailbox_like($method) ? 1 : 0,
				'pickup_like' => $this->api_service->is_method_explicitly_pickup_point($method) ? 1 : 0,
			),
			'pricing_context' => array(
				'show_prices_including_vat' => !empty($live_settings['show_prices_including_vat']),
				'quote_timeout_seconds' => $this->resolve_frontend_quote_timeout($live_settings),
				'quote_fallback_behavior' => (string) $fallback_behavior,
			),
		)));
		$cached_quote = null;
		if ($cache_ttl > 0) {
			$cached = get_transient($cache_key);
			if (is_array($cached) && !empty($cached['success'])) {
				$cached_quote = $cached;
			}
		}

		$xml = '';
		if ($cached_quote === null) {
			$xml = $this->api_service->build_estimate_request_xml(array(
				'recipient' => $recipient,
				'packages' => $packages,
				'selected_service_ids' => array(),
			), $method);
			if ($xml === '') {
				return array(
					'quote_index' => (int) $quote_index,
					'method' => $method,
					'error_result' => array(
						'success' => false,
						'method_key' => $method_key,
						'reason_code' => 'quote_api_failure',
					),
				);
			}
		}

		return array(
			'quote_index' => (int) $quote_index,
			'method' => $method,
			'method_key' => $method_key,
			'method_pricing' => $method_pricing,
			'packages' => $packages,
			'package_summary' => $package_summary,
			'cache_ttl' => $cache_ttl,
			'cache_key' => $cache_key,
			'fallback_behavior' => $fallback_behavior,
			'settings' => $settings,
			'live_settings' => $live_settings,
			'cached_quote' => $cached_quote,
			'request_xml' => $xml,
		);
	}

	private function execute_uncached_quote_requests($contexts, $live_settings) {
		$results = array();
		$candidate_count = is_array($contexts) ? count($contexts) : 0;
		$parallelism = (int) apply_filters('lp_cargonizer_live_quote_parallelism', 4, $candidate_count, $live_settings);
		$parallelism = max(1, $parallelism);
		$can_use_parallel = $this->can_use_parallel_quote_execution() && $candidate_count > 1;
		$mode = ($can_use_parallel && $parallelism > 1) ? 'parallel' : 'sequential';
		$this->log_live_checkout_event('debug', 'Live quote collection executor mode selected.', array(
			'mode' => $mode,
			'uncached_method_count' => $candidate_count,
			'parallelism' => $parallelism,
		));

		if ($mode === 'parallel') {
			return $this->execute_uncached_quote_requests_parallel($contexts, $live_settings, $parallelism);
		}
		foreach ($contexts as $context) {
			$index = isset($context['quote_index']) ? (int) $context['quote_index'] : 0;
			$results[$index] = $this->execute_single_uncached_quote_request($context, $live_settings);
		}
		return $results;
	}

	private function can_use_parallel_quote_execution() {
		return function_exists('curl_init') && function_exists('curl_setopt') && function_exists('curl_exec') && function_exists('curl_multi_init') && function_exists('curl_multi_exec') && function_exists('curl_multi_select');
	}

	private function execute_uncached_quote_requests_parallel($contexts, $live_settings, $parallelism) {
		$results = array();
		$chunks = array_chunk($contexts, max(1, (int) $parallelism));
		$timeout = $this->resolve_frontend_quote_timeout($live_settings);
		$timeout_seconds = max(1, (int) ceil($timeout));
		foreach ($chunks as $chunk) {
			$multi = curl_multi_init();
			$handles = array();
			foreach ($chunk as $context) {
				$index = isset($context['quote_index']) ? (int) $context['quote_index'] : 0;
				$request_xml = isset($context['request_xml']) ? (string) $context['request_xml'] : '';
				$headers = array_merge($this->api_service->get_auth_headers(), array(
					'Accept' => 'application/xml',
					'Content-Type' => 'application/xml',
				));
				$header_lines = array();
				foreach ($headers as $header_name => $header_value) {
					$header_lines[] = $header_name . ': ' . $header_value;
				}
				$ch = curl_init();
				curl_setopt($ch, CURLOPT_URL, LP_Cargonizer_Api_Service::build_endpoint_url('/consignment_costs.xml'));
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($ch, CURLOPT_HTTPHEADER, $header_lines);
				curl_setopt($ch, CURLOPT_POSTFIELDS, $request_xml);
				curl_setopt($ch, CURLOPT_TIMEOUT, $timeout_seconds);
				curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout_seconds);
				curl_multi_add_handle($multi, $ch);
				$handles[$index] = $ch;
			}

			$running = null;
			do {
				$multi_status = curl_multi_exec($multi, $running);
				if ($running > 0 && $multi_status === CURLM_OK) {
					curl_multi_select($multi, 1.0);
				}
			} while ($running > 0 && $multi_status === CURLM_OK);

			foreach ($handles as $index => $handle) {
				$body = curl_multi_getcontent($handle);
				$error_message = curl_error($handle);
				$http_status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
				if ($error_message !== '') {
					$results[$index] = array(
						'type' => 'curl_multi',
						'wp_error' => $error_message,
					);
				} else {
					$results[$index] = array(
						'type' => 'curl_multi',
						'http_status' => $http_status,
						'body' => is_string($body) ? $body : '',
					);
				}
				curl_multi_remove_handle($multi, $handle);
				curl_close($handle);
			}
			curl_multi_close($multi);
		}
		return $results;
	}

	private function execute_single_uncached_quote_request($context, $live_settings) {
		$timeout = $this->resolve_frontend_quote_timeout($live_settings);
		$response = wp_remote_post(LP_Cargonizer_Api_Service::build_endpoint_url('/consignment_costs.xml'), array(
			'timeout' => $timeout,
			'headers' => array_merge($this->api_service->get_auth_headers(), array(
				'Accept' => 'application/xml',
				'Content-Type' => 'application/xml',
			)),
			'body' => isset($context['request_xml']) ? (string) $context['request_xml'] : '',
		));
		if (is_wp_error($response)) {
			return array(
				'type' => 'wp_remote',
				'wp_error' => $response->get_error_message(),
			);
		}
		return array(
			'type' => 'wp_remote',
			'http_status' => (int) wp_remote_retrieve_response_code($response),
			'body' => (string) wp_remote_retrieve_body($response),
		);
	}

	private function build_quote_from_remote_result($context, $remote_result) {
		$method = isset($context['method']) && is_array($context['method']) ? $context['method'] : array();
		$method_key = isset($context['method_key']) ? (string) $context['method_key'] : '';
		$fallback_behavior = isset($context['fallback_behavior']) ? (string) $context['fallback_behavior'] : 'none';
		$cache_key = isset($context['cache_key']) ? (string) $context['cache_key'] : '';
		$packages = isset($context['packages']) && is_array($context['packages']) ? $context['packages'] : array();
		$package_summary = isset($context['package_summary']) && is_array($context['package_summary']) ? $context['package_summary'] : array();
		$method_pricing = isset($context['method_pricing']) && is_array($context['method_pricing']) ? $context['method_pricing'] : array();
		$cache_ttl = isset($context['cache_ttl']) ? max(0, (int) $context['cache_ttl']) : 0;
		$settings = isset($context['settings']) && is_array($context['settings']) ? $context['settings'] : array();
		$live_settings = isset($context['live_settings']) && is_array($context['live_settings']) ? $context['live_settings'] : array();

		if (!empty($remote_result['wp_error'])) {
			$this->log_live_checkout_event('warning', 'Live quote request failed.', array(
				'method_key' => $method_key,
				'error' => (string) $remote_result['wp_error'],
			));
			$fallback_quote = $this->maybe_return_last_known_quote($cache_key, $fallback_behavior);
			if (!empty($fallback_quote['success'])) {
				return $fallback_quote;
			}
			return array(
				'success' => false,
				'method_key' => $method_key,
				'reason_code' => 'quote_api_failure',
				'error' => (string) $remote_result['wp_error'],
			);
		}

		$status = isset($remote_result['http_status']) ? (int) $remote_result['http_status'] : 0;
		$body = isset($remote_result['body']) ? (string) $remote_result['body'] : '';
		if ($status < 200 || $status >= 300 || $body === '') {
			$this->log_live_checkout_event('warning', 'Live quote response was not successful.', array(
				'method_key' => $method_key,
				'http_status' => $status,
				'body_empty' => $body === '',
			));
			$fallback_quote = $this->maybe_return_last_known_quote($cache_key, $fallback_behavior);
			if (!empty($fallback_quote['success'])) {
				return $fallback_quote;
			}
			return array(
				'success' => false,
				'method_key' => $method_key,
				'reason_code' => ($status === 401 || $status === 403) ? 'auth_or_config_problem' : 'quote_api_failure',
				'http_status' => $status,
			);
		}

		$price_fields = $this->api_service->parse_estimate_price_fields($body);
		$selected = $this->estimator_service->select_estimate_price_value($price_fields, isset($method_pricing['price_source']) ? $method_pricing['price_source'] : 'estimated');
		$live_price = $this->estimator_service->parse_price_to_number(isset($selected['value']) ? $selected['value'] : '');
		if ($live_price === null) {
			$fallback_quote = $this->maybe_return_last_known_quote($cache_key, $fallback_behavior);
			if (!empty($fallback_quote['success'])) {
				return $fallback_quote;
			}
			return array(
				'success' => false,
				'method_key' => $method_key,
				'reason_code' => 'quote_api_failure',
			);
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
			$fallback_quote = $this->maybe_return_last_known_quote($cache_key, $fallback_behavior);
			if (!empty($fallback_quote['success'])) {
				return $fallback_quote;
			}
			return array(
				'success' => false,
				'method_key' => $method_key,
				'reason_code' => 'quote_api_failure',
			);
		}

		$customer_visible_cost = !empty($live_settings['show_prices_including_vat'])
			? (float) $calc['rounded_price']
			: (float) $calc['final_price_ex_vat'];
		$display_cost = $this->convert_customer_visible_amount_to_rate_cost($customer_visible_cost, $live_settings);
		$customer_title = $this->resolve_customer_title($method, $settings);
		$quote = array(
			'success' => true,
			'method_key' => $method_key,
			'agreement_id' => isset($method['agreement_id']) ? (string) $method['agreement_id'] : '',
			'carrier_id' => isset($method['carrier_id']) ? (string) $method['carrier_id'] : '',
			'product_id' => isset($method['product_id']) ? (string) $method['product_id'] : '',
			'label' => $customer_title,
			'live_price' => (float) $live_price,
			'customer_visible_cost' => round(max(0, $customer_visible_cost), 2),
			'display_cost' => round(max(0, $display_cost), 2),
			'pickup_capable' => $this->api_service->is_method_explicitly_pickup_point($method) && !empty($package_summary['all_pickup_capable']),
			'pickup_required' => $this->api_service->is_method_explicitly_pickup_point($method) && empty($method['delivery_to_home']),
			'method_payload' => $method,
		);

		if ($cache_ttl > 0) {
			set_transient($cache_key, $quote, $cache_ttl);
		}
		set_transient($this->get_last_known_quote_cache_key($cache_key), $quote, DAY_IN_SECONDS * 30);

		return $quote;
	}

	private function build_quote_failure_entry($quote, $method) {
		return array(
			'method_key' => isset($quote['method_key']) ? (string) $quote['method_key'] : (isset($method['key']) ? sanitize_text_field((string) $method['key']) : ''),
			'reason_code' => isset($quote['reason_code']) ? (string) $quote['reason_code'] : 'quote_api_failure',
			'http_status' => isset($quote['http_status']) ? (int) $quote['http_status'] : 0,
			'error' => isset($quote['error']) ? (string) $quote['error'] : '',
		);
	}

	private function is_auth_related_quote_failure($failure_entry) {
		$http_status = isset($failure_entry['http_status']) ? (int) $failure_entry['http_status'] : 0;
		$reason_code = isset($failure_entry['reason_code']) ? (string) $failure_entry['reason_code'] : '';
		return $http_status === 401 || $http_status === 403 || $reason_code === 'auth_or_config_problem';
	}

	private function apply_checkout_price_adjustments(&$quotes, $order_value, $live_settings, $rules_by_method) {
		if (empty($quotes)) {
			return;
		}

		$threshold = isset($live_settings['free_shipping_threshold']) ? (float) $live_settings['free_shipping_threshold'] : 1500;
		$low_price_strategy = isset($live_settings['low_price_strategy']) ? (string) $live_settings['low_price_strategy'] : 'cheapest_eligible_live';
		if ($order_value < $threshold && $low_price_strategy !== 'disabled') {
			$low_price = isset($live_settings['low_price_option_amount']) ? (float) $live_settings['low_price_option_amount'] : 69;
			$adjusted = false;
			foreach ($quotes as &$quote) {
				$allow = !isset($rules_by_method[$quote['method_key']]['allow_low_price']) || !empty($rules_by_method[$quote['method_key']]['allow_low_price']);
				if (!$allow) {
					continue;
				}
				$quote['customer_visible_cost'] = round(max(0, $low_price), 2);
				$quote['display_cost'] = $this->convert_customer_visible_amount_to_rate_cost($quote['customer_visible_cost'], $live_settings);
				$adjusted = true;
				break;
			}
			unset($quote);
			if (!$adjusted && !empty($quotes[0])) {
				$quotes[0]['customer_visible_cost'] = round(max(0, $low_price), 2);
				$quotes[0]['display_cost'] = $this->convert_customer_visible_amount_to_rate_cost($quotes[0]['customer_visible_cost'], $live_settings);
			}
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
			$quote['customer_visible_cost'] = 0.0;
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
			$action = isset($rule['action']) ? sanitize_key((string) $rule['action']) : 'allow';
			if ($action !== 'decorate') {
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
		$method_pricing = isset($settings['method_pricing']) && is_array($settings['method_pricing']) ? $settings['method_pricing'] : array();
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
			$pricing = isset($method_pricing[$key]) && is_array($method_pricing[$key]) ? $method_pricing[$key] : array();
			$method['delivery_to_pickup_point'] = !empty($pricing['delivery_to_pickup_point']) ? 1 : (!empty($method['delivery_to_pickup_point']) ? 1 : 0);
			$method['delivery_to_home'] = array_key_exists('delivery_to_home', $pricing) ? (!empty($pricing['delivery_to_home']) ? 1 : 0) : (!empty($method['delivery_to_home']) ? 1 : 0);
			$methods[] = $method;
		}
		return $methods;
	}

	private function prune_quote_candidates_for_package_capabilities($candidates, $package_result) {
		$summary = isset($package_result['summary']) && is_array($package_result['summary']) ? $package_result['summary'] : array();
		$pickup_possible = !empty($summary['all_pickup_capable']);
		$mailbox_possible = !empty($summary['all_mailbox_capable']);
		$filtered = array();
		$pruned = 0;

		foreach ($candidates as $method) {
			$is_pickup_method = $this->api_service->is_method_explicitly_pickup_point($method);
			$is_mailbox_method = $this->is_method_mailbox_like($method);
			if ($is_pickup_method && !$pickup_possible) {
				$pruned++;
				continue;
			}
			if ($is_mailbox_method && !$mailbox_possible) {
				$pruned++;
				continue;
			}
			$filtered[] = $method;
		}

		return array(
			'candidates' => $filtered,
			'pruned_count' => $pruned,
		);
	}

	private function is_method_mailbox_like($method) {
		$product_id = isset($method['product_id']) ? strtolower((string) $method['product_id']) : '';
		$product_name = isset($method['product_name']) ? strtolower((string) $method['product_name']) : '';
		$label = isset($method['label']) ? strtolower((string) $method['label']) : '';
		$haystack = $product_id . ' ' . $product_name . ' ' . $label;
		$needles = array('mailbox', 'letterbox', 'postkasse', 'pakke i postkassen');
		foreach ($needles as $needle) {
			if ($needle !== '' && strpos($haystack, $needle) !== false) {
				return true;
			}
		}
		return false;
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

	private function get_order_value($package, $live_settings) {
		$threshold_basis = isset($live_settings['free_shipping_threshold_basis']) ? (string) $live_settings['free_shipping_threshold_basis'] : 'subtotal_incl_vat';
		$use_including_vat_subtotal = $threshold_basis !== 'subtotal_excl_vat';

		$subtotal_excl_vat = 0.0;
		$subtotal_vat = 0.0;
		$contents = isset($package['contents']) && is_array($package['contents']) ? $package['contents'] : array();
		foreach ($contents as $line) {
			if (!is_array($line)) {
				continue;
			}
			$subtotal_excl_vat += isset($line['line_total']) ? (float) $line['line_total'] : 0.0;
			$subtotal_vat += isset($line['line_tax']) ? (float) $line['line_tax'] : 0.0;
		}

		if ($subtotal_excl_vat > 0 || $subtotal_vat > 0) {
			return $use_including_vat_subtotal ? ($subtotal_excl_vat + $subtotal_vat) : $subtotal_excl_vat;
		}

		if (function_exists('WC') && WC() && isset(WC()->cart) && is_object(WC()->cart)) {
			if ($use_including_vat_subtotal) {
				return (float) WC()->cart->get_subtotal() + (float) WC()->cart->get_subtotal_tax();
			}
			return (float) WC()->cart->get_subtotal();
		}
		return isset($package['contents_cost']) ? (float) $package['contents_cost'] : 0.0;
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
			$action = isset($rule['action']) ? sanitize_key((string) $rule['action']) : 'allow';
			if ($action !== 'decorate') {
				continue;
			}
			$result[$method_key] = array(
				'allow_low_price' => !isset($rule['allow_low_price']) || !empty($rule['allow_low_price']),
				'allow_free_shipping' => !isset($rule['allow_free_shipping']) || !empty($rule['allow_free_shipping']),
			);
		}
		return $result;
	}

	private function add_fallback_rates_if_needed($settings, $live_settings, $fallback_behavior, $allow_checkout_with_fallback) {
		$result = array(
			'mode' => (string) $fallback_behavior,
			'added_count' => 0,
			'notice' => '',
		);
		if ($fallback_behavior === 'block_checkout') {
			$message = __('Fraktberegning er midlertidig utilgjengelig. Vennligst prøv igjen senere.', 'lp-cargonizer');
			$this->add_checkout_block_notice($message);
			$result['notice'] = $message;
			return $result;
		}
		if ($fallback_behavior === 'hide_live_checkout') {
			return $result;
		}
		if (!$allow_checkout_with_fallback) {
			$message = __('Fraktberegning er midlertidig utilgjengelig. Fallback-rater er deaktivert.', 'lp-cargonizer');
			$this->add_checkout_block_notice($message);
			$result['notice'] = $message;
			return $result;
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
				'cost' => $this->convert_customer_visible_amount_to_rate_cost($price, $live_settings),
			));
			$result['added_count']++;
		}
		return $result;
	}


	private function convert_customer_visible_amount_to_rate_cost($customer_visible_amount, $live_settings) {
		$amount = max(0, (float) $customer_visible_amount);
		if (empty($live_settings['show_prices_including_vat'])) {
			return round($amount, 2);
		}
		if (!class_exists('WC_Tax')) {
			return round($amount, 2);
		}
		$shipping_tax_rates = WC_Tax::get_shipping_tax_rates();
		if (empty($shipping_tax_rates) || !is_array($shipping_tax_rates)) {
			return round($amount, 2);
		}
		$shipping_taxes = WC_Tax::calc_tax($amount, $shipping_tax_rates, true);
		$amount_excluding_vat = $amount - array_sum(is_array($shipping_taxes) ? $shipping_taxes : array());
		return round(max(0, $amount_excluding_vat), 2);
	}

	private function get_pickup_points_for_rate($quote, $destination, $live_settings) {
		$method = isset($quote['method_payload']) && is_array($quote['method_payload']) ? $quote['method_payload'] : array();
		$country = $this->api_service->sanitize_country_code(isset($destination['country']) ? $destination['country'] : '');
		$postcode = $this->api_service->sanitize_postcode(isset($destination['postcode']) ? $destination['postcode'] : '');
		$city = sanitize_text_field(isset($destination['city']) ? (string) $destination['city'] : '');
		$address = sanitize_text_field((string) (isset($destination['address']) ? $destination['address'] : (isset($destination['address_1']) ? $destination['address_1'] : '')));

		if (!$this->has_minimum_destination_for_pickup_points($destination)) {
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

		$pickup_timeout = $this->resolve_pickup_lookup_timeout($live_settings);
		$lookup_method['request_timeout_seconds'] = $pickup_timeout;
		$custom = $this->api_service->detect_servicepartner_custom_params($lookup_method);
		$cache_ttl = isset($live_settings['pickup_point_cache_ttl_seconds']) ? max(0, (int) $live_settings['pickup_point_cache_ttl_seconds']) : 300;
		$cache_key = 'lp_carg_pickup_' . md5(wp_json_encode(array(
			'transport_agreement_id' => isset($lookup_method['agreement_id']) ? (string) $lookup_method['agreement_id'] : '',
			'carrier' => isset($lookup_method['carrier_id']) ? (string) $lookup_method['carrier_id'] : '',
			'product' => isset($lookup_method['product_id']) ? (string) $lookup_method['product_id'] : '',
			'country' => $country,
			'postcode' => $postcode,
			'city' => $city,
			'address' => $address,
			'request_timeout_seconds' => $pickup_timeout,
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
		if (empty($result['success'])) {
			$this->log_live_checkout_event('warning', 'Pickup point lookup failed.', array(
				'method_key' => isset($quote['method_key']) ? (string) $quote['method_key'] : '',
				'carrier_id' => isset($quote['carrier_id']) ? (string) $quote['carrier_id'] : '',
				'product_id' => isset($quote['product_id']) ? (string) $quote['product_id'] : '',
				'postcode' => $postcode,
				'error_message' => isset($result['error_message']) ? (string) $result['error_message'] : '',
				'http_status' => isset($result['http_status']) ? (int) $result['http_status'] : 0,
			));
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
			$points[] = LP_Cargonizer_Krokedil_Pickup_Meta_Helper::normalize_pickup_point(array(
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
			));
		}
		$points = $this->sort_pickup_points_deterministically($points);

		if ($cache_ttl > 0) {
			set_transient($cache_key, $points, $cache_ttl);
		}

		return $points;
	}

	private function get_cached_pickup_points_for_rate($quote, $destination, $live_settings) {
		if (!$this->has_minimum_destination_for_pickup_points($destination)) {
			return array();
		}

		$method = isset($quote['method_payload']) && is_array($quote['method_payload']) ? $quote['method_payload'] : array();
		$country = $this->api_service->sanitize_country_code(isset($destination['country']) ? $destination['country'] : '');
		$postcode = $this->api_service->sanitize_postcode(isset($destination['postcode']) ? $destination['postcode'] : '');
		$city = sanitize_text_field(isset($destination['city']) ? (string) $destination['city'] : '');
		$address = sanitize_text_field((string) (isset($destination['address']) ? $destination['address'] : (isset($destination['address_1']) ? $destination['address_1'] : '')));
		$pickup_timeout = $this->resolve_pickup_lookup_timeout($live_settings);
		$lookup_method = array_merge($method, array(
			'agreement_id' => isset($quote['agreement_id']) ? $quote['agreement_id'] : '',
			'carrier_id' => isset($quote['carrier_id']) ? $quote['carrier_id'] : '',
			'product_id' => isset($quote['product_id']) ? $quote['product_id'] : '',
			'country' => $country,
			'postcode' => $postcode,
			'city' => $city,
			'address' => $address,
			'request_timeout_seconds' => $pickup_timeout,
		));
		$custom = $this->api_service->detect_servicepartner_custom_params($lookup_method);
		$cache_key = 'lp_carg_pickup_' . md5(wp_json_encode(array(
			'transport_agreement_id' => isset($lookup_method['agreement_id']) ? (string) $lookup_method['agreement_id'] : '',
			'carrier' => isset($lookup_method['carrier_id']) ? (string) $lookup_method['carrier_id'] : '',
			'product' => isset($lookup_method['product_id']) ? (string) $lookup_method['product_id'] : '',
			'country' => $country,
			'postcode' => $postcode,
			'city' => $city,
			'address' => $address,
			'request_timeout_seconds' => $pickup_timeout,
			'custom' => isset($custom['params']) ? $custom['params'] : array(),
		)));
		$cached = get_transient($cache_key);
		if (!is_array($cached) || empty($cached)) {
			return array();
		}

		return array_values($cached);
	}

	private function resolve_selected_pickup_point($rate_id, $pickup_points) {
		$pickup_points = LP_Cargonizer_Krokedil_Pickup_Meta_Helper::normalize_pickup_points($pickup_points);
		$first = reset($pickup_points);
		if (!is_array($first)) {
			return array('id' => '', 'point' => array());
		}

		$selected_id = (string) $first['id'];
		$selected_point = $first;
		$session_map = $this->get_pickup_selection_session_map();
		$selection_source = 'auto_nearest';
		if (isset($session_map[$rate_id]) && is_array($session_map[$rate_id])) {
			$stored_id = isset($session_map[$rate_id]['id']) ? sanitize_text_field((string) $session_map[$rate_id]['id']) : '';
			if ($stored_id !== '') {
				$selection_source = 'customer_override';
				$matched = false;
				foreach ($pickup_points as $point) {
					$point_id = isset($point['id']) ? (string) $point['id'] : '';
					if ($point_id === $stored_id) {
						$selected_id = $point_id;
						$selected_point = $point;
						$matched = true;
						break;
					}
				}
				if (!$matched) {
					$this->log_live_checkout_event('debug', 'Previously selected pickup point was unavailable for the refreshed rate payload; fell back to deterministic nearest point.', array(
						'rate_id' => (string) $rate_id,
						'requested_pickup_point_id' => $stored_id,
						'fallback_pickup_point_id' => $selected_id,
					));
					$selection_source = 'customer_override_fallback_unavailable';
				}
			}
		}

		if ($selected_id !== '') {
			$session_map[$rate_id] = array(
				'id' => $selected_id,
				'point' => $selected_point,
				'source' => $selection_source,
				'rate_context' => array(
					'rate_id' => (string) $rate_id,
				),
			);
			$this->set_pickup_selection_session_map($session_map);
		}

		return array(
			'id' => $selected_id,
			'point' => $selected_point,
		);
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
			$label_cmp = strcmp($left_label, $right_label);
			if ($label_cmp !== 0) {
				return $label_cmp;
			}

			$left_id = isset($left['id']) ? (string) $left['id'] : '';
			$right_id = isset($right['id']) ? (string) $right['id'] : '';
			return strcmp($left_id, $right_id);
		});
		return $pickup_points;
	}

	private function build_checkout_rate_id($quote) {
		$method_key = isset($quote['method_key']) ? sanitize_title((string) $quote['method_key']) : '';
		$components = array(
			'method_key' => isset($quote['method_key']) ? (string) $quote['method_key'] : '',
			'agreement_id' => isset($quote['agreement_id']) ? (string) $quote['agreement_id'] : '',
			'carrier_id' => isset($quote['carrier_id']) ? (string) $quote['carrier_id'] : '',
			'product_id' => isset($quote['product_id']) ? (string) $quote['product_id'] : '',
		);
		$stable_suffix = substr(md5(wp_json_encode($components)), 0, 12);
		return $this->id . ':' . $this->instance_id . ':' . $method_key . '-' . $stable_suffix;
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

	private function has_minimum_destination_for_quotes($destination) {
		$country = $this->api_service->sanitize_country_code(isset($destination['country']) ? $destination['country'] : '');
		$postcode = $this->api_service->sanitize_postcode(isset($destination['postcode']) ? $destination['postcode'] : '');
		$city = sanitize_text_field(isset($destination['city']) ? (string) $destination['city'] : '');
		if ($country !== 'NO') {
			return false;
		}
		return $postcode !== '' && $city !== '';
	}

	private function add_checkout_destination_incomplete_notice($destination) {
		$postcode = $this->api_service->sanitize_postcode(isset($destination['postcode']) ? $destination['postcode'] : '');
		$city = sanitize_text_field(isset($destination['city']) ? (string) $destination['city'] : '');
		$missing = array();
		if ($postcode === '') {
			$missing[] = __('postnummer', 'lp-cargonizer');
		}
		if ($city === '') {
			$missing[] = __('poststed', 'lp-cargonizer');
		}
		if (empty($missing)) {
			return;
		}
		if (!function_exists('wc_add_notice') || is_admin()) {
			return;
		}
		$message = sprintf(
			/* translators: %s: missing destination fields */
			__('Fyll inn %s for å se tilgjengelige fraktalternativer.', 'lp-cargonizer'),
			implode(' / ', $missing)
		);
		if (function_exists('wc_has_notice') && wc_has_notice($message, 'notice')) {
			return;
		}
		wc_add_notice($message, 'notice');
	}

	private function resolve_live_quote_request_context($live_settings) {
		$mode = isset($live_settings['quote_timing_mode']) ? sanitize_key((string) $live_settings['quote_timing_mode']) : 'checkout_only';
		if ($mode === 'cart_and_checkout') {
			return array(
				'allow_remote_quotes' => true,
				'use_cart_placeholder_rate' => false,
			);
		}

		$is_checkout_context = $this->is_checkout_quote_context_request();
		if ($is_checkout_context) {
			return array(
				'allow_remote_quotes' => true,
				'use_cart_placeholder_rate' => false,
			);
		}

		if ($this->is_cart_side_request()) {
			return array(
				'allow_remote_quotes' => false,
				'use_cart_placeholder_rate' => true,
			);
		}

		return array(
			'allow_remote_quotes' => false,
			'use_cart_placeholder_rate' => false,
		);
	}

	private function is_checkout_quote_context_request() {
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

		$request_path = $this->get_request_path();
		if ($this->is_store_api_checkout_path($request_path)) {
			return true;
		}

		return false;
	}

	private function is_cart_side_request() {
		if (function_exists('is_cart') && is_cart()) {
			return true;
		}

		if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
			$ajax_action = isset($_REQUEST['action']) ? sanitize_text_field(wp_unslash((string) $_REQUEST['action'])) : '';
			$cart_ajax_actions = array(
				'woocommerce_get_refreshed_fragments',
				'woocommerce_update_shipping_method',
				'woocommerce_apply_coupon',
				'woocommerce_remove_coupon',
				'woocommerce_remove_from_cart',
				'woocommerce_update_order_review_expired',
				'wc_fragments_refreshed',
			);
			if (in_array($ajax_action, $cart_ajax_actions, true)) {
				return true;
			}
		}

		$request_path = $this->get_request_path();
		if ($this->is_store_api_cart_path($request_path)) {
			return true;
		}

		return false;
	}

	private function is_store_api_checkout_path($request_path) {
		if ($request_path === '') {
			return false;
		}
		return strpos($request_path, '/wc/store/checkout') !== false || strpos($request_path, '/wc/store/v1/checkout') !== false;
	}

	private function is_store_api_cart_path($request_path) {
		if ($request_path === '') {
			return false;
		}
		return strpos($request_path, '/wc/store/cart') !== false || strpos($request_path, '/wc/store/v1/cart') !== false;
	}

	private function get_request_path() {
		if (!isset($_SERVER['REQUEST_URI'])) {
			return '';
		}
		$request_uri = wp_unslash((string) $_SERVER['REQUEST_URI']);
		$path = wp_parse_url($request_uri, PHP_URL_PATH);
		return is_string($path) ? sanitize_text_field($path) : '';
	}

	private function has_minimum_destination_for_pickup_points($destination) {
		$country = $this->api_service->sanitize_country_code(isset($destination['country']) ? $destination['country'] : '');
		$postcode = $this->api_service->sanitize_postcode(isset($destination['postcode']) ? $destination['postcode'] : '');
		$city = sanitize_text_field(isset($destination['city']) ? (string) $destination['city'] : '');
		$address = sanitize_text_field((string) (isset($destination['address']) ? $destination['address'] : (isset($destination['address_1']) ? $destination['address_1'] : '')));
		if ($country !== 'NO') {
			return false;
		}
		return $postcode !== '' && $city !== '' && $address !== '';
	}

	private function log_live_checkout_event($level, $message, $context = array()) {
		if (!$this->should_log_live_checkout_events()) {
			return;
		}
		if (!function_exists('wc_get_logger')) {
			return;
		}
		$logger = wc_get_logger();
		if (!$logger) {
			return;
		}
		$method = method_exists($logger, $level) ? $level : 'info';
		$logger->$method($message, array(
			'source' => 'lp-cargonizer-live-checkout',
			'context' => is_array($context) ? $context : array(),
		));
	}

	private function resolve_quote_fallback_behavior($settings, $live_settings) {
		$fallback = isset($settings['checkout_fallback']) && is_array($settings['checkout_fallback']) ? $settings['checkout_fallback'] : array();
		$behavior = isset($fallback['on_quote_failure']) ? sanitize_text_field((string) $fallback['on_quote_failure']) : '';
		if ($behavior === '' && isset($live_settings['quote_fallback_behavior'])) {
			$behavior = sanitize_text_field((string) $live_settings['quote_fallback_behavior']);
		}
		$allowed = array('safe_fallback_rate', 'block_checkout', 'hide_live_checkout', 'use_last_known_rate');
		if (!in_array($behavior, $allowed, true)) {
			$behavior = 'safe_fallback_rate';
		}
		return $behavior;
	}

	private function should_allow_checkout_with_fallback($settings) {
		$fallback = isset($settings['checkout_fallback']) && is_array($settings['checkout_fallback']) ? $settings['checkout_fallback'] : array();
		return !isset($fallback['allow_checkout_with_fallback']) || !empty($fallback['allow_checkout_with_fallback']);
	}

	private function maybe_return_last_known_quote($cache_key, $fallback_behavior) {
		if ($fallback_behavior !== 'use_last_known_rate') {
			return array('success' => false);
		}
		$cached = get_transient($this->get_last_known_quote_cache_key($cache_key));
		if (is_array($cached) && !empty($cached['success'])) {
			return $cached;
		}
		return array('success' => false);
	}

	private function get_last_known_quote_cache_key($cache_key) {
		return 'lp_carg_last_known_' . md5((string) $cache_key);
	}

	private function add_checkout_block_notice($message) {
		if (!function_exists('wc_add_notice') || is_admin()) {
			return;
		}
		wc_add_notice((string) $message, 'error');
	}

	private function should_log_live_checkout_events() {
		$settings = $this->settings_service->get_settings();
		$live_settings = isset($settings['live_checkout']) && is_array($settings['live_checkout']) ? $settings['live_checkout'] : array();
		return !empty($live_settings['debug_logging']);
	}

	private function build_destination_debug_context($destination) {
		return array(
			'country' => $this->api_service->sanitize_country_code(isset($destination['country']) ? $destination['country'] : ''),
			'postcode_present' => $this->api_service->sanitize_postcode(isset($destination['postcode']) ? $destination['postcode'] : '') !== '',
			'city_present' => sanitize_text_field(isset($destination['city']) ? (string) $destination['city'] : '') !== '',
			'address_present' => sanitize_text_field((string) (isset($destination['address']) ? $destination['address'] : (isset($destination['address_1']) ? $destination['address_1'] : ''))) !== '',
		);
	}

	private function record_last_no_rates_status($reason_code, $reason_group, $message, $context = array()) {
		$payload = array(
			'reason_code' => sanitize_key((string) $reason_code),
			'reason_group' => sanitize_key((string) $reason_group),
			'message' => sanitize_text_field((string) $message),
			'occurred_at_gmt' => gmdate('Y-m-d H:i:s'),
			'context' => is_array($context) ? $context : array(),
		);
		set_transient(self::LAST_NO_RATES_STATUS_TRANSIENT, $payload, DAY_IN_SECONDS * 7);
	}

	private function clear_last_no_rates_status() {
		delete_transient(self::LAST_NO_RATES_STATUS_TRANSIENT);
	}

	private function resolve_frontend_quote_timeout($live_settings) {
		$timeout = isset($live_settings['quote_timeout_seconds']) ? (float) $live_settings['quote_timeout_seconds'] : 3.0;
		if ($timeout <= 0) {
			$timeout = 3.0;
		}
		return max(1.0, min(15.0, $timeout));
	}

	private function resolve_pickup_lookup_timeout($live_settings) {
		$pickup_timeout = isset($live_settings['pickup_point_timeout_seconds']) ? (float) $live_settings['pickup_point_timeout_seconds'] : 8.0;
		if ($pickup_timeout <= 0) {
			$pickup_timeout = 8.0;
		}
		return max(1.0, min(30.0, $pickup_timeout));
	}
}
