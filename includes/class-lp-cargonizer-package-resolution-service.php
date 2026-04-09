<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Package_Resolution_Service {
	/** @var callable */
	private $settings_provider;

	public function __construct($settings_provider) {
		$this->settings_provider = $settings_provider;
	}

	public function get_fallback_order() {
		$defaults = array(
			'product_dimensions',
			'product_override',
			'shipping_class_profile',
			'category_profile',
			'value_rule',
			'default_profile',
		);

		$settings = is_callable($this->settings_provider)
			? call_user_func($this->settings_provider)
			: array();

		$resolved = array();
		if (is_array($settings) && isset($settings['package_resolution']['fallback_sources']) && is_array($settings['package_resolution']['fallback_sources'])) {
			foreach ($settings['package_resolution']['fallback_sources'] as $source) {
				$key = sanitize_key((string) $source);
				if (in_array($key, $defaults, true)) {
					$resolved[] = $key;
				}
			}
		}

		$resolved = array_values(array_unique($resolved));
		if (empty($resolved)) {
			return $defaults;
		}

		foreach ($defaults as $source) {
			if (!in_array($source, $resolved, true)) {
				$resolved[] = $source;
			}
		}

		return $resolved;
	}
}
