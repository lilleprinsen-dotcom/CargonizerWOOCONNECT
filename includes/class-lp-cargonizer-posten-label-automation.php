<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Posten_Label_Automation {
	const AJAX_ACTION_QUEUE = 'lp_cargonizer_queue_posten_label_job';
	const NONCE_ACTION_QUEUE = 'lp_cargonizer_queue_posten_label_job';
	const ADMIN_ACTION_CANCEL_JOB = 'lp_cargonizer_cancel_posten_label_job';
	const NONCE_ACTION_CANCEL_JOB = 'lp_cargonizer_cancel_posten_label_job';
	const ADMIN_ACTION_REPRINT_LABELS = 'lp_cargonizer_reprint_posten_labels';
	const NONCE_ACTION_REPRINT_LABELS = 'lp_cargonizer_reprint_posten_labels';
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
		add_action('admin_post_' . self::ADMIN_ACTION_CANCEL_JOB, array($this, 'admin_cancel_job_action'));
		add_action('admin_post_' . self::ADMIN_ACTION_REPRINT_LABELS, array($this, 'admin_reprint_labels_action'));
		add_action('wp_ajax_' . self::ADMIN_ACTION_REPRINT_LABELS, array($this, 'admin_reprint_labels_action'));
		add_action('woocommerce_admin_order_data_after_order_details', array($this, 'render_order_label_status_panel'), 20);
		add_action('woocommerce_payment_complete', array($this, 'maybe_auto_queue_for_order'), 20, 1);
		add_action('woocommerce_order_status_processing', array($this, 'maybe_auto_queue_for_order'), 20, 1);
		add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_changed'), 20, 4);
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
			'recipient' => $this->get_recipient_override_from_request(),
		));

		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()), 400);
		}

		wp_send_json_success($result);
	}

	public function admin_cancel_job_action() {
		if (!current_user_can('manage_woocommerce')) {
			wp_die(esc_html__('Ingen tilgang.', 'lp-cargonizer'), '', array('response' => 403));
		}

		$job_id = isset($_REQUEST['job_id']) && !is_array($_REQUEST['job_id']) ? $this->sanitize_job_id((string) wp_unslash($_REQUEST['job_id'])) : '';
		$nonce = isset($_REQUEST['_wpnonce']) && !is_array($_REQUEST['_wpnonce']) ? sanitize_text_field((string) wp_unslash($_REQUEST['_wpnonce'])) : '';
		$posted_redirect = isset($_REQUEST['redirect_to']) && !is_array($_REQUEST['redirect_to']) ? esc_url_raw((string) wp_unslash($_REQUEST['redirect_to'])) : '';
		if ($job_id === '' || !wp_verify_nonce($nonce, self::NONCE_ACTION_CANCEL_JOB . '_' . $job_id)) {
			wp_die(esc_html__('Ugyldig forespørsel.', 'lp-cargonizer'), '', array('response' => 403));
		}

		$job = $this->get_job_by_id($job_id);
		$order_id = $job && isset($job->order_id) ? absint($job->order_id) : (isset($_REQUEST['order_id']) ? absint($_REQUEST['order_id']) : 0);
		$result = $job ? $this->cancel_job_by_id($job_id, 'Kansellert manuelt av admin fra ordrevisningen.') : new WP_Error('posten_job_not_found', 'Posten labeljobb ikke funnet.');
		$fallback_redirect_url = $this->get_order_edit_url($order_id);
		$redirect_url = $posted_redirect !== '' ? wp_validate_redirect($posted_redirect, $fallback_redirect_url) : $fallback_redirect_url;
		$redirect_url = add_query_arg(array(
			'lp_posten_cancel_job' => is_wp_error($result) ? 'failed' : 'cancelled',
			'lp_posten_job_id' => $job_id,
		), $redirect_url);

		wp_safe_redirect($redirect_url);
		exit;
	}

	public function admin_reprint_labels_action() {
		$is_ajax = (defined('DOING_AJAX') && DOING_AJAX) || (isset($_REQUEST['ajax']) && !is_array($_REQUEST['ajax']) && (string) wp_unslash($_REQUEST['ajax']) === '1');
		if (!current_user_can('manage_woocommerce')) {
			if ($is_ajax) {
				wp_send_json_error(array('message' => 'Ingen tilgang.'), 403);
			}
			wp_die(esc_html__('Ingen tilgang.', 'lp-cargonizer'), '', array('response' => 403));
		}

		$job_id = isset($_REQUEST['job_id']) && !is_array($_REQUEST['job_id']) ? $this->sanitize_job_id((string) wp_unslash($_REQUEST['job_id'])) : '';
		$nonce = isset($_REQUEST['_wpnonce']) && !is_array($_REQUEST['_wpnonce']) ? sanitize_text_field((string) wp_unslash($_REQUEST['_wpnonce'])) : '';
		$printer_id = isset($_REQUEST['printer_id']) && !is_array($_REQUEST['printer_id']) ? sanitize_text_field((string) wp_unslash($_REQUEST['printer_id'])) : '';
		$package_index = isset($_REQUEST['package_index']) && !is_array($_REQUEST['package_index']) ? absint($_REQUEST['package_index']) : 0;
		$posted_redirect = isset($_REQUEST['redirect_to']) && !is_array($_REQUEST['redirect_to']) ? esc_url_raw((string) wp_unslash($_REQUEST['redirect_to'])) : '';
		if ($job_id === '' || !wp_verify_nonce($nonce, self::NONCE_ACTION_REPRINT_LABELS . '_' . $job_id)) {
			if ($is_ajax) {
				wp_send_json_error(array('message' => 'Ugyldig forespørsel.'), 403);
			}
			wp_die(esc_html__('Ugyldig forespørsel.', 'lp-cargonizer'), '', array('response' => 403));
		}

		$job = $this->get_job_by_id($job_id);
		$order_id = $job && isset($job->order_id) ? absint($job->order_id) : (isset($_REQUEST['order_id']) ? absint($_REQUEST['order_id']) : 0);
		$result = $job ? $this->reprint_job_labels($job, $printer_id, $package_index) : new WP_Error('posten_job_not_found', 'Posten labeljobb ikke funnet.');
		$fallback_redirect_url = $this->get_order_edit_url($order_id);
		$redirect_url = $posted_redirect !== '' ? wp_validate_redirect($posted_redirect, $fallback_redirect_url) : $fallback_redirect_url;
		$message = is_wp_error($result)
			? $result->get_error_message()
			: (isset($result['message']) ? (string) $result['message'] : 'Etikett sendt til printer på nytt.');
		$message = function_exists('mb_substr') ? mb_substr($message, 0, 180) : substr($message, 0, 180);
		if ($is_ajax) {
			if (is_wp_error($result)) {
				wp_send_json_error(array(
					'message' => $message,
					'job_id' => $job_id,
				), 400);
			}

			wp_send_json_success(array(
				'message' => $message,
				'job_id' => $job_id,
				'result' => is_array($result) ? $result : array(),
			));
		}

		$redirect_url = add_query_arg(array(
			'lp_posten_reprint' => is_wp_error($result) ? 'failed' : 'printed',
			'lp_posten_job_id' => $job_id,
			'lp_posten_reprint_message' => $message,
		), $redirect_url);

		wp_safe_redirect($redirect_url);
		exit;
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
		$recipient = $this->build_recipient_snapshot($order, isset($args['recipient']) && is_array($args['recipient']) ? $args['recipient'] : array());
		$recipient_validation = $this->validate_recipient_for_norgespakke($recipient);
		if (is_wp_error($recipient_validation)) {
			return $recipient_validation;
		}
		$shipping = $this->build_shipping_snapshot($order, $method_payload, $args);
		$items = $this->build_items_snapshot($order);
		$source = isset($args['source']) && $args['source'] !== '' ? sanitize_key((string) $args['source']) : 'admin_manual_norgespakke';
		$order_number = method_exists($order, 'get_order_number') ? sanitize_text_field((string) $order->get_order_number()) : (string) $order_id;
		$resume_context = $this->get_retry_resume_context_for_order($order, $method_key, $clean_packages);

		$lock_name = $this->get_order_method_lock_name($order_id, $method_key);
		$lock_acquired = $this->acquire_job_lock($lock_name);
		if (is_wp_error($lock_acquired)) {
			return $lock_acquired;
		}

		global $wpdb;
		$table = $this->get_table_name();
		try {
			$existing = $this->get_active_job_for_order($order_id, $method_key);
			if ($existing && $this->maybe_cancel_job_for_non_waiting_order($existing)) {
				$existing = $this->get_active_job_for_order($order_id, $method_key);
			}
			if ($existing) {
				return array(
					'job' => $existing,
					'payload' => $this->format_job_response($existing, true),
					'created' => false,
				);
			}

			if (!$this->ensure_order_waiting_for_label($order)) {
				return new WP_Error('posten_job_status_not_waiting', 'Posten etikettjobb kan ikke opprettes fordi ordrestatus ikke kunne settes til Venter på etikett.');
			}
			$refreshed_order = wc_get_order($order_id);
			if ($refreshed_order) {
				$order = $refreshed_order;
			}

			$now = gmdate('Y-m-d H:i:s');
			$job_id = '';
			$db_error = '';
			$inserted = false;
			$insert_formats = array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s');
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
					'tracking_number' => isset($resume_context['tracking_number']) ? (string) $resume_context['tracking_number'] : '',
					'tracking_url' => isset($resume_context['tracking_url']) ? (string) $resume_context['tracking_url'] : '',
					'label_file_path' => isset($resume_context['label_file_path']) ? (string) $resume_context['label_file_path'] : '',
					'label_attachment_id' => 0,
					'printed' => 0,
					'tracking_numbers_json' => $this->json_encode(isset($resume_context['tracking_numbers']) ? $resume_context['tracking_numbers'] : array()),
					'package_results_json' => $this->json_encode(isset($resume_context['package_results']) ? $resume_context['package_results'] : array()),
					'label_files_json' => $this->json_encode(isset($resume_context['label_files']) ? $resume_context['label_files'] : array()),
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

		$this->write_order_queue_metadata($order, $job, $recipient, $sender, $clean_packages, $shipping, $resume_context);
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

	public function handle_order_status_changed($order_id, $old_status, $new_status, $order = null) {
		$new_status = sanitize_key((string) $new_status);
		if ($new_status === self::ORDER_STATUS_WAITING) {
			return;
		}

		$status_text = $new_status !== '' ? $new_status : 'ukjent';
		$message = $new_status === 'cancelled'
			? 'WooCommerce-ordren ble kansellert.'
			: 'WooCommerce-ordrestatus er ikke Venter på etikett. Status: ' . $status_text . '.';
		$this->cancel_active_jobs_for_order($order_id, $order, $message);
	}

	public function cancel_active_jobs_for_order($order_id, $order = null, $message = '') {
		$order_id = absint($order_id);
		if ($order_id < 1) {
			return 0;
		}

		$this->maybe_install_table();
		global $wpdb;
		$table = $this->get_table_name();
		$now = gmdate('Y-m-d H:i:s');
		$message = trim((string) $message);
		if ($message === '') {
			$message = 'WooCommerce-ordren ble kansellert.';
		}
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
				$this->add_private_order_note($order, 'Posten Norgespakke etikettjobb kansellert. ' . $message . ' Antall jobber: ' . (int) $updated . '.');
			}
		}

		return (int) $updated;
	}

	private function cancel_job_by_id($job_id, $message = '') {
		$job_id = $this->sanitize_job_id($job_id);
		if ($job_id === '') {
			return new WP_Error('posten_job_missing_id', 'Mangler Posten jobb-ID.');
		}

		$job = $this->get_job_by_id($job_id);
		if (!$job) {
			return new WP_Error('posten_job_not_found', 'Posten labeljobb ikke funnet.');
		}
		$status = isset($job->status) ? (string) $job->status : '';
		if (!in_array($status, array(self::JOB_STATUS_QUEUED, self::JOB_STATUS_PROCESSING), true)) {
			return new WP_Error('posten_job_not_cancellable', 'Posten labeljobb kan bare kanselleres når den venter eller behandles.');
		}

		$message = trim((string) $message);
		if ($message === '') {
			$message = 'Kansellert manuelt av admin.';
		}

		$this->maybe_install_table();
		global $wpdb;
		$table = $this->get_table_name();
		$now = gmdate('Y-m-d H:i:s');
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, last_error = %s, failure_message = %s, updated_at_gmt = %s WHERE job_id = %s AND status IN (%s, %s)",
				self::JOB_STATUS_CANCELLED,
				$message,
				$message,
				$now,
				$job_id,
				self::JOB_STATUS_QUEUED,
				self::JOB_STATUS_PROCESSING
			)
		);
		if (!$updated) {
			return new WP_Error('posten_job_cancel_failed', 'Posten labeljobb kunne ikke kanselleres fordi status er endret.');
		}

		$order = wc_get_order((int) $job->order_id);
		if ($order) {
			$order->update_meta_data(self::META_LABEL_STATUS, self::JOB_STATUS_CANCELLED);
			$order->save();
			$this->add_private_order_note($order, 'Posten Norgespakke etikettjobb kansellert. Jobb: ' . $job_id . '. ' . $message);
		}

		return $this->get_job_by_id($job_id);
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
		$edited_label_url = $label_url !== '' ? $this->get_admin_label_url($job, null, 'stamped') : '';
		$packages = isset($payload['packages']) && is_array($payload['packages']) ? $payload['packages'] : array();
		$package_results = isset($payload['package_results']) && is_array($payload['package_results']) ? $payload['package_results'] : array();
		$package_map = $this->build_package_index_map($packages);
		$worker_id = isset($payload['worker_id']) ? (string) $payload['worker_id'] : '';
		$last_error = isset($payload['last_error']) ? (string) $payload['last_error'] : '';

		echo '<div class="lp-posten-label-status" style="clear:both;margin-top:12px;padding:10px 12px;border:1px solid #dcdcde;background:#f6f7f7;">';
		echo '<strong>Posten/Norgespakke automasjon</strong>';
		echo '<div style="margin-top:6px;">Status: ' . esc_html($status_label !== '' ? $status_label : '-') . '</div>';
		echo '<div>Jobb-ID: <code>' . esc_html((string) $job->job_id) . '</code></div>';
		$this->render_reprint_notice((string) $job->job_id);
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
		if (in_array($status, array(self::JOB_STATUS_QUEUED, self::JOB_STATUS_PROCESSING), true)) {
			$cancel_url = add_query_arg(array(
				'action' => self::ADMIN_ACTION_CANCEL_JOB,
				'job_id' => (string) $job->job_id,
				'order_id' => (int) $order->get_id(),
				'redirect_to' => $this->get_order_edit_url((int) $order->get_id()),
				'_wpnonce' => wp_create_nonce(self::NONCE_ACTION_CANCEL_JOB . '_' . (string) $job->job_id),
			), admin_url('admin-post.php'));
			echo '<div style="margin-top:10px;">';
			echo '<a href="' . esc_url($cancel_url) . '" class="button" onclick="return confirm(\'Kansellere Posten etikettjobb? Roboten vil ikke kunne fullføre denne jobben.\');">Kanseller etikettjobb</a>';
			echo '</div>';
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
					echo ($result_label_url !== '' ? ' | ' : '') . '<a href="' . esc_url($result_stamped_label_url) . '" target="_blank" rel="noopener noreferrer">Redigert PDF</a>';
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
			echo '<div>Etikett: <a href="' . esc_url($label_url) . '" target="_blank" rel="noopener noreferrer">Original label</a>';
			if ($edited_label_url !== '') {
				echo ' | <a href="' . esc_url($edited_label_url) . '" target="_blank" rel="noopener noreferrer">Redigert PDF</a>';
			}
			echo '</div>';
		}
		$this->render_reprint_controls($job, $order);
		echo '<div style="margin-top:6px;color:#646970;">Køjobber behandles når lokal pakkemaskin/robot kjører.</div>';
		echo '</div>';
	}

	private function render_reprint_notice($job_id) {
		$notice_job_id = isset($_GET['lp_posten_job_id']) && !is_array($_GET['lp_posten_job_id']) ? $this->sanitize_job_id((string) wp_unslash($_GET['lp_posten_job_id'])) : '';
		if ($notice_job_id === '' || $notice_job_id !== $job_id) {
			return;
		}
		$status = isset($_GET['lp_posten_reprint']) && !is_array($_GET['lp_posten_reprint']) ? sanitize_key((string) wp_unslash($_GET['lp_posten_reprint'])) : '';
		if (!in_array($status, array('printed', 'failed'), true)) {
			return;
		}
		$message = isset($_GET['lp_posten_reprint_message']) && !is_array($_GET['lp_posten_reprint_message']) ? sanitize_text_field((string) wp_unslash($_GET['lp_posten_reprint_message'])) : '';
		if ($message === '') {
			$message = $status === 'printed' ? 'Etikett sendt til printer på nytt.' : 'Reprint feilet.';
		}
		$color = $status === 'printed' ? '#125228' : '#b32d2e';
		echo '<div style="margin-top:6px;color:' . esc_attr($color) . ';">Reprint: ' . esc_html($message) . '</div>';
	}

	private function render_reprint_controls($job, $order) {
		if (!current_user_can('manage_woocommerce') || !$job || !$order) {
			return;
		}
		$status = isset($job->status) ? (string) $job->status : '';
		if (!in_array($status, array(self::JOB_STATUS_COMPLETED, self::JOB_STATUS_PARTIAL_FAILED), true)) {
			return;
		}

		$package_indexes = $this->get_printable_package_indexes($job);
		if (empty($package_indexes)) {
			return;
		}

		$selected_printer_id = $this->get_default_reprint_printer_id($job);
		$printer_context = $this->get_reprint_printer_options($selected_printer_id);
		$printer_options = isset($printer_context['printers']) && is_array($printer_context['printers']) ? $printer_context['printers'] : array();
		if (empty($printer_options)) {
			$error = isset($printer_context['error']) ? (string) $printer_context['error'] : '';
			echo '<div style="margin-top:10px;color:#b32d2e;">Reprint: Ingen DirectPrint-printere tilgjengelig' . ($error !== '' ? ' - ' . esc_html($error) : '') . '.</div>';
			return;
		}

		if ($selected_printer_id === '' || !isset($printer_options[$selected_printer_id])) {
			$printer_ids = array_keys($printer_options);
			$selected_printer_id = isset($printer_ids[0]) ? (string) $printer_ids[0] : '';
		}

		$order_id = (int) $order->get_id();
		$base_url = add_query_arg(array(
			'action' => self::ADMIN_ACTION_REPRINT_LABELS,
			'job_id' => (string) $job->job_id,
			'order_id' => $order_id,
			'redirect_to' => $this->get_order_edit_url($order_id),
			'_wpnonce' => wp_create_nonce(self::NONCE_ACTION_REPRINT_LABELS . '_' . (string) $job->job_id),
		), admin_url('admin-post.php'));
		$href = add_query_arg(array(
			'printer_id' => $selected_printer_id,
			'package_index' => 0,
		), $base_url);
		$packages = $this->json_decode(isset($job->packages_json) ? $job->packages_json : '');
		$package_map = $this->build_package_index_map($packages);
		$control_id = 'lp-posten-reprint-' . sanitize_html_class((string) $job->job_id);
		$onclick = "return (function(link){var wrap=link.closest('.lp-posten-reprint-controls');var printer=wrap?wrap.querySelector('[data-lp-posten-reprint-printer]'):null;var pack=wrap?wrap.querySelector('[data-lp-posten-reprint-package]'):null;var status=wrap?wrap.querySelector('[data-lp-posten-reprint-status]'):null;if(!printer||!printer.value){alert('Velg printer for reprint.');return false;}if(!window.fetch||!window.FormData){if(status){status.style.color='#b32d2e';status.textContent='Reprint feilet: nettleseren støtter ikke direkte utskrift uten sidelast.';}return false;}if(!confirm('Skrive ut Posten-etikett på nytt?')){return false;}var form=new FormData();form.append('action','" . esc_js(self::ADMIN_ACTION_REPRINT_LABELS) . "');form.append('ajax','1');form.append('job_id',link.getAttribute('data-job-id')||'');form.append('order_id',link.getAttribute('data-order-id')||'');form.append('_wpnonce',link.getAttribute('data-nonce')||'');form.append('printer_id',printer.value);if(pack&&pack.value!==''){form.append('package_index',pack.value);}if(status){status.style.color='#646970';status.textContent='Sender etikett til printer...';}link.classList.add('disabled');link.setAttribute('aria-disabled','true');fetch(link.getAttribute('data-ajax-url')||window.ajaxurl||'',{method:'POST',credentials:'same-origin',body:form}).then(function(response){return response.text().then(function(text){try{return JSON.parse(text);}catch(e){var snippet=text?text.replace(/<[^>]*>/g,' ').replace(/\\s+/g,' ').trim().slice(0,180):'';throw new Error(snippet||'Ugyldig respons fra server.');}});}).then(function(payload){var message=payload&&payload.data&&payload.data.message?payload.data.message:'';if(payload&&payload.success){if(status){status.style.color='#125228';status.textContent=message||'Etikett sendt til printer på nytt.';}return;}throw new Error(message||'Reprint feilet.');}).catch(function(error){if(status){status.style.color='#b32d2e';status.textContent='Reprint feilet: '+(error&&error.message?error.message:'ukjent feil');}}).then(function(){link.classList.remove('disabled');link.removeAttribute('aria-disabled');});return false;})(this);";

		echo '<div id="' . esc_attr($control_id) . '" class="lp-posten-reprint-controls" style="margin-top:10px;padding-top:8px;border-top:1px solid #dcdcde;">';
		echo '<div style="margin-bottom:4px;"><strong>Reprint</strong></div>';
		echo '<label style="display:inline-block;margin-right:8px;">Printer ';
		echo '<select data-lp-posten-reprint-printer="1" style="max-width:220px;">';
		foreach ($printer_options as $printer_id => $printer_label) {
			echo '<option value="' . esc_attr((string) $printer_id) . '"' . selected((string) $printer_id, $selected_printer_id, false) . '>' . esc_html((string) $printer_label) . '</option>';
		}
		echo '</select>';
		echo '</label>';
		if (count($package_indexes) > 1) {
			echo '<label style="display:inline-block;margin-right:8px;">Etikett ';
			echo '<select data-lp-posten-reprint-package="1" style="max-width:220px;">';
			echo '<option value="0">Alle kolli</option>';
			foreach ($package_indexes as $package_index) {
				$description = isset($package_map[$package_index]) ? $this->get_package_display_description($package_index, $package_map[$package_index]) : 'Kolli ' . absint($package_index);
				echo '<option value="' . esc_attr((string) absint($package_index)) . '">' . esc_html($description) . '</option>';
			}
			echo '</select>';
			echo '</label>';
		}
		echo '<a href="' . esc_url($href) . '" data-base-url="' . esc_url($base_url) . '" data-ajax-url="' . esc_url(admin_url('admin-ajax.php')) . '" data-job-id="' . esc_attr((string) $job->job_id) . '" data-order-id="' . esc_attr((string) $order_id) . '" data-nonce="' . esc_attr(wp_create_nonce(self::NONCE_ACTION_REPRINT_LABELS . '_' . (string) $job->job_id)) . '" class="button" onclick="' . esc_attr($onclick) . '">Print på nytt</a>';
		echo '<div data-lp-posten-reprint-status="1" style="margin-top:6px;color:#646970;"></div>';
		if (!empty($printer_context['error'])) {
			echo '<div style="margin-top:4px;color:#646970;">Printerlisten kunne ikke oppdateres akkurat nå. Viser lagret printervalg.</div>';
		}
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
		$exclude_not_waiting_orders = in_array($status, array(self::JOB_STATUS_QUEUED, self::JOB_STATUS_PROCESSING), true);
		foreach ((array) $rows as $row) {
			if ($this->maybe_cancel_job_for_non_waiting_order($row)) {
				if ($exclude_not_waiting_orders) {
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
		if ($this->maybe_cancel_job_for_non_waiting_order($job)) {
			$cancelled_job = $this->get_job_by_id($job_id);
			return new WP_Error('posten_job_order_not_waiting', 'Posten labeljobb ble kansellert fordi WooCommerce-ordren ikke lenger venter på etikett.', array(
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
		if ($this->maybe_cancel_job_for_non_waiting_order($job)) {
			$cancelled_job = $this->get_job_by_id($job_id);
			return new WP_Error('posten_job_order_not_waiting', 'Posten labeljobb ble kansellert fordi WooCommerce-ordren ikke lenger venter på etikett.', array(
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
				$status_updated = $this->set_order_status_if_current($order, self::ORDER_STATUS_WAITING, self::ORDER_STATUS_CREATED, 'Posten Norgespakke etikett opprettet.');
				if ($status_updated) {
					$this->add_customer_order_note($order, $this->build_customer_tracking_order_note($updated_job));
				}
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
			'stamped_label_files' => !empty($response['stamped_label_files']) ? $response['stamped_label_files'] : $this->build_stamped_label_files_for_completion_response($print_context['stamped_label_files']),
			'print_results' => $print_context['print_results'],
			'printed' => (int) $print_context['printed'],
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
		$failure_completion = $this->prepare_failure_package_results_payload($request, $job);
		if (is_wp_error($failure_completion)) {
			return $failure_completion;
		}
		$has_partial_results = is_array($failure_completion) && !empty($failure_completion['package_results']);
		$failure_status = $has_partial_results ? self::JOB_STATUS_PARTIAL_FAILED : self::JOB_STATUS_FAILED;
		if ($has_partial_results && !empty($failure_completion['last_error'])) {
			$message .= ' ' . sanitize_textarea_field((string) $failure_completion['last_error']);
		}

		global $wpdb;
		$table = $this->get_table_name();
		$now = gmdate('Y-m-d H:i:s');
		$set_sql = 'status = %s, last_error = %s, failure_message = %s, failed_at_gmt = %s, updated_at_gmt = %s';
		$args = array(
			$failure_status,
			$message,
			$message,
			$now,
			$now,
		);
		if ($has_partial_results) {
			$set_sql = 'status = %s, tracking_number = %s, tracking_url = %s, label_file_path = %s, label_attachment_id = %d, printed = %d, tracking_numbers_json = %s, package_results_json = %s, label_files_json = %s, last_error = %s, failure_message = %s, failed_at_gmt = %s, updated_at_gmt = %s';
			$args = array(
				$failure_status,
				$failure_completion['tracking_number'],
				$failure_completion['tracking_url'],
				$failure_completion['label_file_path'],
				0,
				0,
				$this->json_encode($failure_completion['tracking_numbers']),
				$this->json_encode($failure_completion['package_results']),
				$this->json_encode($failure_completion['label_files']),
				$message,
				$message,
				$now,
				$now,
			);
		}
		$args[] = $job_id;
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
			$order->update_meta_data(self::META_LABEL_STATUS, $failure_status);
			if ($has_partial_results) {
				$order->update_meta_data(self::META_TRACKING_NUMBER, $failure_completion['tracking_number']);
				$order->update_meta_data(self::META_TRACKING_NUMBERS, $failure_completion['tracking_numbers']);
				$order->update_meta_data(self::META_TRACKING_URL, $failure_completion['tracking_url']);
				$order->update_meta_data(self::META_PACKAGE_RESULTS, $failure_completion['order_package_results']);
				$order->update_meta_data(self::META_LABEL_FILE_PATH, $failure_completion['label_file_path']);
				$order->update_meta_data(self::META_LABEL_FILES, $failure_completion['label_files']);
				$order->update_meta_data(self::META_LABEL_PRINTED, 0);
			}
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
		if ($type !== 'stamped') {
			$type = 'original';
		}
		$effective_package_index = $package_index;
		if ($package_index > 0) {
			$file = $type === 'stamped' ? $this->ensure_stamped_label_file_for_package($job, $package_index) : $this->get_label_file_for_package($job, $package_index);
		} else {
			$packages = $this->json_decode(isset($job->packages_json) ? $job->packages_json : '');
			$label_files = $this->json_decode(isset($job->label_files_json) ? $job->label_files_json : '');
			if (count($packages) > 1 && count($label_files) > 0) {
				return new WP_Error('posten_label_package_index_required', 'Denne jobben har flere etiketter. Angi package_index.', array('status' => 400));
			}
			$effective_package_index = 1;
			$file = $type === 'stamped' ? $this->ensure_stamped_label_file_for_package($job, 1) : (!empty($job->label_file_path) ? (string) $job->label_file_path : '');
			if ($type !== 'stamped' && $file === '' && count($label_files) === 1 && !empty($label_files[0]['label_file_path'])) {
				$file = (string) $label_files[0]['label_file_path'];
			}
		}

		if (is_wp_error($file)) {
			return $file;
		}
		if ($file === '') {
			return new WP_Error('posten_label_not_found', 'Label PDF ikke funnet.', array('status' => 404));
		}
		if (!$this->is_file_inside_label_dir($file) || !is_readable($file)) {
			return new WP_Error('posten_label_unreadable', 'Label PDF kan ikke leses.', array('status' => 404));
		}

		nocache_headers();
		header('Content-Type: application/pdf');
		$filename_package_index = $type === 'stamped' ? $effective_package_index : $package_index;
		$filename = 'posten-label-' . $job->job_id . ($filename_package_index > 0 ? '-kolli-' . $filename_package_index : '') . ($type === 'stamped' ? '-edited' : '') . '.pdf';
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

	private function get_recipient_override_from_request() {
		$recipient = array();
		$fields = array(
			'name' => array('recipient_name', 'text'),
			'address_1' => array('recipient_address_1', 'text'),
			'address_2' => array('recipient_address_2', 'text'),
			'postcode' => array('recipient_postcode', 'text'),
			'city' => array('recipient_city', 'text'),
			'country' => array('recipient_country', 'country'),
			'email' => array('recipient_email', 'email'),
			'phone' => array('recipient_phone', 'text'),
		);

		foreach ($fields as $recipient_key => $field) {
			$post_key = $field[0];
			if (!isset($_POST[$post_key]) || is_array($_POST[$post_key])) {
				continue;
			}

			$value = wp_unslash($_POST[$post_key]);
			$value = $field[1] === 'email'
				? sanitize_email((string) $value)
				: sanitize_text_field((string) $value);
			if ($field[1] === 'country') {
				$value = strtoupper($value);
			}
			if ($value !== '') {
				$recipient[$recipient_key] = $value;
			}
		}

		return $recipient;
	}

	private function sanitize_recipient_override($recipient) {
		if (!is_array($recipient)) {
			return array();
		}

		$clean = array();
		foreach (array('name', 'address_1', 'address_2', 'postcode', 'city', 'country', 'email', 'phone') as $key) {
			if (!isset($recipient[$key])) {
				continue;
			}
			$value = $key === 'email'
				? sanitize_email((string) $recipient[$key])
				: sanitize_text_field((string) $recipient[$key]);
			if ($key === 'country') {
				$value = strtoupper($value);
			}
			if ($value !== '') {
				$clean[$key] = $value;
			}
		}

		return $clean;
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

	private function build_recipient_snapshot($order, $recipient_override = array()) {
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

		$recipient = array(
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
		foreach ($this->sanitize_recipient_override($recipient_override) as $key => $value) {
			$recipient[$key] = $value;
		}

		return $recipient;
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

	private function maybe_cancel_job_for_non_waiting_order($job) {
		if (!$job || !isset($job->order_id)) {
			return false;
		}
		$status = isset($job->status) ? (string) $job->status : '';
		if (!in_array($status, array(self::JOB_STATUS_QUEUED, self::JOB_STATUS_PROCESSING), true)) {
			return false;
		}
		$order_status = $this->get_order_status_for_job($job);
		if ($order_status === self::ORDER_STATUS_WAITING) {
			return false;
		}

		$status_text = $order_status !== '' ? $order_status : 'ukjent';
		$this->cancel_active_jobs_for_order((int) $job->order_id, null, 'WooCommerce-ordrestatus er ikke Venter på etikett. Status: ' . $status_text . '.');
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

	private function get_order_edit_url($order_id) {
		$order_id = absint($order_id);
		if ($order_id > 0 && function_exists('wc_get_order')) {
			$order = wc_get_order($order_id);
			if ($order && method_exists($order, 'get_edit_order_url')) {
				return (string) $order->get_edit_order_url();
			}
		}
		if ($order_id > 0) {
			return admin_url('post.php?post=' . $order_id . '&action=edit');
		}

		return admin_url('edit.php?post_type=shop_order');
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

	private function get_retry_resume_context_for_order($order, $method_key, $packages) {
		$order_id = $order && is_object($order) && method_exists($order, 'get_id') ? absint($order->get_id()) : 0;
		if ($order_id < 1) {
			return $this->build_resume_context_from_package_results(array());
		}

		$latest = $this->get_latest_job_for_order_method($order_id, $method_key);
		$latest_status = $latest && isset($latest->status) ? (string) $latest->status : '';
		if (!in_array($latest_status, array(self::JOB_STATUS_FAILED, self::JOB_STATUS_PARTIAL_FAILED), true)) {
			return $this->build_resume_context_from_package_results(array());
		}

		$expected_indexes = $this->get_expected_package_indexes($packages);
		$package_results = $latest ? $this->json_decode(isset($latest->package_results_json) ? $latest->package_results_json : '') : array();
		$package_results = $latest ? $this->hydrate_package_results_from_label_files($latest, $package_results) : array();
		if (empty($package_results) && $order && is_object($order) && method_exists($order, 'get_meta')) {
			$meta_results = $order->get_meta(self::META_PACKAGE_RESULTS, true);
			$package_results = is_array($meta_results) ? $meta_results : array();
		}

		$package_results = $this->filter_stored_package_results_for_expected_indexes($package_results, $expected_indexes);
		return $this->build_resume_context_from_package_results($package_results);
	}

	private function filter_stored_package_results_for_expected_indexes($package_results, $expected_indexes) {
		$expected_indexes = array_map('absint', (array) $expected_indexes);
		$expected_lookup = array();
		foreach ($expected_indexes as $index) {
			if ($index > 0) {
				$expected_lookup[$index] = true;
			}
		}

		$filtered = array();
		$seen = array();
		foreach ((array) $package_results as $result) {
			if (!is_array($result)) {
				continue;
			}
			$package_index = isset($result['package_index']) ? absint($result['package_index']) : 0;
			if ($package_index < 1 || isset($seen[$package_index])) {
				continue;
			}
			if (!empty($expected_lookup) && empty($expected_lookup[$package_index])) {
				continue;
			}
			$tracking_number = isset($result['tracking_number']) ? sanitize_text_field((string) $result['tracking_number']) : '';
			if ($tracking_number === '') {
				continue;
			}
			$label_file_path = isset($result['label_file_path']) ? (string) $result['label_file_path'] : '';
			$filtered[] = $this->build_stored_package_result(
				$package_index,
				$tracking_number,
				isset($result['tracking_url']) ? (string) $result['tracking_url'] : '',
				$label_file_path,
				isset($result['label_attachment_id']) ? absint($result['label_attachment_id']) : 0,
				isset($result['printed']) ? (int) $result['printed'] : 0
			);
			$last_index = count($filtered) - 1;
			foreach (array('stamped_label_file_path', 'stamped_label_filename', 'print_error', 'printer_id', 'printed_at_gmt') as $key) {
				if (isset($result[$key]) && (string) $result[$key] !== '') {
					$filtered[$last_index][$key] = is_scalar($result[$key]) ? (string) $result[$key] : '';
				}
			}
			$seen[$package_index] = true;
		}

		usort($filtered, array($this, 'sort_by_package_index'));
		return $filtered;
	}

	private function build_resume_context_from_package_results($package_results) {
		$package_results = is_array($package_results) ? $package_results : array();
		usort($package_results, array($this, 'sort_by_package_index'));
		$tracking_numbers = array();
		$label_files = array();
		foreach ($package_results as $result) {
			if (!is_array($result)) {
				continue;
			}
			$tracking_number = isset($result['tracking_number']) ? sanitize_text_field((string) $result['tracking_number']) : '';
			$package_index = isset($result['package_index']) ? absint($result['package_index']) : 0;
			if ($package_index < 1 || $tracking_number === '') {
				continue;
			}
			$tracking_numbers[] = $tracking_number;
			$label_file_path = isset($result['label_file_path']) ? (string) $result['label_file_path'] : '';
			$label_files[] = array(
				'package_index' => $package_index,
				'label_file_path' => $label_file_path,
				'label_attachment_id' => isset($result['label_attachment_id']) ? absint($result['label_attachment_id']) : 0,
				'label_filename' => isset($result['label_filename']) && (string) $result['label_filename'] !== '' ? sanitize_file_name((string) $result['label_filename']) : basename($label_file_path),
			);
		}

		$first = isset($package_results[0]) && is_array($package_results[0]) ? $package_results[0] : array();
		return array(
			'tracking_number' => isset($first['tracking_number']) ? sanitize_text_field((string) $first['tracking_number']) : '',
			'tracking_numbers' => $tracking_numbers,
			'tracking_url' => isset($first['tracking_url']) ? esc_url_raw((string) $first['tracking_url']) : '',
			'label_file_path' => isset($first['label_file_path']) ? (string) $first['label_file_path'] : '',
			'package_results' => $package_results,
			'label_files' => $label_files,
		);
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

	private function write_order_queue_metadata($order, $job, $recipient, $sender, $packages, $shipping, $resume_context = array()) {
		$created_at_gmt = $this->get_job_datetime($job, 'created_at_gmt', $this->get_job_datetime($job, 'requested_at_gmt'));
		$resume_context = is_array($resume_context) ? $resume_context : array();
		$resume_package_results = isset($resume_context['package_results']) && is_array($resume_context['package_results']) ? $resume_context['package_results'] : array();
		$order->update_meta_data(self::META_JOB_ID, (string) $job->job_id);
		$order->update_meta_data(self::META_LABEL_STATUS, self::JOB_STATUS_QUEUED);
		$order->update_meta_data(self::META_REQUESTED_AT_GMT, $created_at_gmt);
		if (!empty($resume_package_results)) {
			$order->update_meta_data(self::META_TRACKING_NUMBER, isset($resume_context['tracking_number']) ? (string) $resume_context['tracking_number'] : '');
			$order->update_meta_data(self::META_TRACKING_NUMBERS, isset($resume_context['tracking_numbers']) && is_array($resume_context['tracking_numbers']) ? $resume_context['tracking_numbers'] : array());
			$order->update_meta_data(self::META_TRACKING_URL, isset($resume_context['tracking_url']) ? (string) $resume_context['tracking_url'] : '');
			$order->update_meta_data(self::META_PACKAGE_RESULTS, $this->build_order_package_results_for_meta($resume_package_results));
			$order->update_meta_data(self::META_LABEL_FILE_PATH, isset($resume_context['label_file_path']) ? (string) $resume_context['label_file_path'] : '');
			$order->update_meta_data(self::META_LABEL_FILES, isset($resume_context['label_files']) && is_array($resume_context['label_files']) ? $resume_context['label_files'] : array());
		} else {
			$order->delete_meta_data(self::META_TRACKING_NUMBER);
			$order->delete_meta_data(self::META_TRACKING_NUMBERS);
			$order->delete_meta_data(self::META_TRACKING_URL);
			$order->delete_meta_data(self::META_PACKAGE_RESULTS);
			$order->delete_meta_data(self::META_LABEL_FILE_PATH);
			$order->delete_meta_data(self::META_LABEL_FILES);
		}
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
		$package_results = $this->hydrate_package_results_from_label_files($job, $package_results);
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

	private function build_customer_tracking_order_note($job) {
		$links = $this->get_tracking_links_from_job($job);
		if (empty($links)) {
			return '';
		}

		if (count($links) === 1) {
			$link = $links[0];
			$tracking_number = isset($link['tracking_number']) ? (string) $link['tracking_number'] : '';
			$tracking_url = isset($link['tracking_url']) ? (string) $link['tracking_url'] : '';
			$tracking_text = $tracking_url;
			if ($tracking_number !== '') {
				$tracking_text = $tracking_number . ': ' . $tracking_url;
			}

			return 'Posten-sporing for ordren din: ' . $tracking_text;
		}

		$lines = array('Posten-sporing for ordren din:');
		foreach ($links as $link) {
			$package_index = isset($link['package_index']) ? absint($link['package_index']) : 0;
			$tracking_number = isset($link['tracking_number']) ? (string) $link['tracking_number'] : '';
			$tracking_url = isset($link['tracking_url']) ? (string) $link['tracking_url'] : '';
			$prefix = $package_index > 0 ? 'Kolli ' . $package_index : 'Sporing';
			if ($tracking_number !== '') {
				$prefix .= ' (' . $tracking_number . ')';
			}
			$lines[] = $prefix . ': ' . $tracking_url;
		}

		return implode("\n", $lines);
	}

	private function get_tracking_links_from_job($job) {
		$links = array();
		$seen_urls = array();
		$package_results = $this->json_decode(isset($job->package_results_json) ? $job->package_results_json : '');
		$package_results = $this->hydrate_package_results_from_label_files($job, $package_results);
		if (!empty($package_results)) {
			usort($package_results, array($this, 'sort_by_package_index'));
			foreach ($package_results as $result) {
				if (!is_array($result)) {
					continue;
				}
				$tracking_url = isset($result['tracking_url']) ? esc_url_raw((string) $result['tracking_url']) : '';
				if ($tracking_url === '') {
					continue;
				}
				if (isset($seen_urls[$tracking_url])) {
					continue;
				}
				$seen_urls[$tracking_url] = true;
				$links[] = array(
					'package_index' => isset($result['package_index']) ? absint($result['package_index']) : 0,
					'tracking_number' => isset($result['tracking_number']) ? sanitize_text_field((string) $result['tracking_number']) : '',
					'tracking_url' => $tracking_url,
				);
			}
		}

		if (empty($links)) {
			$tracking_url = isset($job->tracking_url) ? esc_url_raw((string) $job->tracking_url) : '';
			if ($tracking_url !== '') {
				$links[] = array(
					'package_index' => 0,
					'tracking_number' => isset($job->tracking_number) ? sanitize_text_field((string) $job->tracking_number) : '',
					'tracking_url' => $tracking_url,
				);
			}
		}

		return $links;
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

	private function ensure_order_waiting_for_label($order) {
		if (!$order || !method_exists($order, 'get_status')) {
			return false;
		}
		if ((string) $order->get_status() === self::ORDER_STATUS_WAITING) {
			return true;
		}

		return $this->set_order_status_verified($order, self::ORDER_STATUS_WAITING, 'Posten Norgespakke etikettjobb opprettet.');
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

	private function add_customer_order_note($order, $note) {
		$note = trim((string) $note);
		if (!$order || $note === '' || !method_exists($order, 'add_order_note')) {
			return;
		}
		$order->add_order_note($note, true, true);
	}

	private function reprint_job_labels($job, $printer_id, $package_index = 0) {
		$printer_id = sanitize_text_field((string) $printer_id);
		if ($printer_id === '') {
			return new WP_Error('posten_reprint_printer_missing', 'Velg printer for reprint.');
		}
		$status = isset($job->status) ? (string) $job->status : '';
		if (!in_array($status, array(self::JOB_STATUS_COMPLETED, self::JOB_STATUS_PARTIAL_FAILED), true)) {
			return new WP_Error('posten_reprint_status_invalid', 'Posten etiketter kan bare printes på nytt etter at etikett er opprettet.');
		}

		$printable_indexes = $this->get_printable_package_indexes($job);
		if (empty($printable_indexes)) {
			return new WP_Error('posten_reprint_no_labels', 'Ingen lagrede Posten-labeler kan printes på nytt.');
		}
		$package_index = absint($package_index);
		$selected_indexes = $package_index > 0 ? array($package_index) : $printable_indexes;
		foreach ($selected_indexes as $selected_index) {
			if (!in_array(absint($selected_index), $printable_indexes, true)) {
				return new WP_Error('posten_reprint_package_missing', 'Valgt kolli har ingen lagret label som kan printes på nytt.');
			}
		}

		$settings = $this->get_posten_robot_print_settings();
		$packages = $this->json_decode(isset($job->packages_json) ? $job->packages_json : '');
		$package_map = $this->build_package_index_map($packages);
		$package_results = $this->json_decode(isset($job->package_results_json) ? $job->package_results_json : '');
		if (empty($package_results) && !empty($job->tracking_number)) {
			$package_results = array($this->build_stored_package_result(1, (string) $job->tracking_number, isset($job->tracking_url) ? (string) $job->tracking_url : '', isset($job->label_file_path) ? (string) $job->label_file_path : '', 0, 0));
		}
		$package_results = $this->hydrate_package_results_from_label_files($job, $package_results);
		$stamped_label_files = $this->json_decode(isset($job->stamped_label_files_json) ? $job->stamped_label_files_json : '');
		$print_results = $this->json_decode(isset($job->print_results_json) ? $job->print_results_json : '');
		$now = gmdate('Y-m-d H:i:s');
		$printed_count = 0;
		$errors = array();

		foreach ($selected_indexes as $selected_index) {
			$selected_index = absint($selected_index);
			$file_context = $this->get_reprint_label_file_context($job, $selected_index, isset($package_map[$selected_index]) ? $package_map[$selected_index] : array(), $settings);
			$print_result = array(
				'package_index' => $selected_index,
				'printed' => 0,
				'printer_id' => $printer_id,
				'http_status' => 0,
				'error' => '',
				'printed_at_gmt' => $now,
				'reprint' => 1,
			);

			if (is_wp_error($file_context)) {
				$print_result['error'] = $file_context->get_error_message();
				$file_context = array();
			} else {
				$stamp_warning = !empty($file_context['stamp_error']) ? 'Stempling hoppet over: ' . sanitize_text_field((string) $file_context['stamp_error']) : '';
				if (!empty($file_context['stamped_label_file_path'])) {
					$stamped_label_files = $this->upsert_indexed_row($stamped_label_files, $selected_index, array(
						'package_index' => $selected_index,
						'stamped_label_file_path' => (string) $file_context['stamped_label_file_path'],
						'stamped_label_filename' => basename((string) $file_context['stamped_label_file_path']),
					));
				}
				$pdf_binary = file_get_contents((string) $file_context['print_file_path']);
				if ($pdf_binary === false || $pdf_binary === '') {
					$print_result['error'] = 'PDF for reprint er tom eller kan ikke leses.';
				} else {
					$api_result = $this->api_service->print_document_to_printer($printer_id, $pdf_binary, 'application/pdf');
					$print_result['http_status'] = isset($api_result['http_status']) ? (int) $api_result['http_status'] : 0;
					if (!empty($api_result['success'])) {
						$print_result['printed'] = 1;
						if ($stamp_warning !== '') {
							$print_result['error'] = $stamp_warning;
						}
						$printed_count++;
					} else {
						$api_error = isset($api_result['error']) ? sanitize_text_field((string) $api_result['error']) : '';
						$print_result['error'] = $api_error !== '' ? 'DirectPrint failed: ' . $api_error : 'DirectPrint failed.';
					}
				}
			}

			if (!empty($print_result['error'])) {
				$errors[] = 'Kolli ' . $selected_index . ': ' . $print_result['error'];
			}
			$print_results = $this->upsert_indexed_row($print_results, $selected_index, $print_result);
			$package_result = $this->get_package_result_for_index($package_results, $selected_index);
			$package_result['package_index'] = $selected_index;
			$package_result['printed'] = (int) $print_result['printed'];
			$package_result['print_error'] = (string) $print_result['error'];
			$package_result['printer_id'] = $printer_id;
			$package_result['printed_at_gmt'] = $now;
			if (!empty($file_context['stamped_label_file_path'])) {
				$package_result['stamped_label_file_path'] = (string) $file_context['stamped_label_file_path'];
				$package_result['stamped_label_filename'] = basename((string) $file_context['stamped_label_file_path']);
			}
			$package_results = $this->upsert_indexed_row($package_results, $selected_index, $package_result);
		}

		usort($package_results, array($this, 'sort_by_package_index'));
		usort($stamped_label_files, array($this, 'sort_by_package_index'));
		usort($print_results, array($this, 'sort_by_package_index'));
		$all_printed = $this->are_all_printable_packages_marked_printed($job, $package_results) ? 1 : 0;

		global $wpdb;
		$table = $this->get_table_name();
		$wpdb->update($table, array(
			'printed' => $all_printed,
			'package_results_json' => $this->json_encode($package_results),
			'stamped_label_files_json' => $this->json_encode($stamped_label_files),
			'print_results_json' => $this->json_encode($print_results),
			'updated_at_gmt' => $now,
		), array('job_id' => (string) $job->job_id), array('%d', '%s', '%s', '%s', '%s'), array('%s'));

		$order = wc_get_order((int) $job->order_id);
		if ($order) {
			$order->update_meta_data(self::META_PACKAGE_RESULTS, $this->build_order_package_results_for_meta($package_results));
			$order->update_meta_data(self::META_STAMPED_LABEL_FILES, $stamped_label_files);
			$order->update_meta_data(self::META_PRINT_RESULTS, $print_results);
			$order->update_meta_data(self::META_DIRECT_PRINT_PRINTER_ID, $printer_id);
			$order->update_meta_data(self::META_LABEL_PRINTED, $all_printed);
			$order->save();
			$package_text = implode(', ', array_map('strval', array_map('absint', $selected_indexes)));
			$note = 'Posten Norgespakke etikett sendt til printer på nytt. Jobb: ' . (string) $job->job_id . '. Printer: ' . $this->get_printer_display_label($printer_id) . '. Kolli: ' . $package_text . '. Resultat: ' . $printed_count . '/' . count($selected_indexes) . ' printet.';
			if (!empty($errors)) {
				$note .= ' Feil: ' . implode(' | ', $errors);
			}
			$this->add_private_order_note($order, $note);
		}

		if (!empty($errors)) {
			return new WP_Error('posten_reprint_failed', 'Reprint feilet for ' . count($errors) . ' kolli. ' . implode(' | ', $errors));
		}

		return array(
			'printed' => $printed_count,
			'total' => count($selected_indexes),
			'message' => 'Etikett sendt til printer på nytt (' . $printed_count . '/' . count($selected_indexes) . ').',
		);
	}

	private function process_posten_label_printing($job) {
		$robot_settings = $this->get_posten_robot_print_settings();
		$packages = $this->json_decode(isset($job->packages_json) ? $job->packages_json : '');
		$package_map = $this->build_package_index_map($packages);
		$package_results = $this->json_decode(isset($job->package_results_json) ? $job->package_results_json : '');
		if (empty($package_results) && !empty($job->tracking_number)) {
			$package_results = array($this->build_stored_package_result(1, (string) $job->tracking_number, isset($job->tracking_url) ? (string) $job->tracking_url : '', isset($job->label_file_path) ? (string) $job->label_file_path : '', 0, 0));
		}
		$package_results = $this->hydrate_package_results_from_label_files($job, $package_results);
		usort($package_results, array($this, 'sort_by_package_index'));

		$direct_print_enabled = !empty($robot_settings['direct_print_enabled']) ? 1 : 0;
		$printer_id = isset($robot_settings['direct_print_printer_id']) ? sanitize_text_field((string) $robot_settings['direct_print_printer_id']) : '';
		$stamp_enabled = !empty($robot_settings['stamp_enabled']) ? 1 : 0;
		$stamp_required_for_print = !empty($robot_settings['stamp_required_for_print']);
		$stamped_label_files = array();
		$print_results = array();
		$all_printed = !empty($package_results);
		$now = gmdate('Y-m-d H:i:s');

		if (empty($package_results)) {
			$print_results[] = array(
				'package_index' => 1,
				'printed' => 0,
				'printer_id' => $printer_id,
				'http_status' => 0,
				'error' => $direct_print_enabled ? 'Ingen lagrede Posten-labeler ble funnet for DirectPrint.' : 'DirectPrint disabled',
				'printed_at_gmt' => $now,
			);
			$all_printed = false;
		}

		foreach ($package_results as &$result) {
			if (!is_array($result)) {
				continue;
			}
			$package_index = isset($result['package_index']) ? absint($result['package_index']) : 0;
			$original_path = isset($result['label_file_path']) ? (string) $result['label_file_path'] : $this->get_label_file_for_package($job, $package_index);
			$stamped_path = '';
			$stamped_filename = '';
			$stamp_error = '';
			$stamp_error_is_recoverable = false;

			if ($stamp_enabled && $original_path !== '' && $this->is_file_inside_label_dir($original_path) && is_readable($original_path)) {
				$stamp_text = $this->build_package_stamp_text($package_index, isset($package_map[$package_index]) ? $package_map[$package_index] : array(), $robot_settings);
				if ($stamp_text !== '') {
					$stamped_path = $this->build_stamped_label_path($original_path, $package_index);
					$stamp_result = $this->stamp_pdf_file($original_path, $stamped_path, $stamp_text, $robot_settings);
					if (is_wp_error($stamp_result)) {
						$stamp_error_is_recoverable = $this->is_recoverable_pdf_stamp_error($stamp_result);
						$stamp_error = $stamp_error_is_recoverable ? $this->get_pdf_stamp_fallback_message($stamp_result) : $stamp_result->get_error_message();
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
				$print_result['error'] = ($stamp_error_is_recoverable ? 'Stempling hoppet over: ' : 'Stempling feilet: ') . $stamp_error;
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

	private function get_posten_robot_print_settings() {
		$settings = $this->settings_service->get_settings();
		return isset($settings['posten_robot']) && is_array($settings['posten_robot'])
			? wp_parse_args($settings['posten_robot'], $this->get_posten_robot_print_defaults())
			: $this->get_posten_robot_print_defaults();
	}

	private function get_reprint_label_file_context($job, $package_index, $package, $settings) {
		$package_index = absint($package_index);
		$original_path = $this->get_label_file_for_package($job, $package_index);
		if ($original_path === '' || !$this->is_file_inside_label_dir($original_path) || !is_readable($original_path)) {
			return new WP_Error('posten_reprint_label_missing', 'Lagret PDF mangler eller kan ikke leses.');
		}

		$stamped_path = $this->get_stamped_label_file_for_package($job, $package_index);
		if ($stamped_path !== '' && $this->is_file_inside_label_dir($stamped_path) && is_readable($stamped_path)) {
			return array(
				'print_file_path' => $stamped_path,
				'stamped_label_file_path' => $stamped_path,
			);
		}

		$stamp_enabled = !empty($settings['stamp_enabled']);
		$stamp_required_for_print = !empty($settings['stamp_required_for_print']);
		if ($stamp_enabled) {
			$stamp_text = $this->build_package_stamp_text($package_index, is_array($package) ? $package : array(), $settings);
			if ($stamp_text !== '') {
				$new_stamped_path = $this->build_stamped_label_path($original_path, $package_index);
				$stamp_result = $this->stamp_pdf_file($original_path, $new_stamped_path, $stamp_text, $settings);
				if (!is_wp_error($stamp_result) && is_readable($new_stamped_path)) {
					return array(
						'print_file_path' => $new_stamped_path,
						'stamped_label_file_path' => $new_stamped_path,
					);
				}
				if (is_wp_error($stamp_result) && !$stamp_required_for_print && $this->is_recoverable_pdf_stamp_error($stamp_result)) {
					return array(
						'print_file_path' => $original_path,
						'stamped_label_file_path' => '',
						'stamp_error' => $this->get_pdf_stamp_fallback_message($stamp_result),
					);
				}
				if ($stamp_required_for_print) {
					$message = is_wp_error($stamp_result) ? $stamp_result->get_error_message() : 'Stemplet PDF ble ikke opprettet.';
					return new WP_Error('posten_reprint_stamp_failed', 'Stempling feilet: ' . $message);
				}
				if (is_wp_error($stamp_result)) {
					return array(
						'print_file_path' => $original_path,
						'stamped_label_file_path' => '',
						'stamp_error' => $stamp_result->get_error_message(),
					);
				}
			}
		}

		return array(
			'print_file_path' => $original_path,
			'stamped_label_file_path' => '',
		);
	}

	private function ensure_stamped_label_file_for_package($job, $package_index) {
		$package_index = absint($package_index);
		if ($package_index < 1) {
			return new WP_Error('posten_edited_label_package_missing', 'Mangler kollinummer for redigert PDF.', array('status' => 400));
		}

		$existing_path = $this->get_stamped_label_file_for_package($job, $package_index);
		if ($existing_path !== '' && $this->is_file_inside_label_dir($existing_path) && is_readable($existing_path)) {
			return $existing_path;
		}

		$original_path = $this->get_label_file_for_package($job, $package_index);
		if ($original_path === '' || !$this->is_file_inside_label_dir($original_path) || !is_readable($original_path)) {
			return new WP_Error('posten_edited_label_original_missing', 'Original PDF mangler eller kan ikke leses.', array('status' => 404));
		}

		$settings = $this->get_posten_robot_print_settings();
		$packages = $this->json_decode(isset($job->packages_json) ? $job->packages_json : '');
		$package_map = $this->build_package_index_map($packages);
		$stamp_text = $this->build_package_stamp_text($package_index, isset($package_map[$package_index]) ? $package_map[$package_index] : array(), $settings);
		if ($stamp_text === '') {
			return new WP_Error('posten_edited_label_stamp_empty', 'Redigert PDF mangler tekst for stempling.', array('status' => 400));
		}

		$stamped_path = $this->build_stamped_label_path($original_path, $package_index);
		$stamp_result = $this->stamp_pdf_file($original_path, $stamped_path, $stamp_text, $settings);
		if (is_wp_error($stamp_result)) {
			return $stamp_result;
		}
		if (!$this->is_file_inside_label_dir($stamped_path) || !is_readable($stamped_path)) {
			return new WP_Error('posten_edited_label_missing_after_stamp', 'Redigert PDF ble ikke opprettet.', array('status' => 500));
		}

		$this->record_stamped_label_file($job, $package_index, $stamped_path);
		return $stamped_path;
	}

	private function record_stamped_label_file($job, $package_index, $stamped_path) {
		$package_index = absint($package_index);
		$stamped_path = (string) $stamped_path;
		if (!$job || $package_index < 1 || $stamped_path === '') {
			return;
		}

		$stamped_label_files = $this->json_decode(isset($job->stamped_label_files_json) ? $job->stamped_label_files_json : '');
		$stamped_label_files = $this->upsert_indexed_row($stamped_label_files, $package_index, array(
			'package_index' => $package_index,
			'stamped_label_file_path' => $stamped_path,
			'stamped_label_filename' => basename($stamped_path),
		));

		global $wpdb;
		$table = $this->get_table_name();
		$now = gmdate('Y-m-d H:i:s');
		$wpdb->update($table, array(
			'stamped_label_files_json' => $this->json_encode($stamped_label_files),
			'updated_at_gmt' => $now,
		), array('job_id' => (string) $job->job_id), array('%s', '%s'), array('%s'));

		$order = isset($job->order_id) ? wc_get_order((int) $job->order_id) : null;
		if ($order) {
			$order->update_meta_data(self::META_STAMPED_LABEL_FILES, $stamped_label_files);
			$order->save();
		}
	}

	private function get_package_result_for_index($package_results, $package_index) {
		$package_index = absint($package_index);
		foreach ((array) $package_results as $result) {
			if (is_array($result) && isset($result['package_index']) && absint($result['package_index']) === $package_index) {
				return $result;
			}
		}

		return array();
	}

	private function upsert_indexed_row($rows, $package_index, $data) {
		$package_index = absint($package_index);
		$output = array();
		foreach ((array) $rows as $row) {
			if (!is_array($row) || (isset($row['package_index']) && absint($row['package_index']) === $package_index)) {
				continue;
			}
			$output[] = $row;
		}
		$data = is_array($data) ? $data : array();
		$data['package_index'] = $package_index;
		$output[] = $data;
		usort($output, array($this, 'sort_by_package_index'));
		return $output;
	}

	private function are_all_printable_packages_marked_printed($job, $package_results) {
		$printable_indexes = $this->get_printable_package_indexes($job);
		if (empty($printable_indexes)) {
			return false;
		}
		$indexed_results = $this->index_by_package_index($package_results);
		foreach ($printable_indexes as $package_index) {
			if (empty($indexed_results[$package_index]['printed'])) {
				return false;
			}
		}

		return true;
	}

	private function get_printable_package_indexes($job) {
		$indexes = array();
		$packages = $this->json_decode(isset($job->packages_json) ? $job->packages_json : '');
		$expected_indexes = $this->get_expected_package_indexes($packages);
		$package_results = $this->json_decode(isset($job->package_results_json) ? $job->package_results_json : '');
		foreach ((array) $package_results as $result) {
			if (is_array($result) && isset($result['package_index'])) {
				$expected_indexes[] = absint($result['package_index']);
			}
		}
		if (empty($expected_indexes) && !empty($job->label_file_path)) {
			$expected_indexes[] = 1;
		}

		foreach (array_unique(array_map('absint', $expected_indexes)) as $package_index) {
			if ($package_index < 1) {
				continue;
			}
			$file = $this->get_label_file_for_package($job, $package_index);
			if ($file !== '' && $this->is_file_inside_label_dir($file) && is_readable($file)) {
				$indexes[] = $package_index;
			}
		}
		sort($indexes, SORT_NUMERIC);
		return $indexes;
	}

	private function get_default_reprint_printer_id($job) {
		$print_results = $this->json_decode(isset($job->print_results_json) ? $job->print_results_json : '');
		foreach ((array) $print_results as $print_result) {
			if (is_array($print_result) && !empty($print_result['printer_id'])) {
				return sanitize_text_field((string) $print_result['printer_id']);
			}
		}

		$settings = $this->get_posten_robot_print_settings();
		return isset($settings['direct_print_printer_id']) ? sanitize_text_field((string) $settings['direct_print_printer_id']) : '';
	}

	private function get_reprint_printer_options($selected_printer_id = '') {
		$selected_printer_id = sanitize_text_field((string) $selected_printer_id);
		$options = array();
		$error = '';
		$result = $this->api_service->fetch_printers();
		$aliases = $this->get_printer_alias_map();
		if (!empty($result['success']) && !empty($result['printers']) && is_array($result['printers'])) {
			foreach ($result['printers'] as $printer) {
				if (!is_array($printer)) {
					continue;
				}
				$printer_id = isset($printer['id']) ? sanitize_text_field((string) $printer['id']) : '';
				if ($printer_id === '') {
					continue;
				}
				$base_label = isset($printer['label']) && (string) $printer['label'] !== '' ? sanitize_text_field((string) $printer['label']) : $printer_id;
				$options[$printer_id] = isset($aliases[$printer_id]) ? $aliases[$printer_id] . ' (' . $base_label . ')' : $base_label;
			}
		} else {
			$error = !empty($result['message']) ? (string) $result['message'] : (!empty($result['error']) ? (string) $result['error'] : 'Kunne ikke hente printere.');
		}

		$settings = $this->get_posten_robot_print_settings();
		$fallback_ids = array();
		foreach (array($selected_printer_id, isset($settings['direct_print_printer_id']) ? sanitize_text_field((string) $settings['direct_print_printer_id']) : '') as $fallback_id) {
			if ($fallback_id !== '') {
				$fallback_ids[] = $fallback_id;
			}
		}
		foreach ($fallback_ids as $fallback_id) {
			if ($fallback_id !== '' && !isset($options[$fallback_id])) {
				$options[$fallback_id] = $this->get_printer_display_label($fallback_id);
			}
		}

		return array(
			'printers' => $options,
			'error' => $error,
		);
	}

	private function get_printer_alias_map() {
		$settings = $this->settings_service->get_settings();
		$aliases = isset($settings['printer_aliases']) && is_array($settings['printer_aliases']) ? $settings['printer_aliases'] : array();
		$clean = array();
		foreach ($aliases as $printer_id => $alias) {
			$clean_id = sanitize_text_field((string) $printer_id);
			$clean_alias = sanitize_text_field((string) $alias);
			if ($clean_id !== '' && $clean_alias !== '') {
				$clean[$clean_id] = $clean_alias;
			}
		}

		return $clean;
	}

	private function get_printer_display_label($printer_id) {
		$printer_id = sanitize_text_field((string) $printer_id);
		$aliases = $this->get_printer_alias_map();
		return isset($aliases[$printer_id]) ? $aliases[$printer_id] . ' (' . $printer_id . ')' : $printer_id;
	}

	private function get_package_display_description($package_index, $package) {
		$description = is_array($package) && isset($package['description']) ? $this->sanitize_stamp_text_value($package['description']) : '';
		if ($description === '' && is_array($package) && isset($package['name'])) {
			$description = $this->sanitize_stamp_text_value($package['name']);
		}
		return 'Kolli ' . absint($package_index) . ($description !== '' ? ': ' . $description : '');
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
			$error = new WP_Error('posten_pdf_stamp_failed', 'PDF-stempling feilet: ' . $throwable->getMessage());
			if ($this->is_recoverable_pdf_stamp_error($error)) {
				$fallback = $this->stamp_pdf_file_with_printable_annotation($original_path, $output_path, $stamp_text, $settings);
				if (!is_wp_error($fallback)) {
					return $fallback;
				}

				return new WP_Error('posten_pdf_stamp_failed', $error->get_error_message() . ' Annotation fallback feilet: ' . $fallback->get_error_message());
			}

			return $error;
		}

		return is_readable($output_path) ? $output_path : new WP_Error('posten_pdf_stamp_output_missing', 'Stemplet PDF ble ikke opprettet.');
	}

	private function stamp_pdf_file_with_printable_annotation($original_path, $output_path, $stamp_text, $settings) {
		$pdf = is_readable($original_path) ? file_get_contents($original_path) : false;
		if ($pdf === false || strpos((string) $pdf, '%PDF') !== 0) {
			return new WP_Error('posten_pdf_annotation_original_unreadable', 'Original PDF kan ikke leses for annotation fallback.');
		}

		$page = $this->find_first_pdf_page_object($pdf);
		if (is_wp_error($page)) {
			return $page;
		}

		$max_object_number = $this->get_max_pdf_object_number($pdf);
		if ($max_object_number < 1) {
			return new WP_Error('posten_pdf_annotation_object_scan_failed', 'Kunne ikke finne PDF-objekter for annotation fallback.');
		}

		$font_object_number = $max_object_number + 1;
		$appearance_object_number = $max_object_number + 2;
		$annotation_object_number = $max_object_number + 3;
		$media_box = $this->extract_pdf_page_media_box((string) $page['content']);
		$font_size = isset($settings['stamp_font_size']) ? max(6, min(24, (float) $settings['stamp_font_size'])) : 10;
		$x = isset($settings['stamp_x_mm']) ? max(0, (float) $settings['stamp_x_mm']) * 72 / 25.4 : 8 * 72 / 25.4;
		$y_from_top = isset($settings['stamp_y_mm']) ? max(0, (float) $settings['stamp_y_mm']) * 72 / 25.4 : 8 * 72 / 25.4;
		$page_left = (float) $media_box[0];
		$page_bottom = (float) $media_box[1];
		$page_right = (float) $media_box[2];
		$page_top = (float) $media_box[3];
		$max_width = isset($settings['stamp_max_width_mm']) ? max(10, (float) $settings['stamp_max_width_mm']) * 72 / 25.4 : 80 * 72 / 25.4;
		$width = min($max_width, max(36, ($page_right - $page_left) - $x - 4));
		$max_lines = isset($settings['stamp_max_lines']) ? max(1, min(5, absint($settings['stamp_max_lines']))) : 2;
		$lines = $this->wrap_pdf_annotation_stamp_lines($stamp_text, $font_size, $width, $max_lines);
		if (empty($lines)) {
			return new WP_Error('posten_pdf_annotation_empty_text', 'Annotation fallback mangler tekst.');
		}
		$line_height = max(8.0, $font_size * 1.25);
		$height = (count($lines) * $line_height) + 4;
		$rect_left = $page_left + $x;
		$rect_top = $page_top - $y_from_top;
		$rect_bottom = max($page_bottom + 2, $rect_top - $height);
		$rect_top = $rect_bottom + $height;
		if ($rect_top > $page_top) {
			$rect_top = $page_top - 2;
			$rect_bottom = max($page_bottom + 2, $rect_top - $height);
		}
		$rect_right = min($page_right - 2, $rect_left + $width);
		$width = $rect_right - $rect_left;
		$height = $rect_top - $rect_bottom;
		$appearance_stream = $this->build_pdf_annotation_appearance_stream($lines, $font_size, $width, $height);
		$font_object = $font_object_number . " 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";
		$appearance_object = $appearance_object_number . " 0 obj\n<< /Type /XObject /Subtype /Form /BBox [0 0 " . $this->format_pdf_decimal($width) . ' ' . $this->format_pdf_decimal($height) . '] /Resources << /Font << /F1 ' . $font_object_number . " 0 R >> >> /Length " . strlen($appearance_stream) . " >>\nstream\n" . $appearance_stream . "\nendstream\nendobj\n";
		$annotation_object = $annotation_object_number . " 0 obj\n<< /Type /Annot /Subtype /FreeText /Rect [" . implode(' ', array_map(array($this, 'format_pdf_decimal'), array($rect_left, $rect_bottom, $rect_right, $rect_top))) . '] /Contents (' . $this->escape_pdf_literal($stamp_text) . ') /F 4 /Border [0 0 0] /C [1 1 1] /DA (/F1 ' . $this->format_pdf_decimal($font_size) . " Tf 0 0 0 rg) /AP << /N " . $appearance_object_number . " 0 R >> >>\nendobj\n";
		$updated_page_content = $this->add_annotation_to_pdf_page_content((string) $page['content'], $annotation_object_number . ' 0 R');
		if (is_wp_error($updated_page_content)) {
			return $updated_page_content;
		}
		$page_object = (int) $page['object_number'] . ' ' . (int) $page['generation'] . " obj\n" . $updated_page_content . "\nendobj\n";
		$root_ref = $this->get_pdf_root_reference($pdf);
		if ($root_ref === '') {
			return new WP_Error('posten_pdf_annotation_missing_root', 'Kunne ikke finne PDF Root for annotation fallback.');
		}
		$prev_startxref = $this->get_pdf_previous_startxref($pdf);
		$objects = array(
			$font_object_number => $font_object,
			$appearance_object_number => $appearance_object,
			$annotation_object_number => $annotation_object,
			(int) $page['object_number'] => $page_object,
		);
		$incremental = "\n";
		$offsets = array();
		foreach ($objects as $object_number => $object_body) {
			$offsets[$object_number] = strlen($pdf) + strlen($incremental);
			$incremental .= $object_body;
		}
		$xref_offset = strlen($pdf) + strlen($incremental);
		ksort($offsets, SORT_NUMERIC);
		$incremental .= "xref\n";
		foreach ($offsets as $object_number => $offset) {
			$generation = (int) $object_number === (int) $page['object_number'] ? (int) $page['generation'] : 0;
			$incremental .= (int) $object_number . " 1\n" . sprintf('%010d %05d n ', $offset, $generation) . "\n";
		}
		$incremental .= "trailer\n<< /Size " . ($max_object_number + 4) . ' /Root ' . $root_ref . ($prev_startxref > 0 ? ' /Prev ' . $prev_startxref : '') . " >>\nstartxref\n" . $xref_offset . "\n%%EOF\n";
		$written = file_put_contents($output_path, $pdf . $incremental, LOCK_EX);
		if ($written === false) {
			return new WP_Error('posten_pdf_annotation_write_failed', 'Kunne ikke lagre annotation-stemplet PDF.');
		}

		return is_readable($output_path) ? $output_path : new WP_Error('posten_pdf_annotation_output_missing', 'Annotation-stemplet PDF ble ikke opprettet.');
	}

	private function is_recoverable_pdf_stamp_error($error) {
		if (!is_wp_error($error)) {
			return false;
		}
		if ($error->get_error_code() !== 'posten_pdf_stamp_failed') {
			return false;
		}

		$message = strtolower($error->get_error_message());
		return strpos($message, 'compression technique') !== false
			|| strpos($message, 'not supported by the free parser') !== false
			|| strpos($message, 'free parser shipped with fpdi') !== false;
	}

	private function get_pdf_stamp_fallback_message($error) {
		$message = is_wp_error($error) ? $error->get_error_message() : (string) $error;
		$message = trim($message);
		if ($message === '') {
			return 'PDF-stempling støttes ikke for denne Posten-PDF-en. Bruker original PDF.';
		}

		return 'PDF-stempling støttes ikke for denne Posten-PDF-en. Bruker original PDF. Teknisk detalj: ' . $message;
	}

	private function find_first_pdf_page_object($pdf) {
		if (!preg_match_all('/(\d+)\s+(\d+)\s+obj\b(.*?)endobj/s', (string) $pdf, $matches, PREG_SET_ORDER)) {
			return new WP_Error('posten_pdf_page_not_found', 'Kunne ikke finne PDF-side for annotation fallback.');
		}
		foreach ($matches as $match) {
			$content = isset($match[3]) ? (string) $match[3] : '';
			if ($this->is_pdf_page_dictionary($content)) {
				return array(
					'object_number' => (int) $match[1],
					'generation' => (int) $match[2],
					'content' => trim($content),
				);
			}
		}

		foreach ($this->get_pdf_object_stream_entries((string) $pdf) as $entry) {
			$content = isset($entry['content']) ? (string) $entry['content'] : '';
			if ($this->is_pdf_page_dictionary($content)) {
				return array(
					'object_number' => isset($entry['object_number']) ? (int) $entry['object_number'] : 0,
					'generation' => 0,
					'content' => trim($content),
				);
			}
		}

		return new WP_Error('posten_pdf_page_not_found', 'Kunne ikke finne første PDF-side for annotation fallback.');
	}

	private function get_max_pdf_object_number($pdf) {
		$numbers = array();
		if (preg_match_all('/(\d+)\s+\d+\s+obj\b/', (string) $pdf, $matches)) {
			$numbers = array_map('absint', $matches[1]);
		}
		foreach ($this->get_pdf_object_stream_entries((string) $pdf) as $entry) {
			if (isset($entry['object_number'])) {
				$numbers[] = absint($entry['object_number']);
			}
		}

		return empty($numbers) ? 0 : max($numbers);
	}

	private function is_pdf_page_dictionary($content) {
		return preg_match('/\/Type\s*\/Page\b/', (string) $content) === 1;
	}

	private function get_pdf_object_stream_entries($pdf) {
		$entries = array();
		if (!preg_match_all('/(\d+)\s+(\d+)\s+obj\b(.*?)endobj/s', (string) $pdf, $matches, PREG_SET_ORDER)) {
			return $entries;
		}

		foreach ($matches as $match) {
			$object_content = isset($match[3]) ? (string) $match[3] : '';
			if (strpos($object_content, '/ObjStm') === false || strpos($object_content, '/FlateDecode') === false) {
				continue;
			}
			if (!preg_match('/\/N\s+(\d+)/', $object_content, $n_match) || !preg_match('/\/First\s+(\d+)/', $object_content, $first_match)) {
				continue;
			}

			$stream_data = $this->extract_pdf_stream_data($object_content);
			if ($stream_data === '') {
				continue;
			}
			$decoded_stream = $this->decode_pdf_flate_stream($stream_data);
			if ($decoded_stream === false) {
				continue;
			}

			$n = absint($n_match[1]);
			$first = absint($first_match[1]);
			if ($n < 1 || $first < 1 || strlen($decoded_stream) <= $first) {
				continue;
			}

			$header = substr($decoded_stream, 0, $first);
			$object_data = substr($decoded_stream, $first);
			$tokens = preg_split('/\s+/', trim($header));
			if (!is_array($tokens) || count($tokens) < ($n * 2)) {
				continue;
			}

			$offsets = array();
			for ($i = 0; $i < $n; $i++) {
				$object_number = absint($tokens[$i * 2]);
				$offset = absint($tokens[$i * 2 + 1]);
				if ($object_number > 0) {
					$offsets[] = array(
						'object_number' => $object_number,
						'offset' => $offset,
					);
				}
			}
			usort($offsets, function ($left, $right) {
				return $left['offset'] === $right['offset'] ? 0 : ($left['offset'] < $right['offset'] ? -1 : 1);
			});

			foreach ($offsets as $index => $row) {
				$start = (int) $row['offset'];
				$end = isset($offsets[$index + 1]) ? (int) $offsets[$index + 1]['offset'] : strlen($object_data);
				if ($start < 0 || $end <= $start || $start >= strlen($object_data)) {
					continue;
				}
				$entries[] = array(
					'object_number' => (int) $row['object_number'],
					'content' => trim(substr($object_data, $start, $end - $start)),
				);
			}
		}

		return $entries;
	}

	private function extract_pdf_stream_data($object_content) {
		$stream_pos = strpos((string) $object_content, 'stream');
		if ($stream_pos === false) {
			return '';
		}
		$start = $stream_pos + 6;
		$line_break = substr((string) $object_content, $start, 2);
		if ($line_break === "\r\n") {
			$start += 2;
		} elseif (substr((string) $object_content, $start, 1) === "\n" || substr((string) $object_content, $start, 1) === "\r") {
			$start++;
		}
		$end = strpos((string) $object_content, 'endstream', $start);
		if ($end === false || $end <= $start) {
			return '';
		}

		return substr((string) $object_content, $start, $end - $start);
	}

	private function decode_pdf_flate_stream($stream_data) {
		foreach (array('zlib_decode', 'gzuncompress', 'gzdecode') as $function_name) {
			if (function_exists($function_name)) {
				$decoded = @$function_name($stream_data);
				if (is_string($decoded)) {
					return $decoded;
				}
			}
		}
		if (function_exists('gzinflate')) {
			$decoded = @gzinflate($stream_data);
			if (is_string($decoded)) {
				return $decoded;
			}
			if (strlen($stream_data) > 6) {
				$decoded = @gzinflate(substr($stream_data, 2, -4));
				if (is_string($decoded)) {
					return $decoded;
				}
			}
		}

		return false;
	}

	private function extract_pdf_page_media_box($page_content) {
		if (preg_match('/\/(?:MediaBox|CropBox)\s*\[([^\]]+)\]/', (string) $page_content, $match)) {
			$values = preg_split('/\s+/', trim((string) $match[1]));
			$values = array_values(array_filter($values, 'strlen'));
			if (count($values) >= 4) {
				return array(
					(float) $values[0],
					(float) $values[1],
					(float) $values[2],
					(float) $values[3],
				);
			}
		}

		return array(0.0, 0.0, 283.465, 425.197);
	}

	private function wrap_pdf_annotation_stamp_lines($text, $font_size, $max_width, $max_lines) {
		$text = $this->sanitize_stamp_text_value($text);
		if ($text === '') {
			return array();
		}
		$max_chars = max(8, (int) floor((float) $max_width / max(3.0, (float) $font_size * 0.55)));
		$words = preg_split('/\s+/', $text);
		$lines = array();
		$current = '';
		foreach ($words as $word) {
			$candidate = $current === '' ? $word : $current . ' ' . $word;
			if ($current !== '' && strlen($candidate) > $max_chars) {
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

		return array_slice($lines, 0, $max_lines);
	}

	private function build_pdf_annotation_appearance_stream($lines, $font_size, $width, $height) {
		$font_size = (float) $font_size;
		$line_height = max(8.0, $font_size * 1.25);
		$y = max(2.0, (float) $height - $font_size - 2);
		$stream = "q\n1 1 1 rg\n0 0 " . $this->format_pdf_decimal($width) . ' ' . $this->format_pdf_decimal($height) . " re f\nBT\n/F1 " . $this->format_pdf_decimal($font_size) . " Tf\n0 0 0 rg\n2 " . $this->format_pdf_decimal($y) . " Td\n";
		foreach ((array) $lines as $line) {
			$stream .= '(' . $this->escape_pdf_literal($line) . ") Tj\n0 -" . $this->format_pdf_decimal($line_height) . " Td\n";
		}
		$stream .= "ET\nQ";

		return $stream;
	}

	private function add_annotation_to_pdf_page_content($page_content, $annotation_ref) {
		$page_content = trim((string) $page_content);
		$annotation_ref = trim((string) $annotation_ref);
		if ($page_content === '' || $annotation_ref === '') {
			return new WP_Error('posten_pdf_annotation_page_invalid', 'PDF-side kunne ikke oppdateres med annotation.');
		}
		if (preg_match('/\/Annots\s+\d+\s+\d+\s+R/', $page_content)) {
			return new WP_Error('posten_pdf_annotation_indirect_annots', 'PDF-side har indirekte annotationsliste som ikke støttes av enkel fallback.');
		}
		if (preg_match('/\/Annots\s*\[(.*?)\]/s', $page_content)) {
			return preg_replace('/\/Annots\s*\[(.*?)\]/s', '/Annots [$1 ' . $annotation_ref . ']', $page_content, 1);
		}
		$insert_at = strrpos($page_content, '>>');
		if ($insert_at === false) {
			return new WP_Error('posten_pdf_annotation_page_dict_invalid', 'PDF-side mangler dictionary for annotation fallback.');
		}

		return substr($page_content, 0, $insert_at) . "\n/Annots [" . $annotation_ref . "]\n" . substr($page_content, $insert_at);
	}

	private function get_pdf_root_reference($pdf) {
		if (preg_match_all('/\/Root\s+(\d+\s+\d+\s+R)/', (string) $pdf, $matches) && !empty($matches[1])) {
			return trim((string) end($matches[1]));
		}

		return '';
	}

	private function get_pdf_previous_startxref($pdf) {
		if (preg_match_all('/startxref\s+(\d+)/', (string) $pdf, $matches) && !empty($matches[1])) {
			return absint(end($matches[1]));
		}

		return 0;
	}

	private function format_pdf_decimal($value) {
		$value = str_replace(',', '.', sprintf('%.3F', (float) $value));
		$value = rtrim(rtrim($value, '0'), '.');
		return $value === '' || $value === '-0' ? '0' : $value;
	}

	private function escape_pdf_literal($text) {
		$text = $this->encode_pdf_text($text);
		$text = str_replace('\\', '\\\\', $text);
		$text = str_replace('(', '\\(', $text);
		$text = str_replace(')', '\\)', $text);
		$text = str_replace(array("\r", "\n"), ' ', $text);
		return $text;
	}

	private function load_pdf_stamping_library() {
		if (!class_exists('FPDF', false)) {
			$fpdf_path = __DIR__ . '/vendor/setasign/fpdf/fpdf.php';
			if (!is_readable($fpdf_path)) {
				return false;
			}
			require_once $fpdf_path;
		}
		if (!class_exists('setasign\\Fpdi\\Fpdi', false)) {
			$fpdi_autoload = __DIR__ . '/vendor/setasign/fpdi/src/autoload.php';
			if (!is_readable($fpdi_autoload)) {
				return false;
			}
			require_once $fpdi_autoload;
		}
		return class_exists('FPDF', false) && class_exists('setasign\\Fpdi\\Fpdi', true);
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

		$raw_package_results = $this->get_request_package_results($request, $expected_indexes);
		if (is_wp_error($raw_package_results)) {
			return $raw_package_results;
		}
		$existing_package_results = $this->get_stored_package_results_for_completion($job, $expected_indexes);
		if (is_array($raw_package_results)) {
			if (empty($raw_package_results) && empty($existing_package_results)) {
				return new WP_Error('posten_job_complete_missing_fields', 'tracking_number og label_pdf_base64 er pÃ¥krevd.', array('status' => 400));
			}
			$new_package_results = $this->prepare_multi_package_completion_results((string) $job->job_id, $raw_package_results, $expected_indexes, $printed);
			if (is_wp_error($new_package_results)) {
				return $new_package_results;
			}
			return $this->finalize_completion_payload($this->merge_package_results_by_index($existing_package_results, $new_package_results), $expected_indexes);
		}

		if (!empty($existing_package_results) && !$this->request_has_single_completion_payload($request)) {
			return $this->finalize_completion_payload($existing_package_results, $expected_indexes);
		}

		$completion = $this->prepare_single_package_completion($request, (string) $job->job_id, $expected_indexes, $printed);
		if (is_wp_error($completion) || empty($existing_package_results)) {
			return $completion;
		}

		return $this->finalize_completion_payload($this->merge_package_results_by_index($existing_package_results, $completion['package_results']), $expected_indexes);
	}

	private function prepare_failure_package_results_payload($request, $job) {
		$packages = $this->json_decode(isset($job->packages_json) ? $job->packages_json : '');
		$expected_indexes = $this->get_expected_package_indexes($packages);
		if (empty($expected_indexes)) {
			$expected_indexes = array(1);
		}

		$raw_package_results = $this->get_request_package_results($request, $expected_indexes);
		if (is_wp_error($raw_package_results)) {
			return $raw_package_results;
		}

		$existing_package_results = $this->get_stored_package_results_for_completion($job, $expected_indexes);
		if (!is_array($raw_package_results)) {
			if (empty($existing_package_results)) {
				return null;
			}
			return $this->finalize_completion_payload($existing_package_results, $expected_indexes);
		}

		$new_package_results = $this->prepare_multi_package_completion_results((string) $job->job_id, $raw_package_results, $expected_indexes, 0);
		if (is_wp_error($new_package_results)) {
			return $new_package_results;
		}

		$package_results = $this->merge_package_results_by_index($existing_package_results, $new_package_results);
		if (empty($package_results)) {
			return null;
		}

		return $this->finalize_completion_payload($package_results, $expected_indexes);
	}

	private function get_request_package_results($request, $expected_indexes = array()) {
		foreach (array('package_results', 'labels', 'label_results') as $param_key) {
			$raw = $request->get_param($param_key);
			if ($raw === null || $raw === '') {
				continue;
			}
			if (is_string($raw)) {
				$decoded = json_decode($raw, true);
				$raw = is_array($decoded) ? $decoded : null;
			}
			if (!is_array($raw)) {
				return new WP_Error('posten_job_package_results_invalid', $param_key . ' maa vaere en ikke-tom liste.', array('status' => 400));
			}

			return $this->normalize_request_package_result_rows($raw);
		}

		$parallel_results = $this->build_package_results_from_parallel_request_arrays($request, $expected_indexes);
		if (!empty($parallel_results)) {
			return $parallel_results;
		}

		return null;
	}

	private function normalize_request_package_result_rows($raw) {
		if (is_string($raw)) {
			$decoded = json_decode($raw, true);
			$raw = is_array($decoded) ? $decoded : null;
		}
		if (!is_array($raw)) {
			return new WP_Error('posten_job_package_results_invalid', 'package_results maa vaere en ikke-tom liste.', array('status' => 400));
		}
		if (empty($raw)) {
			return array();
		}

		if ($this->looks_like_package_result_row($raw)) {
			if (!isset($raw['package_index'])) {
				$raw['package_index'] = 1;
			}
			return array($raw);
		}

		$rows = array();
		$is_zero_indexed_list = array_key_exists(0, $raw);
		foreach ($raw as $key => $row) {
			if (is_object($row)) {
				$row = (array) $row;
			}
			if (is_array($row) && !isset($row['package_index'])) {
				$key_index = is_numeric($key) ? absint($key) : 0;
				$row['package_index'] = (!$is_zero_indexed_list && $key_index > 0) ? $key_index : count($rows) + 1;
			}
			$rows[] = $row;
		}

		return $rows;
	}

	private function looks_like_package_result_row($row) {
		if (!is_array($row)) {
			return false;
		}
		foreach (array('tracking_number', 'label_pdf_base64', 'label_pdf', 'pdf_base64', 'label_filename') as $key) {
			if (array_key_exists($key, $row)) {
				return true;
			}
		}
		return false;
	}

	private function request_has_single_completion_payload($request) {
		foreach (array('tracking_number', 'label_pdf_base64', 'label_pdf', 'pdf_base64') as $param_key) {
			$value = $request->get_param($param_key);
			if ($value !== null && $value !== '') {
				return true;
			}
		}

		return false;
	}

	private function get_stored_package_results_for_completion($job, $expected_indexes) {
		$package_results = $this->json_decode(isset($job->package_results_json) ? $job->package_results_json : '');
		if (empty($package_results) && !empty($job->tracking_number)) {
			$package_results = array($this->build_stored_package_result(1, (string) $job->tracking_number, isset($job->tracking_url) ? (string) $job->tracking_url : '', isset($job->label_file_path) ? (string) $job->label_file_path : '', 0, 0));
		}
		$package_results = $this->hydrate_package_results_from_label_files($job, $package_results);
		return $this->filter_stored_package_results_for_expected_indexes($package_results, $expected_indexes);
	}

	private function merge_package_results_by_index($existing_package_results, $new_package_results) {
		$merged = array();
		foreach ((array) $existing_package_results as $result) {
			if (!is_array($result) || !isset($result['package_index'])) {
				continue;
			}
			$merged = $this->upsert_indexed_row($merged, absint($result['package_index']), $result);
		}
		foreach ((array) $new_package_results as $result) {
			if (!is_array($result) || !isset($result['package_index'])) {
				continue;
			}
			$merged = $this->upsert_indexed_row($merged, absint($result['package_index']), $result);
		}
		usort($merged, array($this, 'sort_by_package_index'));
		return $merged;
	}

	private function build_package_results_from_parallel_request_arrays($request, $expected_indexes) {
		$tracking_numbers = $this->get_array_request_param($request, array('tracking_numbers'));
		$tracking_urls = $this->get_array_request_param($request, array('tracking_urls'));
		$label_pdfs = $this->get_array_request_param($request, array('label_pdfs_base64', 'label_pdf_base64s', 'label_pdfs', 'label_pdf_base64', 'label_pdf'));
		$label_filenames = $this->get_array_request_param($request, array('label_filenames'));
		if (empty($tracking_numbers) || empty($label_pdfs)) {
			return array();
		}

		$count = max(count($tracking_numbers), count($label_pdfs));
		$rows = array();
		for ($position = 0; $position < $count; $position++) {
			$package_index = isset($expected_indexes[$position]) ? absint($expected_indexes[$position]) : ($position + 1);
			$rows[] = array(
				'package_index' => $package_index,
				'tracking_number' => $this->get_indexed_or_position_value($tracking_numbers, $package_index, $position),
				'tracking_url' => $this->get_indexed_or_position_value($tracking_urls, $package_index, $position),
				'label_pdf_base64' => $this->get_indexed_or_position_value($label_pdfs, $package_index, $position),
				'label_filename' => $this->get_indexed_or_position_value($label_filenames, $package_index, $position),
			);
		}

		return $rows;
	}

	private function get_array_request_param($request, $keys) {
		foreach ((array) $keys as $key) {
			$value = $request->get_param($key);
			if ($value === null || $value === '') {
				continue;
			}
			if (is_string($value)) {
				$decoded = json_decode($value, true);
				if (is_array($decoded)) {
					return $decoded;
				}
				continue;
			}
			if (is_array($value) && !empty($value)) {
				return $value;
			}
		}

		return array();
	}

	private function get_indexed_or_position_value($values, $package_index, $position) {
		if (!is_array($values) || empty($values)) {
			return '';
		}
		if (array_key_exists($package_index, $values)) {
			return is_scalar($values[$package_index]) ? (string) $values[$package_index] : '';
		}
		$string_index = (string) $package_index;
		if (array_key_exists($string_index, $values)) {
			return is_scalar($values[$string_index]) ? (string) $values[$string_index] : '';
		}
		$list = array_values($values);
		return isset($list[$position]) && is_scalar($list[$position]) ? (string) $list[$position] : '';
	}

	private function prepare_multi_package_completion($job_id, $raw_package_results, $expected_indexes, $printed) {
		$results = $this->prepare_multi_package_completion_results($job_id, $raw_package_results, $expected_indexes, $printed);
		if (is_wp_error($results)) {
			return $results;
		}

		return $this->finalize_completion_payload($results, $expected_indexes);
	}

	private function prepare_multi_package_completion_results($job_id, $raw_package_results, $expected_indexes, $printed) {
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
			if ($label_base64 === '' && isset($result['pdf_base64'])) {
				$label_base64 = (string) $result['pdf_base64'];
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

		return $results;
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

	private function hydrate_package_results_from_label_files($job, $package_results) {
		$package_results = is_array($package_results) ? $package_results : array();
		$label_files = $this->json_decode(isset($job->label_files_json) ? $job->label_files_json : '');
		if (empty($label_files)) {
			return $package_results;
		}

		$tracking_numbers = $this->json_decode(isset($job->tracking_numbers_json) ? $job->tracking_numbers_json : '');
		if (empty($tracking_numbers) && isset($job->tracking_number) && (string) $job->tracking_number !== '') {
			$tracking_numbers = array((string) $job->tracking_number);
		}

		foreach (array_values($label_files) as $position => $label_file) {
			if (!is_array($label_file)) {
				continue;
			}
			$package_index = isset($label_file['package_index']) ? absint($label_file['package_index']) : ($position + 1);
			if ($package_index < 1) {
				continue;
			}

			$result = $this->get_package_result_for_index($package_results, $package_index);
			$tracking_number = isset($result['tracking_number']) ? sanitize_text_field((string) $result['tracking_number']) : '';
			if ($tracking_number === '') {
				$tracking_number = $this->get_indexed_or_position_value($tracking_numbers, $package_index, $position);
			}
			$tracking_url = isset($result['tracking_url']) ? esc_url_raw((string) $result['tracking_url']) : '';
			if ($tracking_url === '' && $tracking_number !== '') {
				$tracking_url = $this->build_default_tracking_url($tracking_number);
			}

			$result['package_index'] = $package_index;
			$result['tracking_number'] = $tracking_number;
			$result['tracking_url'] = $tracking_url;
			if (!empty($label_file['label_file_path'])) {
				$result['label_file_path'] = (string) $label_file['label_file_path'];
			}
			if (!empty($label_file['label_attachment_id'])) {
				$result['label_attachment_id'] = absint($label_file['label_attachment_id']);
			}
			if (!empty($label_file['label_filename'])) {
				$result['label_filename'] = sanitize_file_name((string) $label_file['label_filename']);
			} elseif (!empty($result['label_file_path'])) {
				$result['label_filename'] = basename((string) $result['label_file_path']);
			}

			$package_results = $this->upsert_indexed_row($package_results, $package_index, $result);
		}

		usort($package_results, array($this, 'sort_by_package_index'));
		return $package_results;
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
		$shipping_phone = $this->get_order_shipping_phone($order);
		if ($shipping_phone !== '') {
			return $shipping_phone;
		}

		return $order && is_object($order) && method_exists($order, 'get_billing_phone')
			? sanitize_text_field((string) $order->get_billing_phone())
			: '';
	}

	private function get_order_shipping_phone($order) {
		if (!$order || !is_object($order)) {
			return '';
		}

		$candidates = array();
		if (method_exists($order, 'get_shipping_phone')) {
			$candidates[] = $order->get_shipping_phone();
		}
		if (method_exists($order, 'get_meta')) {
			$candidates[] = $order->get_meta('_shipping_phone', true);
			$candidates[] = $order->get_meta('shipping_phone', true);
		}

		foreach ($candidates as $candidate) {
			$phone = sanitize_text_field((string) $candidate);
			if ($phone !== '') {
				return $phone;
			}
		}

		return '';
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
		$package_results = $this->hydrate_package_results_from_label_files($row, $package_results);
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

	private function build_stamped_label_files_for_completion_response($stamped_label_files) {
		$response_files = array();
		foreach ((array) $stamped_label_files as $label_file) {
			if (!is_array($label_file)) {
				continue;
			}
			$response_files[] = array(
				'package_index' => isset($label_file['package_index']) ? absint($label_file['package_index']) : 0,
				'stamped_label_filename' => isset($label_file['stamped_label_filename']) ? sanitize_file_name((string) $label_file['stamped_label_filename']) : '',
			);
		}
		usort($response_files, array($this, 'sort_by_package_index'));
		return $response_files;
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
			$file = $this->get_label_file_for_package($row, $package_index);
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
		if (count($packages) > 1) {
			return '';
		}
		$file = $type === 'stamped' ? $this->get_label_file_for_package($row, 1) : (!empty($row->label_file_path) ? (string) $row->label_file_path : '');
		if ($type !== 'stamped' && $file === '') {
			$label_files = $this->json_decode(isset($row->label_files_json) ? $row->label_files_json : '');
			if (count($label_files) === 1 && !empty($label_files[0]['label_file_path'])) {
				$file = (string) $label_files[0]['label_file_path'];
			}
		}
		if ($file === '') {
			return '';
		}

		return add_query_arg(
			array(
				'_wpnonce' => wp_create_nonce('wp_rest'),
				'type' => $type,
			),
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
