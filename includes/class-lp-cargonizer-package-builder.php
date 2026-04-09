<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Package_Builder {
	/** @var LP_Cargonizer_Shipping_Profile_Resolver */
	private $profile_resolver;

	public function __construct($profile_resolver) {
		$this->profile_resolver = $profile_resolver;
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
		$combined = array();
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

			$is_separate = get_post_meta($product_id, '_wildrobot_separate_package_for_product', true) === 'yes';
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
					$package['line_quantity'] = $quantity;
					$package['separate_index'] = $i + 1;
					$separate_packages[] = $package;
				}
				continue;
			}

			$single_package['quantity'] = $quantity;
			$single_package['is_separate_package'] = false;
			$combined[] = $single_package;
		}

		$combined_colli = $this->build_combined_colli($combined);
		$result['packages'] = array_merge($combined_colli, $separate_packages);
		$result['summary'] = $this->build_summary($result['packages'], array(
			'profiles_in_use' => array_keys($profiles_in_use),
			'category_slugs' => array_values(array_unique(array_filter($category_slugs))),
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

	private function build_combined_colli($combined) {
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

		return array(array(
			'name' => 'Combined package',
			'description' => 'Combined package',
			'weight' => round($total_weight, 3),
			'length' => round($max_length, 3),
			'width' => round($max_width, 3),
			'height' => round($max_height, 3),
			'quantity' => 1,
			'is_separate_package' => false,
			'missing_dimensions' => $has_missing_dimensions,
			'profile_slugs' => array_keys($profiles),
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
			'has_bulky' => false,
			'has_high_value_secure' => false,
		);

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
					$this->merge_flag_summary($summary, isset($combined_item['flags']) && is_array($combined_item['flags']) ? $combined_item['flags'] : array());
				}
			}
		}

		$summary['profiles_in_use'] = array_keys($summary['profiles_in_use']);
		if (isset($extra['profiles_in_use']) && is_array($extra['profiles_in_use'])) {
			$summary['profiles_in_use'] = array_values(array_unique(array_merge($summary['profiles_in_use'], $extra['profiles_in_use'])));
		}
		if (isset($extra['category_slugs']) && is_array($extra['category_slugs'])) {
			$summary['category_slugs'] = array_values(array_unique($extra['category_slugs']));
		}
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
				'has_bulky' => false,
				'has_high_value_secure' => false,
			),
		);
	}
}
