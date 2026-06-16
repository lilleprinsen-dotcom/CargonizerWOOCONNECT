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
		add_filter('rest_request_after_callbacks', array(__CLASS__, 'add_direct_print_reason_note'), 20, 3);
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

	public static function add_direct_print_reason_note($response, $handler, $request) {
		if (is_wp_error($response)) {
			return $response;
		}

		$job_id = self::get_completion_job_id_from_request();
		if ($job_id === '') {
			return $response;
		}

		$print_snapshot = self::get_job_print_snapshot($job_id);
		if (empty($print_snapshot['direct_print_requested']) || empty($print_snapshot['direct_print_enabled']) || !empty($print_snapshot['reason_note_added_at_gmt'])) {
			return $response;
		}

		$data = self::get_response_data($response);
		$printed = isset($data['printed']) ? (int) $data['printed'] : 0;
		if ($printed === 1) {
			return $response;
		}

		$reason = self::build_direct_print_not_printed_reason($data, $print_snapshot);
		if ($reason !== '' && self::add_direct_print_reason_order_note($job_id, $reason)) {
			self::mark_direct_print_reason_note_added($job_id);
		}

		return $response;
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
			'reason_note_added_at_gmt' => '',
		);
	}

	private static function store_job_print_snapshot($job_id, $print_snapshot) {
		$job_id = self::sanitize_job_id($job_id);
		if ($job_id === '' || empty($print_snapshot)) {
			return;
		}

		$shipping = self::get_job_shipping_snapshot($job_id);
		if ($shipping === null) {
			return;
		}

		$shipping[self::PRINT_SNAPSHOT_KEY] = $print_snapshot;
		self::update_job_shipping_snapshot($job_id, $shipping);
	}

	private static function get_job_print_snapshot($job_id) {
		$shipping = self::get_job_shipping_snapshot($job_id);
		if ($shipping === null || !isset($shipping[self::PRINT_SNAPSHOT_KEY]) || !is_array($shipping[self::PRINT_SNAPSHOT_KEY])) {
			return array();
		}

		$print = $shipping[self::PRINT_SNAPSHOT_KEY];
		return array(
			'direct_print_requested' => !empty($print['direct_print_requested']) ? 1 : 0,
			'direct_print_enabled' => !empty($print['direct_print_enabled']) ? 1 : 0,
			'direct_print_printer_id' => isset($print['direct_print_printer_id']) ? sanitize_text_field((string) $print['direct_print_printer_id']) : '',
			'reason_note_added_at_gmt' => isset($print['reason_note_added_at_gmt']) ? sanitize_text_field((string) $print['reason_note_added_at_gmt']) : '',
		);
	}

	private static function get_job_shipping_snapshot($job_id) {
		$job_id = self::sanitize_job_id($job_id);
		if ($job_id === '') {
			return null;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'lp_cargonizer_posten_jobs';
		$row = $wpdb->get_row($wpdb->prepare("SELECT shipping_json FROM {$table} WHERE job_id = %s LIMIT 1", $job_id));
		if (!$row) {
			return null;
		}

		$shipping = json_decode(isset($row->shipping_json) ? (string) $row->shipping_json : '', true);
		return is_array($shipping) ? $shipping : array();
	}

	private static function update_job_shipping_snapshot($job_id, $shipping) {
		$job_id = self::sanitize_job_id($job_id);
		if ($job_id === '' || !is_array($shipping)) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'lp_cargonizer_posten_jobs';
		$updated = $wpdb->update(
			$table,
			array(
				'shipping_json' => wp_json_encode($shipping, JSON_UNESCAPED_UNICODE),
				'updated_at_gmt' => gmdate('Y-m-d H:i:s'),
			),
			array('job_id' => $job_id),
			array('%s', '%s'),
			array('%s')
		);

		return $updated !== false;
	}

	private static function mark_direct_print_reason_note_added($job_id) {
		$shipping = self::get_job_shipping_snapshot($job_id);
		if ($shipping === null || !isset($shipping[self::PRINT_SNAPSHOT_KEY]) || !is_array($shipping[self::PRINT_SNAPSHOT_KEY])) {
			return;
		}

		$shipping[self::PRINT_SNAPSHOT_KEY]['reason_note_added_at_gmt'] = gmdate('Y-m-d H:i:s');
		self::update_job_shipping_snapshot($job_id, $shipping);
	}

	private static function get_response_data($response) {
		if (is_object($response) && method_exists($response, 'get_data')) {
			$data = $response->get_data();
			return is_array($data) ? $data : array();
		}

		return is_array($response) ? $response : array();
	}

	private static function build_direct_print_not_printed_reason($data, $print_snapshot) {
		$printer_id = isset($print_snapshot['direct_print_printer_id']) ? sanitize_text_field((string) $print_snapshot['direct_print_printer_id']) : '';
		$reasons = self::collect_print_failure_reasons($data);
		if (empty($reasons)) {
			$reasons[] = empty($data['print_results'])
				? 'Roboten returnerte ingen DirectPrint-resultater for jobben.'
				: 'Roboten markerte ikke utskriften som vellykket, men returnerte ingen detaljert feilmelding.';
		}

		$message = 'DirectPrint var valgt';
		if ($printer_id !== '') {
			$message .= ' for printer ' . $printer_id;
		}
		$message .= ', men etiketten ble ikke markert som printet. Årsak: ' . implode(' | ', array_values(array_unique($reasons))) . '.';

		return $message;
	}

	private static function collect_print_failure_reasons($data) {
		$reasons = array();
		$print_results = isset($data['print_results']) && is_array($data['print_results']) ? $data['print_results'] : array();
		foreach ($print_results as $result) {
			if (!is_array($result) || !empty($result['printed'])) {
				continue;
			}
			$package_index = isset($result['package_index']) ? absint($result['package_index']) : 0;
			$error = isset($result['error']) ? sanitize_text_field((string) $result['error']) : '';
			if ($error === '' && isset($result['print_error'])) {
				$error = sanitize_text_field((string) $result['print_error']);
			}
			$prefix = $package_index > 0 ? 'Kolli ' . $package_index . ': ' : '';
			$reasons[] = $prefix . ($error !== '' ? $error : 'Ingen feilmelding fra DirectPrint-responsen.');
		}

		$package_results = isset($data['package_results']) && is_array($data['package_results']) ? $data['package_results'] : array();
		foreach ($package_results as $result) {
			if (!is_array($result) || !empty($result['printed']) || empty($result['print_error'])) {
				continue;
			}
			$package_index = isset($result['package_index']) ? absint($result['package_index']) : 0;
			$prefix = $package_index > 0 ? 'Kolli ' . $package_index . ': ' : '';
			$reasons[] = $prefix . sanitize_text_field((string) $result['print_error']);
		}

		return array_values(array_filter($reasons, 'strlen'));
	}

	private static function add_direct_print_reason_order_note($job_id, $reason) {
		$job_id = self::sanitize_job_id($job_id);
		$reason = sanitize_text_field((string) $reason);
		if ($job_id === '' || $reason === '') {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'lp_cargonizer_posten_jobs';
		$order_id = absint($wpdb->get_var($wpdb->prepare("SELECT order_id FROM {$table} WHERE job_id = %s LIMIT 1", $job_id)));
		if ($order_id < 1) {
			return false;
		}

		$order = function_exists('wc_get_order') ? wc_get_order($order_id) : false;
		if (!$order || !method_exists($order, 'add_order_note')) {
			return false;
		}

		$order->add_order_note($reason, false, true);
		return true;
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
