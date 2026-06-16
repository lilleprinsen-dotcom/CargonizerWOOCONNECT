<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Posten_Printer_Choice_Compat {
	const AJAX_ACTION_QUEUE = 'lp_cargonizer_queue_posten_label_job';
	const PRINT_SNAPSHOT_KEY = 'print';

	public static function register_hooks() {
		add_action('wp_ajax_' . self::AJAX_ACTION_QUEUE, array(__CLASS__, 'ajax_queue_posten_label_job'), 9);
		add_filter('option_' . LP_Cargonizer_Connector::OPTION_KEY, array(__CLASS__, 'override_posten_robot_printer_for_completion'), 20, 1);
	}

	public static function ajax_queue_posten_label_job() {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(array('message' => 'Ingen tilgang.'), 403);
		}

		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (!wp_verify_nonce($nonce, LP_Cargonizer_Posten_Label_Automation::get_queue_nonce_action())) {
			wp_send_json_error(array('message' => 'Ugyldig nonce.'), 403);
		}

		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		$order = $order_id ? wc_get_order($order_id) : false;
		if (!$order) {
			wp_send_json_error(array('message' => 'Ordre ikke funnet.'), 404);
		}

		$packages = isset($_POST['packages']) && is_array($_POST['packages']) ? wp_unslash($_POST['packages']) : array();
		$methods = isset($_POST['methods']) && is_array($_POST['methods']) ? wp_unslash($_POST['methods']) : array();
		if (empty($packages)) {
			wp_send_json_error(array('message' => 'Mangler kolli.'), 400);
		}
		if (count($methods) !== 1) {
			wp_send_json_error(array('message' => 'Velg noyaktig en fraktmetode for Posten labeljobb.'), 400);
		}

		$method_payload = self::sanitize_method_payload($methods[0]);
		$result = LP_Cargonizer_Posten_Label_Automation::instance()->queue_from_admin_request($order, $method_payload, $packages, array(
			'warehouse_profile_id' => isset($_POST['warehouse_profile_id']) ? sanitize_key(wp_unslash($_POST['warehouse_profile_id'])) : '',
			'notify_email_to_consignee' => isset($_POST['notify_email_to_consignee']) ? (bool) self::sanitize_checkbox_value(wp_unslash($_POST['notify_email_to_consignee'])) : false,
			'source' => 'admin_manual_norgespakke',
		));

		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()), 400);
		}

		$job_id = self::get_result_job_id($result);
		if ($job_id !== '') {
			self::store_job_print_snapshot($job_id, self::build_print_snapshot_from_request());
		}

		wp_send_json_success($result);
	}

	public static function override_posten_robot_printer_for_completion($settings) {
		$job_id = self::get_completion_job_id_from_request();
		if ($job_id === '') {
			return $settings;
		}

		$print_snapshot = self::get_job_print_snapshot($job_id);
		if (empty($print_snapshot) || empty($print_snapshot['direct_print_requested'])) {
			return $settings;
		}

		if (!is_array($settings)) {
			$settings = array();
		}
		if (!isset($settings['posten_robot']) || !is_array($settings['posten_robot'])) {
			$settings['posten_robot'] = array();
		}

		$settings['posten_robot']['direct_print_enabled'] = !empty($print_snapshot['direct_print_enabled']) ? 1 : 0;
		$settings['posten_robot']['direct_print_printer_id'] = isset($print_snapshot['direct_print_printer_id']) ? sanitize_text_field((string) $print_snapshot['direct_print_printer_id']) : '';

		return $settings;
	}

	private static function get_result_job_id($result) {
		if (isset($result['posten_label_job']['job_id'])) {
			return self::sanitize_job_id($result['posten_label_job']['job_id']);
		}
		if (isset($result['booking']['posten_label_job_id'])) {
			return self::sanitize_job_id($result['booking']['posten_label_job_id']);
		}

		return '';
	}

	private static function build_print_snapshot_from_request() {
		$has_printer_choice = isset($_POST['printer_choice']) && !is_array($_POST['printer_choice']);
		if (!$has_printer_choice) {
			return array();
		}

		$printer_id = self::resolve_effective_printer_choice(wp_unslash($_POST['printer_choice']));

		return array(
			'direct_print_requested' => 1,
			'direct_print_enabled' => $printer_id !== '' ? 1 : 0,
			'direct_print_printer_id' => $printer_id,
		);
	}

	private static function store_job_print_snapshot($job_id, $print_snapshot) {
		$job_id = self::sanitize_job_id($job_id);
		if ($job_id === '' || empty($print_snapshot)) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'lp_cargonizer_posten_jobs';
		$row = $wpdb->get_row($wpdb->prepare("SELECT shipping_json FROM {$table} WHERE job_id = %s LIMIT 1", $job_id));
		if (!$row) {
			return;
		}

		$shipping = json_decode(isset($row->shipping_json) ? (string) $row->shipping_json : '', true);
		if (!is_array($shipping)) {
			$shipping = array();
		}
		$shipping[self::PRINT_SNAPSHOT_KEY] = $print_snapshot;

		$wpdb->update(
			$table,
			array(
				'shipping_json' => wp_json_encode($shipping, JSON_UNESCAPED_UNICODE),
				'updated_at_gmt' => gmdate('Y-m-d H:i:s'),
			),
			array('job_id' => $job_id),
			array('%s', '%s'),
			array('%s')
		);
	}

	private static function get_job_print_snapshot($job_id) {
		$job_id = self::sanitize_job_id($job_id);
		if ($job_id === '') {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'lp_cargonizer_posten_jobs';
		$shipping_json = $wpdb->get_var($wpdb->prepare("SELECT shipping_json FROM {$table} WHERE job_id = %s LIMIT 1", $job_id));
		$shipping = json_decode((string) $shipping_json, true);
		if (!is_array($shipping) || !isset($shipping[self::PRINT_SNAPSHOT_KEY]) || !is_array($shipping[self::PRINT_SNAPSHOT_KEY])) {
			return array();
		}

		$print = $shipping[self::PRINT_SNAPSHOT_KEY];
		return array(
			'direct_print_requested' => !empty($print['direct_print_requested']) ? 1 : 0,
			'direct_print_enabled' => !empty($print['direct_print_enabled']) ? 1 : 0,
			'direct_print_printer_id' => isset($print['direct_print_printer_id']) ? sanitize_text_field((string) $print['direct_print_printer_id']) : '',
		);
	}

	private static function get_completion_job_id_from_request() {
		$uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
		if ($uri === '') {
			return '';
		}

		if (!preg_match('~/(?:posten-jobs|posten-label-jobs)/([A-Za-z0-9_-]+)/complete(?:[/?]|$)~', $uri, $matches)) {
			return '';
		}

		return self::sanitize_job_id(rawurldecode($matches[1]));
	}

	private static function resolve_effective_printer_choice($posted_printer_choice) {
		$choice = sanitize_text_field((string) $posted_printer_choice);
		if ($choice === '__default__') {
			$default_printer_id = get_user_meta(get_current_user_id(), 'lp_cargonizer_default_printer_id', true);
			$choice = is_scalar($default_printer_id) ? sanitize_text_field((string) $default_printer_id) : '';
		}

		return $choice;
	}

	private static function sanitize_method_payload($method) {
		$method = is_array($method) ? $method : array();
		$output = array();
		foreach ($method as $key => $value) {
			$clean_key = sanitize_key((string) $key);
			if ($clean_key === '') {
				continue;
			}
			$output[$clean_key] = self::sanitize_recursive($value);
		}

		return $output;
	}

	private static function sanitize_recursive($value) {
		if (is_array($value)) {
			$clean = array();
			foreach ($value as $key => $item) {
				$clean_key = is_int($key) ? $key : sanitize_key((string) $key);
				$clean[$clean_key] = self::sanitize_recursive($item);
			}
			return $clean;
		}
		if (is_bool($value)) {
			return $value;
		}
		if (is_numeric($value)) {
			return $value + 0;
		}

		return sanitize_text_field((string) $value);
	}

	private static function sanitize_checkbox_value($value) {
		if (is_bool($value)) {
			return $value ? 1 : 0;
		}

		return in_array(strtolower(trim((string) $value)), array('1', 'true', 'yes', 'on'), true) ? 1 : 0;
	}

	private static function sanitize_job_id($job_id) {
		return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $job_id);
	}
}
