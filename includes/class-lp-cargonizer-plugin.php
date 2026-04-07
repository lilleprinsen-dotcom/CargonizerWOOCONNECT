<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Plugin {
	/** @var LP_Cargonizer_Connector */
	private $connector;

	public function __construct() {
		$this->connector = new LP_Cargonizer_Connector();
	}

	public function bootstrap() {
		$this->connector->register_hooks();
	}
}
