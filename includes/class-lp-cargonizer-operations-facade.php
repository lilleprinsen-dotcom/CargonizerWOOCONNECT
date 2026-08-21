<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Operations_Facade {
	const IDEMPOTENCY_META_KEY = '_lp_cargonizer_booking_idempotency';
	const LOCK_TTL_SECONDS = 120;

	/** @var LP_Cargonizer_Connector */
	private $connector;

	public function __construct($connector) {
		$this->connector = $connector;
	}

	public function get_capabilities() {
		return $this->connector->operations_get_capabilities();
	}

	public function get_order_booking_context($order_id) {
		return $this->connector->operations_get_order_booking_context($order_id);
	}

	public function get_shipping_options($order_id = 0, $sender_profile_id = '') {
		return $this->connector->operations_get_shipping_options($order_id, $sender_profile_id);
	}

	public function estimate_shipping($order_id, $packages, $selected_methods, $sender_profile_id = '', $recipient_override = array()) {
		return $this->connector->operations_estimate_shipping($order_id, $packages, $selected_methods, $sender_profile_id, $recipient_override);
	}

	public function get_servicepartners($order_id, $method, $sender_profile_id = '', $recipient = array()) {
		return $this->connector->operations_get_servicepartners($order_id, $method, $sender_profile_id, $recipient);
	}

	public function get_printers($wordpress_user_id = null) {
		return $this->connector->operations_get_printers($wordpress_user_id);
	}

	public function get_booking_state($order_id) {
		return $this->connector->operations_get_booking_state($order_id);
	}

	public function book_shipment($order_id, $booking_request, $actor_context = array()) {
		$order_id = absint($order_id);
		if ($order_id < 1) {
			return new WP_Error('lp_cargonizer_order_missing', 'Mangler ordre-ID.');
		}
		if (!is_array($booking_request)) {
			return new WP_Error('lp_cargonizer_booking_request_invalid', 'Booking request må være et array.');
		}

		$idempotency_key = isset($booking_request['idempotency_key']) ? sanitize_text_field((string) $booking_request['idempotency_key']) : '';
		if ($idempotency_key === '') {
			return new WP_Error('lp_cargonizer_idempotency_key_missing', 'Mangler idempotency key for booking.');
		}

		$fingerprint = $this->fingerprint_booking_request($booking_request);
		$existing = $this->get_idempotency_record($order_id, $idempotency_key);
		if (is_array($existing)) {
			$existing_fingerprint = isset($existing['fingerprint']) ? (string) $existing['fingerprint'] : '';
			if ($existing_fingerprint !== '' && !hash_equals($existing_fingerprint, $fingerprint)) {
				return new WP_Error('lp_cargonizer_idempotency_conflict', 'Samme idempotency key er brukt med et annet bookinginnhold.');
			}
			if (isset($existing['result']) && is_array($existing['result'])) {
				$result = $existing['result'];
				$result['idempotency'] = array('status' => 'replayed', 'key' => $idempotency_key);
				return $result;
			}
			if (isset($existing['error']) && is_array($existing['error'])) {
				return new WP_Error(
					isset($existing['error']['code']) ? (string) $existing['error']['code'] : 'lp_cargonizer_previous_booking_failed',
					isset($existing['error']['message']) ? (string) $existing['error']['message'] : 'Tidligere bookingforsøk med denne idempotency key feilet.',
					isset($existing['error']['data']) && is_array($existing['error']['data']) ? $existing['error']['data'] : array()
				);
			}
		}

		$lock_key = $this->get_lock_key($order_id, $idempotency_key);
		if (!$this->acquire_lock($lock_key)) {
			return new WP_Error('lp_cargonizer_booking_in_progress', 'Booking med samme idempotency key pågår allerede.');
		}

		try {
			$result = $this->connector->operations_book_shipment($order_id, $booking_request, $actor_context);
			if (is_wp_error($result)) {
				$this->store_idempotency_error($order_id, $idempotency_key, $fingerprint, $result);
				return $result;
			}
			if (is_array($result)) {
				$result['idempotency'] = array('status' => 'created', 'key' => $idempotency_key);
				$this->store_idempotency_result($order_id, $idempotency_key, $fingerprint, $result);
			}
			return $result;
		} catch (Throwable $throwable) {
			$error = new WP_Error('lp_cargonizer_execution_unknown', 'Bookingstatus er ukjent etter en teknisk feil. Ikke prøv samme booking blindt på nytt før ordren er avklart manuelt.', array(
				'execution_unknown' => true,
				'message' => $throwable->getMessage(),
			));
			$this->store_idempotency_error($order_id, $idempotency_key, $fingerprint, $error);
			return $error;
		} finally {
			$this->release_lock($lock_key);
		}
	}

	public function reprint_cargonizer_labels($order_id, $printer_id, $actor_context = array()) {
		return $this->connector->operations_reprint_cargonizer_labels($order_id, $printer_id, $actor_context);
	}

	public function get_posten_job_state($job_id) {
		if (!class_exists('LP_Cargonizer_Posten_Label_Automation')) {
			return new WP_Error('lp_cargonizer_posten_unavailable', 'Posten robot er ikke tilgjengelig.');
		}
		return LP_Cargonizer_Posten_Label_Automation::instance()->operations_get_job_state($job_id);
	}

	public function reprint_posten_labels($job_id, $printer_id, $package_index = 0, $actor_context = array()) {
		if (!class_exists('LP_Cargonizer_Posten_Label_Automation')) {
			return new WP_Error('lp_cargonizer_posten_unavailable', 'Posten robot er ikke tilgjengelig.');
		}
		return LP_Cargonizer_Posten_Label_Automation::instance()->operations_reprint_labels($job_id, $printer_id, $package_index, $actor_context);
	}

	public function cancel_posten_job($job_id, $actor_context = array()) {
		if (!class_exists('LP_Cargonizer_Posten_Label_Automation')) {
			return new WP_Error('lp_cargonizer_posten_unavailable', 'Posten robot er ikke tilgjengelig.');
		}
		return LP_Cargonizer_Posten_Label_Automation::instance()->operations_cancel_job($job_id, $actor_context);
	}

	private function fingerprint_booking_request($booking_request) {
		$normalized = $booking_request;
		unset($normalized['idempotency_key']);
		$this->recursive_ksort($normalized);
		return hash('sha256', wp_json_encode($normalized));
	}

	private function recursive_ksort(&$value) {
		if (!is_array($value)) {
			return;
		}
		ksort($value);
		foreach ($value as &$child) {
			$this->recursive_ksort($child);
		}
		unset($child);
	}

	private function get_idempotency_record($order_id, $idempotency_key) {
		$records = get_post_meta($order_id, self::IDEMPOTENCY_META_KEY, true);
		if (!is_array($records)) {
			return null;
		}
		$key = $this->normalize_idempotency_storage_key($idempotency_key);
		return isset($records[$key]) && is_array($records[$key]) ? $records[$key] : null;
	}

	private function store_idempotency_result($order_id, $idempotency_key, $fingerprint, $result) {
		$this->store_idempotency_record($order_id, $idempotency_key, array(
			'fingerprint' => $fingerprint,
			'created_at_gmt' => gmdate('Y-m-d H:i:s'),
			'result' => is_array($result) ? $result : array(),
		));
	}

	private function store_idempotency_error($order_id, $idempotency_key, $fingerprint, $error) {
		$data = is_wp_error($error) ? $error->get_error_data() : array();
		if (!is_array($data)) {
			$data = array('raw' => $data);
		}
		$this->store_idempotency_record($order_id, $idempotency_key, array(
			'fingerprint' => $fingerprint,
			'created_at_gmt' => gmdate('Y-m-d H:i:s'),
			'error' => array(
				'code' => is_wp_error($error) ? $error->get_error_code() : 'lp_cargonizer_booking_error',
				'message' => is_wp_error($error) ? $error->get_error_message() : 'Booking feilet.',
				'data' => $data,
			),
		));
	}

	private function store_idempotency_record($order_id, $idempotency_key, $record) {
		$records = get_post_meta($order_id, self::IDEMPOTENCY_META_KEY, true);
		if (!is_array($records)) {
			$records = array();
		}
		$records[$this->normalize_idempotency_storage_key($idempotency_key)] = $record;
		update_post_meta($order_id, self::IDEMPOTENCY_META_KEY, $records);
	}

	private function normalize_idempotency_storage_key($idempotency_key) {
		return hash('sha256', (string) $idempotency_key);
	}

	private function get_lock_key($order_id, $idempotency_key) {
		return 'lp_cargonizer_booking_lock_' . hash('sha256', absint($order_id) . '|' . (string) $idempotency_key);
	}

	private function acquire_lock($lock_key) {
		$now = time();
		$existing = get_option($lock_key, null);
		if ($existing !== null && (int) $existing > $now - self::LOCK_TTL_SECONDS) {
			return false;
		}
		if ($existing !== null) {
			delete_option($lock_key);
		}
		return add_option($lock_key, (string) $now, '', 'no');
	}

	private function release_lock($lock_key) {
		delete_option($lock_key);
	}
}
