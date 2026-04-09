<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Checkout_Pickup_Compatibility_Layer {
	const AJAX_ACTION_GET = 'lp_cargonizer_get_checkout_pickup_points';

	public function register_hooks() {
		add_action('wp_ajax_' . self::AJAX_ACTION_GET, array($this, 'ajax_get_checkout_pickup_points'));
		add_action('wp_ajax_nopriv_' . self::AJAX_ACTION_GET, array($this, 'ajax_get_checkout_pickup_points'));
		add_filter('woocommerce_package_rates', array($this, 'enrich_package_rates_with_compatibility_meta'), 20, 2);
	}

	public function enrich_package_rates_with_compatibility_meta($rates, $package) {
		if (!is_array($rates)) {
			return $rates;
		}

		$session_map = $this->get_pickup_selection_session_map();
		foreach ($rates as $rate_id => $rate) {
			if (!is_a($rate, 'WC_Shipping_Rate')) {
				continue;
			}

			$pickup_points = $this->rate_meta($rate, 'krokedil_pickup_points');
			if (!is_array($pickup_points) || empty($pickup_points)) {
				continue;
			}

			$normalized_rate_id = (string) $rate->get_id();
			$selected_id = (string) $this->rate_meta($rate, 'krokedil_selected_pickup_point_id');
			if ($normalized_rate_id !== '' && isset($session_map[$normalized_rate_id]['id'])) {
				$session_selected_id = sanitize_text_field((string) $session_map[$normalized_rate_id]['id']);
				if ($session_selected_id !== '') {
					$selected_id = $session_selected_id;
				}
			}

			$normalized = array(
				'pickup_points' => array_values($pickup_points),
				'selected_pickup_point_id' => $selected_id,
				'selected_pickup_point' => $this->resolve_point_by_id($pickup_points, $selected_id),
				'rate_id' => $normalized_rate_id,
			);

			$rate->add_meta_data('lp_cargonizer_pickup_point_context', $normalized, true);
		}

		return $rates;
	}

	public function ajax_get_checkout_pickup_points() {
		check_ajax_referer(LP_Cargonizer_Checkout_Pickup_Controller::NONCE_ACTION, 'nonce');

		$packages = $this->get_shipping_packages_from_session();
		$payload = array();
		$session_map = $this->get_pickup_selection_session_map();

		foreach ($packages as $package_index => $shipping_package) {
			$rates = isset($shipping_package['rates']) && is_array($shipping_package['rates']) ? $shipping_package['rates'] : array();
			foreach ($rates as $rate) {
				if (!is_a($rate, 'WC_Shipping_Rate')) {
					continue;
				}

				$pickup_points = $this->rate_meta($rate, 'krokedil_pickup_points');
				if (!is_array($pickup_points) || empty($pickup_points)) {
					continue;
				}

				$rate_id = (string) $rate->get_id();
				$selected_id = (string) $this->rate_meta($rate, 'krokedil_selected_pickup_point_id');
				if ($rate_id !== '' && isset($session_map[$rate_id]['id'])) {
					$session_selected_id = sanitize_text_field((string) $session_map[$rate_id]['id']);
					if ($session_selected_id !== '') {
						$selected_id = $session_selected_id;
					}
				}

				$payload[] = array(
					'package_index' => (int) $package_index,
					'rate_id' => $rate_id,
					'method_id' => method_exists($rate, 'get_method_id') ? (string) $rate->get_method_id() : '',
					'pickup_points' => array_values($pickup_points),
					'krokedil_pickup_points' => array_values($pickup_points),
					'selected_pickup_point_id' => $selected_id,
					'krokedil_selected_pickup_point_id' => $selected_id,
					'selected_pickup_point' => $this->resolve_point_by_id($pickup_points, $selected_id),
					'krokedil_selected_pickup_point' => $this->resolve_point_by_id($pickup_points, $selected_id),
				);
			}
		}

		wp_send_json_success(array(
			'items' => $payload,
		));
	}

	private function resolve_point_by_id($pickup_points, $point_id) {
		$point_id = sanitize_text_field((string) $point_id);
		if ($point_id === '') {
			$first = reset($pickup_points);
			return is_array($first) ? $first : array();
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

		$first = reset($pickup_points);
		return is_array($first) ? $first : array();
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

	private function get_pickup_selection_session_map() {
		if (!function_exists('WC') || !WC() || !WC()->session) {
			return array();
		}
		$value = WC()->session->get(LP_Cargonizer_Checkout_Pickup_Controller::SESSION_KEY, array());
		return is_array($value) ? $value : array();
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
