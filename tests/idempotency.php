<?php

define('ABSPATH', __DIR__);

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct($code, $message, $data = array()) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return trim((string) $value); }
function wp_json_encode($value) { return json_encode($value); }
function gmdate_stub() { return '2026-08-21 00:00:00'; }

$options = array();
function get_option($key, $default = null) { global $options; return array_key_exists($key, $options) ? $options[$key] : $default; }
function add_option($key, $value) { global $options; if (array_key_exists($key, $options)) return false; $options[$key] = $value; return true; }
function delete_option($key) { global $options; unset($options[$key]); return true; }

class WC_Order {
	public $meta = array();
	public function get_meta($key) { return isset($this->meta[$key]) ? $this->meta[$key] : null; }
	public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
	public function save_meta_data() {}
}
$order = new WC_Order();
function wc_get_order($id) { global $order; return $id === 42 ? $order : false; }

class Fake_Connector {
	public $calls = 0;
	public function operations_book_shipment($order_id, $request, $actor) {
		$this->calls++;
		if (!empty($request['force_unknown'])) return new WP_Error('lp_cargonizer_execution_unknown', 'Unknown', array('execution_unknown' => true));
		if (empty($request['confirm_execution'])) return array('state' => 'preflight_ready');
		return array('state' => 'booked', 'consignment_number' => 'TEST-1');
	}
}

require_once __DIR__ . '/../includes/class-lp-cargonizer-operations-facade.php';

function assert_true($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

$connector = new Fake_Connector();
$facade = new LP_Cargonizer_Operations_Facade($connector);
$base = array('idempotency_key' => 'same-key', 'packages' => array(array('weight' => 1)));
$preflight = $facade->book_shipment(42, $base, array());
assert_true($preflight['state'] === 'preflight_ready', 'Preflight did not complete.');
assert_true($preflight['idempotency']['status'] === 'not_persisted_preflight', 'Preflight was persisted as a booking.');

$created = $facade->book_shipment(42, array_merge($base, array('confirm_execution' => true)), array());
assert_true($created['idempotency']['status'] === 'created', 'First execution was not created.');
$replayed = $facade->book_shipment(42, array_merge($base, array('confirm_execution' => true)), array());
assert_true($replayed['idempotency']['status'] === 'replayed', 'Same request was not replayed.');
assert_true($connector->calls === 2, 'Provider boundary was invoked more than once after preflight.');

$conflict = $facade->book_shipment(42, array('idempotency_key' => 'same-key', 'confirm_execution' => true, 'packages' => array(array('weight' => 2))), array());
assert_true(is_wp_error($conflict) && $conflict->get_error_code() === 'lp_cargonizer_idempotency_conflict', 'Changed request did not conflict.');

$unknown_request = array('idempotency_key' => 'unknown-key', 'confirm_execution' => true, 'force_unknown' => true);
$unknown = $facade->book_shipment(42, $unknown_request, array());
$unknown_replay = $facade->book_shipment(42, $unknown_request, array());
assert_true(is_wp_error($unknown) && $unknown->get_error_code() === 'lp_cargonizer_execution_unknown', 'Unknown provider outcome was not preserved.');
assert_true(is_wp_error($unknown_replay) && $unknown_replay->get_error_code() === 'lp_cargonizer_execution_unknown', 'Unknown outcome was retried instead of replayed fail-closed.');

$lock_request = array('idempotency_key' => 'locked-key', 'confirm_execution' => true, 'packages' => array(array('weight' => 1)));
$lock_key = 'lp_cargonizer_booking_lock_' . hash('sha256', '42|locked-key');
$options[$lock_key] = (string) time();
$locked = $facade->book_shipment(42, $lock_request, array());
assert_true(is_wp_error($locked) && $locked->get_error_code() === 'lp_cargonizer_booking_in_progress', 'Concurrent equivalent booking was not blocked.');

echo "OK: preflight, create, replay, conflict, lock and execution_unknown.\n";
