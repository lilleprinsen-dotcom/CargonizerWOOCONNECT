<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Posten_Label_Automation {
	const AJAX_ACTION_QUEUE = 'lp_cargonizer_queue_posten_label_job';
	const NONCE_ACTION_QUEUE = 'lp_cargonizer_queue_posten_label_job';
	const REST_NAMESPACE = 'lilleprinsen/v1';
	const REST_ROUTE_PRIMARY = '/posten-jobs';
	const REST_ROUTE_LEGACY = '/posten-label-jobs';
	const TOKEN_HEADER = 'X-LP-Posten-Robot-Token';
	const SCHEMA_VERSION_OPTION = 'lp_cargonizer_posten_jobs_schema_version';
	const SCHEMA_VERSION = '4';
	const ORDER_STATUS_WAITING = 'lp-waiting-label';
	const ORDER_STATUS_CREATED = 'lp-label-created';
	const JOB_STATUS_QUEUED = 'queued';
	const JOB_STATUS_PROCESSING = 'processing';
	const JOB_STATUS_COMPLETED = 'completed';
	const JOB_STATUS_FAILED = 'failed';
	const JOB_STATUS_PARTIAL_FAILED = 'partial_failed';
	const JOB_STATUS_CANCELLED = 'cancelled';
	const META_JOB_ID = '_lp_posten_label_job_id';
	const META_LABEL_STATUS = '_lp_posten_label_status';
	const META_TRACKING_NUMBER = '_lp_posten_tracking_number';
	const META_TRACKING_NUMBERS = '_lp_posten_tracking_numbers';
	const META_TRACKING_URL = '_lp_posten_tracking_url';
	const META_PACKAGE_RESULTS = '_lp_posten_package_results';
	const META_LABEL_FILE_PATH = '_lp_posten_label_file_path';
	const META_LABEL_FILES = '_lp_posten_label_files';
	const META_STAMPED_LABEL_FILES = '_lp_posten_stamped_label_files';
	const META_PRINT_RESULTS = '_lp_posten_print_results';
	const META_DIRECT_PRINT_ENABLED = '_lp_posten_direct_print_enabled';
	const META_DIRECT_PRINT_PRINTER_ID = '_lp_posten_direct_print_printer_id';
	const META_STAMP_ENABLED = '_lp_posten_stamp_enabled';
	const META_LABEL_ATTACHMENT_ID = '_lp_posten_label_attachment_id';
	const META_LABEL_PRINTED = '_lp_posten_label_printed';
	const META_REQUESTED_AT_GMT = '_lp_posten_label_requested_at_gmt';
	const META_COMPLETED_AT_GMT = '_lp_posten_label_completed_at_gmt';

	/** @var LP_Cargonizer_Posten_Label_Automation|null */
	private static $instance = null;

	/** @var LP_Cargonizer_Settings_Service */
	private $settings_service;

	/** @var LP_Cargonizer_Api_Service */
	private $api_service;

	public static function instance() {
		if (!self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public static function generate_robot_token() {
		if (function_exists('wp_generate_password')) {
			return 'lp_posten_' . wp_generate_password(48, false, false);
		}

		return 'lp_posten_' . bin2hex(random_bytes(24));
	}

	public static function hash_robot_token($token) {
		$token = trim((string) $token);
		if ($token === '') {
			return '';
		}

		return hash_hmac('sha256', $token, wp_salt('auth'));
	}

	public static function get_queue_nonce_action() {
		return self::NONCE_ACTION_QUEUE;
	}

	public function __construct() {
		$this->settings_service = new LP_Cargonizer_Settings_Service(LP_Cargonizer_Connector::OPTION_KEY, LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY);
		$this->api_service = new LP_Cargonizer_Api_Service(function () {
			return $this->settings_service->get_settings();
		});
	}

	public function register_hooks() {
		add_action('init', array($this, 'register_order_statuses'));
		add_action('init', array($this, 'maybe_install_table'), 20);
		add_filter('wc_order_statuses', array($this, 'add_order_statuses'));
		add_action('rest_api_init', array($this, 'register_rest_routes'));
		add_action('wp_ajax_' . self::AJAX_ACTION_QUEUE, array($this, 'ajax_queue_posten_label_job'));
		add_action('woocommerce_admin_order_data_after_order_details', array($this, 'render_order_label_status_panel'), 20);
		add_action('woocommerce_payment_complete', array($this, 'maybe_auto_queue_for_order'), 20, 1);
		add_action('woocommerce_order_status_processing', array($this, 'maybe_auto_queue_for_order'), 20, 1);
		add_action('woocommerce_order_status_cancelled', array($this, 'cancel_active_jobs_for_order'), 20, 2);
	}

	public function register_order_statuses() {
		register_post_status('wc-' . self::ORDER_STATUS_WAITING, array(
			'label' => 'Venter på etikett',
			'public' => false,
			'exclude_from_search' => false,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
			'label_count' => _n_noop('Venter på etikett <span class="count">(%s)</span>', 'Venter på etikett <span class="count">(%s)</span>', 'lp-cargonizer'),
		));

		register_post_status('wc-' . self::ORDER_STATUS_CREATED, array(
			'label' => 'Etikett opprettet',
			'public' => false,
			'exclude_from_search' => false,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
			'label_count' => _n_noop('Etikett opprettet <span class="count">(%s)</span>', 'Etikett opprettet <span class="count">(%s)</span>', 'lp-cargonizer'),
		));
	}

	public function add_order_statuses($statuses) {
		if (!is_array($statuses)) {
			$statuses = array();
		}

		$statuses['wc-' . self::ORDER_STATUS_WAITING] = 'Venter på etikett';
		$statuses['wc-' . self::ORDER_STATUS_CREATED] = 'Etikett opprettet';
		return $statuses;
	}

	public function maybe_install_table() {
		if (get_option(self::SCHEMA_VERSION_OPTION) === self::SCHEMA_VERSION && $this->table_exists()) {
			return;
		}

		global $wpdb;
		$table = $this->get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			job_id varchar(64) NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			order_number varchar(64) NOT NULL DEFAULT '',
			status varchar(32) NOT NULL DEFAULT 'queued',
			source varchar(64) NOT NULL DEFAULT '',
			service varchar(64) NOT NULL DEFAULT 'norgespakke',
			method_key varchar(191) NOT NULL DEFAULT 'manual|norgespakke',
			recipient_json longtext NOT NULL,
			packages_json longtext NOT NULL,
			shipping_json longtext NOT NULL,
			sender_json longtext NOT NULL,
			items_json longtext NOT NULL,
			tracking_number varchar(191) NOT NULL DEFAULT '',
			tracking_url text NULL,
			tracking_numbers_json longtext NULL,
			package_results_json longtext NULL,
			label_files_json longtext NULL,
			stamped_label_files_json longtext NULL,
			print_results_json longtext NULL,
			label_file_path text NULL,
			label_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			printed tinyint(1) NOT NULL DEFAULT 0,
			worker_id varchar(191) NOT NULL DEFAULT '',
			attempts int unsigned NOT NULL DEFAULT 0,
			last_error text NULL,
			requested_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at_gmt datetime NULL,
			updated_at_gmt datetime NULL,
			claimed_at_gmt datetime NULL,
			completed_at_gmt datetime NULL,
			failed_at_gmt datetime NULL,
			requested_at_gmt datetime NULL,
			failure_message text NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_id (job_id),
			KEY order_id (order_id),
			KEY status (status),
			KEY method_key (method_key),
			KEY created_at_gmt (created_at_gmt),
			KEY worker_id (worker_id),
			KEY requested_at_gmt (requested_at_gmt)
		) {$charset_collate};";

		dbDelta($sql);
		$this->migrate_table_defaults();
		update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false);
	}

	public function register_rest_routes() {
		foreach (array(self::REST_ROUTE_PRIMARY, self::REST_ROUTE_LEGACY) as $route_base) {
			register_rest_route(self::REST_NAMESPACE, $route_base, array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array($this, 'rest_list_jobs'),
				'permission_callback' => array($this, 'rest_robot_or_admin_permission'),
				'args' => array(
					'status' => array('required' => false),
					'limit' => array('required' => false),
				),
			));

			register_rest_route(self::REST_NAMESPACE, $route_base . '/(?P<job_id>[A-Za-z0-9_-]+)', array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array($this, 'rest_get_job'),
				'permission_callback' => array($this, 'rest_robot_or_admin_permission'),
			));

			register_rest_route(self::REST_NAMESPACE, $route_base . '/(?P<job_id>[A-Za-z0-9_-]+)/claim', array(
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => array($this, 'rest_claim_job'),
				'permission_callback' => array($this, 'rest_robot_or_admin_permission'),
			));

			register_rest_route(self::REST_NAMESPACE, $route_base . '/(?P<job_id>[A-Za-z0-9_-]+)/complete', array(
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => array($this, 'rest_complete_job'),
				'permission_callback' => array($this, 'rest_robot_or_admin_permission'),
			));

			register_rest_route(self::REST_NAMESPACE, $route_base . '/(?P<job_id>[A-Za-z0-9_-]+)/fail', array(
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => array($this, 'rest_fail_job'),
				'permission_callback' => array($this, 'rest_robot_or_admin_permission'),
			));

			register_rest_route(self::REST_NAMESPACE, $route_base . '/(?P<job_id>[A-Za-z0-9_-]+)/label', array(
				'methods' => WP_REST_Server::READABLE,
				'callback' => array($this, 'rest_download_label'),
				'permission_callback' => array($this, 'rest_admin_permission'),
			));
		}
	}

	public function ajax_queue_posten_label_job() {
		if (!current_user_can('manage_woocommerce')) {
			wp_send_json_error(array('message' => 'Ingen tilgang.'), 403);
		}

		$nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
		if (!wp_verify_nonce($nonce, self::NONCE_ACTION_QUEUE)) {
			wp_send_json_error(array('message' => 'Ugyldig nonce.'), 403);
		}

		$order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
		$order = $order_id ? wc_get_order($order_id) : false;
		if (!$order) {
			wp_send_json_error(array('message' => 'Ordre ikke funnet.'), 404);
		}

		$packages = isset($_POST['packages']) && is_array($_POST['packages']) ? wp_unslash($_POST['packages']) : array();
		$methods = isset($_POST['methods']) && is_array($_POST['methods']) ? wp_unslash($_POST['methods']) : array();
		if (empty($packages)) {
			wp_send_json_error(array('message' => 'Mangler kolli.'), 400);
		}
		if (count($methods) !== 1) {
			wp_send_json_error(array('message' => 'Velg noyaktig en fraktmetode for Posten labeljobb.'), 400);
		}

		$method_payload = $this->sanitize_method_payload($methods[0]);
		$result = $this->queue_from_admin_request($order, $method_payload, $packages, array(
			'warehouse_profile_id' => isset($_POST['warehouse_profile_id']) ? sanitize_key(wp_unslash($_POST['warehouse_profile_id'])) : '',
			'notify_email_to_consignee' => isset($_POST['notify_email_to_consignee']) ? (bool) $this->settings_service->sanitize_checkbox_value(wp_unslash($_POST['notify_email_to_consignee'])) : false,
			'source' => 'admin_manual_norgespakke',
		));

		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()), 400);
		}

		wp_send_json_success($result);
	}

	public function queue_from_admin_request($order, $method_payload, $packages, $args = array()) {
		$settings = $this->settings_service->get_settings();
		$robot_settings = isset($settings['posten_robot']) && is_array($settings['posten_robot']) ? $settings['posten_robot'] : array();
		if (empty($robot_settings['enabled'])) {
			return new WP_Error('posten_robot_disabled', 'Posten robotko er deaktivert i Cargonizer-innstillingene.');
		}

		if (!$this->settings_service->is_manual_norgespakke_method($method_payload)) {
			return new WP_Error('posten_robot_wrong_method', 'Posten robotko kan bare brukes for manuell Norgespakke.');
		}

		if (!$this->is_manual_norgespakke_enabled($settings)) {
			return new WP_Error('posten_robot_method_disabled', 'Manuell Norgespakke er ikke aktivert i Cargonizer-innstillingene.');
		}

		$method_payload['key'] = LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY;
		$method_payload['agreement_id'] = 'manual';
		$method_payload['product_id'] = 'norgespakke';
		$method_payload['carrier_id'] = isset($method_payload['carrier_id']) && $method_payload['carrier_id'] !== '' ? $method_payload['carrier_id'] : 'posten';
		$method_payload['carrier_name'] = isset($method_payload['carrier_name']) && $method_payload['carrier_name'] !== '' ? $method_payload['carrier_name'] : 'Posten';
		$method_payload['product_name'] = isset($method_payload['product_name']) && $method_payload['product_name'] !== '' ? $method_payload['product_name'] : 'Norgespakke';
		$method_payload['is_manual_norgespakke'] = true;
		$args['source'] = isset($args['source']) && $args['source'] !== '' ? sanitize_key((string) $args['source']) : 'admin_manual_norgespakke';
		if ($args['source'] === 'admin_modal' || $args['source'] === 'legacy_booking_ajax') {
			$args['source'] = 'admin_manual_norgespakke';
		}

		$queued = $this->queue_job_for_order($order, $method_payload, $packages, $args);
		if (is_wp_error($queued)) {
			return $queued;
		}

		return array(
			'booking' => $this->build_admin_booking_response($queued['job'], $queued['payload'], $method_payload, $args),
			'posten_label_job' => $queued['payload'],
		);
	}

	public function queue_job_for_order($order, $method_payload, $packages, $args = array()) {
		if (!$order || !is_object($order) || !method_exists($order, 'get_id')) {
			return new WP_Error('invalid_order', 'Ordre ikke funnet.');
		}

		$order_id = (int) $order->get_id();
		if (method_exists($order, 'get_status') && (string) $order->get_status() === 'cancelled') {
			return new WP_Error('posten_job_order_cancelled', 'Posten etikettjobb kan ikke opprettes fordi WooCommerce-ordren er kansellert.');
		}
		$method_key = LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY;
		$clean_packages = $this->sanitize_packages($packages);
		if (is_wp_error($clean_packages)) {
			return $clean_packages;
		}

		$sender = $this->resolve_sender_profile(isset($args['warehouse_profile_id']) ? $args['warehouse_profile_id'] : '');
		$recipient = $this->build_recipient_snapshot($order);
		$recipient_validation = $this->validate_recipient_for_norgespakke($recipient);
		if (is_wp_error($recipient_validation)) {
			return $recipient_validation;
		}
		$shipping = $this->build_shipping_snapshot($order, $method_payload, $args);
		$items = $this->build_items_snapshot($order);
		$source = isset($args['source']) && $args['source'] !== '' ? sanitize_key((string) $args['source']) : 'admin_manual_norgespakke';
		$order_number = method_exists($order, 'get_order_number') ? sanitize_text_field((string) $order->get_order_number()) : (string) $order_id;

		$lock_name = $this->get_order_method_lock_name($order_id, $method_key);
		$lock_acquired = $this->acquire_job_lock($lock_name);
		if (is_wp_error($lock_acquired)) {
			return $lock_acquired;
		}

		global $wpdb;
		$table = $this->get_table_name();
		try {
			$existing = $this->get_active_job_for_order($order_id, $method_key);
			if ($existing) {
				return array(
					'job' => $existing,
					'payload' => $this->format_job_response($existing, true),
					'created' => false,
				);
			}

			$now = gmdate('Y-m-d H:i:s');
			$job_id = '';
			$db_error = '';
			$inserted = false;
			$insert_formats = array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s');
			for ($job_id_attempt = 0; $job_id_attempt < 8; $job_id_attempt++) {
				$job_id = $this->generate_job_id($order_id, $job_id_attempt);
				$inserted = $wpdb->insert($table, array(
					'job_id' => $job_id,
					'order_id' => $order_id,
					'order_number' => $order_number,
					'status' => self::JOB_STATUS_QUEUED,
					'source' => $source,
					'service' => 'norgespakke',
					'method_key' => $method_key,
					'recipient_json' => $this->json_encode($recipient),
					'packages_json' => $this->json_encode($clean_packages),
					'shipping_json' => $this->json_encode($shipping),
					'sender_json' => $this->json_encode($sender),
					'items_json' => $this->json_encode($items),
					'tracking_number' => '',
					'tracking_url' => '',
					'label_file_path' => '',
					'label_attachment_id' => 0,
					'printed' => 0,
					'worker_id' => '',
					'attempts' => 0,
					'last_error' => '',
					'requested_by_user_id' => get_current_user_id(),
					'created_at_gmt' => $now,
					'updated_at_gmt' => $now,
					'requested_at_gmt' => $now,
					'failure_message' => '',
				), $insert_formats);

				if ($inserted) {
					break;
				}

				$db_error = isset($wpdb->last_error) ? trim((string) $wpdb->last_error) : '';
				if (!$this->is_duplicate_job_id_insert_error($db_error)) {
					break;
				}
			}

			if (!$inserted) {
				return new WP_Error('posten_job_insert_failed', 'Kunne ikke opprette Posten etikettjobb i databasen.' . ($db_error !== '' ? ' Databasefeil: ' . $db_error : ''));
			}

			$job = $this->get_job_by_id($job_id);
			if (!$job) {
				return new WP_Error('posten_job_missing_after_insert', 'Posten etikettjobb ble opprettet, men kunne ikke leses tilbake.');
			}
		} finally {
			$this->release_job_lock($lock_name);
		}

		$this->write_order_queue_metadata($order, $job, $recipient, $sender, $clean_packages, $shipping);
		$this->set_order_status_verified($order, self::ORDER_STATUS_WAITING, 'Posten Norgespakke etikettjobb opprettet.');
		$this->add_private_order_note($order, $this->build_queue_order_note($job, $recipient, $sender, $clean_packages, $shipping));

		return array(
			'job' => $job,
			'payload' => $this->format_job_response($job, true),
			'created' => true,
		);
	}

	public function maybe_auto_queue_for_order($order_id) {
		$order_id = absint($order_id);
		if ($order_id < 1) {
			return;
		}

		try {
			$settings = $this->settings_service->get_settings();
			$robot_settings = isset($settings['posten_robot']) && is_array($settings['posten_robot']) ? $settings['posten_robot'] : array();
			if (empty($robot_settings['enabled']) || empty($robot_settings['auto_queue_checkout_norgespakke'])) {
				return;
			}

			$order = wc_get_order($order_id);
			if (!$order) {
				return;
			}

			$selection = $order->get_meta('_lp_cargonizer_checkout_selection', true);
			if (!is_array($selection)) {
				return;
			}

			$shipping = isset($selection['shipping']) && is_array($selection['shipping']) ? $selection['shipping'] : array();
			$method_key = isset($shipping['method_key']) ? sanitize_text_field((string) $shipping['method_key']) : '';
			$method_payload = array(
				'key' => $method_key,
				'agreement_id' => isset($shipping['transport_agreement_id']) ? sanitize_text_field((string) $shipping['transport_agreement_id']) : '',
				'product_id' => isset($shipping['product_id']) ? sanitize_text_field((string) $shipping['product_id']) : '',
				'product_name' => isset($shipping['label']) ? sanitize_text_field((string) $shipping['label']) : 'Norgespakke',
				'selected_price' => isset($shipping['cost_incl_vat']) ? sanitize_text_field((string) $shipping['cost_incl_vat']) : '',
				'selected_price_source' => 'checkout_selection',
				'is_manual_norgespakke' => $method_key === LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY,
			);

			if (!$this->settings_service->is_manual_norgespakke_method($method_payload)) {
				return;
			}

			$packages = $this->build_packages_from_order($order);
			if (empty($packages)) {
				$this->add_private_order_note($order, 'Posten auto-ko ble hoppet over: ingen gyldige kolli kunne bygges fra ordren.');
				return;
			}

			$this->queue_from_admin_request($order, $method_payload, $packages, array(
				'warehouse_profile_id' => '',
				'notify_email_to_consignee' => false,
				'source' => 'checkout_auto',
				'checkout_selection' => $selection,
			));
		} catch (Throwable $throwable) {
			$this->log_event('error', 'Posten auto-queue failed.', array(
				'order_id' => $order_id,
				'error' => $throwable->getMessage(),
			));
		}
	}

	public function cancel_active_jobs_for_order($order_id, $order = null) {
		$order_id = absint($order_id);
		if ($order_id < 1) {
			return 0;
		}

		$this->maybe_install_table();
		global $wpdb;
		$table = $this->get_table_name();
		$now = gmdate('Y-m-d H:i:s');
		$message = 'WooCommerce-ordren ble kansellert.';
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, last_error = %s, failure_message = %s, updated_at_gmt = %s WHERE order_id = %d AND status IN (%s, %s)",
				self::JOB_STATUS_CANCELLED,
				$message,
				$message,
				$now,
				$order_id,
				self::JOB_STATUS_QUEUED,
				self::JOB_STATUS_PROCESSING
			)
		);

		if ((int) $updated > 0) {
			$order = is_object($order) ? $order : wc_get_order($order_id);
			if ($order) {
				$order->update_meta_data(self::META_LABEL_STATUS, self::JOB_STATUS_CANCELLED);
				$order->save();
				$this->add_private_order_note($order, 'Posten Norgespakke etikettjobb kansellert fordi ordren ble kansellert. Antall jobber: ' . (int) $updated . '.');
			}
		}

		return (int) $updated;
	}

	public function render_order_label_status_panel($order) {
		if (!current_user_can('manage_woocommerce') || !$order || !is_object($order) || !method_exists($order, 'get_id')) {
			return;
		}

		$job = $this->get_latest_job_for_order((int) $order->get_id());
		if (!$job) {
			return;
		}

		$payload = $this->format_job_response($job, true);
		$status = isset($payload['status']) ? (string) $payload['status'] : '';
		$status_label = $this->get_job_status_label($status);
		$tracking_url = isset($payload['tracking_url']) ? (string) $payload['tracking_url'] : '';
		$tracking_number = isset($payload['tracking_number']) ? (string) $payload['tracking_number'] : '';
		$label_url = isset($payload['label_url']) ? (string) $payload['label_url'] : '';
		$packages = isset($payload['packages']) && is_array($payload['packages']) ? $payload['packages'] : array();
		$package_results = isset($payload['package_results']) && is_array($payload['package_results']) ? $payload['package_results'] : array();
		$package_map = $this->build_package_index_map($packages);
		$worker_id = isset($payload['worker_id']) ? (string) $payload['worker_id'] : '';
		$last_error = isset($payload['last_error']) ? (string) $payload['last_error'] : '';

		echo '<div class="lp-posten-label-status" style="clear:both;margin-top:12px;padding:10px 12px;border:1px solid #dcdcde;background:#f6f7f7;">';
		echo '<strong>Posten/Norgespakke automasjon</strong>';
		echo '<div style="margin-top:6px;">Status: ' . esc_html($status_label !== '' ? $status_label : '-') . '</div>';
		echo '<div>Jobb-ID: <code>' . esc_html((string) $job->job_id) . '</code></div>';
		echo '<div>Antall kolli: ' . esc_html((string) count($packages)) . '</div>';
		if ($worker_id !== '' && $status === self::JOB_STATUS_PROCESSING) {
			echo '<div>Worker ID: ' . esc_html($worker_id) . '</div>';
		}
		if ($last_error !== '' && in_array($status, array(self::JOB_STATUS_FAILED, self::JOB_STATUS_PARTIAL_FAILED), true)) {
			echo '<div style="color:#b32d2e;">Siste feil: ' . esc_html($last_error) . '</div>';
		}
		if ($this->is_retryable_terminal_job_status($status)) {
			echo '<div style="margin-top:6px;color:#125228;">Denne jobben kan bookes på nytt fra Book shipment.</div>';
		}
		if (!empty($package_results)) {
			echo '<div style="margin-top:6px;"><strong>Kolli/etiketter:</strong></div>';
			foreach ($package_results as $result) {
				$package_index = isset($result['package_index']) ? absint($result['package_index']) : 0;
				$package = $package_index > 0 && isset($package_map[$package_index]) ? $package_map[$package_index] : array();
				$package_description = isset($package['description']) ? $this->sanitize_stamp_text_value($package['description']) : '';
				if ($package_description === '' && isset($package['name'])) {
					$package_description = $this->sanitize_stamp_text_value($package['name']);
				}
				$result_tracking_number = isset($result['tracking_number']) ? (string) $result['tracking_number'] : '';
				$result_tracking_url = isset($result['tracking_url']) ? (string) $result['tracking_url'] : '';
				$result_label_url = isset($result['label_url']) ? (string) $result['label_url'] : '';
				$result_stamped_label_url = isset($result['stamped_label_url']) ? (string) $result['stamped_label_url'] : '';
				$result_printed = !empty($result['printed']);
				$result_print_error = isset($result['print_error']) ? (string) $result['print_error'] : '';
				echo '<div style="margin:6px 0 8px;padding-left:10px;border-left:3px solid #dcdcde;">';
				echo '<div><strong>Kolli ' . esc_html((string) $package_index) . ($package_description !== '' ? ': ' . esc_html($package_description) : '') . '</strong></div>';
				echo '<div>Sporing: ';
				if ($result_tracking_number !== '' && $result_tracking_url !== '') {
					echo '<a href="' . esc_url($result_tracking_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($result_tracking_number) . '</a>';
				} else {
					echo esc_html($result_tracking_number !== '' ? $result_tracking_number : '-');
				}
				echo '</div>';
				echo '<div>';
				if ($result_label_url !== '') {
					echo '<a href="' . esc_url($result_label_url) . '" target="_blank" rel="noopener noreferrer">Original label</a>';
				}
				if ($result_stamped_label_url !== '') {
					echo ($result_label_url !== '' ? ' | ' : '') . '<a href="' . esc_url($result_stamped_label_url) . '" target="_blank" rel="noopener noreferrer">Printet label</a>';
				}
				echo '</div>';
				if ($result_printed) {
					echo '<div>DirectPrint: Printet</div>';
				} elseif ($result_print_error !== '') {
					if ($result_print_error === 'DirectPrint disabled') {
						echo '<div>DirectPrint: Ikke aktivert</div>';
					} else {
						echo '<div style="color:#b32d2e;">DirectPrint: Feilet - ' . esc_html($result_print_error) . '</div>';
					}
				} else {
					echo '<div>DirectPrint: Ikke printet</div>';
				}
				echo '</div>';
			}
		} elseif ($tracking_number !== '') {
			echo '<div>Sporingsnummer: ' . esc_html($tracking_number) . '</div>';
		}
		if (empty($package_results) && $tracking_url !== '') {
			echo '<div>Sporing: <a href="' . esc_url($tracking_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($tracking_url) . '</a></div>';
		}
		if (empty($package_results) && $label_url !== '') {
			echo '<div>Etikett: <a href="' . esc_url($label_url) . '" target="_blank" rel="noopener noreferrer">Last ned PDF</a></div>';
		}
		echo '<div style="margin-top:6px;color:#646970;">Køjobber behandles når lokal pakkemaskin/robot kjører.</div>';
		echo '</div>';
	}

	public function rest_robot_or_admin_permission($request) {
		if (current_user_can('manage_woocommerce')) {
			return true;
		}

		$settings = $this->settings_service->get_settings();
		$robot_settings = isset($settings['posten_robot']) && is_array($settings['posten_robot']) ? $settings['posten_robot'] : array();
		if (empty($robot_settings['enabled'])) {
			return false;
		}

		$expected_hash = isset($robot_settings['token_hash']) ? trim((string) $robot_settings['token_hash']) : '';
		if ($expected_hash === '') {
			return false;
		}

		$token = $this->get_request_token($request);
		if ($token === '') {
			return false;
		}

		return hash_equals($expected_hash, self::hash_robot_token($token));
	}

	public function rest_admin_permission() {
		return current_user_can('manage_woocommerce');
	}

	public function rest_list_jobs($request) {
		$this->maybe_install_table();
		$status = sanitize_key((string) $request->get_param('status'));
		if ($status === '') {
			$status = self::JOB_STATUS_QUEUED;
		}
		$limit = absint($request->get_param('limit'));
		if ($limit < 1) {
			$limit = 20;
		}
		$limit = min(50, $limit);

		global $wpdb;
		$table = $this->get_table_name();
		if ($status === 'all') {
			$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY created_at_gmt ASC LIMIT %d", $limit));
		} else {
			$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status = %s ORDER BY created_at_gmt ASC LIMIT %d", $status, $limit));
		}

		$jobs = array();
		$exclude_cancelled_orders = in_array($status, array(self::JOB_STATUS_QUEUED, self::JOB_STATUS_PROCESSING), true);
		foreach ((array) $rows as $row) {
			if ($this->maybe_cancel_job_for_cancelled_order($row)) {
				if ($exclude_cancelled_orders) {
					continue;
				}
				$refreshed = $this->get_job_by_id(isset($row->job_id) ? (string) $row->job_id : '');
				if ($refreshed) {
					$row = $refreshed;
				}
			}
			$jobs[] = $this->format_job_response($row, true);
		}

		return rest_ensure_response(array(
			'jobs' => $jobs,
			'count' => count($jobs),
		));
	}

	public function rest_get_job($request) {
		$job = $this->get_job_by_id($this->sanitize_job_id($request['job_id']));
		if (!$job) {
			return new WP_Error('posten_job_not_found', 'Posten labeljobb ikke funnet.', array('status' => 404));
		}

		return rest_ensure_response($this->format_job_response($job, true));
	}

	public function rest_claim_job($request) {
		$job_id = $this->sanitize_job_id($request['job_id']);
		$worker_id = sanitize_text_field((string) $request->get_param('worker_id'));
		if ($worker_id === '') {
			return new WP_Error('posten_job_worker_required', 'worker_id er påkrevd.', array('status' => 400));
		}

		$job = $this->get_job_by_id($job_id);
		if (!$job) {
			return new WP_Error('posten_job_not_found', 'Posten labeljobb ikke funnet.', array('status' => 404));
		}
		if ($this->maybe_cancel_job_for_cancelled_order($job)) {
			$cancelled_job = $this->get_job_by_id($job_id);
			return new WP_Error('posten_job_order_cancelled', 'Posten labeljobb ble kansellert fordi WooCommerce-ordren er kansellert.', array(
				'status' => 409,
				'job_status' => self::JOB_STATUS_CANCELLED,
				'job' => $cancelled_job ? $this->format_job_response($cancelled_job, true) : array(),
			));
		}

		global $wpdb;
		$table = $this->get_table_name();
		$now = gmdate('Y-m-d H:i:s');
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, worker_id = %s, attempts = attempts + 1, claimed_at_gmt = %s, updated_at_gmt = %s WHERE job_id = %s AND status = %s",
				self::JOB_STATUS_PROCESSING,
				$worker_id,
				$now,
				$now,
				$job_id,
				self::JOB_STATUS_QUEUED
			)
		);

		if (!$updated) {
			$job = $this->get_job_by_id($job_id);
			if (!$job) {
				return new WP_Error('posten_job_not_found', 'Posten labeljobb ikke funnet.', array('status' => 404));
			}

			return new WP_Error('posten_job_not_claimable', 'Posten labeljobb er ikke i ko.', array(
				'status' => 409,
				'job_status' => isset($job->status) ? (string) $job->status : '',
			));
		}

		return rest_ensure_response($this->format_job_response($this->get_job_by_id($job_id), true));
	}

	public function rest_complete_job($request) {
		$job_id = $this->sanitize_job_id($request['job_id']);
		$job = $this->get_job_by_id($job_id);
		if (!$job) {
			return new WP_Error('posten_job_not_found', 'Posten labeljobb ikke funnet.', array('status' => 404));
		}

		if ((string) $job->status !== self::JOB_STATUS_PROCESSING) {
			return new WP_Error('posten_job_not_processing', 'Posten labeljobb maa claimes for den kan fullfores.', array(
				'status' => 409,
				'job_status' => isset($job->status) ? (string) $job->status : '',
				'job' => $this->format_job_response($job, true),
			));
		}
		if ($this->maybe_cancel_job_for_cancelled_order($job)) {
			$cancelled_job = $this->get_job_by_id($job_id);
			return new WP_Error('posten_job_order_cancelled', 'Posten labeljobb ble kansellert fordi WooCommerce-ordren er kansellert.', array(
				'status' => 409,
				'job_status' => self::JOB_STATUS_CANCELLED,
				'job' => $cancelled_job ? $this->format_job_response($cancelled_job, true) : array(),
			));
		}

		$worker_id = sanitize_text_field((string) $request->get_param('worker_id'));
		if ($worker_id === '') {
			return new WP_Error('posten_job_worker_required', 'worker_id er påkrevd.', array('status' => 400));
		}
		if (!$this->is_admin_override_request() && (string) $job->worker_id !== '' && $worker_id !== (string) $job->worker_id) {
			return new WP_Error('posten_job_worker_mismatch', 'Worker-ID matcher ikke claime-jobben.', array('status' => 409));
		}

		$completion = $this->prepare_completion_payload($request, $job, 0);
		if (is_wp_error($completion)) {
			return $completion;
		}

		global $wpdb;
		$table = $this->get_table_name();
		$now = gmdate('Y-m-d H:i:s');
		$set_sql = 'status = %s, tracking_number = %s, tracking_url = %s, label_file_path = %s, label_attachment_id = %d, printed = %d, tracking_numbers_json = %s, package_results_json = %s, label_files_json = %s, updated_at_gmt = %s, last_error = %s, failure_message = %s';
		$where_sql = 'job_id = %s AND status = %s';
		$args = array(
			$completion['status'],
			$completion['tracking_number'],
			$completion['tracking_url'],
			$completion['label_file_path'],
			0,
			0,
			$this->json_encode($completion['tracking_numbers']),
			$this->json_encode($completion['package_results']),
			$this->json_encode($completion['label_files']),
			$now,
			$completion['last_error'],
			$completion['last_error'],
		);
		if ($completion['status'] === self::JOB_STATUS_COMPLETED) {
			$set_sql .= ', completed_at_gmt = %s, failed_at_gmt = NULL';
			$args[] = $now;
		} else {
			$set_sql .= ', completed_at_gmt = NULL, failed_at_gmt = %s';
			$args[] = $now;
		}
		$args = array_merge($args, array(
			$job_id,
			self::JOB_STATUS_PROCESSING,
		));
		if (!$this->is_admin_override_request()) {
			$where_sql .= ' AND worker_id = %s';
			$args[] = $worker_id;
		}

		$updated = $wpdb->query($wpdb->prepare("UPDATE {$table} SET {$set_sql} WHERE {$where_sql}", $args));
		if (!$updated) {
			$current_job = $this->get_job_by_id($job_id);
			return new WP_Error('posten_job_complete_conflict', 'Posten labeljobb kunne ikke fullfores fordi status eller worker er endret.', array(
				'status' => 409,
				'job_status' => $current_job && isset($current_job->status) ? (string) $current_job->status : '',
				'job' => $current_job ? $this->format_job_response($current_job, true) : array(),
			));
		}

		$updated_job = $this->get_job_by_id($job_id);
		$order = wc_get_order((int) $job->order_id);
		if ($order) {
			$order->update_meta_data(self::META_JOB_ID, $job_id);
			$order->update_meta_data(self::META_LABEL_STATUS, $completion['status']);
			$order->update_meta_data(self::META_TRACKING_NUMBER, $completion['tracking_number']);
			$order->update_meta_data(self::META_TRACKING_NUMBERS, $completion['tracking_numbers']);
			$order->update_meta_data(self::META_TRACKING_URL, $completion['tracking_url']);
			$order->update_meta_data(self::META_PACKAGE_RESULTS, $completion['order_package_results']);
			$order->update_meta_data(self::META_LABEL_FILE_PATH, $completion['label_file_path']);
			$order->update_meta_data(self::META_LABEL_FILES, $completion['label_files']);
			$order->update_meta_data(self::META_LABEL_ATTACHMENT_ID, 0);
			$order->update_meta_data(self::META_LABEL_PRINTED, 0);
			if ($completion['status'] === self::JOB_STATUS_COMPLETED) {
				$order->update_meta_data(self::META_COMPLETED_AT_GMT, $now);
			}
			$order->save();
		}

		$print_context = $this->process_posten_label_printing($updated_job);
		$print_updated_at = gmdate('Y-m-d H:i:s');
		$wpdb->update($table, array(
			'printed' => $print_context['printed'],
			'package_results_json' => $this->json_encode($print_context['package_results']),
			'stamped_label_files_json' => $this->json_encode($print_context['stamped_label_files']),
			'print_results_json' => $this->json_encode($print_context['print_results']),
			'updated_at_gmt' => $print_updated_at,
		), array('job_id' => $job_id), array('%d', '%s', '%s', '%s', '%s'), array('%s'));

		$updated_job = $this->get_job_by_id($job_id);
		if ($order) {
			$order->update_meta_data(self::META_PACKAGE_RESULTS, $print_context['order_package_results']);
			$order->update_meta_data(self::META_STAMPED_LABEL_FILES, $print_context['stamped_label_files']);
			$order->update_meta_data(self::META_PRINT_RESULTS, $print_context['print_results']);
			$order->update_meta_data(self::META_DIRECT_PRINT_ENABLED, $print_context['direct_print_enabled']);
			$order->update_meta_data(self::META_DIRECT_PRINT_PRINTER_ID, $print_context['printer_id']);
			$order->update_meta_data(self::META_STAMP_ENABLED, $print_context['stamp_enabled']);
			$order->update_meta_data(self::META_LABEL_PRINTED, $print_context['printed']);
			$order->save();
			$this->add_private_order_note($order, $this->build_completed_order_note($updated_job));
			if ($completion['status'] === self::JOB_STATUS_COMPLETED) {
				$this->set_order_status_if_current($order, self::ORDER_STATUS_WAITING, self::ORDER_STATUS_CREATED, 'Posten Norgespakke etikett opprettet.');
			}
		}

		$response = $this->format_job_response($updated_job, true);
		return rest_ensure_response(array(
			'job_id' => isset($response['job_id']) ? $response['job_id'] : $job_id,
			'order_id' => isset($response['order_id']) ? $response['order_id'] : (int) $job->order_id,
			'status' => isset($response['status']) ? $response['status'] : $completion['status'],
			'tracking_number' => $completion['tracking_number'],
			'tracking_numbers' => isset($response['tracking_numbers']) ? $response['tracking_numbers'] : $completion['tracking_numbers'],
			'tracking_url' => $completion['tracking_url'],
			'label_url' => isset($response['label_url']) ? $response['label_url'] : '',
			'package_results' => isset($response['package_results']) ? $response['package_results'] : array(),
			'label_files' => isset($response['label_files']) ? $response['label_files'] : array(),
			'stamped_label_files' => isset($response['stamped_label_files']) ? $response['stamped_label_files'] : array(),
			'print_results' => isset($response['print_results']) ? $response['print_results'] : array(),
			'printed' => isset($response['printed']) ? (int) $response['printed'] : 0,
			'missing_package_indexes' => isset($completion['missing_package_indexes']) ? $completion['missing_package_indexes'] : array(),
		));
	}

	public function rest_fail_job($request) {
		$job_id = $this->sanitize_job_id($request['job_id']);
		$job = $this->get_job_by_id($job_id);
		if (!$job) {
			return new WP_Error('posten_job_not_found', 'Posten labeljobb ikke funnet.', array('status' => 404));
		}

		$worker_id = sanitize_text_field((string) $request->get_param('worker_id'));
		if ($worker_id === '') {
			return new WP_Error('posten_job_worker_required', 'worker_id er påkrevd.', array('status' => 400));
		}
		$status = isset($job->status) ? (string) $job->status : '';
		if ($status === self::JOB_STATUS_FAILED) {
			return rest_ensure_response($this->format_job_response($job, true));
		}
		if ($status === self::JOB_STATUS_COMPLETED || $status === self::JOB_STATUS_CANCELLED) {
			return new WP_Error('posten_job_not_failable', 'Posten labeljobb kan ikke feiles etter at den er fullfort eller kansellert.', array(
				'status' => 409,
				'job_status' => $status,
				'job' => $this->format_job_response($job, true),
			));
		}
		if (!in_array($status, array(self::JOB_STATUS_QUEUED, self::JOB_STATUS_PROCESSING), true)) {
			return new WP_Error('posten_job_not_failable', 'Posten labeljobb kan ikke feiles fra gjeldende status.', array(
				'status' => 409,
				'job_status' => $status,
				'job' => $this->format_job_response($job, true),
			));
		}
		if ($status === self::JOB_STATUS_PROCESSING && !$this->is_admin_override_request() && (string) $job->worker_id !== '' && $worker_id !== (string) $job->worker_id) {
			return new WP_Error('posten_job_worker_mismatch', 'Worker-ID matcher ikke claime-jobben.', array('status' => 409));
		}

		$message = sanitize_textarea_field((string) $request->get_param('error'));
		if ($message === '') {
			$message = sanitize_textarea_field((string) $request->get_param('message'));
		}
		if ($message === '') {
			$message = 'Posten robot meldte feil uten detalj.';
		}
		$screenshot_note = sanitize_textarea_field((string) $request->get_param('screenshot_note'));
		if ($screenshot_note !== '') {
			$message .= ' Screenshot note: ' . $screenshot_note;
		}

		global $wpdb;
		$table = $this->get_table_name();
		$now = gmdate('Y-m-d H:i:s');
		$set_sql = 'status = %s, last_error = %s, failure_message = %s, failed_at_gmt = %s, updated_at_gmt = %s';
		$args = array(
			self::JOB_STATUS_FAILED,
			$message,
			$message,
			$now,
			$now,
			$job_id,
		);
		if ($this->is_admin_override_request()) {
			$where_sql = 'job_id = %s AND status IN (%s, %s)';
			$args[] = self::JOB_STATUS_QUEUED;
			$args[] = self::JOB_STATUS_PROCESSING;
		} else {
			$where_sql = 'job_id = %s AND (status = %s OR (status = %s AND worker_id = %s))';
			$args[] = self::JOB_STATUS_QUEUED;
			$args[] = self::JOB_STATUS_PROCESSING;
			$args[] = $worker_id;
		}

		$updated = $wpdb->query($wpdb->prepare("UPDATE {$table} SET {$set_sql} WHERE {$where_sql}", $args));
		if (!$updated) {
			$current_job = $this->get_job_by_id($job_id);
			return new WP_Error('posten_job_fail_conflict', 'Posten labeljobb kunne ikke feiles fordi status eller worker er endret.', array(
				'status' => 409,
				'job_status' => $current_job && isset($current_job->status) ? (string) $current_job->status : '',
				'job' => $current_job ? $this->format_job_response($current_job, true) : array(),
			));
		}

		$updated_job = $this->get_job_by_id($job_id);
		$order = wc_get_order((int) $job->order_id);
		if ($order) {
			$order->update_meta_data(self::META_LABEL_STATUS, self::JOB_STATUS_FAILED);
			$order->save();
			$this->add_private_order_note($order, 'Posten Norgespakke etikettjobb feilet. Jobb: ' . $job_id . '. Feil: ' . $message);
		}

		return rest_ensure_response($this->format_job_response($updated_job, true));
	}

	public function rest_download_label($request) {
		$job = $this->get_job_by_id($this->sanitize_job_id($request['job_id']));
		if (!$job) {
			return new WP_Error('posten_label_not_found', 'Label PDF ikke funnet.', array('status' => 404));
		}

		$package_index = absint($request->get_param('package_index'));
		$type = sanitize_key((string) $request->get_param('type'));
		if ($type === '') {
			$type = 'original';
		}
		if ($package_index > 0) {
			$file = $type === 'stamped' ? $this->get_stamped_label_file_for_package($job, $package_index) : $this->get_label_file_for_package($job, $package_index);
		} else {
			$packages = $this->json_decode(isset($job->packages_json) ? $job->packages_json : '');
			$label_files = $this->json_decode(isset($job->label_files_json) ? $job->label_files_json : '');
			if (count($packages) > 1 && count($label_files) > 0) {
				return new WP_Error('posten_label_package_index_required', 'Denne jobben har flere etiketter. Angi package_index.', array('status' => 400));
			}
			$file = $type === 'stamped' ? $this->get_stamped_label_file_for_package($job, 1) : (!empty($job->label_file_path) ? (string) $job->label_file_path : '');
			if ($type !== 'stamped' && $file === '' && count($label_files) === 1 && !empty($label_files[0]['label_file_path'])) {
				$file = (string) $label_files[0]['label_file_path'];
			}
		}

		if ($file === '') {
			return new WP_Error('posten_label_not_found', 'Label PDF ikke funnet.', array('status' => 404));
		}
		if (!$this->is_file_inside_label_dir($file) || !is_readable($file)) {
			return new WP_Error('posten_label_unreadable', 'Label PDF kan ikke leses.', array('status' => 404));
		}

		nocache_headers();
		header('Content-Type: application/pdf');
		$filename = 'posten-label-' . $job->job_id . ($package_index > 0 ? '-kolli-' . $package_index : '') . ($type === 'stamped' ? '-stamped' : '') . '.pdf';
		header('Content-Disposition: inline; filename="' . sanitize_file_name($filename) . '"');
		header('Content-Length: ' . filesize($file));
		readfile($file);
		exit;
	}

	private function build_admin_booking_response($job, $payload, $method_payload, $args) {
		$user = wp_get_current_user();
		$recipient = isset($payload['recipient']) && is_array($payload['recipient']) ? $payload['recipient'] : array();
		$sender = isset($payload['sender']) && is_array($payload['sender']) ? $payload['sender'] : array();
		$packages = isset($payload['packages']) && is_array($payload['packages']) ? $payload['packages'] : array();
		$estimated_price = isset($payload['shipping']['estimated_price']) ? (string) $payload['shipping']['estimated_price'] : '';

		return array(
			'booking_mode' => 'posten_label_queue',
			'booked' => false,
			'posten_label_job' => $payload,
			'posten_label_job_id' => isset($job->job_id) ? (string) $job->job_id : '',
			'posten_label_status' => isset($job->status) ? (string) $job->status : self::JOB_STATUS_QUEUED,
			'consignment_number' => '',
			'consignment_id' => '',
			'piece_numbers' => array(),
			'piece_ids' => array(),
			'tracking_url' => '',
			'method_key' => LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY,
			'agreement_id' => 'manual',
			'product_id' => 'norgespakke',
			'sender_profile_id' => isset($sender['profile_id']) ? $sender['profile_id'] : '',
			'sender_profile_name' => isset($sender['name']) ? $sender['name'] : '',
			'sender_id' => isset($sender['sender_id']) ? $sender['sender_id'] : '',
			'sender_entity_id' => isset($sender['sender_entity_id']) ? $sender['sender_entity_id'] : '',
			'sender_address' => isset($sender['address_line']) ? $sender['address_line'] : '',
			'selected_service_ids' => isset($method_payload['selected_service_ids']) && is_array($method_payload['selected_service_ids']) ? $method_payload['selected_service_ids'] : array(),
			'notify_email_to_consignee' => !empty($args['notify_email_to_consignee']),
			'created_at_gmt' => $this->get_job_datetime($job, 'created_at_gmt', $this->get_job_datetime($job, 'requested_at_gmt')),
			'created_by_user_id' => get_current_user_id(),
			'created_by_user_login' => isset($user->user_login) ? (string) $user->user_login : '',
			'created_by_display_name' => isset($user->display_name) ? (string) $user->display_name : '',
			'estimated_shipping_price' => $estimated_price !== '' ? $estimated_price : 'ikke tilgjengelig',
			'estimated_shipping_price_source' => isset($payload['shipping']['estimated_price_source']) ? (string) $payload['shipping']['estimated_price_source'] : 'posten_robot_queue',
			'history' => array(),
			'posten_summary' => array(
				'colli_count' => count($packages),
				'receiver_name' => isset($recipient['name']) ? $recipient['name'] : '',
				'receiver_postcode' => isset($recipient['postcode']) ? $recipient['postcode'] : '',
			),
			'print' => array(
				'attempted' => false,
				'success' => false,
				'message' => 'Posten-label opprettes av lokal robot.',
				'pieces' => array(),
			),
			'status_change' => array(
				'enabled' => true,
				'success' => true,
				'verified' => true,
				'target_status' => self::ORDER_STATUS_WAITING,
				'target_status_label' => 'Venter på etikett',
				'message' => 'Ordren er satt til Venter på etikett.',
			),
		);
	}

	private function sanitize_method_payload($method) {
		$method = is_array($method) ? $method : array();
		$output = array();
		foreach ($method as $key => $value) {
			$clean_key = sanitize_key((string) $key);
			if ($clean_key === '') {
				continue;
			}
			$output[$clean_key] = $this->sanitize_recursive($value);
		}

		if (empty($output['key']) && !empty($output['agreement_id']) && !empty($output['product_id'])) {
			$output['key'] = $output['agreement_id'] . '|' . $output['product_id'];
		}

		return $output;
	}

	private function sanitize_recursive($value) {
		if (is_array($value)) {
			$output = array();
			foreach ($value as $key => $item) {
				$output[is_int($key) ? $key : sanitize_key((string) $key)] = $this->sanitize_recursive($item);
			}
			return $output;
		}

		if (is_bool($value)) {
			return $value;
		}

		if (is_numeric($value)) {
			return (string) $value;
		}

		return sanitize_text_field((string) $value);
	}

	private function sanitize_packages($packages) {
		$packages = is_array($packages) ? $packages : array();
		$clean = array();
		$errors = array();
		$index = 0;
		foreach ($packages as $package) {
			$index++;
			if (!is_array($package)) {
				continue;
			}

			$weight = $this->to_float(isset($package['weight']) ? $package['weight'] : (isset($package['weight_kg']) ? $package['weight_kg'] : 0));
			$length = $this->sanitize_optional_dimension($package, array('length', 'length_cm'), 'lengde', $index, $errors);
			$width = $this->sanitize_optional_dimension($package, array('width', 'width_cm'), 'bredde', $index, $errors);
			$height = $this->sanitize_optional_dimension($package, array('height', 'height_cm'), 'høyde', $index, $errors);

			if ($weight <= 0) {
				$errors[] = 'Kolli ' . $index . ' mangler vekt.';
				continue;
			}
			if ($weight > 35) {
				$errors[] = 'Kolli ' . $index . ' er over 35 kg.';
				continue;
			}

			$clean[] = array(
				'index' => count($clean) + 1,
				'name' => isset($package['name']) ? sanitize_text_field((string) $package['name']) : '',
				'description' => isset($package['description']) ? sanitize_text_field((string) $package['description']) : '',
				'weight_kg' => $weight,
				'weight_grams' => (int) round($weight * 1000),
				'length_cm' => max(0, $length),
				'width_cm' => max(0, $width),
				'height_cm' => max(0, $height),
			);
		}

		if (!empty($errors)) {
			return new WP_Error('invalid_packages', implode(' ', $errors));
		}

		if (empty($clean)) {
			return new WP_Error('invalid_packages', 'Ingen gyldige kolli ble sendt til Posten labelko.');
		}

		return $clean;
	}

	private function build_recipient_snapshot($order) {
		$shipping_name = trim((string) $order->get_shipping_first_name() . ' ' . (string) $order->get_shipping_last_name());
		$billing_name = trim((string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name());
		$name = $this->first_non_empty($shipping_name, $billing_name);
		$company = $this->first_non_empty($order->get_shipping_company(), $order->get_billing_company());
		$address_1 = $this->first_non_empty($order->get_shipping_address_1(), $order->get_billing_address_1());
		$address_2 = $this->first_non_empty($order->get_shipping_address_2(), $order->get_billing_address_2());
		$postcode = $this->first_non_empty($order->get_shipping_postcode(), $order->get_billing_postcode());
		$city = $this->first_non_empty($order->get_shipping_city(), $order->get_billing_city());
		$country = $this->first_non_empty($order->get_shipping_country(), $order->get_billing_country());
		if ($country === '') {
			$country = 'NO';
		}

		return array(
			'name' => sanitize_text_field($name),
			'company' => sanitize_text_field($company),
			'address_1' => sanitize_text_field($address_1),
			'address_2' => sanitize_text_field($address_2),
			'postcode' => sanitize_text_field($postcode),
			'city' => sanitize_text_field($city),
			'country' => strtoupper(sanitize_text_field($country)),
			'email' => sanitize_email((string) $order->get_billing_email()),
			'phone' => $this->get_order_recipient_phone($order),
		);
	}

	private function build_shipping_snapshot($order, $method_payload, $args) {
		$checkout_selection = isset($args['checkout_selection']) && is_array($args['checkout_selection']) ? $args['checkout_selection'] : $order->get_meta('_lp_cargonizer_checkout_selection', true);
		$pickup = is_array($checkout_selection) && isset($checkout_selection['pickup_point']) && is_array($checkout_selection['pickup_point'])
			? $checkout_selection['pickup_point']
			: array();

		return array(
			'method_key' => LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY,
			'label' => isset($method_payload['label']) && $method_payload['label'] !== '' ? $method_payload['label'] : 'Posten - Norgespakke (manuell)',
			'agreement_id' => 'manual',
			'carrier_id' => isset($method_payload['carrier_id']) ? $method_payload['carrier_id'] : 'posten',
			'carrier_name' => isset($method_payload['carrier_name']) ? $method_payload['carrier_name'] : 'Posten',
			'product_id' => 'norgespakke',
			'product_name' => isset($method_payload['product_name']) ? $method_payload['product_name'] : 'Norgespakke',
			'estimated_price' => $this->extract_estimated_price($method_payload),
			'estimated_price_source' => isset($method_payload['selected_price_source']) ? sanitize_text_field((string) $method_payload['selected_price_source']) : 'manual_norgespakke',
			'selected_service_ids' => isset($method_payload['selected_service_ids']) && is_array($method_payload['selected_service_ids']) ? array_values(array_filter(array_map('sanitize_text_field', $method_payload['selected_service_ids']))) : array(),
			'pickup_point' => $this->sanitize_recursive($pickup),
			'source' => isset($args['source']) ? sanitize_key((string) $args['source']) : '',
			'order_number' => method_exists($order, 'get_order_number') ? (string) $order->get_order_number() : '',
			'currency' => method_exists($order, 'get_currency') ? (string) $order->get_currency() : '',
		);
	}

	private function build_items_snapshot($order) {
		$items = array();
		foreach ($order->get_items() as $item) {
			if (!is_a($item, 'WC_Order_Item_Product')) {
				continue;
			}
			$product = $item->get_product();
			$variation_id = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
			if ($variation_id < 1 && $product && method_exists($product, 'is_type') && $product->is_type('variation') && method_exists($product, 'get_id')) {
				$variation_id = (int) $product->get_id();
			}
			$items[] = array(
				'name' => sanitize_text_field((string) $item->get_name()),
				'quantity' => (int) $item->get_quantity(),
				'product_id' => $product && method_exists($product, 'get_id') ? (int) $product->get_id() : 0,
				'variation_id' => $variation_id,
				'sku' => $product && method_exists($product, 'get_sku') ? sanitize_text_field((string) $product->get_sku()) : '',
				'total' => method_exists($item, 'get_total') ? (string) $item->get_total() : '',
			);
		}

		return $items;
	}

	private function resolve_sender_profile($requested_profile_id = '') {
		$settings = $this->settings_service->get_settings();
		$profiles = $this->get_sender_profiles_from_settings($settings);
		$requested_profile_id = sanitize_key((string) $requested_profile_id);
		$selected = null;
		foreach ($profiles as $profile) {
			if ($requested_profile_id !== '' && $profile['profile_id'] === $requested_profile_id) {
				$selected = $profile;
				break;
			}
			if ($selected === null && !empty($profile['is_default'])) {
				$selected = $profile;
			}
		}
		if ($selected === null && !empty($profiles)) {
			$selected = $profiles[0];
		}
		if ($selected === null) {
			$selected = array(
				'profile_id' => '',
				'name' => '',
				'sender_id' => isset($settings['sender_id']) ? sanitize_text_field((string) $settings['sender_id']) : '',
				'sender_entity_id' => '',
				'company' => '',
				'address1' => '',
				'address2' => '',
				'postcode' => '',
				'city' => '',
				'country' => '',
				'email' => '',
				'phone' => '',
				'default_printer_id' => '',
				'is_default' => true,
			);
		}

		$selected['address_line'] = trim((isset($selected['address1']) ? $selected['address1'] : '') . (!empty($selected['address2']) ? ', ' . $selected['address2'] : ''));
		$selected['postal_line'] = trim((isset($selected['postcode']) ? $selected['postcode'] : '') . ' ' . (isset($selected['city']) ? $selected['city'] : ''));
		return $selected;
	}

	private function get_sender_profiles_from_settings($settings) {
		$warehouse_profiles = isset($settings['warehouse_profiles']) && is_array($settings['warehouse_profiles']) ? $settings['warehouse_profiles'] : array();
		$profiles = isset($warehouse_profiles['profiles']) && is_array($warehouse_profiles['profiles']) ? $warehouse_profiles['profiles'] : array();
		$normalized = array();
		$seen = array();
		foreach ($profiles as $profile) {
			if (!is_array($profile) || empty($profile['active'])) {
				continue;
			}
			$row = $this->normalize_sender_profile_row($profile);
			if (empty($row) || isset($seen[$row['sender_id']])) {
				continue;
			}
			$seen[$row['sender_id']] = true;
			$normalized[] = $row;
		}

		$fallback_sender_id = isset($settings['sender_id']) ? sanitize_text_field((string) $settings['sender_id']) : '';
		if ($fallback_sender_id !== '' && !isset($seen[$fallback_sender_id])) {
			$normalized[] = $this->normalize_sender_profile_row(array(
				'profile_id' => 'default_sender',
				'name' => 'Default sender',
				'sender_id' => $fallback_sender_id,
				'active' => 1,
			));
		}

		$default_profile_id = isset($warehouse_profiles['default_profile_id']) ? sanitize_key((string) $warehouse_profiles['default_profile_id']) : '';
		if ($default_profile_id === '' && !empty($normalized)) {
			$default_profile_id = $normalized[0]['profile_id'];
		}
		foreach ($normalized as &$profile) {
			$profile['is_default'] = $default_profile_id !== '' && $profile['profile_id'] === $default_profile_id;
		}
		unset($profile);

		return $normalized;
	}

	private function normalize_sender_profile_row($profile) {
		$sender_id = isset($profile['sender_id']) ? sanitize_text_field((string) $profile['sender_id']) : '';
		if ($sender_id === '') {
			return array();
		}
		$profile_id = isset($profile['profile_id']) ? sanitize_key((string) $profile['profile_id']) : '';
		if ($profile_id === '') {
			$profile_id = sanitize_key('sender_' . $sender_id);
		}
		$name = isset($profile['name']) ? sanitize_text_field((string) $profile['name']) : '';
		$company = isset($profile['company']) ? sanitize_text_field((string) $profile['company']) : '';
		return array(
			'profile_id' => $profile_id,
			'name' => $name !== '' ? $name : ($company !== '' ? $company : $sender_id),
			'sender_id' => $sender_id,
			'sender_entity_id' => isset($profile['sender_entity_id']) ? sanitize_text_field((string) $profile['sender_entity_id']) : '',
			'company' => $company,
			'address1' => isset($profile['address1']) ? sanitize_text_field((string) $profile['address1']) : '',
			'address2' => isset($profile['address2']) ? sanitize_text_field((string) $profile['address2']) : '',
			'postcode' => isset($profile['postcode']) ? sanitize_text_field((string) $profile['postcode']) : '',
			'city' => isset($profile['city']) ? sanitize_text_field((string) $profile['city']) : '',
			'country' => isset($profile['country']) ? sanitize_text_field((string) $profile['country']) : '',
			'email' => isset($profile['email']) ? sanitize_email((string) $profile['email']) : '',
			'phone' => isset($profile['phone']) ? sanitize_text_field((string) $profile['phone']) : '',
			'default_printer_id' => isset($profile['default_printer_id']) ? sanitize_text_field((string) $profile['default_printer_id']) : '',
			'active' => !empty($profile['active']) ? 1 : 0,
		);
	}

	private function build_packages_from_order($order) {
		if (!class_exists('LP_Cargonizer_Package_Builder') || !class_exists('LP_Cargonizer_Shipping_Profile_Resolver') || !class_exists('LP_Cargonizer_Package_Resolution_Service')) {
			return array();
		}

		$settings_provider = function () {
			return $this->settings_service->get_settings();
		};
		$package_resolution = new LP_Cargonizer_Package_Resolution_Service($settings_provider);
		$resolver = new LP_Cargonizer_Shipping_Profile_Resolver($settings_provider, $package_resolution);
		$builder = new LP_Cargonizer_Package_Builder($resolver, $settings_provider);
		return $builder->build_admin_prefill_packages_from_order($order);
	}

	private function extract_estimated_price($method_payload) {
		foreach (array('selected_price', 'selected_price_value', 'estimated_shipping_price', 'price', 'gross_amount', 'net_amount') as $key) {
			if (isset($method_payload[$key]) && trim((string) $method_payload[$key]) !== '') {
				return sanitize_text_field((string) $method_payload[$key]);
			}
		}
		return '';
	}

	private function is_manual_norgespakke_enabled($settings) {
		$enabled_methods = isset($settings['enabled_methods']) && is_array($settings['enabled_methods']) ? $settings['enabled_methods'] : array();
		return in_array(LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY, array_map('strval', $enabled_methods), true);
	}

	private function maybe_cancel_job_for_cancelled_order($job) {
		if (!$job || !isset($job->order_id)) {
			return false;
		}
		$status = isset($job->status) ? (string) $job->status : '';
		if (!in_array($status, array(self::JOB_STATUS_QUEUED, self::JOB_STATUS_PROCESSING), true)) {
			return false;
		}
		if ($this->get_order_status_for_job($job) !== 'cancelled') {
			return false;
		}

		$this->cancel_active_jobs_for_order((int) $job->order_id);
		return true;
	}

	private function get_order_status_for_job($job) {
		$order_id = $job && isset($job->order_id) ? absint($job->order_id) : 0;
		if ($order_id < 1 || !function_exists('wc_get_order')) {
			return '';
		}
		$order = wc_get_order($order_id);
		if (!$order || !method_exists($order, 'get_status')) {
			return '';
		}

		return sanitize_key((string) $order->get_status());
	}

	private function get_active_job_for_order($order_id, $method_key) {
		global $wpdb;
		$table = $this->get_table_name();
		$order_id = absint($order_id);
		$method_key = sanitize_text_field((string) $method_key);
		$latest = $this->get_latest_job_for_order_method($order_id, $method_key);
		if ($latest && $this->is_retryable_terminal_job_status(isset($latest->status) ? (string) $latest->status : '')) {
			$statuses = array(self::JOB_STATUS_QUEUED, self::JOB_STATUS_PROCESSING);
		} else {
			$statuses = array(self::JOB_STATUS_QUEUED, self::JOB_STATUS_PROCESSING, self::JOB_STATUS_COMPLETED);
		}
		$placeholders = implode(',', array_fill(0, count($statuses), '%s'));
		$args = array_merge(array($order_id, $method_key), $statuses);
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d AND method_key = %s AND status IN ({$placeholders}) ORDER BY id DESC LIMIT 1", $args));
	}

	private function get_latest_job_for_order_method($order_id, $method_key) {
		global $wpdb;
		$table = $this->get_table_name();
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d AND method_key = %s ORDER BY id DESC LIMIT 1", absint($order_id), sanitize_text_field((string) $method_key)));
	}

	private function is_retryable_terminal_job_status($status) {
		return in_array((string) $status, array(self::JOB_STATUS_FAILED, self::JOB_STATUS_PARTIAL_FAILED, self::JOB_STATUS_CANCELLED), true);
	}

	private function get_latest_job_for_order($order_id) {
		global $wpdb;
		$table = $this->get_table_name();
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT 1", absint($order_id)));
	}

	private function get_job_by_id($job_id) {
		$job_id = $this->sanitize_job_id($job_id);
		if ($job_id === '') {
			return null;
		}
		global $wpdb;
		$table = $this->get_table_name();
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE job_id = %s LIMIT 1", $job_id));
	}

	private function format_job_response($row, $include_payload = false) {
		if (!$row) {
			return array();
		}

		$shipping = $this->json_decode(isset($row->shipping_json) ? $row->shipping_json : '');
		$method_key = isset($row->method_key) && (string) $row->method_key !== '' ? (string) $row->method_key : LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY;
		$tracking_numbers = $this->json_decode(isset($row->tracking_numbers_json) ? $row->tracking_numbers_json : '');
		if (empty($tracking_numbers) && isset($row->tracking_number) && (string) $row->tracking_number !== '') {
			$tracking_numbers = array((string) $row->tracking_number);
		}
		$package_results = $this->get_package_results_for_response($row);
		$label_files = $this->get_label_files_for_response($row);
		$stamped_label_files = $this->get_stamped_label_files_for_response($row);
		$print_results = $this->get_print_results_for_response($row);
		$response = array(
			'job_id' => (string) $row->job_id,
			'order_id' => (int) $row->order_id,
			'order_number' => isset($row->order_number) ? (string) $row->order_number : '',
			'order_status' => $this->get_order_status_for_job($row),
			'status' => (string) $row->status,
			'source' => isset($row->source) ? (string) $row->source : '',
			'attempts' => isset($row->attempts) ? (int) $row->attempts : 0,
			'worker_id' => (string) $row->worker_id,
			'printed' => isset($row->printed) ? (int) $row->printed : 0,
			'requested_by_user_id' => isset($row->requested_by_user_id) ? (int) $row->requested_by_user_id : 0,
			'service' => array(
				'carrier' => 'Posten',
				'product' => 'Norgespakke',
				'method_key' => $method_key,
			),
			'tracking_number' => (string) $row->tracking_number,
			'tracking_numbers' => array_values($tracking_numbers),
			'tracking_url' => (string) $row->tracking_url,
			'label_url' => $this->get_admin_label_url($row),
			'package_results' => $package_results,
			'label_files' => $label_files,
			'stamped_label_files' => $stamped_label_files,
			'print_results' => $print_results,
			'last_error' => isset($row->last_error) ? (string) $row->last_error : (isset($row->failure_message) ? (string) $row->failure_message : ''),
			'created_at_gmt' => $this->get_job_datetime($row, 'created_at_gmt', $this->get_job_datetime($row, 'requested_at_gmt')),
			'claimed_at_gmt' => $this->nullable_datetime($this->get_job_datetime($row, 'claimed_at_gmt')),
			'completed_at_gmt' => $this->nullable_datetime($this->get_job_datetime($row, 'completed_at_gmt')),
			'failed_at_gmt' => $this->nullable_datetime($this->get_job_datetime($row, 'failed_at_gmt')),
			'updated_at_gmt' => $this->get_job_datetime($row, 'updated_at_gmt', $this->get_job_datetime($row, 'created_at_gmt', $this->get_job_datetime($row, 'requested_at_gmt'))),
		);

		if ($include_payload) {
			$shipping_payload = wp_parse_args(is_array($shipping) ? $shipping : array(), array(
				'method_key' => LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY,
				'label' => 'Posten - Norgespakke (manuell)',
				'agreement_id' => 'manual',
				'carrier_id' => 'posten',
				'carrier_name' => 'Posten',
				'product_id' => 'norgespakke',
				'product_name' => 'Norgespakke',
				'estimated_price' => '',
				'estimated_price_source' => 'manual_norgespakke',
				'selected_service_ids' => array(),
				'pickup_point' => array(),
				'source' => isset($row->source) ? (string) $row->source : '',
				'order_number' => isset($row->order_number) ? (string) $row->order_number : '',
				'currency' => '',
			));
			$shipping_payload['method_key'] = isset($shipping_payload['method_key']) && (string) $shipping_payload['method_key'] !== '' ? (string) $shipping_payload['method_key'] : LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY;
			$shipping_payload['label'] = isset($shipping_payload['label']) && (string) $shipping_payload['label'] !== '' ? (string) $shipping_payload['label'] : 'Posten - Norgespakke (manuell)';
			$shipping_payload['selected_service_ids'] = isset($shipping_payload['selected_service_ids']) && is_array($shipping_payload['selected_service_ids']) ? array_values($shipping_payload['selected_service_ids']) : array();
			$shipping_payload['pickup_point'] = isset($shipping_payload['pickup_point']) && is_array($shipping_payload['pickup_point']) ? $shipping_payload['pickup_point'] : array();
			$shipping_payload['source'] = isset($shipping_payload['source']) && (string) $shipping_payload['source'] !== '' ? (string) $shipping_payload['source'] : (isset($row->source) ? (string) $row->source : '');
			$shipping_payload['order_number'] = isset($shipping_payload['order_number']) && (string) $shipping_payload['order_number'] !== '' ? (string) $shipping_payload['order_number'] : (isset($row->order_number) ? (string) $row->order_number : '');

			$response['recipient'] = $this->json_decode($row->recipient_json);
			$response['sender'] = $this->json_decode($row->sender_json);
			$response['packages'] = $this->json_decode($row->packages_json);
			$response['shipping'] = $shipping_payload;
			$response['items'] = $this->json_decode($row->items_json);
		}

		return $response;
	}

	private function write_order_queue_metadata($order, $job, $recipient, $sender, $packages, $shipping) {
		$created_at_gmt = $this->get_job_datetime($job, 'created_at_gmt', $this->get_job_datetime($job, 'requested_at_gmt'));
		$order->update_meta_data(self::META_JOB_ID, (string) $job->job_id);
		$order->update_meta_data(self::META_LABEL_STATUS, self::JOB_STATUS_QUEUED);
		$order->update_meta_data(self::META_REQUESTED_AT_GMT, $created_at_gmt);
		$order->delete_meta_data(self::META_TRACKING_NUMBER);
		$order->delete_meta_data(self::META_TRACKING_NUMBERS);
		$order->delete_meta_data(self::META_TRACKING_URL);
		$order->delete_meta_data(self::META_PACKAGE_RESULTS);
		$order->delete_meta_data(self::META_LABEL_FILE_PATH);
		$order->delete_meta_data(self::META_LABEL_FILES);
		$order->delete_meta_data(self::META_STAMPED_LABEL_FILES);
		$order->delete_meta_data(self::META_PRINT_RESULTS);
		$order->delete_meta_data(self::META_LABEL_ATTACHMENT_ID);
		$order->delete_meta_data(self::META_COMPLETED_AT_GMT);
		$order->update_meta_data(self::META_LABEL_PRINTED, 0);
		$order->update_meta_data('_lp_posten_label_method_key', LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY);
		$order->update_meta_data('_lp_posten_label_recipient_snapshot', $recipient);
		$order->update_meta_data('_lp_posten_label_sender_snapshot', $sender);
		$order->update_meta_data('_lp_posten_label_packages_snapshot', $packages);
		$order->update_meta_data('_lp_posten_label_shipping_snapshot', $shipping);
		$order->save();
	}

	private function build_queue_order_note($job, $recipient, $sender, $packages, $shipping) {
		return 'Posten Norgespakke etikettjobb opprettet. Jobb: ' . (string) $job->job_id . '. Antall kolli: ' . count($packages) . '.';
	}

	private function build_completed_order_note($job) {
		$printed = isset($job->printed) && (int) $job->printed === 1 ? 'Ja' : 'Nei';
		$package_results = $this->json_decode(isset($job->package_results_json) ? $job->package_results_json : '');
		$packages = $this->json_decode(isset($job->packages_json) ? $job->packages_json : '');
		$is_multi_note = count($packages) > 1 || count($package_results) > 1 || (isset($job->status) && (string) $job->status === self::JOB_STATUS_PARTIAL_FAILED);
		if (!empty($package_results) && $is_multi_note) {
			usort($package_results, array($this, 'sort_by_package_index'));
			$lines = array();
			$lines[] = isset($job->status) && (string) $job->status === self::JOB_STATUS_PARTIAL_FAILED
				? 'Posten Norgespakke etiketter delvis opprettet.'
				: 'Posten Norgespakke etiketter opprettet.';
			foreach ($package_results as $result) {
				$package_index = isset($result['package_index']) ? absint($result['package_index']) : 0;
				$tracking_number = isset($result['tracking_number']) ? (string) $result['tracking_number'] : '';
				$print_text = $this->get_print_result_note_text($result);
				if ($package_index > 0 && $tracking_number !== '') {
					$lines[] = 'Kolli ' . $package_index . ': ' . $tracking_number . ' - ' . $print_text;
				}
			}
			$missing = $this->get_missing_package_indexes($packages, $package_results);
			if (!empty($missing)) {
				$lines[] = 'Mangler kolli: ' . implode(', ', array_map('strval', $missing));
			}
			$lines[] = 'Utskrift: ' . $printed;
			return implode("\n", $lines);
		}

		$tracking_number = isset($job->tracking_number) ? (string) $job->tracking_number : '';
		$tracking_url = isset($job->tracking_url) ? (string) $job->tracking_url : '';
		$tracking_text = $tracking_number;
		if ($tracking_url !== '') {
			$tracking_text .= ' (<a href="' . esc_url($tracking_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($tracking_url) . '</a>)';
		}

		$single_print_text = !empty($package_results[0]) && is_array($package_results[0]) ? $this->get_print_result_note_text($package_results[0]) : ($printed === 'Ja' ? 'Printet' : 'Ikke printet');
		return 'Posten Norgespakke etikett opprettet. Sporingsnummer: ' . $tracking_text . '. DirectPrint: ' . $single_print_text . '.';
	}

	private function get_print_result_note_text($result) {
		if (!is_array($result)) {
			return 'Ikke printet';
		}
		if (!empty($result['printed'])) {
			return 'Printet';
		}
		$error = isset($result['print_error']) ? (string) $result['print_error'] : '';
		if ($error === 'DirectPrint disabled') {
			return 'Ikke aktivert';
		}
		if ($error !== '') {
			return 'Print feilet: ' . $error;
		}
		return 'Ikke printet';
	}

	private function set_order_status_verified($order, $status, $note) {
		$status = sanitize_key((string) $status);
		if (!$order || $status === '' || !method_exists($order, 'update_status')) {
			return false;
		}

		try {
			$order->update_status($status, $note, true);
			$refreshed = wc_get_order((int) $order->get_id());
			if ($refreshed && method_exists($refreshed, 'get_status') && (string) $refreshed->get_status() === $status) {
				return true;
			}
			$this->add_private_order_note($order, 'Advarsel: forsokte aa sette ordrestatus til ' . $status . ', men verifisering feilet.');
		} catch (Throwable $throwable) {
			$this->add_private_order_note($order, 'Advarsel: kunne ikke sette ordrestatus til ' . $status . ': ' . $throwable->getMessage());
		}

		return false;
	}

	private function add_private_order_note($order, $note) {
		if (!$order || !method_exists($order, 'add_order_note')) {
			return;
		}
		$order->add_order_note((string) $note, false, true);
	}

	private function process_posten_label_printing($job) {
		$settings = $this->settings_service->get_settings();
		$robot_settings = isset($settings['posten_robot']) && is_array($settings['posten_robot']) ? wp_parse_args($settings['posten_robot'], $this->get_posten_robot_print_defaults()) : $this->get_posten_robot_print_defaults();
		$packages = $this->json_decode(isset($job->packages_json) ? $job->packages_json : '');
		$package_map = $this->build_package_index_map($packages);
		$package_results = $this->json_decode(isset($job->package_results_json) ? $job->package_results_json : '');
		if (empty($package_results) && !empty($job->tracking_number)) {
			$package_results = array($this->build_stored_package_result(1, (string) $job->tracking_number, isset($job->tracking_url) ? (string) $job->tracking_url : '', isset($job->label_file_path) ? (string) $job->label_file_path : '', 0, 0));
		}
		usort($package_results, array($this, 'sort_by_package_index'));

		$direct_print_enabled = !empty($robot_settings['direct_print_enabled']) ? 1 : 0;
		$printer_id = isset($robot_settings['direct_print_printer_id']) ? sanitize_text_field((string) $robot_settings['direct_print_printer_id']) : '';
		$stamp_enabled = !empty($robot_settings['stamp_enabled']) ? 1 : 0;
		$stamp_required_for_print = !empty($robot_settings['stamp_required_for_print']);
		$stamped_label_files = array();
		$print_results = array();
		$all_printed = !empty($package_results);
		$now = gmdate('Y-m-d H:i:s');

		foreach ($package_results as &$result) {
			if (!is_array($result)) {
				continue;
			}
			$package_index = isset($result['package_index']) ? absint($result['package_index']) : 0;
			$original_path = isset($result['label_file_path']) ? (string) $result['label_file_path'] : $this->get_label_file_for_package($job, $package_index);
			$stamped_path = '';
			$stamped_filename = '';
			$stamp_error = '';

			if ($stamp_enabled && $original_path !== '' && $this->is_file_inside_label_dir($original_path) && is_readable($original_path)) {
				$stamp_text = $this->build_package_stamp_text($package_index, isset($package_map[$package_index]) ? $package_map[$package_index] : array(), $robot_settings);
				if ($stamp_text !== '') {
					$stamped_path = $this->build_stamped_label_path($original_path, $package_index);
					$stamp_result = $this->stamp_pdf_file($original_path, $stamped_path, $stamp_text, $robot_settings);
					if (is_wp_error($stamp_result)) {
						$stamp_error = $stamp_result->get_error_message();
						$stamped_path = '';
					} else {
						$stamped_filename = basename($stamped_path);
						$stamped_label_files[] = array(
							'package_index' => $package_index,
							'stamped_label_file_path' => $stamped_path,
							'stamped_label_filename' => $stamped_filename,
						);
					}
				}
			} elseif ($stamp_enabled) {
				$stamp_error = 'Original PDF mangler eller kan ikke leses.';
			}

			$print_path = $stamped_path !== '' ? $stamped_path : $original_path;
			$print_result = array(
				'package_index' => $package_index,
				'printed' => 0,
				'printer_id' => $printer_id,
				'http_status' => 0,
				'error' => '',
				'printed_at_gmt' => $now,
			);
			if (!$direct_print_enabled) {
				$print_result['error'] = 'DirectPrint disabled';
			} elseif ($printer_id === '') {
				$print_result['error'] = 'DirectPrint printer mangler.';
			} elseif ($stamp_error !== '' && $stamp_required_for_print) {
				$print_result['error'] = 'Stempling feilet: ' . $stamp_error;
			} elseif ($print_path === '' || !$this->is_file_inside_label_dir($print_path) || !is_readable($print_path)) {
				$print_result['error'] = 'PDF for DirectPrint mangler eller kan ikke leses.';
			} else {
				$pdf_binary = file_get_contents($print_path);
				if ($pdf_binary === false || $pdf_binary === '') {
					$print_result['error'] = 'PDF for DirectPrint er tom eller kan ikke leses.';
				} else {
					$api_result = $this->api_service->print_document_to_printer($printer_id, $pdf_binary, 'application/pdf');
					$print_result['http_status'] = isset($api_result['http_status']) ? (int) $api_result['http_status'] : 0;
					if (!empty($api_result['success'])) {
						$print_result['printed'] = 1;
					} else {
						$api_error = isset($api_result['error']) ? sanitize_text_field((string) $api_result['error']) : '';
						$print_result['error'] = $api_error !== '' ? 'DirectPrint failed: ' . $api_error : 'DirectPrint failed.';
					}
				}
			}
			if ($stamp_error !== '' && empty($print_result['error'])) {
				$print_result['error'] = 'Stempling feilet: ' . $stamp_error;
			}

			$result['printed'] = (int) $print_result['printed'];
			$result['print_error'] = (string) $print_result['error'];
			$result['printer_id'] = $printer_id;
			$result['printed_at_gmt'] = $now;
			if ($stamped_path !== '') {
				$result['stamped_label_file_path'] = $stamped_path;
				$result['stamped_label_filename'] = $stamped_filename;
			}
			$print_results[] = $print_result;
			if (empty($print_result['printed'])) {
				$all_printed = false;
			}
		}
		unset($result);

		return array(
			'printed' => $all_printed ? 1 : 0,
			'package_results' => $package_results,
			'order_package_results' => $this->build_order_package_results_for_meta($package_results),
			'stamped_label_files' => $stamped_label_files,
			'print_results' => $print_results,
			'direct_print_enabled' => $direct_print_enabled,
			'printer_id' => $printer_id,
			'stamp_enabled' => $stamp_enabled,
		);
	}

	private function get_posten_robot_print_defaults() {
		return array(
			'direct_print_enabled' => 0,
			'direct_print_printer_id' => '',
			'stamp_enabled' => 1,
			'stamp_text_template' => 'Kolli {index}: {description}',
			'stamp_font_size' => 10,
			'stamp_x_mm' => 8,
			'stamp_y_mm' => 8,
			'stamp_max_width_mm' => 80,
			'stamp_max_lines' => 2,
			'stamp_required_for_print' => 1,
		);
	}

	private function build_package_index_map($packages) {
		$map = array();
		foreach ((array) $packages as $position => $package) {
			if (!is_array($package)) {
				continue;
			}
			$index = isset($package['index']) ? absint($package['index']) : ((int) $position + 1);
			if ($index > 0) {
				$map[$index] = $package;
			}
		}
		return $map;
	}

	private function build_package_stamp_text($package_index, $package, $settings) {
		$description = isset($package['description']) ? $this->sanitize_stamp_text_value($package['description']) : '';
		if ($description === '' && isset($package['name'])) {
			$description = $this->sanitize_stamp_text_value($package['name']);
		}
		if ($description === '') {
			return 'Kolli ' . absint($package_index);
		}
		$template = isset($settings['stamp_text_template']) && trim((string) $settings['stamp_text_template']) !== '' ? (string) $settings['stamp_text_template'] : 'Kolli {index}: {description}';
		$text = str_replace(array('{index}', '{description}', '{name}'), array((string) absint($package_index), $description, $description), $template);
		$text = $this->sanitize_stamp_text_value($text);
		return $text !== '' ? $text : 'Kolli ' . absint($package_index);
	}

	private function sanitize_stamp_text_value($value) {
		$value = html_entity_decode(wp_strip_all_tags((string) $value), ENT_QUOTES, 'UTF-8');
		$value = preg_replace('/\s+/', ' ', $value);
		$value = trim((string) $value);
		if (function_exists('mb_substr')) {
			$value = mb_substr($value, 0, 120);
		} else {
			$value = substr($value, 0, 120);
		}
		return $value;
	}

	private function build_stamped_label_path($original_path, $package_index) {
		$path_info = pathinfo((string) $original_path);
		$dir = isset($path_info['dirname']) ? $path_info['dirname'] : $this->get_label_storage_dir();
		$filename = isset($path_info['filename']) ? $path_info['filename'] : 'posten-label-kolli-' . absint($package_index);
		return trailingslashit($dir) . sanitize_file_name($filename . '-stamped.pdf');
	}

	private function stamp_pdf_file($original_path, $output_path, $stamp_text, $settings) {
		if (!$this->load_pdf_stamping_library()) {
			return new WP_Error('posten_pdf_library_missing', 'PDF-stempling krever FPDI/FPDF-biblioteket, men det kunne ikke lastes.');
		}
		if (!is_readable($original_path)) {
			return new WP_Error('posten_pdf_original_unreadable', 'Original PDF kan ikke leses.');
		}

		try {
			$pdf = new \setasign\Fpdi\Fpdi('P', 'mm');
			$pdf->SetAutoPageBreak(false);
			$page_count = $pdf->setSourceFile($original_path);
			$stamp_text = $this->sanitize_stamp_text_value($stamp_text);
			for ($page = 1; $page <= $page_count; $page++) {
				$template_id = $pdf->importPage($page);
				$size = $pdf->getTemplateSize($template_id);
				$width = isset($size['width']) ? (float) $size['width'] : 100.0;
				$height = isset($size['height']) ? (float) $size['height'] : 150.0;
				$orientation = $width > $height ? 'L' : 'P';
				$pdf->AddPage($orientation, array($width, $height));
				$pdf->useTemplate($template_id, 0, 0, $width, $height, true);
				if ($page === 1 && $stamp_text !== '') {
					$this->draw_pdf_stamp_text($pdf, $stamp_text, $settings);
				}
			}
			$pdf->Output('F', $output_path);
		} catch (Throwable $throwable) {
			return new WP_Error('posten_pdf_stamp_failed', 'PDF-stempling feilet: ' . $throwable->getMessage());
		}

		return is_readable($output_path) ? $output_path : new WP_Error('posten_pdf_stamp_output_missing', 'Stemplet PDF ble ikke opprettet.');
	}

	private function load_pdf_stamping_library() {
		if (!class_exists('FPDF', false)) {
			$fpdf_path = __DIR__ . '/vendor/setasign/fpdf/fpdf.php';
			if (!is_readable($fpdf_path)) {
				return false;
			}
			require_once $fpdf_path;
		}
		if (!class_exists('\setasign\Fpdi\Fpdi', false)) {
			$fpdi_autoload = __DIR__ . '/vendor/setasign/fpdi/src/autoload.php';
			if (!is_readable($fpdi_autoload)) {
				return false;
			}
			require_once $fpdi_autoload;
		}
		return class_exists('FPDF', false) && class_exists('\setasign\Fpdi\Fpdi', false);
	}

	private function draw_pdf_stamp_text($pdf, $stamp_text, $settings) {
		$font_size = isset($settings['stamp_font_size']) ? max(6, min(24, (float) $settings['stamp_font_size'])) : 10;
		$x = isset($settings['stamp_x_mm']) ? max(0, (float) $settings['stamp_x_mm']) : 8;
		$y = isset($settings['stamp_y_mm']) ? max(0, (float) $settings['stamp_y_mm']) : 8;
		$max_width = isset($settings['stamp_max_width_mm']) ? max(10, (float) $settings['stamp_max_width_mm']) : 80;
		$max_lines = isset($settings['stamp_max_lines']) ? max(1, min(5, absint($settings['stamp_max_lines']))) : 2;
		$pdf->SetFont('Helvetica', '', $font_size);
		$pdf->SetTextColor(0, 0, 0);
		$pdf->SetFillColor(255, 255, 255);
		$lines = $this->wrap_pdf_stamp_lines($pdf, $stamp_text, $max_width, $max_lines);
		if (empty($lines)) {
			return;
		}
		$line_height = max(3.5, $font_size * 0.45);
		$pdf->Rect($x - 1, $y - 1, $max_width + 2, (count($lines) * $line_height) + 2, 'F');
		foreach ($lines as $line) {
			$pdf->SetXY($x, $y);
			$pdf->Cell($max_width, $line_height, $this->encode_pdf_text($line), 0, 0, 'L', false);
			$y += $line_height;
		}
	}

	private function wrap_pdf_stamp_lines($pdf, $text, $max_width, $max_lines) {
		$words = preg_split('/\s+/', trim((string) $text));
		$lines = array();
		$current = '';
		foreach ($words as $word) {
			$candidate = $current === '' ? $word : $current . ' ' . $word;
			if ($current !== '' && $pdf->GetStringWidth($this->encode_pdf_text($candidate)) > $max_width) {
				$lines[] = $current;
				$current = $word;
				if (count($lines) >= $max_lines) {
					break;
				}
			} else {
				$current = $candidate;
			}
		}
		if ($current !== '' && count($lines) < $max_lines) {
			$lines[] = $current;
		}
		if (count($lines) > $max_lines) {
			$lines = array_slice($lines, 0, $max_lines);
		}
		return $lines;
	}

	private function encode_pdf_text($text) {
		$text = (string) $text;
		if (function_exists('iconv')) {
			$encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
			if (is_string($encoded)) {
				return $encoded;
			}
		}
		return preg_replace('/[^\x20-\x7E]/', '?', $text);
	}

	private function prepare_completion_payload($request, $job, $printed) {
		$packages = $this->json_decode(isset($job->packages_json) ? $job->packages_json : '');
		$expected_indexes = $this->get_expected_package_indexes($packages);
		if (empty($expected_indexes)) {
			$expected_indexes = array(1);
		}

		$raw_package_results = $this->get_request_package_results($request);
		if (is_wp_error($raw_package_results)) {
			return $raw_package_results;
		}
		if (is_array($raw_package_results)) {
			return $this->prepare_multi_package_completion((string) $job->job_id, $raw_package_results, $expected_indexes, $printed);
		}

		return $this->prepare_single_package_completion($request, (string) $job->job_id, $expected_indexes, $printed);
	}

	private function get_request_package_results($request) {
		$raw = $request->get_param('package_results');
		if ($raw === null || $raw === '') {
			return null;
		}
		if (is_string($raw)) {
			$decoded = json_decode($raw, true);
			$raw = is_array($decoded) ? $decoded : null;
		}
		if (!is_array($raw) || empty($raw)) {
			return new WP_Error('posten_job_package_results_invalid', 'package_results maa vaere en ikke-tom liste.', array('status' => 400));
		}

		return array_values($raw);
	}

	private function prepare_multi_package_completion($job_id, $raw_package_results, $expected_indexes, $printed) {
		$results = array();
		$seen = array();
		foreach ($raw_package_results as $result) {
			if (!is_array($result)) {
				return new WP_Error('posten_job_package_result_invalid', 'Hvert package_results-element maa vaere et objekt.', array('status' => 400));
			}
			$package_index = isset($result['package_index']) ? absint($result['package_index']) : 0;
			if ($package_index < 1 || !in_array($package_index, $expected_indexes, true)) {
				return new WP_Error('posten_job_package_index_invalid', 'package_index matcher ikke et kolli i jobben.', array('status' => 400));
			}
			if (isset($seen[$package_index])) {
				return new WP_Error('posten_job_package_index_duplicate', 'package_results inneholder duplikat package_index.', array('status' => 400));
			}
			$seen[$package_index] = true;

			$tracking_number = isset($result['tracking_number']) ? sanitize_text_field((string) $result['tracking_number']) : '';
			$label_base64 = isset($result['label_pdf_base64']) ? (string) $result['label_pdf_base64'] : '';
			if ($label_base64 === '' && isset($result['label_pdf'])) {
				$label_base64 = (string) $result['label_pdf'];
			}
			if ($tracking_number === '' || $label_base64 === '') {
				return new WP_Error('posten_job_package_result_missing_fields', 'Hvert kolli maa ha tracking_number og label_pdf_base64.', array('status' => 400));
			}
			$tracking_url = isset($result['tracking_url']) ? esc_url_raw((string) $result['tracking_url']) : '';
			if ($tracking_url === '') {
				$tracking_url = $this->build_default_tracking_url($tracking_number);
			}
			$stored_label = $this->store_label_pdf($job_id, $label_base64, isset($result['label_filename']) ? sanitize_file_name((string) $result['label_filename']) : '', $package_index);
			if (is_wp_error($stored_label)) {
				return $stored_label;
			}

			$results[] = $this->build_stored_package_result($package_index, $tracking_number, $tracking_url, $stored_label['path'], 0, $printed);
		}

		return $this->finalize_completion_payload($results, $expected_indexes);
	}

	private function prepare_single_package_completion($request, $job_id, $expected_indexes, $printed) {
		$tracking_number = sanitize_text_field((string) $request->get_param('tracking_number'));
		$tracking_url = esc_url_raw((string) $request->get_param('tracking_url'));
		if ($tracking_url === '' && $tracking_number !== '') {
			$tracking_url = $this->build_default_tracking_url($tracking_number);
		}
		$label_base64 = (string) $request->get_param('label_pdf_base64');
		if ($label_base64 === '') {
			$label_base64 = (string) $request->get_param('label_pdf');
		}
		if ($tracking_number === '' || $label_base64 === '') {
			return new WP_Error('posten_job_complete_missing_fields', 'tracking_number og label_pdf_base64 er påkrevd.', array('status' => 400));
		}

		$package_index = isset($expected_indexes[0]) ? (int) $expected_indexes[0] : 1;
		$stored_label = $this->store_label_pdf($job_id, $label_base64, sanitize_file_name((string) $request->get_param('label_filename')), count($expected_indexes) > 1 ? $package_index : 0);
		if (is_wp_error($stored_label)) {
			return $stored_label;
		}

		return $this->finalize_completion_payload(array(
			$this->build_stored_package_result($package_index, $tracking_number, $tracking_url, $stored_label['path'], 0, $printed),
		), $expected_indexes);
	}

	private function build_stored_package_result($package_index, $tracking_number, $tracking_url, $label_file_path, $attachment_id, $printed) {
		return array(
			'package_index' => absint($package_index),
			'tracking_number' => sanitize_text_field((string) $tracking_number),
			'tracking_url' => esc_url_raw((string) $tracking_url),
			'label_file_path' => (string) $label_file_path,
			'label_attachment_id' => absint($attachment_id),
			'label_filename' => basename((string) $label_file_path),
			'printed' => (int) $printed,
		);
	}

	private function build_order_package_results_for_meta($package_results) {
		$order_package_results = array();
		foreach ((array) $package_results as $result) {
			if (!is_array($result)) {
				continue;
			}
			$order_package_results[] = array(
				'package_index' => isset($result['package_index']) ? (int) $result['package_index'] : 0,
				'tracking_number' => isset($result['tracking_number']) ? (string) $result['tracking_number'] : '',
				'tracking_url' => isset($result['tracking_url']) ? (string) $result['tracking_url'] : '',
				'label_file_path' => isset($result['label_file_path']) ? (string) $result['label_file_path'] : '',
				'label_attachment_id' => isset($result['label_attachment_id']) ? (int) $result['label_attachment_id'] : 0,
				'label_filename' => isset($result['label_filename']) ? (string) $result['label_filename'] : '',
				'stamped_label_file_path' => isset($result['stamped_label_file_path']) ? (string) $result['stamped_label_file_path'] : '',
				'stamped_label_filename' => isset($result['stamped_label_filename']) ? (string) $result['stamped_label_filename'] : '',
				'printed' => isset($result['printed']) ? (int) $result['printed'] : 0,
				'print_error' => isset($result['print_error']) ? (string) $result['print_error'] : '',
				'printer_id' => isset($result['printer_id']) ? (string) $result['printer_id'] : '',
				'printed_at_gmt' => isset($result['printed_at_gmt']) ? (string) $result['printed_at_gmt'] : '',
			);
		}

		return $order_package_results;
	}

	private function finalize_completion_payload($package_results, $expected_indexes) {
		usort($package_results, array($this, 'sort_by_package_index'));
		$missing = $this->get_missing_package_indexes_from_expected($expected_indexes, $package_results);
		$status = empty($missing) ? self::JOB_STATUS_COMPLETED : self::JOB_STATUS_PARTIAL_FAILED;
		$tracking_numbers = array();
		$label_files = array();
		foreach ($package_results as $result) {
			$tracking_numbers[] = (string) $result['tracking_number'];
			$label_files[] = array(
				'package_index' => (int) $result['package_index'],
				'label_file_path' => (string) $result['label_file_path'],
				'label_attachment_id' => (int) $result['label_attachment_id'],
				'label_filename' => (string) $result['label_filename'],
			);
		}

		$first = isset($package_results[0]) ? $package_results[0] : array();
		$last_error = '';
		if ($status === self::JOB_STATUS_PARTIAL_FAILED) {
			$last_error = 'Posten robot fullførte ' . count($package_results) . ' av ' . count($expected_indexes) . ' kolli. Mangler kolli: ' . implode(', ', array_map('strval', $missing)) . '.';
		}

		return array(
			'status' => $status,
			'tracking_number' => isset($first['tracking_number']) ? (string) $first['tracking_number'] : '',
			'tracking_numbers' => $tracking_numbers,
			'tracking_url' => isset($first['tracking_url']) ? (string) $first['tracking_url'] : '',
			'label_file_path' => isset($first['label_file_path']) ? (string) $first['label_file_path'] : '',
			'package_results' => $package_results,
			'order_package_results' => $this->build_order_package_results_for_meta($package_results),
			'label_files' => $label_files,
			'missing_package_indexes' => $missing,
			'last_error' => $last_error,
		);
	}

	private function get_expected_package_indexes($packages) {
		$indexes = array();
		foreach ((array) $packages as $position => $package) {
			$index = is_array($package) && isset($package['index']) ? absint($package['index']) : ((int) $position + 1);
			if ($index > 0 && !in_array($index, $indexes, true)) {
				$indexes[] = $index;
			}
		}
		sort($indexes, SORT_NUMERIC);
		return $indexes;
	}

	private function get_missing_package_indexes($packages, $package_results) {
		return $this->get_missing_package_indexes_from_expected($this->get_expected_package_indexes($packages), $package_results);
	}

	private function get_missing_package_indexes_from_expected($expected_indexes, $package_results) {
		$completed = array();
		foreach ((array) $package_results as $result) {
			if (is_array($result) && isset($result['package_index'])) {
				$completed[] = absint($result['package_index']);
			}
		}

		return array_values(array_diff(array_map('absint', (array) $expected_indexes), $completed));
	}

	private function sort_by_package_index($a, $b) {
		$a_index = is_array($a) && isset($a['package_index']) ? absint($a['package_index']) : 0;
		$b_index = is_array($b) && isset($b['package_index']) ? absint($b['package_index']) : 0;
		if ($a_index === $b_index) {
			return 0;
		}

		return $a_index < $b_index ? -1 : 1;
	}

	private function store_label_pdf($job_id, $label_base64, $label_filename = '', $package_index = 0) {
		$label_base64 = trim((string) $label_base64);
		if (strpos($label_base64, ',') !== false && preg_match('/^data:application\/pdf;base64,/', $label_base64)) {
			$label_base64 = substr($label_base64, strpos($label_base64, ',') + 1);
		}

		$pdf = base64_decode($label_base64, true);
		if ($pdf === false || strlen($pdf) < 5) {
			return new WP_Error('posten_label_invalid_base64', 'Label PDF er ikke gyldig base64.', array('status' => 400));
		}
		if (strlen($pdf) > 10 * 1024 * 1024) {
			return new WP_Error('posten_label_too_large', 'Label PDF er over 10 MB.', array('status' => 400));
		}
		if (substr($pdf, 0, 4) !== '%PDF') {
			return new WP_Error('posten_label_not_pdf', 'Label-data ser ikke ut som en PDF.', array('status' => 400));
		}

		$dir = $this->get_label_storage_dir();
		if (!wp_mkdir_p($dir)) {
			return new WP_Error('posten_label_storage_unavailable', 'Kunne ikke opprette privat labelmappe.', array('status' => 500));
		}

		$this->write_label_dir_guards($dir);
		$label_filename = sanitize_file_name((string) $label_filename);
		if ($label_filename === '' || strtolower(substr($label_filename, -4)) !== '.pdf') {
			$label_filename = 'posten-label-' . $job_id . ($package_index > 0 ? '-kolli-' . absint($package_index) : '') . '.pdf';
		}
		$prefix = $job_id . ($package_index > 0 ? '-kolli-' . absint($package_index) : '');
		$file = trailingslashit($dir) . sanitize_file_name($prefix . '-' . $label_filename);
		$written = file_put_contents($file, $pdf, LOCK_EX);
		if ($written === false) {
			return new WP_Error('posten_label_write_failed', 'Kunne ikke lagre label PDF.', array('status' => 500));
		}

		return array('path' => $file);
	}

	private function get_label_storage_dir() {
		$upload_dir = wp_upload_dir();
		$base_dir = isset($upload_dir['basedir']) ? (string) $upload_dir['basedir'] : WP_CONTENT_DIR . '/uploads';
		return trailingslashit($base_dir) . 'lp-posten-labels';
	}

	private function write_label_dir_guards($dir) {
		$index = trailingslashit($dir) . 'index.html';
		if (!file_exists($index)) {
			file_put_contents($index, '');
		}
		$htaccess = trailingslashit($dir) . '.htaccess';
		$rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
		if (!file_exists($htaccess) || (string) file_get_contents($htaccess) !== $rules) {
			file_put_contents($htaccess, $rules);
		}
	}

	private function is_file_inside_label_dir($file) {
		$base = realpath($this->get_label_storage_dir());
		$target = realpath($file);
		if (!$base || !$target) {
			return false;
		}

		return strpos($target, $base . DIRECTORY_SEPARATOR) === 0;
	}

	private function migrate_table_defaults() {
		global $wpdb;
		$table = $this->get_table_name();
		$now = gmdate('Y-m-d H:i:s');

		if ($this->column_exists('order_number') && $this->column_exists('order_id')) {
			$wpdb->query("UPDATE {$table} SET order_number = CAST(order_id AS CHAR) WHERE (order_number IS NULL OR order_number = '')");
		}
		if ($this->column_exists('source')) {
			$wpdb->query($wpdb->prepare("UPDATE {$table} SET source = %s WHERE (source IS NULL OR source = '')", 'admin_manual_norgespakke'));
		}
		if ($this->column_exists('service')) {
			$wpdb->query($wpdb->prepare("UPDATE {$table} SET service = %s WHERE (service IS NULL OR service = '')", 'norgespakke'));
		}
		if ($this->column_exists('method_key')) {
			$wpdb->query($wpdb->prepare("UPDATE {$table} SET method_key = %s WHERE (method_key IS NULL OR method_key = '')", LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY));
		}
		if ($this->column_exists('created_at_gmt')) {
			if ($this->column_exists('requested_at_gmt')) {
				$wpdb->query("UPDATE {$table} SET created_at_gmt = requested_at_gmt WHERE (created_at_gmt IS NULL OR created_at_gmt = '0000-00-00 00:00:00') AND requested_at_gmt IS NOT NULL AND requested_at_gmt <> '0000-00-00 00:00:00'");
			}
			$wpdb->query($wpdb->prepare("UPDATE {$table} SET created_at_gmt = %s WHERE created_at_gmt IS NULL OR created_at_gmt = '0000-00-00 00:00:00'", $now));
		}
		if ($this->column_exists('updated_at_gmt')) {
			if ($this->column_exists('created_at_gmt')) {
				$wpdb->query("UPDATE {$table} SET updated_at_gmt = created_at_gmt WHERE (updated_at_gmt IS NULL OR updated_at_gmt = '0000-00-00 00:00:00') AND created_at_gmt IS NOT NULL AND created_at_gmt <> '0000-00-00 00:00:00'");
			}
			$wpdb->query($wpdb->prepare("UPDATE {$table} SET updated_at_gmt = %s WHERE updated_at_gmt IS NULL OR updated_at_gmt = '0000-00-00 00:00:00'", $now));
		}
		if ($this->column_exists('last_error') && $this->column_exists('failure_message')) {
			$wpdb->query("UPDATE {$table} SET last_error = failure_message WHERE (last_error IS NULL OR last_error = '') AND failure_message IS NOT NULL AND failure_message <> ''");
		}
	}

	private function validate_recipient_for_norgespakke($recipient) {
		$required = array(
			'name' => 'navn',
			'address_1' => 'adresse',
			'postcode' => 'postnummer',
			'city' => 'sted',
			'country' => 'land',
		);
		$missing = array();
		foreach ($required as $key => $label) {
			if (!isset($recipient[$key]) || trim((string) $recipient[$key]) === '') {
				$missing[] = $label;
			}
		}
		if (!empty($missing)) {
			return new WP_Error('invalid_recipient', 'Mottaker mangler ' . implode(', ', $missing) . '.');
		}
		if (strtoupper((string) $recipient['country']) !== 'NO') {
			return new WP_Error('invalid_recipient_country', 'Manuell Norgespakke kan bare brukes for mottakerland NO.');
		}

		return true;
	}

	private function sanitize_optional_dimension($package, $keys, $label, $index, &$errors) {
		foreach ($keys as $key) {
			if (!array_key_exists($key, $package) || $package[$key] === '') {
				continue;
			}
			$value = $package[$key];
			if (is_string($value)) {
				$value = str_replace(',', '.', $value);
			}
			if (!is_numeric($value)) {
				$errors[] = 'Kolli ' . $index . ' har ugyldig ' . $label . '.';
				return 0.0;
			}
			$value = (float) $value;
			if ($value < 0) {
				$errors[] = 'Kolli ' . $index . ' har negativ ' . $label . '.';
				return 0.0;
			}
			return $value;
		}

		return 0.0;
	}

	private function get_order_recipient_phone($order) {
		$shipping_phone = '';
		if ($order && is_object($order) && method_exists($order, 'get_shipping_phone')) {
			$shipping_phone = sanitize_text_field((string) $order->get_shipping_phone());
		}
		if ($shipping_phone !== '') {
			return $shipping_phone;
		}

		return sanitize_text_field((string) $order->get_billing_phone());
	}

	private function first_non_empty($primary, $fallback) {
		$primary = trim((string) $primary);
		if ($primary !== '') {
			return $primary;
		}

		return trim((string) $fallback);
	}

	private function get_order_method_lock_name($order_id, $method_key) {
		return 'lp_posten_job_' . md5(absint($order_id) . '|' . (string) $method_key);
	}

	private function acquire_job_lock($lock_name) {
		global $wpdb;
		$lock_name = substr(sanitize_key((string) $lock_name), 0, 64);
		$acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 10));
		if ((string) $acquired !== '1') {
			return new WP_Error('posten_job_lock_failed', 'Kunne ikke låse Posten etikettkø for ordren. Prøv igjen.');
		}

		return true;
	}

	private function release_job_lock($lock_name) {
		global $wpdb;
		$lock_name = substr(sanitize_key((string) $lock_name), 0, 64);
		$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));
	}

	private function is_admin_override_request() {
		return current_user_can('manage_woocommerce');
	}

	private function build_default_tracking_url($tracking_number) {
		return esc_url_raw('https://sporing.posten.no/sporing/' . rawurlencode((string) $tracking_number));
	}

	private function parse_bool_param($value) {
		if (is_bool($value)) {
			return $value;
		}
		$value = strtolower(trim((string) $value));
		return in_array($value, array('1', 'true', 'yes', 'ja', 'on'), true);
	}

	private function set_order_status_if_current($order, $expected_status, $target_status, $note) {
		if (!$order || !method_exists($order, 'get_status')) {
			return false;
		}
		if ((string) $order->get_status() !== (string) $expected_status) {
			return false;
		}

		return $this->set_order_status_verified($order, $target_status, $note);
	}

	private function get_job_status_label($status) {
		switch ((string) $status) {
			case self::JOB_STATUS_QUEUED:
			case self::JOB_STATUS_PROCESSING:
				return 'Venter på etikett';
			case self::JOB_STATUS_COMPLETED:
				return 'Etikett opprettet';
			case self::JOB_STATUS_FAILED:
				return 'Feilet';
			case self::JOB_STATUS_PARTIAL_FAILED:
				return 'Delvis feilet';
			case self::JOB_STATUS_CANCELLED:
				return 'Kansellert';
		}

		return (string) $status;
	}

	private function get_package_results_for_response($row) {
		$package_results = $this->json_decode(isset($row->package_results_json) ? $row->package_results_json : '');
		if (empty($package_results) && !empty($row->tracking_number)) {
			$package_results = array(array(
				'package_index' => 1,
				'tracking_number' => (string) $row->tracking_number,
				'tracking_url' => isset($row->tracking_url) ? (string) $row->tracking_url : '',
				'label_attachment_id' => isset($row->label_attachment_id) ? (int) $row->label_attachment_id : 0,
				'label_filename' => !empty($row->label_file_path) ? basename((string) $row->label_file_path) : '',
			));
		}
		$print_results = $this->index_by_package_index($this->json_decode(isset($row->print_results_json) ? $row->print_results_json : ''));
		usort($package_results, array($this, 'sort_by_package_index'));
		foreach ($package_results as &$result) {
			$package_index = isset($result['package_index']) ? absint($result['package_index']) : 0;
			$print_result = $package_index > 0 && isset($print_results[$package_index]) ? $print_results[$package_index] : array();
			unset($result['label_file_path']);
			unset($result['stamped_label_file_path']);
			$result['label_url'] = $package_index > 0 ? $this->get_admin_label_url($row, $package_index) : '';
			$result['stamped_label_url'] = $package_index > 0 ? $this->get_admin_label_url($row, $package_index, 'stamped') : '';
			$result['printed'] = isset($print_result['printed']) ? (int) $print_result['printed'] : (isset($result['printed']) ? (int) $result['printed'] : 0);
			$result['print_error'] = isset($print_result['error']) ? (string) $print_result['error'] : (isset($result['print_error']) ? (string) $result['print_error'] : '');
			$result['printer_id'] = isset($print_result['printer_id']) ? (string) $print_result['printer_id'] : (isset($result['printer_id']) ? (string) $result['printer_id'] : '');
			$result['printed_at_gmt'] = isset($print_result['printed_at_gmt']) ? (string) $print_result['printed_at_gmt'] : (isset($result['printed_at_gmt']) ? (string) $result['printed_at_gmt'] : '');
		}
		unset($result);

		return $package_results;
	}

	private function get_label_files_for_response($row) {
		$label_files = $this->json_decode(isset($row->label_files_json) ? $row->label_files_json : '');
		if (empty($label_files) && !empty($row->label_file_path)) {
			$label_files = array(array(
				'package_index' => 1,
				'label_attachment_id' => isset($row->label_attachment_id) ? (int) $row->label_attachment_id : 0,
				'label_filename' => basename((string) $row->label_file_path),
				'label_file_path' => (string) $row->label_file_path,
			));
		}
		usort($label_files, array($this, 'sort_by_package_index'));
		foreach ($label_files as &$label_file) {
			$package_index = isset($label_file['package_index']) ? absint($label_file['package_index']) : 0;
			unset($label_file['label_file_path']);
			$label_file['label_url'] = $package_index > 0 ? $this->get_admin_label_url($row, $package_index) : '';
		}
		unset($label_file);

		return $label_files;
	}

	private function get_stamped_label_files_for_response($row) {
		$label_files = $this->json_decode(isset($row->stamped_label_files_json) ? $row->stamped_label_files_json : '');
		usort($label_files, array($this, 'sort_by_package_index'));
		foreach ($label_files as &$label_file) {
			$package_index = isset($label_file['package_index']) ? absint($label_file['package_index']) : 0;
			unset($label_file['stamped_label_file_path']);
			$label_file['stamped_label_url'] = $package_index > 0 ? $this->get_admin_label_url($row, $package_index, 'stamped') : '';
		}
		unset($label_file);

		return $label_files;
	}

	private function get_print_results_for_response($row) {
		$print_results = $this->json_decode(isset($row->print_results_json) ? $row->print_results_json : '');
		usort($print_results, array($this, 'sort_by_package_index'));
		foreach ($print_results as &$print_result) {
			if (!is_array($print_result)) {
				$print_result = array();
				continue;
			}
			$print_result['printed'] = isset($print_result['printed']) ? (int) $print_result['printed'] : 0;
			$print_result['http_status'] = isset($print_result['http_status']) ? (int) $print_result['http_status'] : 0;
		}
		unset($print_result);

		return $print_results;
	}

	private function index_by_package_index($rows) {
		$indexed = array();
		foreach ((array) $rows as $row) {
			if (!is_array($row) || !isset($row['package_index'])) {
				continue;
			}
			$package_index = absint($row['package_index']);
			if ($package_index > 0) {
				$indexed[$package_index] = $row;
			}
		}

		return $indexed;
	}

	private function get_label_file_for_package($row, $package_index) {
		$package_index = absint($package_index);
		if ($package_index < 1) {
			return '';
		}
		$label_files = $this->json_decode(isset($row->label_files_json) ? $row->label_files_json : '');
		foreach ($label_files as $label_file) {
			if (is_array($label_file) && isset($label_file['package_index']) && absint($label_file['package_index']) === $package_index && !empty($label_file['label_file_path'])) {
				return (string) $label_file['label_file_path'];
			}
		}
		if ($package_index === 1 && !empty($row->label_file_path)) {
			return (string) $row->label_file_path;
		}

		return '';
	}

	private function get_stamped_label_file_for_package($row, $package_index) {
		$package_index = absint($package_index);
		if ($package_index < 1) {
			return '';
		}
		$label_files = $this->json_decode(isset($row->stamped_label_files_json) ? $row->stamped_label_files_json : '');
		foreach ($label_files as $label_file) {
			if (is_array($label_file) && isset($label_file['package_index']) && absint($label_file['package_index']) === $package_index && !empty($label_file['stamped_label_file_path'])) {
				return (string) $label_file['stamped_label_file_path'];
			}
		}

		return '';
	}

	private function get_admin_label_url($row, $package_index = null, $type = 'original') {
		if (!current_user_can('manage_woocommerce')) {
			return '';
		}
		$type = sanitize_key((string) $type);
		if ($type !== 'stamped') {
			$type = 'original';
		}
		$package_index = $package_index === null ? null : absint($package_index);
		if ($package_index !== null && $package_index > 0) {
			$file = $type === 'stamped' ? $this->get_stamped_label_file_for_package($row, $package_index) : $this->get_label_file_for_package($row, $package_index);
			if ($file === '') {
				return '';
			}

			return add_query_arg(
				array(
					'_wpnonce' => wp_create_nonce('wp_rest'),
					'package_index' => $package_index,
					'type' => $type,
				),
				rest_url(self::REST_NAMESPACE . self::REST_ROUTE_PRIMARY . '/' . rawurlencode((string) $row->job_id) . '/label')
			);
		}

		$packages = $this->json_decode(isset($row->packages_json) ? $row->packages_json : '');
		if (count($packages) > 1 || empty($row->label_file_path)) {
			return '';
		}

		return add_query_arg(
			'_wpnonce',
			wp_create_nonce('wp_rest'),
			rest_url(self::REST_NAMESPACE . self::REST_ROUTE_PRIMARY . '/' . rawurlencode((string) $row->job_id) . '/label')
		);
	}

	private function get_job_datetime($row, $field, $fallback = '') {
		if (isset($row->$field) && trim((string) $row->$field) !== '' && (string) $row->$field !== '0000-00-00 00:00:00') {
			return (string) $row->$field;
		}

		return (string) $fallback;
	}

	private function nullable_datetime($value) {
		$value = trim((string) $value);
		return $value === '' || $value === '0000-00-00 00:00:00' ? null : $value;
	}

	private function get_request_token($request) {
		if (is_object($request) && method_exists($request, 'get_header')) {
			$header = trim((string) $request->get_header(self::TOKEN_HEADER));
			if ($header !== '') {
				return $header;
			}
			$authorization = trim((string) $request->get_header('authorization'));
			if (stripos($authorization, 'Bearer ') === 0) {
				return trim(substr($authorization, 7));
			}
		}

		return '';
	}

	private function generate_job_id($order_id, $attempt = 0) {
		$entropy = array(
			(string) absint($order_id),
			(string) absint($attempt),
			(string) microtime(true),
			uniqid('', true),
		);
		if (function_exists('wp_generate_uuid4')) {
			$entropy[] = (string) wp_generate_uuid4();
		}
		if (function_exists('wp_rand')) {
			$entropy[] = (string) wp_rand(0, PHP_INT_MAX);
		}
		if (function_exists('random_bytes')) {
			try {
				$entropy[] = bin2hex(random_bytes(16));
			} catch (Throwable $throwable) {
				$entropy[] = $throwable->getMessage();
			}
		}

		$suffix = substr(hash('sha256', implode('|', $entropy)), 0, 32);
		return substr('posten_' . absint($order_id) . '_' . $suffix, 0, 64);
	}

	private function is_duplicate_job_id_insert_error($db_error) {
		$db_error = (string) $db_error;
		return stripos($db_error, 'Duplicate entry') !== false && stripos($db_error, 'job_id') !== false;
	}

	private function sanitize_job_id($job_id) {
		return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $job_id);
	}

	private function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'lp_cargonizer_posten_jobs';
	}

	private function column_exists($column) {
		global $wpdb;
		$column = preg_replace('/[^A-Za-z0-9_]/', '', (string) $column);
		if ($column === '') {
			return false;
		}

		return (string) $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM {$this->get_table_name()} LIKE %s", $column)) === $column;
	}

	private function table_exists() {
		global $wpdb;
		$table = $this->get_table_name();
		return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
	}

	private function json_encode($value) {
		$json = wp_json_encode($value, JSON_UNESCAPED_UNICODE);
		return is_string($json) ? $json : '{}';
	}

	private function json_decode($json) {
		$value = json_decode((string) $json, true);
		return is_array($value) ? $value : array();
	}

	private function to_float($value) {
		if (is_string($value)) {
			$value = str_replace(',', '.', $value);
		}
		return is_numeric($value) ? (float) $value : 0.0;
	}

	private function log_event($level, $message, $context = array()) {
		if (!function_exists('wc_get_logger')) {
			return;
		}
		$logger = wc_get_logger();
		if (!$logger) {
			return;
		}
		$method = method_exists($logger, $level) ? $level : 'info';
		$logger->$method($message, array(
			'source' => 'lp-cargonizer-posten-label',
			'context' => is_array($context) ? $context : array(),
		));
	}
}
