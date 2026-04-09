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
		$rules = $this->normalize_rules($this->get_rules());
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
		$has_mailbox_capable = false;
		$has_pickup_capable = false;
		$has_bulky = false;
		$total_flag_items = 0;
		$mailbox_capable_items = 0;
		$pickup_capable_items = 0;
		foreach ($packages as $package) {
			$items = isset($package['combined_items']) && is_array($package['combined_items']) && !empty($package['combined_items'])
				? $package['combined_items']
				: array($package);
			foreach ($items as $item) {
				$flags = isset($item['flags']) && is_array($item['flags']) ? $item['flags'] : array();
				$total_flag_items++;
				if (!empty($flags['high_value_secure'])) {
					$has_high_value_flag = true;
				}
				if (!empty($flags['mailbox_capable'])) {
					$has_mailbox_capable = true;
					$mailbox_capable_items++;
				}
				if (!empty($flags['pickup_capable'])) {
					$has_pickup_capable = true;
					$pickup_capable_items++;
				}
				if (!empty($flags['bulky'])) {
					$has_bulky = true;
				}
			}
		}
		$all_mailbox_capable = $total_flag_items > 0 && $mailbox_capable_items === $total_flag_items;
		$all_pickup_capable = $total_flag_items > 0 && $pickup_capable_items === $total_flag_items;

		return array(
			'order_value' => (float) $order_value,
			'total_weight' => isset($summary['total_weight']) ? (float) $summary['total_weight'] : 0,
			'profiles_in_use' => isset($summary['profiles_in_use']) && is_array($summary['profiles_in_use']) ? $summary['profiles_in_use'] : array(),
			'category_slugs' => isset($summary['category_slugs']) && is_array($summary['category_slugs']) ? $summary['category_slugs'] : array(),
			'has_separate_package' => !empty($summary['separate_package_count']),
			'missing_dimensions' => !empty($summary['missing_dimensions']),
			'has_high_value_secure' => $has_high_value_flag,
			'has_mailbox_capable' => !empty($summary['all_mailbox_capable']) || $all_mailbox_capable,
			'has_pickup_capable' => !empty($summary['all_pickup_capable']) || $all_pickup_capable,
			'has_bulky' => $has_bulky || !empty($summary['has_bulky']),
		);
	}

	private function evaluate_method_rules($method, $rules, $context) {
		$allow_rules = array();
		$deny_rules = array();
		$decorate_rules = array();
		foreach ($rules as $rule) {
			$action = isset($rule['action']) ? (string) $rule['action'] : 'allow';
			if ($action === 'deny') {
				$deny_rules[] = $rule;
			} elseif ($action === 'decorate') {
				$decorate_rules[] = $rule;
			} else {
				$allow_rules[] = $rule;
			}
		}

		$decision = array(
			'eligible' => true,
			'matched_rules' => array(),
			'failed_rules' => array(),
			'matched_allow_rules' => array(),
			'matched_deny_rules' => array(),
			'matched_decorate_rules' => array(),
		);

		if (empty($rules)) {
			return $decision;
		}

		$matched_allow = 0;
		foreach ($allow_rules as $rule) {
			if (empty($rule['enabled'])) {
				continue;
			}
			$check = $this->evaluate_rule_groups($rule, $context);
			if ($check['pass']) {
				$decision['matched_rules'][] = $rule;
				$decision['matched_allow_rules'][] = $rule;
				$matched_allow++;
				continue;
			}
			$decision['failed_rules'][] = array('reason' => $check['reason'], 'rule' => $rule);
		}
		if (!empty($allow_rules) && $matched_allow < 1) {
			$decision['eligible'] = false;
		}

		foreach ($deny_rules as $rule) {
			if (empty($rule['enabled'])) {
				continue;
			}
			$check = $this->evaluate_rule_groups($rule, $context);
			if (!$check['pass']) {
				continue;
			}
			$decision['eligible'] = false;
			$decision['matched_rules'][] = $rule;
			$decision['matched_deny_rules'][] = $rule;
			$decision['failed_rules'][] = array('reason' => 'deny_rule_matched', 'rule' => $rule);
		}

		foreach ($decorate_rules as $rule) {
			if (empty($rule['enabled'])) {
				continue;
			}
			$check = $this->evaluate_rule_groups($rule, $context);
			if (!$check['pass']) {
				continue;
			}
			$decision['matched_rules'][] = $rule;
			$decision['matched_decorate_rules'][] = $rule;
		}

		return $decision;
	}

	private function evaluate_rule_groups($rule, $context) {
		$groups = isset($rule['conditions_groups']) && is_array($rule['conditions_groups']) ? $rule['conditions_groups'] : array();
		if (empty($groups)) {
			$conditions = isset($rule['conditions']) && is_array($rule['conditions']) ? $rule['conditions'] : array();
			$groups = array($conditions);
		}
		if (empty($groups)) {
			return array('pass' => true, 'reason' => 'matched');
		}

		foreach ($groups as $conditions) {
			$check = $this->evaluate_conditions(is_array($conditions) ? $conditions : array(), $context);
			if (!empty($check['pass'])) {
				return array('pass' => true, 'reason' => 'matched');
			}
		}

		return array('pass' => false, 'reason' => 'group_no_match');
	}

	private function evaluate_conditions($conditions, $context) {
		$min_weight = isset($conditions['min_total_weight']) ? (float) $conditions['min_total_weight'] : (isset($conditions['min_weight']) ? (float) $conditions['min_weight'] : null);
		$max_weight = isset($conditions['max_total_weight']) ? (float) $conditions['max_total_weight'] : (isset($conditions['max_weight']) ? (float) $conditions['max_weight'] : null);
		$min_order_value = isset($conditions['min_order_value']) ? (float) $conditions['min_order_value'] : null;
		$max_order_value = isset($conditions['max_order_value']) ? (float) $conditions['max_order_value'] : null;
		$profile_slug = isset($conditions['profile_slug']) ? sanitize_key((string) $conditions['profile_slug']) : '';
		$category_slug = isset($conditions['category_slug']) ? sanitize_key((string) $conditions['category_slug']) : '';
		$profile_slugs = isset($conditions['profile_slugs']) && is_array($conditions['profile_slugs']) ? array_map('sanitize_key', $conditions['profile_slugs']) : array();
		$category_slugs = isset($conditions['category_slugs']) && is_array($conditions['category_slugs']) ? array_map('sanitize_key', $conditions['category_slugs']) : array();

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
		if (!empty($profile_slugs) && count(array_intersect($profile_slugs, $context['profiles_in_use'])) < 1) {
			return array('pass' => false, 'reason' => 'profile_slugs');
		}
		if ($category_slug !== '' && !in_array($category_slug, $context['category_slugs'], true)) {
			return array('pass' => false, 'reason' => 'category_slug');
		}
		if (!empty($category_slugs) && count(array_intersect($category_slugs, $context['category_slugs'])) < 1) {
			return array('pass' => false, 'reason' => 'category_slugs');
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
		$match_separate = isset($conditions['has_separate_package']) ? sanitize_key((string) $conditions['has_separate_package']) : '';
		if ($this->matches_tristate_flag($match_separate, !empty($context['has_separate_package'])) === false) {
			return array('pass' => false, 'reason' => 'has_separate_package');
		}
		$match_missing = isset($conditions['has_missing_dimensions']) ? sanitize_key((string) $conditions['has_missing_dimensions']) : '';
		if ($this->matches_tristate_flag($match_missing, !empty($context['missing_dimensions'])) === false) {
			return array('pass' => false, 'reason' => 'has_missing_dimensions');
		}
		$match_high = isset($conditions['has_high_value_secure']) ? sanitize_key((string) $conditions['has_high_value_secure']) : '';
		if ($this->matches_tristate_flag($match_high, !empty($context['has_high_value_secure'])) === false) {
			return array('pass' => false, 'reason' => 'has_high_value_secure');
		}
		$mailbox_match = isset($conditions['mailbox_capable']) ? sanitize_key((string) $conditions['mailbox_capable']) : '';
		if ($this->matches_tristate_flag($mailbox_match, !empty($context['has_mailbox_capable'])) === false) {
			return array('pass' => false, 'reason' => 'mailbox_capable');
		}
		$pickup_match = isset($conditions['pickup_capable']) ? sanitize_key((string) $conditions['pickup_capable']) : '';
		if ($this->matches_tristate_flag($pickup_match, !empty($context['has_pickup_capable'])) === false) {
			return array('pass' => false, 'reason' => 'pickup_capable');
		}
		$bulky_match = isset($conditions['bulky']) ? sanitize_key((string) $conditions['bulky']) : '';
		if ($this->matches_tristate_flag($bulky_match, !empty($context['has_bulky'])) === false) {
			return array('pass' => false, 'reason' => 'bulky');
		}

		return array('pass' => true, 'reason' => 'matched');
	}

	private function matches_tristate_flag($expected, $actual) {
		if ($expected === '' || $expected === 'any') {
			return true;
		}
		if ($expected === 'yes') {
			return (bool) $actual;
		}
		if ($expected === 'no') {
			return !$actual;
		}
		return true;
	}

	private function normalize_rules($rules) {
		$normalized = array();
		foreach ($rules as $rule) {
			if (!is_array($rule)) {
				continue;
			}
			$action = isset($rule['action']) ? sanitize_key((string) $rule['action']) : '';
			if (!in_array($action, array('allow', 'deny', 'decorate'), true)) {
				$action = 'allow';
			}
			$groups = isset($rule['conditions_groups']) && is_array($rule['conditions_groups']) ? $rule['conditions_groups'] : array();
			if (empty($groups) && isset($rule['conditions']) && is_array($rule['conditions'])) {
				$groups = array($rule['conditions']);
			}
			$rule['action'] = $action;
			$rule['conditions_groups'] = $groups;
			$normalized[] = $rule;
		}
		return $normalized;
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
