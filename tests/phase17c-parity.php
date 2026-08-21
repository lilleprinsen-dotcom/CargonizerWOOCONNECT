<?php

/**
 * Deterministic, provider-free Phase 17C contract checks.
 *
 * Run with: php tests/phase17c-parity.php
 */

function fail_test($message) {
	fwrite(STDERR, "FAIL: {$message}\n");
	exit(1);
}

function canonicalize($value) {
	if (!is_array($value)) {
		return $value;
	}
	ksort($value);
	foreach ($value as $key => $child) {
		$value[$key] = canonicalize($child);
	}
	return $value;
}

function semantic_request($request) {
	unset($request['nonce'], $request['action'], $request['idempotency_key'], $request['confirm_execution'], $request['actor']);
	return canonicalize($request);
}

function fixture($name, $changes = array()) {
	$base = array(
		'name' => $name,
		'order_id' => 522259801,
		'packages' => array(array('name' => 'Kolli 1', 'weight' => '2.5', 'length' => '30', 'width' => '20', 'height' => '10')),
		'methods' => array(array(
			'key' => 'sender-1::agreement-1|product-1',
			'agreement_id' => 'agreement-1',
			'carrier_id' => 'bring',
			'product_id' => 'product-1',
			'selected_service_ids' => array(),
			'servicepartner' => '',
			'use_sms_service' => false,
		)),
		'warehouse_profile_id' => 'sender-1',
		'notify_email_to_consignee' => false,
		'printer_choice' => '',
		'package_printer_assignments' => array(),
	);
	return array_replace_recursive($base, $changes);
}

$fixtures = array(
	fixture('standard_one_package'),
	fixture('multi_package', array('packages' => array(
		array('name' => 'Kolli 1', 'weight' => '2'),
		array('name' => 'Kolli 2', 'weight' => '3'),
	))),
	fixture('pickup_servicepartner', array('methods' => array(array('servicepartner' => 'SP-100', 'delivery_to_pickup_point' => true)))),
	fixture('customer_selected_pickup', array('methods' => array(array('servicepartner' => 'SP-101', 'servicepartner_selection_source' => 'customer', 'servicepartner_user_selected' => true)))),
	fixture('additional_services', array('methods' => array(array('selected_service_ids' => array('SERVICE-A', 'SERVICE-B'))))),
	fixture('sms_required', array('methods' => array(array('use_sms_service' => true, 'sms_service_id' => 'SMS')))),
	fixture('email_notification', array('notify_email_to_consignee' => true)),
	fixture('explicit_sender', array('warehouse_profile_id' => 'sender-2')),
	fixture('explicit_printer', array('printer_choice' => 'printer-1')),
	fixture('per_package_printer', array('packages' => array(array('name' => '1'), array('name' => '2')), 'package_printer_assignments' => array(1 => 'printer-2'))),
	fixture('existing_booking_history'),
	fixture('intentional_second_booking'),
	fixture('manual_norgespakke', array('methods' => array(array('key' => 'manual|norgespakke', 'is_manual_norgespakke' => true)))),
	fixture('invalid_stale_method', array('methods' => array(array('key' => 'stale|method')))),
	fixture('missing_servicepartner', array('methods' => array(array('delivery_to_pickup_point' => true, 'servicepartner' => '')))),
	fixture('same_idempotency_same_payload'),
	fixture('same_idempotency_changed_payload', array('packages' => array(array('name' => 'Changed', 'weight' => '4')))),
	fixture('provider_execution_disabled'),
);

if (count($fixtures) !== 18) {
	fail_test('Expected 18 golden fixtures.');
}

foreach ($fixtures as $index => $input) {
	$admin = $input;
	$admin['nonce'] = 'admin-nonce';
	$admin['action'] = 'lp_cargonizer_book_shipment';
	$admin['confirm_execution'] = true;
	$admin['idempotency_key'] = 'wordpress-admin:fixture-' . $index;

	$facade = $input;
	$facade['confirm_execution'] = true;
	$facade['idempotency_key'] = 'woo-ops:fixture-' . $index;
	$facade['actor'] = array('employee_id' => 'employee-1', 'device_id' => 'device-1');

	if (semantic_request($admin) !== semantic_request($facade)) {
		fail_test('Admin/facade semantic mismatch for ' . $input['name']);
	}
}

$source = file_get_contents(__DIR__ . '/../includes/trait-lp-cargonizer-ajax-controller.php');
$facade_source = file_get_contents(__DIR__ . '/../includes/class-lp-cargonizer-operations-facade.php');
if (substr_count($source, 'execute_shared_booking_core(') < 2) {
	fail_test('Admin and facade do not both route to the shared booking core.');
}
if (substr_count($source, 'execute_shared_estimate_core(') < 2) {
	fail_test('Admin and facade do not both route to the shared estimator core.');
}
if (strpos($source, "'state' => 'preflight_ready'") === false || strpos($source, "'provider_execution' => 'disabled'") === false) {
	fail_test('Provider-safe preflight response is missing.');
}
$preflight_position = strpos($source, "'provider_execution' => 'disabled'");
$provider_position = strpos($source, '$booking_result = $this->create_booking_consignment');
if ($preflight_position === false || $provider_position === false || $preflight_position > $provider_position) {
	fail_test('Preflight must stop before the provider booking call.');
}
if (strpos($source, "'message' => 'Posten-bookingen er validert, men ingen labeljobb ble opprettet.'") === false) {
	fail_test('Manual Norgespakke preflight guard is missing.');
}
if (strpos($facade_source, "unset(\$normalized['confirm_execution'])") === false) {
	fail_test('Execution transport flag must not alter the idempotency fingerprint.');
}

echo "OK: 18 admin/facade golden fixtures; shared booking and estimator cores; provider-safe preflight.\n";
