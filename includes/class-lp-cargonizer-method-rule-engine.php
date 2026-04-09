<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Method_Rule_Engine {
	/** @var callable */
	private $settings_provider;

	public function __construct($settings_provider) {
		$this->settings_provider = $settings_provider;
	}

	public function evaluate_methods($methods, $package_result, $order_value) {
		$context = $this->build_context($package_result, $order_value);
		$rules = $this->get_rules();
		$by_method = array();
		foreach ($rules as $rule) {
			$method_key = isset($rule['method_key']) ? sanitize_text_field((string) $rule['method_key']) : '';
			if ($method_key === '') {
				continue;
			}
			$by_method[$method_key][] = $rule;
		}

		$result = array();
		foreach ($methods as $method) {
			$key = isset($method['key']) ? sanitize_text_field((string) $method['key']) : '';
			$method_rules = isset($by_method[$key]) ? $by_method[$key] : array();
			$evaluation = $this->evaluate_method_rules($method, $method_rules, $context);
			if ($evaluation['eligible']) {
				$result[] = array_merge($method, array(
					'eligibility' => $evaluation,
				));
			}
		}

		return array(
			'eligible_methods' => $result,
			'context' => $context,
		);
	}

	public function build_context($package_result, $order_value) {
		$summary = isset($package_result['summary']) && is_array($package_result['summary']) ? $package_result['summary'] : array();
		$packages = isset($package_result['packages']) && is_array($package_result['packages']) ? $package_result['packages'] : array();

		$has_high_value_flag = false;
		foreach ($packages as $package) {
			$flags = isset($package['flags']) && is_array($package['flags']) ? $package['flags'] : array();
			if (!empty($flags['high_value_secure'])) {
				$has_high_value_flag = true;
				break;
			}
		}

		return array(
			'order_value' => (float) $order_value,
			'total_weight' => isset($summary['total_weight']) ? (float) $summary['total_weight'] : 0,
			'profiles_in_use' => isset($summary['profiles_in_use']) && is_array($summary['profiles_in_use']) ? $summary['profiles_in_use'] : array(),
			'category_slugs' => isset($summary['category_slugs']) && is_array($summary['category_slugs']) ? $summary['category_slugs'] : array(),
			'has_separate_package' => !empty($summary['separate_package_count']),
			'missing_dimensions' => !empty($summary['missing_dimensions']),
			'has_high_value_secure' => $has_high_value_flag,
		);
	}

	private function evaluate_method_rules($method, $rules, $context) {
		$decision = array(
			'eligible' => true,
			'matched_rules' => array(),
			'failed_rules' => array(),
		);

		if (empty($rules)) {
			return $decision;
		}

		foreach ($rules as $rule) {
			$enabled = isset($rule['enabled']) ? (bool) $rule['enabled'] : true;
			if (!$enabled) {
				$decision['eligible'] = false;
				$decision['failed_rules'][] = array('reason' => 'rule_disabled', 'rule' => $rule);
				continue;
			}

			$conditions = isset($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : array();
			$check = $this->evaluate_conditions($conditions, $context);
			if ($check['pass']) {
				$decision['matched_rules'][] = $rule;
				continue;
			}

			$decision['eligible'] = false;
			$decision['failed_rules'][] = array(
				'reason' => $check['reason'],
				'rule' => $rule,
			);
		}

		return $decision;
	}

	private function evaluate_conditions($conditions, $context) {
		$min_weight = isset($conditions['min_weight']) ? (float) $conditions['min_weight'] : null;
		$max_weight = isset($conditions['max_weight']) ? (float) $conditions['max_weight'] : null;
		$min_order_value = isset($conditions['min_order_value']) ? (float) $conditions['min_order_value'] : null;
		$max_order_value = isset($conditions['max_order_value']) ? (float) $conditions['max_order_value'] : null;
		$profile_slug = isset($conditions['profile_slug']) ? sanitize_key((string) $conditions['profile_slug']) : '';
		$category_slug = isset($conditions['category_slug']) ? sanitize_key((string) $conditions['category_slug']) : '';

		if ($min_weight !== null && $context['total_weight'] < $min_weight) {
			return array('pass' => false, 'reason' => 'min_weight');
		}
		if ($max_weight !== null && $max_weight > 0 && $context['total_weight'] > $max_weight) {
			return array('pass' => false, 'reason' => 'max_weight');
		}
		if ($min_order_value !== null && $context['order_value'] < $min_order_value) {
			return array('pass' => false, 'reason' => 'min_order_value');
		}
		if ($max_order_value !== null && $max_order_value > 0 && $context['order_value'] > $max_order_value) {
			return array('pass' => false, 'reason' => 'max_order_value');
		}

		if ($profile_slug !== '' && !in_array($profile_slug, $context['profiles_in_use'], true)) {
			return array('pass' => false, 'reason' => 'profile_slug');
		}
		if ($category_slug !== '' && !in_array($category_slug, $context['category_slugs'], true)) {
			return array('pass' => false, 'reason' => 'category_slug');
		}

		if (!empty($conditions['require_separate_package']) && empty($context['has_separate_package'])) {
			return array('pass' => false, 'reason' => 'require_separate_package');
		}
		if (!empty($conditions['require_high_value']) && empty($context['has_high_value_secure'])) {
			return array('pass' => false, 'reason' => 'require_high_value');
		}
		if (!empty($conditions['require_security']) && empty($context['has_high_value_secure'])) {
			return array('pass' => false, 'reason' => 'require_security');
		}
		if (!empty($conditions['require_missing_dimensions']) && empty($context['missing_dimensions'])) {
			return array('pass' => false, 'reason' => 'require_missing_dimensions');
		}

		return array('pass' => true, 'reason' => 'matched');
	}

	private function get_rules() {
		$settings = is_callable($this->settings_provider)
			? call_user_func($this->settings_provider)
			: array();
		return isset($settings['checkout_method_rules']['rules']) && is_array($settings['checkout_method_rules']['rules'])
			? $settings['checkout_method_rules']['rules']
			: array();
	}
}
