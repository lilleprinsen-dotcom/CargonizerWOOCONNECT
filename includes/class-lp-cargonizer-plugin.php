<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Plugin {
	/** @var LP_Cargonizer_Connector */
	private $connector;
	/** @var LP_Cargonizer_Checkout_Pickup_Controller */
	private $checkout_pickup_controller;
	/** @var LP_Cargonizer_Checkout_Selection_Persistence_Service */
	private $checkout_selection_persistence_service;
	/** @var LP_Cargonizer_Checkout_Pickup_Compatibility_Layer */
	private $checkout_pickup_compatibility_layer;
	/** @var LP_Cargonizer_Settings_Service */
	private $settings_service;

	public function __construct() {
		$this->settings_service = new LP_Cargonizer_Settings_Service(LP_Cargonizer_Connector::OPTION_KEY, LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY);
		$this->connector = new LP_Cargonizer_Connector();
		$this->checkout_pickup_controller = new LP_Cargonizer_Checkout_Pickup_Controller();
		$this->checkout_pickup_compatibility_layer = new LP_Cargonizer_Checkout_Pickup_Compatibility_Layer();
		$this->checkout_selection_persistence_service = new LP_Cargonizer_Checkout_Selection_Persistence_Service();
	}

	public function bootstrap() {
		$this->log_live_checkout_event('debug', 'Bootstrapping Cargonizer plugin services.');
		$this->connector->register_hooks();
		$this->checkout_pickup_controller->register_hooks();
		$this->checkout_pickup_compatibility_layer->register_hooks();
		$this->checkout_selection_persistence_service->register_hooks();
		add_action('woocommerce_shipping_init', array($this, 'register_live_shipping_method_class'));
		add_filter('woocommerce_shipping_methods', array($this, 'register_live_shipping_method_id'));
	}

	public function register_live_shipping_method_class() {
		if (!class_exists('WC_Shipping_Method')) {
			$this->log_live_checkout_event('debug', 'Skipped live shipping method class bootstrap: WC_Shipping_Method unavailable.');
			return;
		}

		$this->log_live_checkout_event('debug', 'Bootstrapping live shipping method class.');
		require_once __DIR__ . '/class-lp-cargonizer-live-shipping-method.php';
	}

	public function register_live_shipping_method_id($methods) {
		if (!is_array($methods)) {
			$methods = array();
		}
		if (!class_exists('LP_Cargonizer_Live_Checkout')) {
			$this->log_live_checkout_event('debug', 'Skipped live shipping method registration: LP_Cargonizer_Live_Checkout class unavailable.');
			return $methods;
		}
		$methods[LP_Cargonizer_Live_Checkout::METHOD_ID] = 'LP_Cargonizer_Live_Shipping_Method';
		$this->log_live_checkout_event('debug', 'Registered live shipping method identifier.', array(
			'method_id' => LP_Cargonizer_Live_Checkout::METHOD_ID,
		));
		return $methods;
	}

	private function log_live_checkout_event($level, $message, $context = array()) {
		if (!$this->should_log_live_checkout_events()) {
			return;
		}
		if (!function_exists('wc_get_logger')) {
			return;
		}
		$logger = wc_get_logger();
		if (!$logger) {
			return;
		}
		$method = method_exists($logger, $level) ? $level : 'info';
		$logger->$method($message, array(
			'source' => 'lp-cargonizer-live-checkout',
			'context' => is_array($context) ? $context : array(),
		));
	}

	private function should_log_live_checkout_events() {
		$settings = $this->settings_service->get_settings();
		$live_settings = isset($settings['live_checkout']) && is_array($settings['live_checkout']) ? $settings['live_checkout'] : array();
		return !empty($live_settings['debug_logging']);
	}
}
