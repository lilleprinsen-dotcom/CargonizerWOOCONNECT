<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Estimator_Service {
	private $sanitize_price_source_callback;
	private $sanitize_rounding_mode_callback;
	private $sanitize_discount_percent_callback;
	private $sanitize_non_negative_number_callback;
	private $sanitize_checkbox_value_callback;
	private $run_consignment_estimate_callback;

	public function __construct($callbacks = array()) {
		$this->sanitize_price_source_callback = isset($callbacks['sanitize_price_source']) ? $callbacks['sanitize_price_source'] : null;
		$this->sanitize_rounding_mode_callback = isset($callbacks['sanitize_rounding_mode']) ? $callbacks['sanitize_rounding_mode'] : null;
		$this->sanitize_discount_percent_callback = isset($callbacks['sanitize_discount_percent']) ? $callbacks['sanitize_discount_percent'] : null;
		$this->sanitize_non_negative_number_callback = isset($callbacks['sanitize_non_negative_number']) ? $callbacks['sanitize_non_negative_number'] : null;
		$this->sanitize_checkbox_value_callback = isset($callbacks['sanitize_checkbox_value']) ? $callbacks['sanitize_checkbox_value'] : null;
		$this->run_consignment_estimate_callback = isset($callbacks['run_consignment_estimate_for_packages']) ? $callbacks['run_consignment_estimate_for_packages'] : null;
	}

	private function sanitize_price_source($value) {
		if (is_callable($this->sanitize_price_source_callback)) {
			return call_user_func($this->sanitize_price_source_callback, $value);
		}
		return 'estimated';
	}

	private function sanitize_rounding_mode($value) {
		if (is_callable($this->sanitize_rounding_mode_callback)) {
			return call_user_func($this->sanitize_rounding_mode_callback, $value);
		}
		return 'none';
	}

	private function sanitize_discount_percent($value) {
		if (is_callable($this->sanitize_discount_percent_callback)) {
			return call_user_func($this->sanitize_discount_percent_callback, $value);
		}
		return 0;
	}

	private function sanitize_non_negative_number($value) {
		if (is_callable($this->sanitize_non_negative_number_callback)) {
			return call_user_func($this->sanitize_non_negative_number_callback, $value);
		}
		return 0;
	}

	private function sanitize_checkbox_value($value) {
		if (is_callable($this->sanitize_checkbox_value_callback)) {
			return call_user_func($this->sanitize_checkbox_value_callback, $value);
		}
		return false;
	}

	public function apply_rounding_mode($value, $mode) {
		$rounded = (float) $value;
		switch ($mode) {
			case 'nearest_1':
				$rounded = round($rounded);
				break;
			case 'nearest_10':
				$rounded = round($rounded / 10) * 10;
				break;
			case 'price_ending_9':
				$rounded = $this->round_up_to_price_ending_9($rounded);
				break;
			case 'none':
			default:
				break;
		}

		return round(max(0, $rounded), 2);
	}

	public function round_up_to_price_ending_9($value) {
		$number = (float) $value;
		if ($number <= 9) {
			return 9;
		}

		$candidate = floor($number / 10) * 10 + 9;
		if ($candidate < $number) {
			$candidate += 10;
		}

		return $candidate;
	}

	public function calculate_adjustment_amount($base_price, $type, $value, $max_value = null) {
		$amount = 0.0;
		if ($type === 'percent') {
			$amount = $base_price * ((float) $value) / 100;
			if ($max_value !== null) {
				$amount = min($amount, (float) $max_value);
			}
		} else {
			$amount = (float) $value;
		}
		if ($amount < 0) {
			$amount = 0;
		}
		return round($amount, 2);
	}

	public function parse_price_to_number($price_value) {
		$raw = trim((string) $price_value);
		if ($raw === '') {
			return null;
		}
		$clean = preg_replace('/[^\d,\.\-]/u', '', $raw);
		if ($clean === '' || $clean === '-' || $clean === '.' || $clean === ',') {
			return null;
		}
		$last_dot = strrpos($clean, '.');
		$last_comma = strrpos($clean, ',');
		if ($last_dot !== false && $last_comma !== false) {
			$decimal_separator = $last_dot > $last_comma ? '.' : ',';
			$thousand_separator = $decimal_separator === '.' ? ',' : '.';
			$clean = str_replace($thousand_separator, '', $clean);
			$clean = str_replace($decimal_separator, '.', $clean);
		} elseif ($last_comma !== false) {
			$clean = str_replace(',', '.', $clean);
		}
		if (!is_numeric($clean)) {
			return null;
		}
		return (float) $clean;
	}

	public function select_estimate_price_value($price_fields, $configured_source = 'estimated') {
		$selected = array(
			'source' => '', 'value' => '', 'configured_source' => $this->sanitize_price_source($configured_source),
			'configured_key' => '', 'fallback_priority' => array(), 'actual_fallback_priority' => array(), 'fallback_step_used' => 0, 'used_fallback' => false,
		);
		if (!is_array($price_fields)) {
			return $selected;
		}
		$priority_data = $this->get_price_source_priority($selected['configured_source']);
		$selected['configured_key'] = $priority_data['configured_key'];
		$selected['fallback_priority'] = $priority_data['priority'];
		$selected['actual_fallback_priority'] = $priority_data['priority'];
		foreach ($priority_data['priority'] as $index => $source) {
			$value = isset($price_fields[$source]) ? trim((string) $price_fields[$source]) : '';
			if ($value !== '') {
				$selected['source'] = $source;
				$selected['value'] = $value;
				$selected['fallback_step_used'] = $index + 1;
				$selected['used_fallback'] = $source !== $priority_data['configured_key'];
				return $selected;
			}
		}
		$selected['used_fallback'] = true;
		return $selected;
	}

	public function get_price_source_priority($configured_source = 'estimated') {
		$source_priority_map = array(
			'estimated' => array('estimated_cost', 'gross_amount', 'net_amount', 'fallback_price'),
			'gross' => array('gross_amount', 'net_amount', 'estimated_cost', 'fallback_price'),
			'net' => array('net_amount', 'gross_amount', 'estimated_cost', 'fallback_price'),
			'fallback' => array('fallback_price', 'estimated_cost', 'gross_amount', 'net_amount'),
		);
		$configured = $this->sanitize_price_source($configured_source);
		$priority = isset($source_priority_map[$configured]) ? $source_priority_map[$configured] : $source_priority_map['estimated'];
		$configured_key = $priority[0];
		$priority = array_values(array_unique($priority));
		return array('configured_source' => $configured, 'configured_key' => $configured_key, 'priority' => $priority);
	}

	public function is_bring_method($method_payload) {
		if (!is_array($method_payload)) return false;
		$carrier_id = isset($method_payload['carrier_id']) ? strtolower((string) $method_payload['carrier_id']) : '';
		$carrier_name = isset($method_payload['carrier_name']) ? strtolower((string) $method_payload['carrier_name']) : '';
		return strpos($carrier_id, 'bring') !== false || strpos($carrier_name, 'bring') !== false;
	}

	public function is_dsv_method($method_payload) {
		if (!is_array($method_payload)) return false;
		$carrier_id = isset($method_payload['carrier_id']) ? strtolower((string) $method_payload['carrier_id']) : '';
		$carrier_name = isset($method_payload['carrier_name']) ? strtolower((string) $method_payload['carrier_name']) : '';
		return strpos($carrier_id, 'dsv') !== false || strpos($carrier_name, 'dsv') !== false;
	}

	public function generate_package_index_partitions($package_indexes) {
		$unique = array_values(array_unique(array_map('intval', is_array($package_indexes) ? $package_indexes : array())));
		sort($unique);
		$result = array();
		$this->build_package_index_partitions_recursive($unique, 0, array(), $result);
		return $result;
	}

	public function build_package_index_partitions_recursive($indexes, $position, $current_partition, &$all_partitions) {
		if ($position >= count($indexes)) {
			$normalized = $this->normalize_package_partition($current_partition);
			if (!empty($normalized)) {
				$all_partitions[] = $normalized;
			}
			return;
		}
		$index = $indexes[$position];
		$group_count = count($current_partition);
		for ($i = 0; $i < $group_count; $i++) {
			$next = $current_partition;
			$next[$i][] = $index;
			$this->build_package_index_partitions_recursive($indexes, $position + 1, $next, $all_partitions);
		}
		$next = $current_partition;
		$next[] = array($index);
		$this->build_package_index_partitions_recursive($indexes, $position + 1, $next, $all_partitions);
	}

	public function normalize_package_partition($partition) {
		$normalized = array();
		if (!is_array($partition)) {
			return $normalized;
		}
		foreach ($partition as $group) {
			$clean_group = array_values(array_unique(array_map('intval', is_array($group) ? $group : array())));
			sort($clean_group);
			if (!empty($clean_group)) {
				$normalized[] = $clean_group;
			}
		}
		usort($normalized, function ($a, $b) {
			return strcmp(implode(',', $a), implode(',', $b));
		});
		return $normalized;
	}

	public function package_triggers_manual_handling($package) {
		if (!is_array($package)) return false;
		$dimensions = array(
			isset($package['length']) ? (float) $package['length'] : 0,
			isset($package['width']) ? (float) $package['width'] : 0,
			isset($package['height']) ? (float) $package['height'] : 0,
		);
		$over_60_count = 0;
		foreach ($dimensions as $dimension) {
			if ($dimension > 120) return true;
			if ($dimension > 60) $over_60_count++;
		}
		return $over_60_count >= 2;
	}

	public function get_bring_manual_handling_fee($packages, $method_payload) {
		$result = array('fee' => 0, 'triggered' => false, 'package_count' => 0);
		if (!$this->is_bring_method($method_payload) || !is_array($packages)) return $result;
		foreach ($packages as $package) {
			if ($this->package_triggers_manual_handling($package)) {
				$result['package_count']++;
			}
		}
		$result['fee'] = round($result['package_count'] * 164, 2);
		$result['triggered'] = $result['package_count'] > 0;
		return $result;
	}

	public function calculate_norgespakke_estimate($packages, $method_payload, $pricing_config) {
		// copied verbatim behavior
		$result = array('status' => 'failed','error' => '','selected_price_source' => 'manual_norgespakke','selected_price_value' => '','original_list_price' => '','manual_handling_fee' => '0.00','bring_manual_handling_fee' => '0.00','total_handling_fee' => '0.00','bring_manual_handling_triggered' => false,'bring_manual_handling_package_count' => 0,'base_price' => '','discount_percent' => '','discounted_base' => '','fuel_surcharge' => '','recalculated_fuel_surcharge' => '','toll_surcharge' => '','handling_fee' => '','subtotal_ex_vat' => '','vat_percent' => '','price_incl_vat' => '','rounded_price' => '','final_price_ex_vat' => '','norgespakke_debug' => array());
		if (!is_array($packages) || empty($packages)) { $result['error'] = 'Norgespakke krever minst ett kolli.'; return $result; }
		$discount_percent = isset($pricing_config['discount_percent']) ? $this->sanitize_discount_percent($pricing_config['discount_percent']) : 0;
		$fuel_percent = isset($pricing_config['fuel_surcharge']) ? $this->sanitize_non_negative_number($pricing_config['fuel_surcharge']) : 0;
		$toll_surcharge = isset($pricing_config['toll_surcharge']) ? $this->sanitize_non_negative_number($pricing_config['toll_surcharge']) : 0;
		$vat_percent = isset($pricing_config['vat_percent']) ? $this->sanitize_non_negative_number($pricing_config['vat_percent']) : 0;
		$rounding_mode = isset($pricing_config['rounding_mode']) ? $this->sanitize_rounding_mode($pricing_config['rounding_mode']) : 'none';
		$include_handling_fee = isset($pricing_config['manual_norgespakke_include_handling']) ? (bool) $this->sanitize_checkbox_value($pricing_config['manual_norgespakke_include_handling']) : true;
		$package_rows = array(); $total_base = 0.0; $total_handling = 0.0; $handling_count = 0;
		foreach ($packages as $idx => $package) {
			$weight = isset($package['weight']) ? (float) $package['weight'] : 0;
			$length = isset($package['length']) ? (float) $package['length'] : 0;
			$width = isset($package['width']) ? (float) $package['width'] : 0;
			$height = isset($package['height']) ? (float) $package['height'] : 0;
			$name = isset($package['name']) && (string) $package['name'] !== '' ? (string) $package['name'] : (isset($package['description']) ? (string) $package['description'] : 'Kolli ' . ($idx + 1));
			$description = isset($package['description']) ? (string) $package['description'] : '';
			if ($weight <= 0) { $result['error'] = 'Norgespakke-kolli ' . ($idx + 1) . ' har ugyldig eller manglende vekt. Vekt må være over 0 kg.'; return $result; }
			if ($weight > 35) { $result['error'] = 'Norgespakke-kolli ' . ($idx + 1) . ' veier ' . number_format($weight, 2, '.', '') . ' kg og overskrider maksgrensen på 35 kg.'; return $result; }
			if ($weight <= 10) { $base_price = 112.0; } elseif ($weight <= 25) { $base_price = 200.8; } else { $base_price = 268.0; }
			$handling_triggered = $this->package_triggers_manual_handling($package);
			$handling_fee = ($include_handling_fee && $handling_triggered) ? 164.0 : 0.0;
			if ($include_handling_fee && $handling_triggered) { $handling_count++; }
			$total_base += $base_price; $total_handling += $handling_fee;
			$package_rows[] = array('package_number' => $idx + 1,'name' => $name,'description' => $description,'weight' => number_format($weight, 2, '.', ''),'length' => number_format($length, 2, '.', ''),'width' => number_format($width, 2, '.', ''),'height' => number_format($height, 2, '.', ''),'base_price' => number_format($base_price, 2, '.', ''),'handling_triggered' => $handling_triggered,'handling_reason' => $handling_triggered ? 'Én side over 120 cm eller minst to sider over 60 cm.' : 'Ingen håndteringstrigger.','handling_fee' => number_format($handling_fee, 2, '.', ''),'package_total' => number_format($base_price + $handling_fee, 2, '.', ''));
		}
		$discount_amount = $total_base * ($discount_percent / 100); $discounted_base = $total_base - $discount_amount; $fuel_amount = $discounted_base * ($fuel_percent / 100);
		$subtotal_ex_vat = $discounted_base + $fuel_amount + $toll_surcharge + $total_handling; $price_incl_vat = $subtotal_ex_vat * (1 + ($vat_percent / 100));
		$rounded_price = $this->apply_rounding_mode($price_incl_vat, $rounding_mode); $final_price_ex_vat = $vat_percent > 0 ? $rounded_price / (1 + ($vat_percent / 100)) : $rounded_price;
		$result['status'] = 'ok'; $result['selected_price_value'] = number_format($total_base, 2, '.', ''); $result['original_list_price'] = number_format($total_base, 2, '.', '');
		$result['base_price'] = number_format($total_base, 2, '.', ''); $result['discount_percent'] = number_format($discount_percent, 2, '.', '');
		$result['discounted_base'] = number_format($discounted_base, 2, '.', ''); $result['fuel_surcharge'] = number_format($fuel_percent, 2, '.', ''); $result['recalculated_fuel_surcharge'] = number_format($fuel_amount, 2, '.', '');
		$result['toll_surcharge'] = number_format($toll_surcharge, 2, '.', ''); $result['handling_fee'] = number_format($total_handling, 2, '.', ''); $result['total_handling_fee'] = number_format($total_handling, 2, '.', '');
		$result['bring_manual_handling_fee'] = number_format($total_handling, 2, '.', ''); $result['bring_manual_handling_triggered'] = $handling_count > 0; $result['bring_manual_handling_package_count'] = $handling_count;
		$result['subtotal_ex_vat'] = number_format($subtotal_ex_vat, 2, '.', ''); $result['vat_percent'] = number_format($vat_percent, 2, '.', ''); $result['price_incl_vat'] = number_format($price_incl_vat, 2, '.', '');
		$result['rounded_price'] = number_format($rounded_price, 2, '.', ''); $result['final_price_ex_vat'] = number_format($final_price_ex_vat, 2, '.', '');
		$result['norgespakke_debug'] = array('method_type' => 'manual','api_calls_used' => false,'handling_fee_enabled' => $include_handling_fee,'number_of_packages' => count($packages),'packages' => $package_rows,'total_base_freight' => number_format($total_base, 2, '.', ''),'total_discount' => number_format($discount_amount, 2, '.', ''),'total_handling' => number_format($total_handling, 2, '.', ''),'fuel_percent' => number_format($fuel_percent, 2, '.', ''),'fuel_amount' => number_format($fuel_amount, 2, '.', ''),'toll_surcharge' => number_format($toll_surcharge, 2, '.', ''),'vat_percent' => number_format($vat_percent, 2, '.', ''),'rounding_mode' => $rounding_mode,'final_price_ex_vat' => number_format($final_price_ex_vat, 2, '.', ''));
		return $result;
	}

	public function calculate_estimate_from_price_source($selected_price, $pricing_config) {
		$result = array('status' => 'unknown','error' => '','original_price' => '','original_list_price' => '','manual_handling_fee' => '','bring_manual_handling_fee' => '','total_handling_fee' => '','bring_manual_handling_triggered' => false,'bring_manual_handling_package_count' => 0,'extracted_handling_fee' => '','extracted_toll_surcharge' => '','extracted_fuel_percent' => '','extracted_base_freight' => '','discounted_base_freight' => '','recalculated_fuel_surcharge' => '','base_price' => '','discount_percent' => '','discounted_base' => '','fuel_surcharge' => '','toll_surcharge' => '','handling_fee' => '','subtotal_ex_vat' => '','price_incl_vat' => '','rounded_price' => '','final_price_ex_vat' => '');
		$source = isset($selected_price['source']) ? (string) $selected_price['source'] : '';
		$value = isset($selected_price['value']) ? (string) $selected_price['value'] : '';
		if ($value === '') { $result['error'] = 'Fikk svar, men fant ingen prisfelt (net_amount, gross_amount, estimated_cost eller fallback_price) i responsen.'; return $result; }
		$original_list_price = $this->parse_price_to_number($value);
		if ($original_list_price === null) { $result['status'] = 'price_parse_failed'; $result['error'] = 'Kunne ikke tolke valgt prisfelt (' . $source . ') som et tall. Viser kun original respons.'; return $result; }
		$discount_percent = isset($pricing_config['discount_percent']) ? $this->sanitize_discount_percent($pricing_config['discount_percent']) : 0;
		$fuel_percent = isset($pricing_config['fuel_surcharge']) ? $this->sanitize_non_negative_number($pricing_config['fuel_surcharge']) : 0;
		$extracted_toll_surcharge = isset($pricing_config['toll_surcharge']) ? $this->sanitize_non_negative_number($pricing_config['toll_surcharge']) : 0;
		$manual_handling_fee = isset($pricing_config['manual_handling_fee']) ? $this->sanitize_non_negative_number($pricing_config['manual_handling_fee']) : (isset($pricing_config['handling_fee']) ? $this->sanitize_non_negative_number($pricing_config['handling_fee']) : 0);
		$bring_manual_handling_fee = isset($pricing_config['bring_manual_handling_fee']) ? $this->sanitize_non_negative_number($pricing_config['bring_manual_handling_fee']) : 0;
		$bring_manual_handling_triggered = !empty($pricing_config['bring_manual_handling_triggered']);
		if (!$bring_manual_handling_triggered) { $bring_manual_handling_fee = 0; }
		$total_handling_fee = round($manual_handling_fee + $bring_manual_handling_fee, 2);
		$bring_manual_handling_package_count = isset($pricing_config['bring_manual_handling_package_count']) ? max(0, (int) $pricing_config['bring_manual_handling_package_count']) : 0;
		$vat_percent = isset($pricing_config['vat_percent']) ? (float) $pricing_config['vat_percent'] : 0;
		$rounding_mode = isset($pricing_config['rounding_mode']) ? $this->sanitize_rounding_mode($pricing_config['rounding_mode']) : 'none';
		$list_minus_fixed_fees = $original_list_price - $total_handling_fee - $extracted_toll_surcharge; if ($list_minus_fixed_fees < 0) { $list_minus_fixed_fees = 0; }
		$fuel_multiplier = 1 + ($fuel_percent / 100); if ($fuel_multiplier <= 0) { $fuel_multiplier = 1; }
		$extracted_base_freight = $list_minus_fixed_fees / $fuel_multiplier; $discounted_base_freight = $extracted_base_freight - ($extracted_base_freight * $discount_percent / 100);
		$recalculated_fuel_surcharge = $discounted_base_freight * ($fuel_percent / 100); $subtotal_ex_vat = $discounted_base_freight + $recalculated_fuel_surcharge + $extracted_toll_surcharge + $total_handling_fee;
		$price_incl_vat = $subtotal_ex_vat * (1 + ($vat_percent / 100)); $rounded_price = $this->apply_rounding_mode($price_incl_vat, $rounding_mode); $final_price_ex_vat = $vat_percent > 0 ? ($rounded_price / (1 + ($vat_percent / 100))) : $rounded_price;
		$result['status'] = 'ok';
		$result['original_price'] = number_format($original_list_price, 2, '.', ''); $result['original_list_price'] = number_format($original_list_price, 2, '.', '');
		$result['manual_handling_fee'] = number_format($manual_handling_fee, 2, '.', ''); $result['bring_manual_handling_fee'] = number_format($bring_manual_handling_fee, 2, '.', ''); $result['total_handling_fee'] = number_format($total_handling_fee, 2, '.', '');
		$result['bring_manual_handling_triggered'] = $bring_manual_handling_triggered; $result['bring_manual_handling_package_count'] = $bring_manual_handling_package_count;
		$result['extracted_handling_fee'] = number_format($total_handling_fee, 2, '.', ''); $result['extracted_toll_surcharge'] = number_format($extracted_toll_surcharge, 2, '.', ''); $result['extracted_fuel_percent'] = number_format($fuel_percent, 2, '.', '');
		$result['extracted_base_freight'] = number_format($extracted_base_freight, 2, '.', ''); $result['discounted_base_freight'] = number_format($discounted_base_freight, 2, '.', ''); $result['recalculated_fuel_surcharge'] = number_format($recalculated_fuel_surcharge, 2, '.', '');
		$result['base_price'] = number_format($extracted_base_freight, 2, '.', ''); $result['discount_percent'] = number_format($discount_percent, 2, '.', ''); $result['discounted_base'] = number_format($discounted_base_freight, 2, '.', '');
		$result['fuel_surcharge'] = number_format($fuel_percent, 2, '.', ''); $result['toll_surcharge'] = number_format($extracted_toll_surcharge, 2, '.', ''); $result['handling_fee'] = number_format($total_handling_fee, 2, '.', '');
		$result['subtotal_ex_vat'] = number_format($subtotal_ex_vat, 2, '.', ''); $result['price_incl_vat'] = number_format($price_incl_vat, 2, '.', ''); $result['rounded_price'] = number_format($rounded_price, 2, '.', ''); $result['final_price_ex_vat'] = number_format($final_price_ex_vat, 2, '.', '');
		return $result;
	}

	public function build_packages_summary($packages) {
		$summary = array();
		if (!is_array($packages)) { return $summary; }
		foreach ($packages as $package) {
			$summary[] = array(
				'name' => isset($package['name']) ? (string) $package['name'] : '',
				'description' => isset($package['description']) ? (string) $package['description'] : '',
				'weight' => isset($package['weight']) ? (float) $package['weight'] : 0,
				'length' => isset($package['length']) ? (float) $package['length'] : 0,
				'width' => isset($package['width']) ? (float) $package['width'] : 0,
				'height' => isset($package['height']) ? (float) $package['height'] : 0,
			);
		}
		return $summary;
	}

	public function evaluate_dsv_partition($partition, $all_packages, $recipient, $method_payload, $pricing_config, $partition_index) {
		$variant = array('partition_index' => (int) $partition_index,'shipment_count' => count($partition),'status' => 'ok','is_winner' => false,'is_baseline' => false,'total_final_price_ex_vat' => '','total_rounded_price' => '','error' => '','groups' => array());
		$total_final = 0.0; $total_rounded = 0.0;
		foreach ($partition as $group_indexes) {
			$group_packages = array(); foreach ($group_indexes as $package_index) { if (isset($all_packages[$package_index])) { $group_packages[] = $all_packages[$package_index]; }}
			$group_bring = $this->get_bring_manual_handling_fee($group_packages, $method_payload);
			$group_manual_fee = isset($pricing_config['manual_handling_fee']) ? $this->sanitize_non_negative_number($pricing_config['manual_handling_fee']) : 0;
			$group_bring_fee = isset($group_bring['fee']) ? $this->sanitize_non_negative_number($group_bring['fee']) : 0;
			$group_pricing = $pricing_config; $group_pricing['bring_manual_handling_fee'] = $group_bring_fee; $group_pricing['bring_manual_handling_triggered'] = !empty($group_bring['triggered']); $group_pricing['bring_manual_handling_package_count'] = isset($group_bring['package_count']) ? (int) $group_bring['package_count'] : 0; $group_pricing['handling_fee'] = round($group_manual_fee + $group_bring_fee, 2);
			$group_result = is_callable($this->run_consignment_estimate_callback) ? call_user_func($this->run_consignment_estimate_callback, $group_packages, $recipient, $method_payload, $group_pricing) : array('status' => 'failed', 'http_status' => 0, 'selected_price_source' => '', 'selected_price_value' => '', 'raw_response' => '', 'parsed_error_message' => '', 'error_code' => '', 'error_type' => '', 'error_details' => '', 'error' => '');
			$group_debug = array('package_indexes' => $group_indexes,'packages_summary' => $this->build_packages_summary($group_packages),'status' => $group_result['status'],'http_status' => $group_result['http_status'],'selected_price_source' => $group_result['selected_price_source'],'selected_price_value' => $group_result['selected_price_value'],'final_price_ex_vat' => '','rounded_price' => '','raw_response' => $group_result['raw_response'],'parsed_error_message' => $group_result['parsed_error_message'],'error_code' => $group_result['error_code'],'error_type' => $group_result['error_type'],'error_details' => $group_result['error_details'],'error' => $group_result['error']);
			if (!empty($group_result['calculated'])) {
				$group_debug['final_price_ex_vat'] = isset($group_result['calculated']['final_price_ex_vat']) ? $group_result['calculated']['final_price_ex_vat'] : '';
				$group_debug['rounded_price'] = isset($group_result['calculated']['rounded_price']) ? $group_result['calculated']['rounded_price'] : '';
				$total_final += (float) $group_debug['final_price_ex_vat']; $total_rounded += (float) $group_debug['rounded_price'];
			} else { $variant['status'] = 'failed'; $variant['error'] = $group_result['error'] !== '' ? $group_result['error'] : 'Kunne ikke beregne pris for en delsendelse.'; }
			$variant['groups'][] = $group_debug;
		}
		if ($variant['status'] === 'ok') { $variant['total_final_price_ex_vat'] = number_format($total_final, 2, '.', ''); $variant['total_rounded_price'] = number_format($total_rounded, 2, '.', ''); }
		return $variant;
	}

	public function build_dsv_baseline_variant($estimate_result, $packages) {
		$indexes = array(); if (is_array($packages) && count($packages) > 0) { $indexes = range(0, count($packages) - 1); }
		$group = array('package_indexes' => $indexes,'packages_summary' => $this->build_packages_summary($packages),'status' => isset($estimate_result['status']) ? $estimate_result['status'] : 'failed','http_status' => isset($estimate_result['http_status']) ? $estimate_result['http_status'] : 0,'selected_price_source' => isset($estimate_result['selected_price_source']) ? $estimate_result['selected_price_source'] : '','selected_price_value' => isset($estimate_result['selected_price_value']) ? $estimate_result['selected_price_value'] : '','final_price_ex_vat' => '','rounded_price' => '','raw_response' => isset($estimate_result['raw_response']) ? $estimate_result['raw_response'] : '','parsed_error_message' => isset($estimate_result['parsed_error_message']) ? $estimate_result['parsed_error_message'] : '','error_code' => isset($estimate_result['error_code']) ? $estimate_result['error_code'] : '','error_type' => isset($estimate_result['error_type']) ? $estimate_result['error_type'] : '','error_details' => isset($estimate_result['error_details']) ? $estimate_result['error_details'] : '','error' => isset($estimate_result['error']) ? $estimate_result['error'] : '');
		$variant = array('partition_index' => -1,'shipment_count' => 1,'status' => isset($estimate_result['status']) ? $estimate_result['status'] : 'failed','is_winner' => false,'is_baseline' => true,'total_final_price_ex_vat' => '','total_rounded_price' => '','error' => isset($estimate_result['error']) ? $estimate_result['error'] : '','groups' => array($group));
		if (!empty($estimate_result['calculated'])) {
			$final_price = isset($estimate_result['calculated']['final_price_ex_vat']) ? (string) $estimate_result['calculated']['final_price_ex_vat'] : '';
			$rounded_price = isset($estimate_result['calculated']['rounded_price']) ? (string) $estimate_result['calculated']['rounded_price'] : '';
			$variant['status'] = 'ok'; $variant['total_final_price_ex_vat'] = $final_price; $variant['total_rounded_price'] = $rounded_price; $variant['groups'][0]['final_price_ex_vat'] = $final_price; $variant['groups'][0]['rounded_price'] = $rounded_price;
		}
		return $variant;
	}

	public function compare_dsv_variants($left, $right) {
		$left_price = isset($left['total_final_price_ex_vat']) ? (float) $left['total_final_price_ex_vat'] : INF;
		$right_price = isset($right['total_final_price_ex_vat']) ? (float) $right['total_final_price_ex_vat'] : INF;
		if ($left_price < $right_price) return -1; if ($left_price > $right_price) return 1;
		$left_shipments = isset($left['shipment_count']) ? (int) $left['shipment_count'] : PHP_INT_MAX;
		$right_shipments = isset($right['shipment_count']) ? (int) $right['shipment_count'] : PHP_INT_MAX;
		if ($left_shipments < $right_shipments) return -1; if ($left_shipments > $right_shipments) return 1;
		$left_all_together = $left_shipments === 1; $right_all_together = $right_shipments === 1;
		if ($left_all_together && !$right_all_together) return -1; if (!$left_all_together && $right_all_together) return 1;
		$left_partition = isset($left['partition_index']) ? (int) $left['partition_index'] : PHP_INT_MAX;
		$right_partition = isset($right['partition_index']) ? (int) $right['partition_index'] : PHP_INT_MAX;
		if ($left_partition < $right_partition) return -1; if ($left_partition > $right_partition) return 1;
		return 0;
	}

	public function optimize_dsv_partition_estimates($packages, $recipient, $method_payload, $pricing_config, $baseline_estimate_result) {
		$max_full_partition_packages = 5; $package_count = is_array($packages) ? count($packages) : 0;
		$baseline_variant = $this->build_dsv_baseline_variant($baseline_estimate_result, $packages);
		$debug = array('enabled' => false,'reason' => '','package_count' => $package_count,'baseline_estimate_attempted' => true,'baseline_estimate_status' => isset($baseline_variant['status']) ? $baseline_variant['status'] : 'failed','partitions_tested' => 0,'winner_partition_index' => ($baseline_variant['status'] === 'ok') ? (int) $baseline_variant['partition_index'] : -1,'winner_total_final_price_ex_vat' => ($baseline_variant['status'] === 'ok') ? $baseline_variant['total_final_price_ex_vat'] : '','winner_total_rounded_price' => ($baseline_variant['status'] === 'ok') ? $baseline_variant['total_rounded_price'] : '','winner_shipment_count' => ($baseline_variant['status'] === 'ok') ? (int) $baseline_variant['shipment_count'] : 0,'optimization_changed_result' => false,'variants' => array($baseline_variant));
		$result = array('used' => false, 'winner' => $baseline_variant, 'debug' => $debug); $winner = $baseline_variant['status'] === 'ok' ? $baseline_variant : null;
		if (!$this->is_dsv_method($method_payload)) { $result['debug']['reason'] = 'Hoppet over: metoden er ikke DSV.'; $result['debug']['winner_partition_index'] = isset($baseline_variant['partition_index']) ? (int) $baseline_variant['partition_index'] : -1; return $result; }
		if ($package_count <= 1) { $result['debug']['reason'] = 'Hoppet over: krever mer enn 1 kolli.'; $result['debug']['winner_partition_index'] = isset($baseline_variant['partition_index']) ? (int) $baseline_variant['partition_index'] : -1; return $result; }
		if ($package_count > $max_full_partition_packages) { $result['debug']['reason'] = 'Hoppet over: antall kolli (' . $package_count . ') overstiger sikkerhetsgrense på ' . $max_full_partition_packages . ' for full partition-testing.'; $result['debug']['winner_partition_index'] = isset($baseline_variant['partition_index']) ? (int) $baseline_variant['partition_index'] : -1; return $result; }
		$partitions = $this->generate_package_index_partitions(range(0, $package_count - 1));
		$result['debug']['enabled'] = true; $result['debug']['reason'] = 'DSV-optimalisering kjørt med samlet estimat som baseline.';
		foreach ($partitions as $partition_index => $partition) {
			if (count($partition) === 1 && isset($partition[0]) && count($partition[0]) === $package_count) continue;
			$result['debug']['partitions_tested']++;
			$variant = $this->evaluate_dsv_partition($partition, $packages, $recipient, $method_payload, $pricing_config, $partition_index);
			$result['debug']['variants'][] = $variant;
			if ($variant['status'] !== 'ok') continue;
			if ($winner === null || $this->compare_dsv_variants($variant, $winner) < 0) { $winner = $variant; }
		}
		if ($winner === null) { $result['debug']['reason'] = 'DSV-optimalisering feilet: verken samlet estimat eller partitions ga gyldig resultat.'; return $result; }
		$result['winner'] = $winner; $result['used'] = isset($winner['partition_index']) && (int) $winner['partition_index'] >= 0; $result['debug']['optimization_changed_result'] = $result['used'];
		foreach ($result['debug']['variants'] as $idx => $variant) { $is_same_partition = (int) $variant['partition_index'] === (int) $winner['partition_index']; $is_same_baseline = !empty($variant['is_baseline']) === !empty($winner['is_baseline']); $result['debug']['variants'][$idx]['is_winner'] = $is_same_partition && $is_same_baseline; }
		$result['debug']['winner_partition_index'] = (int) $winner['partition_index']; $result['debug']['winner_total_final_price_ex_vat'] = $winner['total_final_price_ex_vat']; $result['debug']['winner_total_rounded_price'] = $winner['total_rounded_price']; $result['debug']['winner_shipment_count'] = (int) $winner['shipment_count'];
		return $result;
	}
}
