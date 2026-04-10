<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Package_Builder {
	/** @var LP_Cargonizer_Shipping_Profile_Resolver */
	private $profile_resolver;
	/** @var callable|null */
	private $settings_provider;

	public function __construct($profile_resolver, $settings_provider = null) {
		$this->profile_resolver = $profile_resolver;
		$this->settings_provider = is_callable($settings_provider) ? $settings_provider : null;
	}

	public function build_from_order($order) {
		if (!$order || !is_object($order) || !method_exists($order, 'get_items')) {
			return $this->empty_result();
		}

		$lines = array();
		foreach ($order->get_items() as $item) {
			if (!is_a($item, 'WC_Order_Item_Product')) {
				continue;
			}
			$product = $item->get_product();
			if (!$product) {
				continue;
			}
			$lines[] = array(
				'product' => $product,
				'quantity' => max(1, (int) $item->get_quantity()),
				'line_total' => (float) $item->get_total(),
				'line_name' => (string) $item->get_name(),
			);
		}

		return $this->build_from_lines($lines);
	}

	public function build_from_cart($cart) {
		if (!$cart || !is_object($cart) || !method_exists($cart, 'get_cart')) {
			return $this->empty_result();
		}

		$lines = array();
		foreach ($cart->get_cart() as $cart_item) {
			$product = isset($cart_item['data']) && is_object($cart_item['data']) ? $cart_item['data'] : null;
			if (!$product) {
				continue;
			}
			$lines[] = array(
				'product' => $product,
				'quantity' => isset($cart_item['quantity']) ? max(1, (int) $cart_item['quantity']) : 1,
				'line_total' => isset($cart_item['line_total']) ? (float) $cart_item['line_total'] : 0,
				'line_name' => method_exists($product, 'get_name') ? (string) $product->get_name() : '',
			);
		}

		return $this->build_from_lines($lines);
	}

	public function build_from_lines($lines) {
		$result = $this->empty_result();
		$build_mode = $this->get_package_build_mode();
		$combined = array();
		$combined_by_profile = array();
		$separate_packages = array();
		$profiles_in_use = array();
		$category_slugs = array();

		foreach ($lines as $line) {
			$product = isset($line['product']) ? $line['product'] : null;
			if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
				continue;
			}

			$product_id = (int) $product->get_id();
			$quantity = isset($line['quantity']) ? max(1, (int) $line['quantity']) : 1;
			$line_total = isset($line['line_total']) ? (float) $line['line_total'] : 0;
			$line_name = isset($line['line_name']) ? (string) $line['line_name'] : (method_exists($product, 'get_name') ? (string) $product->get_name() : '');

			$profile_resolution = $this->profile_resolver->resolve_for_product($product, $quantity, $line_total);
			$profile_slug = isset($profile_resolution['profile_slug']) ? (string) $profile_resolution['profile_slug'] : 'default';
			$profiles_in_use[$profile_slug] = true;

			$is_old_separate = get_post_meta($product_id, '_wildrobot_separate_package_for_product', true) === 'yes';
			$is_profile_forced_separate = !empty($profile_resolution['flags']['force_separate_package']);
			$is_bulky = !empty($profile_resolution['flags']['bulky']);
			$is_separate = $is_old_separate || $is_profile_forced_separate;
			if (!$is_separate && $build_mode === 'separate_bulky_profiles' && $is_bulky) {
				$is_separate = true;
			}
			$separate_name = (string) get_post_meta($product_id, '_wildrobot_separate_package_for_product_name', true);
			if ($separate_name === '') {
				$separate_name = $line_name;
			}

			$category_slugs = array_merge($category_slugs, $this->collect_category_slugs($product_id));

			$single_package = $this->build_single_package($product, $line_name, $profile_resolution, $quantity);
			if ($is_separate) {
				for ($i = 0; $i < $quantity; $i++) {
					$package = $single_package;
					$package['name'] = $separate_name;
					$package['quantity'] = 1;
					$package['is_separate_package'] = true;
					$package['separate_package_source'] = $is_old_separate
						? 'product_meta'
						: ($is_profile_forced_separate ? 'profile_force_separate' : 'profile_bulky');
					$package['line_quantity'] = $quantity;
					$package['separate_index'] = $i + 1;
					$separate_packages[] = $package;
				}
				continue;
			}

			$single_package['quantity'] = $quantity;
			$single_package['is_separate_package'] = false;
			if ($build_mode === 'split_by_profile' || $build_mode === 'separate_bulky_profiles') {
				$group_key = !empty($single_package['profile_slug']) ? (string) $single_package['profile_slug'] : 'default';
				if (!isset($combined_by_profile[$group_key])) {
					$combined_by_profile[$group_key] = array();
				}
				$combined_by_profile[$group_key][] = $single_package;
			} else {
				$combined[] = $single_package;
			}
		}

		$combined_colli = array();
		if ($build_mode === 'split_by_profile' || $build_mode === 'separate_bulky_profiles') {
			foreach ($combined_by_profile as $profile_slug => $packages_in_group) {
				$combined_colli = array_merge($combined_colli, $this->build_combined_colli($packages_in_group, (string) $profile_slug));
			}
		} else {
			$combined_colli = $this->build_combined_colli($combined);
		}
		$result['packages'] = array_merge($combined_colli, $separate_packages);
		$result['packages'] = $this->apply_separate_package_strategy($result['packages']);
		$result['summary'] = $this->build_summary($result['packages'], array(
			'profiles_in_use' => array_keys($profiles_in_use),
			'category_slugs' => array_values(array_unique(array_filter($category_slugs))),
			'package_build_mode' => $build_mode,
		));

		return $result;
	}

	private function build_single_package($product, $line_name, $profile_resolution, $quantity) {
		$dimensions = isset($profile_resolution['dimensions']) ? $profile_resolution['dimensions'] : array();
		$weight = isset($profile_resolution['weight']) ? (float) $profile_resolution['weight'] : 0;
		return array(
			'name' => $line_name,
			'description' => $line_name,
			'weight' => $weight,
			'length' => isset($dimensions['length']) ? (float) $dimensions['length'] : 0,
			'width' => isset($dimensions['width']) ? (float) $dimensions['width'] : 0,
			'height' => isset($dimensions['height']) ? (float) $dimensions['height'] : 0,
			'profile_slug' => isset($profile_resolution['profile_slug']) ? (string) $profile_resolution['profile_slug'] : 'default',
			'profile_resolution_source' => isset($profile_resolution['resolution_source']) ? (string) $profile_resolution['resolution_source'] : 'default_profile',
			'profile_resolution_trace' => isset($profile_resolution['resolution_trace']) ? $profile_resolution['resolution_trace'] : array(),
			'missing_dimensions' => !empty($profile_resolution['missing_dimensions']),
			'flags' => isset($profile_resolution['flags']) && is_array($profile_resolution['flags']) ? $profile_resolution['flags'] : array(),
			'product_id' => method_exists($product, 'get_id') ? (int) $product->get_id() : 0,
			'line_quantity' => max(1, (int) $quantity),
		);
	}

	private function build_combined_colli($combined, $profile_scope = '') {
		if (empty($combined)) {
			return array();
		}

		$total_weight = 0;
		$max_length = 0;
		$max_width = 0;
		$max_height = 0;
		$has_missing_dimensions = false;
		$profiles = array();
		$resolution_sources = array();

		foreach ($combined as $package) {
			$quantity = isset($package['quantity']) ? max(1, (int) $package['quantity']) : 1;
			$total_weight += ((float) $package['weight']) * $quantity;
			$max_length = max($max_length, (float) $package['length']);
			$max_width = max($max_width, (float) $package['width']);
			$max_height = max($max_height, (float) $package['height']);
			if (!empty($package['missing_dimensions'])) {
				$has_missing_dimensions = true;
			}
			if (!empty($package['profile_slug'])) {
				$profiles[(string) $package['profile_slug']] = true;
			}
			if (!empty($package['profile_resolution_source'])) {
				$resolution_sources[(string) $package['profile_resolution_source']] = true;
			}
		}

		$combined_label = $profile_scope !== '' ? ('Combined package (' . $profile_scope . ')') : 'Combined package';
		return array(array(
			'name' => $combined_label,
			'description' => $combined_label,
			'weight' => round($total_weight, 3),
			'length' => round($max_length, 3),
			'width' => round($max_width, 3),
			'height' => round($max_height, 3),
			'quantity' => 1,
			'is_separate_package' => false,
			'missing_dimensions' => $has_missing_dimensions,
			'profile_slugs' => array_keys($profiles),
			'combined_profile_scope' => $profile_scope,
			'profile_resolution_sources' => array_keys($resolution_sources),
			'combined_items' => $combined,
		));
	}

	private function build_summary($packages, $extra = array()) {
		$summary = array(
			'colli_count' => 0,
			'total_weight' => 0,
			'separate_package_count' => 0,
			'missing_dimensions' => false,
			'profiles_in_use' => array(),
			'category_slugs' => array(),
			'has_mailbox_capable' => false,
			'has_pickup_capable' => false,
			'all_mailbox_capable' => false,
			'all_pickup_capable' => false,
			'has_bulky' => false,
			'has_high_value_secure' => false,
			'package_build_mode' => isset($extra['package_build_mode']) ? (string) $extra['package_build_mode'] : 'combined_single',
		);
		$total_flag_items = 0;
		$mailbox_capable_items = 0;
		$pickup_capable_items = 0;

		foreach ($packages as $package) {
			$summary['colli_count']++;
			$summary['total_weight'] += isset($package['weight']) ? (float) $package['weight'] : 0;
			if (!empty($package['is_separate_package'])) {
				$summary['separate_package_count']++;
			}
			if (!empty($package['missing_dimensions'])) {
				$summary['missing_dimensions'] = true;
			}
			if (!empty($package['profile_slug'])) {
				$summary['profiles_in_use'][(string) $package['profile_slug']] = true;
			}
			if (isset($package['profile_slugs']) && is_array($package['profile_slugs'])) {
				foreach ($package['profile_slugs'] as $profile_slug) {
					$summary['profiles_in_use'][(string) $profile_slug] = true;
				}
			}
			$this->merge_flag_summary($summary, isset($package['flags']) && is_array($package['flags']) ? $package['flags'] : array());
			if (isset($package['combined_items']) && is_array($package['combined_items'])) {
				foreach ($package['combined_items'] as $combined_item) {
					$flags = isset($combined_item['flags']) && is_array($combined_item['flags']) ? $combined_item['flags'] : array();
					$this->merge_flag_summary($summary, $flags);
					$total_flag_items++;
					if (!empty($flags['mailbox_capable'])) {
						$mailbox_capable_items++;
					}
					if (!empty($flags['pickup_capable'])) {
						$pickup_capable_items++;
					}
				}
				continue;
			}
			$flags = isset($package['flags']) && is_array($package['flags']) ? $package['flags'] : array();
			$total_flag_items++;
			if (!empty($flags['mailbox_capable'])) {
				$mailbox_capable_items++;
			}
			if (!empty($flags['pickup_capable'])) {
				$pickup_capable_items++;
			}
		}

		$summary['profiles_in_use'] = array_keys($summary['profiles_in_use']);
		if (isset($extra['profiles_in_use']) && is_array($extra['profiles_in_use'])) {
			$summary['profiles_in_use'] = array_values(array_unique(array_merge($summary['profiles_in_use'], $extra['profiles_in_use'])));
		}
		if (isset($extra['category_slugs']) && is_array($extra['category_slugs'])) {
			$summary['category_slugs'] = array_values(array_unique($extra['category_slugs']));
		}
		$summary['all_mailbox_capable'] = $total_flag_items > 0 && $mailbox_capable_items === $total_flag_items;
		$summary['all_pickup_capable'] = $total_flag_items > 0 && $pickup_capable_items === $total_flag_items;
		$summary['total_weight'] = round($summary['total_weight'], 3);
		return $summary;
	}

	private function merge_flag_summary(&$summary, $flags) {
		$summary['has_mailbox_capable'] = !empty($summary['has_mailbox_capable']) || !empty($flags['mailbox_capable']);
		$summary['has_pickup_capable'] = !empty($summary['has_pickup_capable']) || !empty($flags['pickup_capable']);
		$summary['has_bulky'] = !empty($summary['has_bulky']) || !empty($flags['bulky']);
		$summary['has_high_value_secure'] = !empty($summary['has_high_value_secure']) || !empty($flags['high_value_secure']);
	}

	private function collect_category_slugs($product_id) {
		$slugs = array();
		$terms = wp_get_post_terms((int) $product_id, 'product_cat');
		if (!is_array($terms)) {
			return $slugs;
		}
		foreach ($terms as $term) {
			if (!is_object($term) || empty($term->slug)) {
				continue;
			}
			$slugs[] = sanitize_key((string) $term->slug);
		}
		return $slugs;
	}

	private function empty_result() {
		return array(
			'packages' => array(),
			'summary' => array(
				'colli_count' => 0,
				'total_weight' => 0,
				'separate_package_count' => 0,
				'missing_dimensions' => false,
				'profiles_in_use' => array(),
				'category_slugs' => array(),
				'has_mailbox_capable' => false,
				'has_pickup_capable' => false,
				'all_mailbox_capable' => false,
				'all_pickup_capable' => false,
				'has_bulky' => false,
				'has_high_value_secure' => false,
				'package_build_mode' => 'combined_single',
			),
		);
	}

	private function get_package_build_mode() {
		$settings = is_callable($this->settings_provider) ? call_user_func($this->settings_provider) : array();
		$mode = isset($settings['package_resolution']['package_build_mode'])
			? sanitize_key((string) $settings['package_resolution']['package_build_mode'])
			: 'combined_single';
		if (!in_array($mode, array('combined_single', 'split_by_profile', 'separate_bulky_profiles'), true)) {
			$mode = 'combined_single';
		}
		return $mode;
	}

	private function get_separate_package_strategy() {
		$settings = is_callable($this->settings_provider) ? call_user_func($this->settings_provider) : array();
		$strategy = isset($settings['package_resolution']['separate_package_strategy'])
			? sanitize_key((string) $settings['package_resolution']['separate_package_strategy'])
			: 'keep_separate_colli';
		if (!in_array($strategy, array('keep_separate_colli', 'merge_non_separate_into_first_separate'), true)) {
			$strategy = 'keep_separate_colli';
		}
		return $strategy;
	}

	private function apply_separate_package_strategy($packages) {
		if (!is_array($packages) || empty($packages)) {
			return array();
		}

		$strategy = $this->get_separate_package_strategy();
		if ($strategy !== 'merge_non_separate_into_first_separate') {
			return $packages;
		}

		$first_separate_index = -1;
		foreach ($packages as $index => $package) {
			if (!empty($package['is_separate_package'])) {
				$first_separate_index = (int) $index;
				break;
			}
		}

		if ($first_separate_index < 0) {
			return $packages;
		}

		$merged = $packages;
		$remove_indexes = array();
		foreach ($merged as $index => $package) {
			if ((int) $index === $first_separate_index || !empty($package['is_separate_package'])) {
				continue;
			}
			$merged[$first_separate_index] = $this->merge_packages($merged[$first_separate_index], $package);
			$remove_indexes[] = (int) $index;
		}

		if (empty($remove_indexes)) {
			return $merged;
		}

		foreach ($remove_indexes as $remove_index) {
			unset($merged[$remove_index]);
		}

		return array_values($merged);
	}

	private function merge_packages($target, $source) {
		$target_weight = isset($target['weight']) ? (float) $target['weight'] : 0;
		$source_weight = isset($source['weight']) ? (float) $source['weight'] : 0;
		$target['weight'] = round($target_weight + $source_weight, 3);
		$target['length'] = round(max(isset($target['length']) ? (float) $target['length'] : 0, isset($source['length']) ? (float) $source['length'] : 0), 3);
		$target['width'] = round(max(isset($target['width']) ? (float) $target['width'] : 0, isset($source['width']) ? (float) $source['width'] : 0), 3);
		$target['height'] = round(max(isset($target['height']) ? (float) $target['height'] : 0, isset($source['height']) ? (float) $source['height'] : 0), 3);
		$target['missing_dimensions'] = !empty($target['missing_dimensions']) || !empty($source['missing_dimensions']);

		$target_items = isset($target['combined_items']) && is_array($target['combined_items']) ? $target['combined_items'] : array($target);
		$source_items = isset($source['combined_items']) && is_array($source['combined_items']) ? $source['combined_items'] : array($source);
		$target['combined_items'] = array_merge($target_items, $source_items);

		$target_profiles = isset($target['profile_slugs']) && is_array($target['profile_slugs']) ? $target['profile_slugs'] : array();
		$source_profiles = isset($source['profile_slugs']) && is_array($source['profile_slugs']) ? $source['profile_slugs'] : array();
		if (isset($source['profile_slug']) && $source['profile_slug'] !== '') {
			$source_profiles[] = (string) $source['profile_slug'];
		}
		if (isset($target['profile_slug']) && $target['profile_slug'] !== '') {
			$target_profiles[] = (string) $target['profile_slug'];
		}
		$target['profile_slugs'] = array_values(array_unique(array_filter(array_merge($target_profiles, $source_profiles))));

		$target['combined_profile_scope'] = '';
		return $target;
	}
}
