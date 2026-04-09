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
		if (!function_exists('is_checkout') || !is_checkout()) {
			return;
		}

		$handle = 'lp-cargonizer-checkout-pickup-points';
		$src = plugin_dir_url(dirname(__FILE__)) . 'assets/js/checkout-pickup-points.js';
		wp_enqueue_script($handle, $src, array('jquery'), '1.0.0', true);
		wp_localize_script($handle, 'lpCargonizerPickupPointsConfig', array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'ajaxAction' => self::AJAX_ACTION,
			'ajaxGetAction' => LP_Cargonizer_Checkout_Pickup_Compatibility_Layer::AJAX_ACTION_GET,
			'nonce' => wp_create_nonce(self::NONCE_ACTION),
		));
	}

	public function render_pickup_selector_for_rate($rate, $index) {
		if (!is_a($rate, 'WC_Shipping_Rate')) {
			return;
		}

		$chosen_methods = $this->get_chosen_shipping_methods();
		$chosen_for_package = isset($chosen_methods[$index]) ? (string) $chosen_methods[$index] : '';
		$current_rate_id = (string) $rate->get_id();
		if ($chosen_for_package === '' || $chosen_for_package !== $current_rate_id) {
			return;
		}

		$pickup_points = $this->rate_meta($rate, 'krokedil_pickup_points');
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

		echo '<div class="lp-cargonizer-checkout-pickup-point" style="margin:8px 0 0 24px;">';
		echo '<label for="lp-cargonizer-pickup-' . esc_attr($index) . '" style="display:block;margin:0 0 6px;font-weight:600;">' . esc_html__('Pickup point', 'lp-cargonizer') . '</label>';
		echo '<select id="lp-cargonizer-pickup-' . esc_attr($index) . '" class="lp-cargonizer-pickup-point-select" data-rate-id="' . esc_attr($current_rate_id) . '" data-selected-pickup-point-id="' . esc_attr($selected_id) . '">';
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

	public function ajax_set_pickup_point() {
		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		$rate_id = isset($_POST['rate_id']) ? sanitize_text_field(wp_unslash((string) $_POST['rate_id'])) : '';
		$pickup_point_id = isset($_POST['pickup_point_id']) ? sanitize_text_field(wp_unslash((string) $_POST['pickup_point_id'])) : '';
		if ($rate_id === '' || $pickup_point_id === '') {
			wp_send_json_error(array('message' => 'Missing rate or pickup point.'), 400);
		}

		$available = $this->find_rate_pickup_point($rate_id, $pickup_point_id);
		if (empty($available)) {
			wp_send_json_error(array('message' => 'Pickup point not available for selected rate.'), 400);
		}

		$map = $this->get_session_map();
		$map[$rate_id] = array(
			'id' => $pickup_point_id,
			'point' => $available,
			'source' => 'customer_override',
		);
		$existing_points = $this->find_rate_pickup_points($rate_id);
		if (!empty($existing_points)) {
			$map[$rate_id]['pickup_points'] = $existing_points;
		}
		$this->set_session_map($map);

		wp_send_json_success(array(
			'rate_id' => $rate_id,
			'pickup_point_id' => $pickup_point_id,
		));
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
			$points = $this->rate_meta($rates[$rate_id], 'krokedil_pickup_points');
			if (!is_array($points)) {
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
			$points = $this->rate_meta($rates[$rate_id], 'krokedil_pickup_points');
			if (is_array($points) && !empty($points)) {
				return $points;
			}
		}
		return array();
	}

	private function get_shipping_packages_from_session() {
		if (!function_exists('WC') || !WC() || !WC()->session) {
			return array();
		}
		$package_count = 1;
		if (WC()->cart && method_exists(WC()->cart, 'get_shipping_packages')) {
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
		if (!function_exists('WC') || !WC() || !WC()->session) {
			return array();
		}
		$chosen = WC()->session->get('chosen_shipping_methods', array());
		return is_array($chosen) ? $chosen : array();
	}

	private function get_session_map() {
		if (!function_exists('WC') || !WC() || !WC()->session) {
			return array();
		}
		$value = WC()->session->get(self::SESSION_KEY, array());
		return is_array($value) ? $value : array();
	}

	private function set_session_map($map) {
		if (!function_exists('WC') || !WC() || !WC()->session) {
			return;
		}
		WC()->session->set(self::SESSION_KEY, is_array($map) ? $map : array());
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
}
