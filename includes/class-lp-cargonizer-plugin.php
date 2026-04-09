<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Plugin {
	/** @var LP_Cargonizer_Connector */
	private $connector;
	/** @var LP_Cargonizer_Checkout_Pickup_Controller */
	private $checkout_pickup_controller;

	public function __construct() {
		$this->connector = new LP_Cargonizer_Connector();
		$this->checkout_pickup_controller = new LP_Cargonizer_Checkout_Pickup_Controller();
	}

	public function bootstrap() {
		$this->connector->register_hooks();
		$this->checkout_pickup_controller->register_hooks();
		add_action('woocommerce_shipping_init', array($this, 'register_live_shipping_method_class'));
		add_filter('woocommerce_shipping_methods', array($this, 'register_live_shipping_method_id'));
	}

	public function register_live_shipping_method_class() {
		if (!class_exists('WC_Shipping_Method')) {
			return;
		}

		require_once __DIR__ . '/class-lp-cargonizer-live-shipping-method.php';
	}

	public function register_live_shipping_method_id($methods) {
		if (class_exists('LP_Cargonizer_Live_Shipping_Method')) {
			$methods[LP_Cargonizer_Live_Shipping_Method::METHOD_ID] = 'LP_Cargonizer_Live_Shipping_Method';
		}

		return $methods;
	}
}
