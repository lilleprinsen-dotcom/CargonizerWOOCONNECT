<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Checkout_Pickup_Controller {
	const SESSION_KEY = 'lp_cargonizer_checkout_pickup_selection_map';
	const AJAX_ACTION = 'lp_cargonizer_set_checkout_pickup_point';
	const NONCE_ACTION = 'lp_cargonizer_checkout_pickup_point';

	public function register_hooks() {
		add_action('wp_enqueue_scripts', array($this, 'enqueue_checkout_assets'));
		add_action('woocommerce_after_shipping_rate', array($this, 'render_pickup_selector_for_rate'), 20, 2);
		add_action('wp_ajax_' . self::AJAX_ACTION, array($this, 'ajax_set_pickup_point'));
		add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, array($this, 'ajax_set_pickup_point'));
	}

	public function enqueue_checkout_assets() {
		if (!$this->is_checkout_or_order_pay_context()) {
			return;
		}

		$handle = 'lp-cargonizer-checkout-pickup-points';
		$src = plugin_dir_url(dirname(__FILE__)) . 'assets/js/checkout-pickup-points.js';
		$script_path = plugin_dir_path(dirname(__FILE__)) . 'assets/js/checkout-pickup-points.js';
		$script_version = file_exists($script_path) ? filemtime($script_path) : false;
		wp_enqueue_script($handle, $src, array('jquery'), $script_version ? (string) $script_version : '1.0.0', true);
		wp_localize_script($handle, 'lpCargonizerPickupPointsConfig', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'ajaxAction' => self::AJAX_ACTION,
			'ajaxGetAction' => LP_Cargonizer_Checkout_Pickup_Compatibility_Layer::AJAX_ACTION_GET,
			'nonce' => wp_create_nonce(self::NONCE_ACTION),
		));
	}

	public function render_pickup_selector_for_rate($rate, $index) {
		if (!$this->is_checkout_or_order_pay_context()) {
			return;
		}

		if (!is_a($rate, 'WC_Shipping_Rate')) {
			return;
		}

		$package_index = $this->resolve_package_index_for_rate($rate);
		if ($package_index < 0) {
			return;
		}

		$chosen_methods = $this->get_chosen_shipping_methods();
		$chosen_for_package = isset($chosen_methods[$package_index]) ? (string) $chosen_methods[$package_index] : '';
		$current_rate_id = (string) $rate->get_id();
		if ($chosen_for_package === '' || $chosen_for_package !== $current_rate_id) {
			return;
		}

		$pickup_points = LP_Cargonizer_Krokedil_Pickup_Meta_Helper::decode_pickup_points_meta($this->rate_meta($rate, 'krokedil_pickup_points'));
		$session_map = $this->get_session_map();
		if ((!is_array($pickup_points) || empty($pickup_points)) && isset($session_map[$current_rate_id]['pickup_points']) && is_array($session_map[$current_rate_id]['pickup_points'])) {
			$pickup_points = $session_map[$current_rate_id]['pickup_points'];
		}
		$is_pickup_capable = !empty($this->rate_meta($rate, 'lp_cargonizer_pickup_capable'));
		if ((!is_array($pickup_points) || empty($pickup_points)) && !$is_pickup_capable) {
			return;
		}

		$selected_id = (string) $this->rate_meta($rate, 'krokedil_selected_pickup_point_id');
		if (isset($session_map[$current_rate_id]['id'])) {
			$session_selected_id = sanitize_text_field((string) $session_map[$current_rate_id]['id']);
			if ($session_selected_id !== '') {
				$selected_id = $session_selected_id;
			}
		}
		if ($selected_id === '' && !empty($pickup_points[0]['id'])) {
			$selected_id = (string) $pickup_points[0]['id'];
		}
		if ($selected_id !== '' && is_array($pickup_points) && !empty($pickup_points)) {
			$has_selected = false;
			foreach ($pickup_points as $pickup_point) {
				if (!is_array($pickup_point)) {
					continue;
				}
				if (isset($pickup_point['id']) && (string) $pickup_point['id'] === $selected_id) {
					$has_selected = true;
					break;
				}
			}
			if (!$has_selected && !empty($pickup_points[0]['id'])) {
				$selected_id = (string) $pickup_points[0]['id'];
			}
		}

		echo '<div class="lp-cargonizer-checkout-pickup-point" style="margin:8px 0 0 24px;">';
		$selector_id = 'lp-cargonizer-pickup-' . $package_index . '-' . sanitize_title($current_rate_id);
		echo '<label for="' . esc_attr($selector_id) . '" style="display:block;margin:0 0 6px;font-weight:600;">' . esc_html__('Pickup point', 'lp-cargonizer') . '</label>';
		$selector_state = (is_array($pickup_points) && !empty($pickup_points)) ? 'loaded' : 'loading';
		echo '<select id="' . esc_attr($selector_id) . '" class="lp-cargonizer-pickup-point-select" data-rate-id="' . esc_attr($current_rate_id) . '" data-selected-pickup-point-id="' . esc_attr($selected_id) . '" data-state="' . esc_attr($selector_state) . '">';
		if (is_array($pickup_points) && !empty($pickup_points)) {
			foreach ($pickup_points as $point) {
				if (!is_array($point)) {
					continue;
				}
				$point_id = isset($point['id']) ? (string) $point['id'] : '';
				if ($point_id === '') {
					continue;
				}
				$label = isset($point['label']) ? (string) $point['label'] : $point_id;
				echo '<option value="' . esc_attr($point_id) . '" ' . selected($selected_id, $point_id, false) . '>' . esc_html($label) . '</option>';
			}
		} else {
			echo '<option value="">' . esc_html__('Fetching pickup points…', 'lp-cargonizer') . '</option>';
		}
		echo '</select>';
		echo '</div>';
	}

	private function is_checkout_or_order_pay_context() {
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
		if ($request_path !== '' && (strpos($request_path, '/wc/store/checkout') !== false || strpos($request_path, '/wc/store/v1/checkout') !== false)) {
			return true;
		}

		return false;
	}

	private function resolve_package_index_for_rate($rate) {
		$current_rate_id = is_a($rate, 'WC_Shipping_Rate') ? (string) $rate->get_id() : '';
		if ($current_rate_id === '') {
			return -1;
		}

		$shipping_packages = $this->get_shipping_packages_from_session();
		if (empty($shipping_packages)) {
			return -1;
		}

		$chosen_methods = $this->get_chosen_shipping_methods();
		$fallback_package_index = -1;
		foreach ($shipping_packages as $package_index => $shipping_package) {
			$rates = isset($shipping_package['rates']) && is_array($shipping_package['rates']) ? $shipping_package['rates'] : array();
			$has_current_rate = isset($rates[$current_rate_id]) && is_a($rates[$current_rate_id], 'WC_Shipping_Rate');
			if (!$has_current_rate) {
				continue;
			}
			if ($fallback_package_index < 0) {
				$fallback_package_index = (int) $package_index;
			}
			$chosen_for_package = isset($chosen_methods[$package_index]) ? (string) $chosen_methods[$package_index] : '';
			if ($chosen_for_package === $current_rate_id) {
				return (int) $package_index;
			}
		}

		return $fallback_package_index;
	}

	public function ajax_set_pickup_point() {
		try {
			check_ajax_referer(self::NONCE_ACTION, 'nonce');

			$rate_id = isset($_POST['rate_id']) ? sanitize_text_field(wp_unslash((string) $_POST['rate_id'])) : '';
			$pickup_point_id = isset($_POST['pickup_point_id']) ? sanitize_text_field(wp_unslash((string) $_POST['pickup_point_id'])) : '';
			if ($rate_id === '' || $pickup_point_id === '') {
				$this->log_live_checkout_event('debug', 'Rejected pickup point update: missing rate or pickup id.');
				wp_send_json_error(array('message' => 'Missing rate or pickup point.'), 400);
			}

			$available = $this->find_rate_pickup_point($rate_id, $pickup_point_id);
			$fallback_applied = false;
			if (empty($available)) {
				$fallback_points = $this->find_rate_pickup_points($rate_id);
				$fallback = !empty($fallback_points) && is_array($fallback_points) ? reset($fallback_points) : array();
				if (!is_array($fallback) || empty($fallback['id'])) {
					$this->log_live_checkout_event('debug', 'Rejected pickup point update: pickup id not available for selected rate.', array(
						'rate_id' => $rate_id,
					));
					wp_send_json_error(array('message' => 'Pickup point not available for selected rate.'), 400);
				}
				$available = $fallback;
				$pickup_point_id = sanitize_text_field((string) $available['id']);
				$fallback_applied = true;
			}

			$map = $this->get_session_map();
			$map[$rate_id] = array(
				'id' => $pickup_point_id,
				'point' => $available,
				'source' => 'customer_override',
				'rate_context' => array(
					'rate_id' => $rate_id,
				),
			);
			$this->set_session_map($map);
			$this->set_generic_krokedil_selection_session($available, $pickup_point_id);

			wp_send_json_success(array(
				'rate_id' => $rate_id,
				'pickup_point_id' => $pickup_point_id,
				'fallback_applied' => $fallback_applied,
			));
		} catch (Throwable $throwable) {
			$this->log_live_checkout_event('error', 'Pickup point update failed unexpectedly.', array(
				'error' => $throwable->getMessage(),
			));
			wp_send_json_error(array('message' => 'Pickup point could not be updated.'), 500);
		}
	}

	private function find_rate_pickup_point($rate_id, $pickup_point_id) {
		$session_map = $this->get_session_map();
		if (isset($session_map[$rate_id]['pickup_points']) && is_array($session_map[$rate_id]['pickup_points'])) {
			foreach ($session_map[$rate_id]['pickup_points'] as $point) {
				if (!is_array($point)) {
					continue;
				}
				$point_id = isset($point['id']) ? (string) $point['id'] : '';
				if ($point_id === $pickup_point_id) {
					return $point;
				}
			}
		}

		$shipping_packages = $this->get_shipping_packages_from_session();
		foreach ($shipping_packages as $shipping_package) {
			$rates = isset($shipping_package['rates']) && is_array($shipping_package['rates']) ? $shipping_package['rates'] : array();
			if (!isset($rates[$rate_id]) || !is_a($rates[$rate_id], 'WC_Shipping_Rate')) {
				continue;
			}
			$points = LP_Cargonizer_Krokedil_Pickup_Meta_Helper::decode_pickup_points_meta($this->rate_meta($rates[$rate_id], 'krokedil_pickup_points'));
			if (empty($points)) {
				return array();
			}
			foreach ($points as $point) {
				if (!is_array($point)) {
					continue;
				}
				$point_id = isset($point['id']) ? (string) $point['id'] : '';
				if ($point_id === $pickup_point_id) {
					return $point;
				}
			}
		}

		return array();
	}

	private function find_rate_pickup_points($rate_id) {
		$shipping_packages = $this->get_shipping_packages_from_session();
		foreach ($shipping_packages as $shipping_package) {
			$rates = isset($shipping_package['rates']) && is_array($shipping_package['rates']) ? $shipping_package['rates'] : array();
			if (!isset($rates[$rate_id]) || !is_a($rates[$rate_id], 'WC_Shipping_Rate')) {
				continue;
			}
			$points = LP_Cargonizer_Krokedil_Pickup_Meta_Helper::decode_pickup_points_meta($this->rate_meta($rates[$rate_id], 'krokedil_pickup_points'));
			if (!empty($points)) {
				return $points;
			}
		}
		return array();
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

	private function get_chosen_shipping_methods() {
		if (!$this->has_wc_session()) {
			return array();
		}
		$chosen = WC()->session->get('chosen_shipping_methods', array());
		return is_array($chosen) ? $chosen : array();
	}

	private function get_session_map() {
		if (!$this->has_wc_session()) {
			return array();
		}
		$value = WC()->session->get(self::SESSION_KEY, array());
		return is_array($value) ? $value : array();
	}

	private function set_session_map($map) {
		if (!$this->has_wc_session()) {
			$this->log_live_checkout_event('debug', 'Skipped writing pickup selection session map: WC session unavailable.');
			return;
		}
		WC()->session->set(self::SESSION_KEY, $this->sanitize_pickup_session_map($map));
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
			$id = isset($row['id']) ? sanitize_text_field((string) $row['id']) : '';
			$point = isset($row['point']) && is_array($row['point']) ? $row['point'] : array();
			$pickup_points = isset($row['pickup_points']) && is_array($row['pickup_points']) ? array_values($row['pickup_points']) : array();
			if (count($pickup_points) > 20) {
				$pickup_points = array_slice($pickup_points, 0, 20);
			}
			$normalized[$rate_id] = array(
				'id' => $id,
				'point' => $point,
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

	private function has_wc_session() {
		return function_exists('WC') && WC() && isset(WC()->session) && WC()->session;
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
		if (!class_exists('LP_Cargonizer_Settings_Service')) {
			return false;
		}
		$settings_service = new LP_Cargonizer_Settings_Service(LP_Cargonizer_Connector::OPTION_KEY, LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY);
		$settings = $settings_service->get_settings();
		$live_settings = isset($settings['live_checkout']) && is_array($settings['live_checkout']) ? $settings['live_checkout'] : array();
		return !empty($live_settings['debug_logging']);
	}
}
