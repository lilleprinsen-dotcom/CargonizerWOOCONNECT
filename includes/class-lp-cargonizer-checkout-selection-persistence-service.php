<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Checkout_Selection_Persistence_Service {
	const ORDER_META_KEY = '_lp_cargonizer_checkout_selection';
	const PICKUP_SESSION_KEY = 'lp_cargonizer_checkout_pickup_selection_map';

	/** @var LP_Cargonizer_Settings_Service */
	private $settings_service;

	public function __construct() {
		$this->settings_service = new LP_Cargonizer_Settings_Service(LP_Cargonizer_Connector::OPTION_KEY, LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY);
	}

	public function register_hooks() {
		add_action('woocommerce_checkout_order_processed', array($this, 'handle_classic_checkout_order_processed'), 20, 3);
		add_action('woocommerce_store_api_checkout_order_processed', array($this, 'handle_store_api_checkout_order_processed'), 20, 1);
	}

	public function handle_classic_checkout_order_processed($order_id, $posted_data = array(), $order = null) {
		try {
			if (!$this->is_wc_order($order)) {
				$order = $order_id ? wc_get_order($order_id) : false;
			}

			if (!$this->is_wc_order($order)) {
				$this->log_live_checkout_event('debug', 'Skipped checkout selection persistence for classic checkout: order instance unavailable.');
				return;
			}

			$this->persist_for_order($order, 'classic_checkout');
		} catch (Throwable $throwable) {
			$this->log_live_checkout_event('error', 'Checkout selection persistence failed for classic checkout.', array(
				'order_id' => (int) $order_id,
				'error' => $throwable->getMessage(),
			));
		}
	}

	public function handle_store_api_checkout_order_processed($order) {
		try {
			if (!$this->is_wc_order($order)) {
				if (is_numeric($order)) {
					$order = wc_get_order((int) $order);
				} else {
					$this->log_live_checkout_event('debug', 'Skipped checkout selection persistence for Store API: order payload was not resolvable.');
					return;
				}
			}

			if (!$this->is_wc_order($order)) {
				$this->log_live_checkout_event('debug', 'Skipped checkout selection persistence for Store API: order instance unavailable.');
				return;
			}

			$this->persist_for_order($order, 'store_api');
		} catch (Throwable $throwable) {
			$this->log_live_checkout_event('error', 'Checkout selection persistence failed for Store API.', array(
				'order_id' => $this->is_wc_order($order) && method_exists($order, 'get_id') ? (int) $order->get_id() : 0,
				'error' => $throwable->getMessage(),
			));
		}
	}

	private function persist_for_order($order, $source) {
		$selected_rates = $this->get_selected_rates_from_session();
		$pickup_selection_map = $this->get_pickup_selection_session_map();
		$shipping_items = $order->get_items('shipping');
		if (empty($shipping_items)) {
			$this->log_live_checkout_event('debug', 'Skipped checkout selection persistence: order contains no shipping items.', array(
				'source' => $source,
				'order_id' => method_exists($order, 'get_id') ? (int) $order->get_id() : 0,
			));
			return;
		}
		if (empty($selected_rates)) {
			$selected_rates = $this->build_selected_rates_from_shipping_items($shipping_items);
			if (empty($selected_rates)) {
				$this->log_live_checkout_event('debug', 'Skipped checkout selection persistence: no selected rates in session and no recoverable live rate in order shipping items.', array(
					'source' => $source,
					'order_id' => method_exists($order, 'get_id') ? (int) $order->get_id() : 0,
				));
				return;
			}
			$this->log_live_checkout_event('debug', 'Recovered selected rates for order persistence from shipping items.', array(
				'source' => $source,
				'order_id' => method_exists($order, 'get_id') ? (int) $order->get_id() : 0,
				'rate_count' => count($selected_rates),
			));
		}

		$persisted_rows = array();
		$method_index = $this->index_methods_by_key();
		$package_index = 0;

		foreach ($shipping_items as $shipping_item_id => $shipping_item) {
			$selected_rate = isset($selected_rates[$package_index]) && is_array($selected_rates[$package_index]) ? $selected_rates[$package_index] : array();
			$package_index++;

			if (empty($selected_rate)) {
				continue;
			}

			$method_id = isset($selected_rate['method_id']) ? (string) $selected_rate['method_id'] : '';
			if ($method_id !== LP_Cargonizer_Live_Checkout::METHOD_ID) {
				continue;
			}

			$rate_id = isset($selected_rate['rate_id']) ? (string) $selected_rate['rate_id'] : '';
			$rate_meta = isset($selected_rate['meta_data']) && is_array($selected_rate['meta_data']) ? $selected_rate['meta_data'] : array();
			$pickup = $this->resolve_pickup_selection($rate_meta, $rate_id, $pickup_selection_map);
			$pickup_point_ids = $this->extract_pickup_point_ids($pickup['pickup_points']);
			$minimal_rate_meta = $this->extract_minimal_rate_meta($rate_meta);

			$method_key = isset($rate_meta['method_key']) ? sanitize_text_field((string) $rate_meta['method_key']) : '';
			$method_config = $method_key !== '' && isset($method_index[$method_key]) ? $method_index[$method_key] : array();
			$selected_service_ids = $this->extract_selected_service_ids($rate_meta, $method_config);
			$available_service_ids = $this->extract_available_service_ids($method_config);

			$shipping_item->update_meta_data('transport_agreement_id', isset($rate_meta['transport_agreement_id']) ? (string) $rate_meta['transport_agreement_id'] : '');
			$shipping_item->update_meta_data('carrier_id', isset($rate_meta['carrier_id']) ? (string) $rate_meta['carrier_id'] : '');
			$shipping_item->update_meta_data('product_id', isset($rate_meta['product_id']) ? (string) $rate_meta['product_id'] : '');
			$shipping_item->update_meta_data('method_key', $method_key);
			$shipping_item->update_meta_data('krokedil_pickup_points', $pickup_point_ids);
			$shipping_item->update_meta_data('krokedil_selected_pickup_point', $pickup['selected_pickup_point']);
			$shipping_item->update_meta_data('krokedil_selected_pickup_point_id', $pickup['selected_pickup_point_id']);
			$shipping_item->save();

			$persisted_rows[] = array(
				'shipping' => array(
					'method_id' => $method_id,
					'rate_id' => $rate_id,
					'label' => isset($selected_rate['label']) ? (string) $selected_rate['label'] : '',
					'cost_incl_vat' => isset($selected_rate['cost']) ? (string) $selected_rate['cost'] : '',
					'currency' => $order->get_currency(),
					'transport_agreement_id' => isset($rate_meta['transport_agreement_id']) ? (string) $rate_meta['transport_agreement_id'] : '',
					'carrier_id' => isset($rate_meta['carrier_id']) ? (string) $rate_meta['carrier_id'] : '',
					'product_id' => isset($rate_meta['product_id']) ? (string) $rate_meta['product_id'] : '',
					'method_key' => $method_key,
					'selected_service_ids' => $selected_service_ids,
					'available_service_ids' => $available_service_ids,
				),
				'pickup_point' => array(
					'required' => !empty($pickup['pickup_points']),
					'selected_id' => $pickup['selected_pickup_point_id'],
					'selected' => $pickup['selected_pickup_point'],
					'selection_source' => $pickup['selection_source'],
					'selection_valid' => !empty($pickup['selection_valid']),
				),
				'krokedil' => array(
					'krokedil_pickup_points' => $pickup_point_ids,
					'krokedil_selected_pickup_point' => $pickup['selected_pickup_point'],
					'krokedil_selected_pickup_point_id' => $pickup['selected_pickup_point_id'],
				),
				'quote_context' => array(
					'package_index' => isset($selected_rate['package_index']) ? (int) $selected_rate['package_index'] : 0,
					'instance_id' => isset($selected_rate['instance_id']) ? (string) $selected_rate['instance_id'] : '',
					'rate_meta' => $minimal_rate_meta,
				),
				'compatibility_payload' => array(
					'chosen_rate_id' => $rate_id,
					'selected_pickup_point_id' => $pickup['selected_pickup_point_id'],
					'selected_pickup_point' => $pickup['selected_pickup_point'],
					'rate_context' => $minimal_rate_meta,
				),
			);
		}

		if (empty($persisted_rows)) {
			$this->log_live_checkout_event('debug', 'Skipped checkout selection persistence: no live checkout rows were eligible for persistence.', array(
				'source' => $source,
				'order_id' => method_exists($order, 'get_id') ? (int) $order->get_id() : 0,
			));
			return;
		}

		$payload = array(
			'version' => 1,
			'saved_at_gmt' => gmdate('Y-m-d H:i:s'),
			'source' => $source,
			'shipping' => $persisted_rows[0]['shipping'],
			'pickup_point' => $persisted_rows[0]['pickup_point'],
			'krokedil' => $persisted_rows[0]['krokedil'],
			'quote_context' => $persisted_rows[0]['quote_context'],
			'compatibility_payload' => $persisted_rows[0]['compatibility_payload'],
			'packages' => $persisted_rows,
		);

		$order->update_meta_data(self::ORDER_META_KEY, $payload);
		$order->save();
		$this->log_live_checkout_event('debug', 'Persisted checkout selection metadata for order.', array(
			'source' => $source,
			'order_id' => method_exists($order, 'get_id') ? (int) $order->get_id() : 0,
			'package_count' => count($persisted_rows),
		));
	}

	private function build_selected_rates_from_shipping_items($shipping_items) {
		$result = array();
		$package_index = 0;
		foreach ($shipping_items as $shipping_item) {
			if (!is_object($shipping_item) || !method_exists($shipping_item, 'get_method_id')) {
				$package_index++;
				continue;
			}
			$method_id = (string) $shipping_item->get_method_id();
			if ($method_id !== LP_Cargonizer_Live_Checkout::METHOD_ID) {
				$package_index++;
				continue;
			}
			$rate_id = method_exists($shipping_item, 'get_instance_id') ? (string) $shipping_item->get_instance_id() : '';
			$result[$package_index] = array(
				'package_index' => (int) $package_index,
				'rate_id' => $rate_id,
				'method_id' => $method_id,
				'instance_id' => $rate_id,
				'label' => method_exists($shipping_item, 'get_name') ? (string) $shipping_item->get_name() : '',
				'cost' => method_exists($shipping_item, 'get_total') ? (string) $shipping_item->get_total() : '',
				'meta_data' => $this->extract_order_shipping_item_meta($shipping_item),
			);
			$package_index++;
		}
		ksort($result);
		return $result;
	}

	private function extract_order_shipping_item_meta($shipping_item) {
		$keys = array(
			'transport_agreement_id',
			'carrier_id',
			'product_id',
			'method_key',
			'krokedil_pickup_points',
			'krokedil_selected_pickup_point',
			'krokedil_selected_pickup_point_id',
			'lp_cargonizer_pickup_capable',
		);
		$result = array();
		foreach ($keys as $key) {
			if (!method_exists($shipping_item, 'get_meta')) {
				continue;
			}
			$value = $shipping_item->get_meta($key, true);
			if ($value === '' || $value === null) {
				continue;
			}
			$result[$key] = $this->sanitize_recursive($value);
		}
		return $result;
	}

	private function get_selected_rates_from_session() {
		if (!$this->has_wc_session()) {
			$this->log_live_checkout_event('debug', 'Skipped reading selected checkout rates from session: WC session unavailable.');
			return array();
		}

		$chosen_methods = WC()->session->get('chosen_shipping_methods', array());
		if (!is_array($chosen_methods) || empty($chosen_methods)) {
			return array();
		}

		$rates_by_package = array();
		foreach ($chosen_methods as $package_index => $chosen_rate_id) {
			$chosen_rate_id = sanitize_text_field((string) $chosen_rate_id);
			if ($chosen_rate_id === '') {
				continue;
			}

			$shipping_for_package = WC()->session->get('shipping_for_package_' . $package_index, array());
			$rates = isset($shipping_for_package['rates']) && is_array($shipping_for_package['rates']) ? $shipping_for_package['rates'] : array();
			if (!isset($rates[$chosen_rate_id]) || !is_a($rates[$chosen_rate_id], 'WC_Shipping_Rate')) {
				$this->log_live_checkout_event('debug', 'Skipping selected shipping rate persistence path: chosen rate not available in package session.', array(
					'package_index' => (int) $package_index,
					'chosen_rate_id' => $chosen_rate_id,
				));
				continue;
			}

			$rate = $rates[$chosen_rate_id];
			$rates_by_package[(int) $package_index] = array(
				'package_index' => (int) $package_index,
				'rate_id' => (string) $rate->get_id(),
				'method_id' => (string) $rate->get_method_id(),
				'instance_id' => method_exists($rate, 'get_instance_id') ? (string) $rate->get_instance_id() : '',
				'label' => method_exists($rate, 'get_label') ? (string) $rate->get_label() : '',
				'cost' => method_exists($rate, 'get_cost') ? (string) $rate->get_cost() : '',
				'meta_data' => $this->sanitize_recursive($this->extract_rate_meta($rate)),
			);
		}

		ksort($rates_by_package);

		return $rates_by_package;
	}

	private function extract_rate_meta($rate) {
		$meta = array();
		if (!is_a($rate, 'WC_Shipping_Rate')) {
			return $meta;
		}

		$meta_data = method_exists($rate, 'get_meta_data') ? $rate->get_meta_data() : array();
		if (!is_array($meta_data)) {
			return $meta;
		}

		foreach ($meta_data as $meta_key => $meta_value) {
			if (is_object($meta_value) && isset($meta_value->key)) {
				$key = (string) $meta_value->key;
				$value = isset($meta_value->value) ? $meta_value->value : null;
				$meta[$key] = $value;
				continue;
			}
			$meta[(string) $meta_key] = $meta_value;
		}

		return $meta;
	}

	private function get_pickup_selection_session_map() {
		if (!$this->has_wc_session()) {
			return array();
		}
		$value = WC()->session->get(self::PICKUP_SESSION_KEY, array());
		return is_array($value) ? $value : array();
	}

	private function resolve_pickup_selection($rate_meta, $rate_id, $pickup_selection_map) {
		$pickup_points = isset($rate_meta['krokedil_pickup_points']) && is_array($rate_meta['krokedil_pickup_points'])
			? array_values($rate_meta['krokedil_pickup_points'])
			: array();
		$selected_id = isset($rate_meta['krokedil_selected_pickup_point_id']) ? sanitize_text_field((string) $rate_meta['krokedil_selected_pickup_point_id']) : '';
		$selected_point = isset($rate_meta['krokedil_selected_pickup_point']) && is_array($rate_meta['krokedil_selected_pickup_point'])
			? $rate_meta['krokedil_selected_pickup_point']
			: array();
		$selection_source = 'auto_nearest';
		$selection_valid = true;

		if ($rate_id !== '' && isset($pickup_selection_map[$rate_id]) && is_array($pickup_selection_map[$rate_id])) {
			if (empty($pickup_points) && isset($pickup_selection_map[$rate_id]['pickup_points']) && is_array($pickup_selection_map[$rate_id]['pickup_points'])) {
				$pickup_points = array_values($pickup_selection_map[$rate_id]['pickup_points']);
			}
			$session_selected_id = isset($pickup_selection_map[$rate_id]['id']) ? sanitize_text_field((string) $pickup_selection_map[$rate_id]['id']) : '';
			if ($session_selected_id !== '') {
				$selected_id = $session_selected_id;
				$selection_source = 'customer_override';
			}
		}

		if (empty($pickup_points)) {
			return array(
				'pickup_points' => array(),
				'selected_pickup_point_id' => '',
				'selected_pickup_point' => array(),
				'selection_source' => 'none',
				'selection_valid' => true,
			);
		}

		$matching_point = $this->find_pickup_point_by_id($pickup_points, $selected_id);
		if (!empty($matching_point)) {
			$selected_point = $matching_point;
		} elseif (!empty($selected_point) && is_array($selected_point) && !empty($selected_point['id'])) {
			$matching_point = $this->find_pickup_point_by_id($pickup_points, (string) $selected_point['id']);
			if (!empty($matching_point)) {
				$selected_id = (string) $matching_point['id'];
				$selected_point = $matching_point;
			} else {
				$selection_valid = false;
			}
		} else {
			$selection_valid = false;
		}

		if (empty($selected_point) || empty($selected_point['id']) || !$selection_valid) {
			$fallback = reset($pickup_points);
			$selected_point = is_array($fallback) ? $fallback : array();
			$selected_id = isset($selected_point['id']) ? (string) $selected_point['id'] : '';
			$this->log_live_checkout_event('debug', 'Persisted pickup selection used deterministic fallback after selected point disappeared.', array(
				'rate_id' => (string) $rate_id,
				'requested_pickup_point_id' => isset($rate_meta['krokedil_selected_pickup_point_id']) ? sanitize_text_field((string) $rate_meta['krokedil_selected_pickup_point_id']) : '',
				'fallback_pickup_point_id' => $selected_id,
			));
			if ($selection_source === 'customer_override') {
				$selection_source = 'customer_override_fallback_unavailable';
			} else {
				$selection_source = 'auto_nearest';
			}
		}

		return array(
			'pickup_points' => $pickup_points,
			'selected_pickup_point_id' => $selected_id,
			'selected_pickup_point' => is_array($selected_point) ? $selected_point : array(),
			'selection_source' => $selection_source,
			'selection_valid' => $selection_valid,
		);
	}

	private function find_pickup_point_by_id($pickup_points, $point_id) {
		$point_id = sanitize_text_field((string) $point_id);
		if ($point_id === '') {
			return array();
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

		return array();
	}

	private function extract_pickup_point_ids($pickup_points) {
		$result = array();
		if (!is_array($pickup_points)) {
			return $result;
		}
		foreach ($pickup_points as $pickup_point) {
			if (!is_array($pickup_point)) {
				continue;
			}
			$point_id = isset($pickup_point['id']) ? sanitize_text_field((string) $pickup_point['id']) : '';
			if ($point_id !== '') {
				$result[] = $point_id;
			}
		}
		return array_values(array_unique($result));
	}

	private function extract_minimal_rate_meta($rate_meta) {
		$keys = array(
			'transport_agreement_id',
			'carrier_id',
			'product_id',
			'method_key',
			'lp_cargonizer_pickup_capable',
		);
		$result = array();
		foreach ($keys as $key) {
			if (!array_key_exists($key, $rate_meta)) {
				continue;
			}
			$result[$key] = sanitize_text_field((string) $rate_meta[$key]);
		}
		return $result;
	}

	private function index_methods_by_key() {
		$settings = $this->settings_service->get_settings();
		$methods = isset($settings['available_methods']) && is_array($settings['available_methods']) ? $settings['available_methods'] : array();
		$index = array();

		foreach ($methods as $method) {
			if (!is_array($method)) {
				continue;
			}
			$key = isset($method['key']) ? sanitize_text_field((string) $method['key']) : '';
			if ($key === '') {
				continue;
			}
			$index[$key] = $method;
		}

		return $index;
	}

	private function extract_selected_service_ids($rate_meta, $method_config) {
		$selected = array();
		if (isset($rate_meta['selected_service_ids']) && is_array($rate_meta['selected_service_ids'])) {
			foreach ($rate_meta['selected_service_ids'] as $service_id) {
				$service_id = sanitize_text_field((string) $service_id);
				if ($service_id !== '') {
					$selected[] = $service_id;
				}
			}
		}

		if (!empty($selected)) {
			return array_values(array_unique($selected));
		}

		if (!empty($method_config['use_sms_service']) && !empty($method_config['sms_service_id'])) {
			$selected[] = sanitize_text_field((string) $method_config['sms_service_id']);
		}

		return array_values(array_unique(array_filter($selected)));
	}

	private function extract_available_service_ids($method_config) {
		$result = array();
		$services = isset($method_config['services']) && is_array($method_config['services']) ? $method_config['services'] : array();
		foreach ($services as $service) {
			if (!is_array($service)) {
				continue;
			}
			$service_id = isset($service['service_id']) ? sanitize_text_field((string) $service['service_id']) : '';
			if ($service_id !== '') {
				$result[] = $service_id;
			}
		}
		return array_values(array_unique($result));
	}

	private function sanitize_recursive($value) {
		if (is_array($value)) {
			$result = array();
			foreach ($value as $key => $item) {
				$result[sanitize_text_field((string) $key)] = $this->sanitize_recursive($item);
			}
			return $result;
		}

		if (is_scalar($value)) {
			return sanitize_text_field((string) $value);
		}

		return $value;
	}

	private function is_wc_order($order) {
		return $order && class_exists('WC_Order') && is_a($order, 'WC_Order');
	}

	private function has_wc_session() {
		return function_exists('WC') && WC() && isset(WC()->session) && WC()->session;
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

	private function should_log_live_checkout_events() {
		$settings = $this->settings_service->get_settings();
		$live_settings = isset($settings['live_checkout']) && is_array($settings['live_checkout']) ? $settings['live_checkout'] : array();
		return !empty($live_settings['debug_logging']);
	}
}
