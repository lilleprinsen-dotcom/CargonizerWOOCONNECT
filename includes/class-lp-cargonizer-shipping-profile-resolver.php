<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Shipping_Profile_Resolver {
	/** @var callable */
	private $settings_provider;

	/** @var LP_Cargonizer_Package_Resolution_Service */
	private $package_resolution_service;

	public function __construct($settings_provider, $package_resolution_service) {
		$this->settings_provider = $settings_provider;
		$this->package_resolution_service = $package_resolution_service;
	}

	public function resolve_for_product($product, $quantity, $line_total = 0) {
		$product_id = is_object($product) && method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
		$fallback_order = $this->package_resolution_service->get_fallback_order();
		$profiles_index = $this->get_profiles_index();
		$default_profile_slug = $this->get_default_profile_slug($profiles_index);

		$result = array(
			'profile_slug' => $default_profile_slug,
			'profile' => isset($profiles_index[$default_profile_slug]) ? $profiles_index[$default_profile_slug] : array(),
			'resolution_source' => 'default_profile',
			'resolution_trace' => array(),
			'dimensions' => array('length' => 0, 'width' => 0, 'height' => 0),
			'weight' => 0,
			'missing_dimensions' => true,
			'flags' => array(),
			'product_id' => $product_id,
		);

		foreach ($fallback_order as $source) {
			$step = $this->resolve_step($source, $product, $quantity, $line_total, $profiles_index, $default_profile_slug);
			$result['resolution_trace'][] = $step;
			if (empty($step['matched'])) {
				continue;
			}

			if (!empty($step['profile_slug']) && isset($profiles_index[$step['profile_slug']])) {
				$result['profile_slug'] = $step['profile_slug'];
				$result['profile'] = $profiles_index[$step['profile_slug']];
			}

			if (!empty($step['dimensions'])) {
				$result['dimensions'] = $this->normalize_dimensions($step['dimensions']);
			}
			if (isset($step['weight'])) {
				$result['weight'] = $this->normalize_number($step['weight']);
			}

			$result['resolution_source'] = $source;
			break;
		}

		$result['dimensions'] = $this->apply_profile_dimension_defaults($result['dimensions'], $result['profile']);
		$result['weight'] = $this->apply_profile_weight_default($result['weight'], $result['profile']);
		$result['missing_dimensions'] = $this->has_missing_dimensions($result['dimensions']);
		$result['flags'] = isset($result['profile']['flags']) && is_array($result['profile']['flags']) ? $result['profile']['flags'] : array();

		return $result;
	}

	private function resolve_step($source, $product, $quantity, $line_total, $profiles_index, $default_profile_slug) {
		$step = array(
			'source' => $source,
			'matched' => false,
			'profile_slug' => '',
			'dimensions' => array(),
			'weight' => null,
		);

		switch ($source) {
			case 'product_dimensions':
				$step['dimensions'] = $this->extract_product_dimensions($product);
				$step['weight'] = $this->extract_product_weight($product);
				$step['matched'] = !$this->has_missing_dimensions($step['dimensions']) && $this->normalize_number($step['weight']) > 0;
				break;
			case 'product_override':
				$step = $this->resolve_product_override($step, $product);
				break;
			case 'shipping_class_profile':
				$step = $this->resolve_shipping_class_profile($step, $product, $profiles_index);
				break;
			case 'category_profile':
				$step = $this->resolve_category_profile($step, $product, $profiles_index);
				break;
			case 'value_rule':
				$step = $this->resolve_value_rule_profile($step, $line_total, $quantity, $profiles_index);
				break;
			case 'default_profile':
				$step['profile_slug'] = $default_profile_slug;
				$step['matched'] = isset($profiles_index[$default_profile_slug]);
				if ($step['matched']) {
					$profile = $profiles_index[$default_profile_slug];
					$step['dimensions'] = isset($profile['default_dimensions']) ? $profile['default_dimensions'] : array();
					$step['weight'] = isset($profile['default_weight']) ? $profile['default_weight'] : 0;
				}
				break;
		}

		return $step;
	}

	private function resolve_product_override($step, $product) {
		if (!is_object($product) || !method_exists($product, 'get_id')) {
			return $step;
		}

		$product_id = (int) $product->get_id();
		$profile_slug = sanitize_key((string) get_post_meta($product_id, '_lp_cargonizer_profile_slug', true));
		$weight = get_post_meta($product_id, '_lp_cargonizer_profile_weight', true);
		$dimensions = array(
			'length' => get_post_meta($product_id, '_lp_cargonizer_profile_length', true),
			'width' => get_post_meta($product_id, '_lp_cargonizer_profile_width', true),
			'height' => get_post_meta($product_id, '_lp_cargonizer_profile_height', true),
		);
		$step['profile_slug'] = $profile_slug;
		$step['weight'] = $weight;
		$step['dimensions'] = $dimensions;
		$step['matched'] = $profile_slug !== '' || !$this->has_missing_dimensions($dimensions) || $this->normalize_number($weight) > 0;
		return $step;
	}

	private function resolve_shipping_class_profile($step, $product, $profiles_index) {
		if (!is_object($product) || !method_exists($product, 'get_shipping_class')) {
			return $step;
		}
		$shipping_class_slug = sanitize_key((string) $product->get_shipping_class());
		if ($shipping_class_slug === '') {
			return $step;
		}
		$mapped_slug = sanitize_key((string) $this->get_shipping_class_profile_map_value($shipping_class_slug));
		if ($mapped_slug === '' || !isset($profiles_index[$mapped_slug])) {
			return $step;
		}
		$profile = $profiles_index[$mapped_slug];
		$step['profile_slug'] = $mapped_slug;
		$step['dimensions'] = isset($profile['default_dimensions']) ? $profile['default_dimensions'] : array();
		$step['weight'] = isset($profile['default_weight']) ? $profile['default_weight'] : 0;
		$step['matched'] = true;
		return $step;
	}

	private function resolve_category_profile($step, $product, $profiles_index) {
		if (!is_object($product) || !method_exists($product, 'get_id')) {
			return $step;
		}
		$terms = wp_get_post_terms((int) $product->get_id(), 'product_cat');
		if (!is_array($terms)) {
			return $step;
		}
		$map = $this->get_category_profile_map();
		foreach ($terms as $term) {
			if (!is_object($term) || empty($term->slug)) {
				continue;
			}
			$term_slug = sanitize_key((string) $term->slug);
			$mapped_slug = isset($map[$term_slug]) ? sanitize_key((string) $map[$term_slug]) : '';
			if ($mapped_slug !== '' && isset($profiles_index[$mapped_slug])) {
				$profile = $profiles_index[$mapped_slug];
				$step['profile_slug'] = $mapped_slug;
				$step['dimensions'] = isset($profile['default_dimensions']) ? $profile['default_dimensions'] : array();
				$step['weight'] = isset($profile['default_weight']) ? $profile['default_weight'] : 0;
				$step['matched'] = true;
				return $step;
			}
		}
		return $step;
	}

	private function resolve_value_rule_profile($step, $line_total, $quantity, $profiles_index) {
		$rules = $this->get_value_profile_rules();
		foreach ($rules as $rule) {
			$min = isset($rule['min_total']) ? $this->normalize_number($rule['min_total']) : 0;
			$max = isset($rule['max_total']) ? $this->normalize_number($rule['max_total']) : 0;
			$profile_slug = isset($rule['profile_slug']) ? sanitize_key((string) $rule['profile_slug']) : '';
			$min_qty = isset($rule['min_quantity']) ? max(0, (int) $rule['min_quantity']) : 0;
			$max_qty = isset($rule['max_quantity']) ? max(0, (int) $rule['max_quantity']) : 0;

			if ($profile_slug === '' || !isset($profiles_index[$profile_slug])) {
				continue;
			}
			if ($line_total < $min) {
				continue;
			}
			if ($max > 0 && $line_total > $max) {
				continue;
			}
			if ($min_qty > 0 && (int) $quantity < $min_qty) {
				continue;
			}
			if ($max_qty > 0 && (int) $quantity > $max_qty) {
				continue;
			}

			$profile = $profiles_index[$profile_slug];
			$step['profile_slug'] = $profile_slug;
			$step['dimensions'] = isset($profile['default_dimensions']) ? $profile['default_dimensions'] : array();
			$step['weight'] = isset($profile['default_weight']) ? $profile['default_weight'] : 0;
			$step['matched'] = true;
			return $step;
		}

		return $step;
	}

	private function get_profiles_index() {
		$settings = is_callable($this->settings_provider)
			? call_user_func($this->settings_provider)
			: array();
		$profiles = isset($settings['shipping_profiles']['profiles']) && is_array($settings['shipping_profiles']['profiles'])
			? $settings['shipping_profiles']['profiles']
			: array();
		$index = array();
		foreach ($profiles as $profile) {
			if (!is_array($profile)) {
				continue;
			}
			$slug = isset($profile['slug']) ? sanitize_key((string) $profile['slug']) : '';
			if ($slug === '') {
				continue;
			}
			$index[$slug] = $profile;
		}
		return $index;
	}

	private function get_default_profile_slug($profiles_index) {
		$settings = is_callable($this->settings_provider)
			? call_user_func($this->settings_provider)
			: array();
		$candidate = isset($settings['shipping_profiles']['default_profile_slug'])
			? sanitize_key((string) $settings['shipping_profiles']['default_profile_slug'])
			: '';
		if ($candidate !== '' && isset($profiles_index[$candidate])) {
			return $candidate;
		}
		$keys = array_keys($profiles_index);
		return !empty($keys) ? (string) $keys[0] : 'default';
	}

	private function get_shipping_class_profile_map_value($shipping_class_slug) {
		$settings = is_callable($this->settings_provider)
			? call_user_func($this->settings_provider)
			: array();
		$map = isset($settings['shipping_profiles']['shipping_class_map']) && is_array($settings['shipping_profiles']['shipping_class_map'])
			? $settings['shipping_profiles']['shipping_class_map']
			: array();
		return isset($map[$shipping_class_slug]) ? $map[$shipping_class_slug] : '';
	}

	private function get_category_profile_map() {
		$settings = is_callable($this->settings_provider)
			? call_user_func($this->settings_provider)
			: array();
		$map = isset($settings['shipping_profiles']['category_map']) && is_array($settings['shipping_profiles']['category_map'])
			? $settings['shipping_profiles']['category_map']
			: array();
		$clean = array();
		foreach ($map as $category_slug => $profile_slug) {
			$clean[sanitize_key((string) $category_slug)] = sanitize_key((string) $profile_slug);
		}
		return $clean;
	}

	private function get_value_profile_rules() {
		$settings = is_callable($this->settings_provider)
			? call_user_func($this->settings_provider)
			: array();
		$rules = isset($settings['shipping_profiles']['value_rules']) && is_array($settings['shipping_profiles']['value_rules'])
			? $settings['shipping_profiles']['value_rules']
			: array();
		return $rules;
	}

	private function extract_product_dimensions($product) {
		$dimensions = array('length' => 0, 'width' => 0, 'height' => 0);
		if (!is_object($product)) {
			return $dimensions;
		}
		if (method_exists($product, 'get_length')) {
			$dimensions['length'] = $this->normalize_number($product->get_length());
		}
		if (method_exists($product, 'get_width')) {
			$dimensions['width'] = $this->normalize_number($product->get_width());
		}
		if (method_exists($product, 'get_height')) {
			$dimensions['height'] = $this->normalize_number($product->get_height());
		}
		return $dimensions;
	}

	private function extract_product_weight($product) {
		if (!is_object($product) || !method_exists($product, 'get_weight')) {
			return 0;
		}
		return $this->normalize_number($product->get_weight());
	}

	private function apply_profile_dimension_defaults($dimensions, $profile) {
		$dimensions = $this->normalize_dimensions($dimensions);
		$defaults = isset($profile['default_dimensions']) && is_array($profile['default_dimensions']) ? $profile['default_dimensions'] : array();
		foreach (array('length', 'width', 'height') as $key) {
			if ($dimensions[$key] > 0) {
				continue;
			}
			$dimensions[$key] = isset($defaults[$key]) ? $this->normalize_number($defaults[$key]) : 0;
		}
		return $dimensions;
	}

	private function apply_profile_weight_default($weight, $profile) {
		$weight = $this->normalize_number($weight);
		if ($weight > 0) {
			return $weight;
		}
		return isset($profile['default_weight']) ? $this->normalize_number($profile['default_weight']) : 0;
	}

	private function normalize_dimensions($dimensions) {
		return array(
			'length' => isset($dimensions['length']) ? $this->normalize_number($dimensions['length']) : 0,
			'width' => isset($dimensions['width']) ? $this->normalize_number($dimensions['width']) : 0,
			'height' => isset($dimensions['height']) ? $this->normalize_number($dimensions['height']) : 0,
		);
	}

	private function has_missing_dimensions($dimensions) {
		$dimensions = $this->normalize_dimensions($dimensions);
		return $dimensions['length'] <= 0 || $dimensions['width'] <= 0 || $dimensions['height'] <= 0;
	}

	private function normalize_number($value) {
		if (is_string($value)) {
			$value = str_replace(',', '.', $value);
		}
		if (!is_numeric($value)) {
			return 0;
		}
		$number = (float) $value;
		return $number > 0 ? round($number, 3) : 0;
	}
}
