<?php
/*
Plugin Name: Lilleprinsen Cargonizer Connector
Description: Egen WooCommerce-adminside for å lagre Cargonizer-autentisering og hente fraktmetoder fra transport_agreements.xml
Version: 1.0.0
Author: Lilleprinsen
*/

if (!defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/includes/class-lp-cargonizer-connector.php';
require_once __DIR__ . '/includes/class-lp-cargonizer-settings-service.php';
require_once __DIR__ . '/includes/class-lp-cargonizer-plugin.php';

$lp_cargonizer_plugin = new LP_Cargonizer_Plugin();
$lp_cargonizer_plugin->bootstrap();
