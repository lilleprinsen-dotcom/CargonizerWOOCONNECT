<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Posten_Label_Automation {
	const AJAX_ACTION_QUEUE = 'lp_cargonizer_queue_posten_label_job';
	const NONCE_ACTION_QUEUE = 'lp_cargonizer_queue_posten_label_job';
	const REST_NAMESPACE = 'lilleprinsen/v1';
	const TOKEN_HEADER = 'X-LP-Posten-Robot-Token';
	const SCHEMA_VERSION_OPTION = 'lp_cargonizer_posten_jobs_schema_version';
	const SCHEMA_VERSION = '1';
	const ORDER_STATUS_WAITING = 'lp-waiting-label';
	const ORDER_STATUS_CREATED = 'lp-label-created';
	const JOB_STATUS_QUEUED = 'queued';
	const JOB_STATUS_PROCESSING = 'processing';
	const JOB_STATUS_COMPLETED = 'completed';
	const JOB_STATUS_FAILED = 'failed';
	const META_JOB_ID = '_lp_posten_label_job_id';
	const META_LABEL_STATUS = '_lp_posten_label_status';
	const META_TRACKING_NUMBER = '_lp_posten_tracking_number';
	const META_TRACKING_URL = '_lp_posten_tracking_url';
	const META_LABEL_FILE_PATH = '_lp_posten_label_file_path';
	const META_REQUESTED_AT_GMT = '_lp_posten_label_requested_at_gmt';
	const META_COMPLETED_AT_GMT = '_lp_posten_label_completed_at_gmt';

	/** @var LP_Cargonizer_Posten_Label_Automation|null */
	private static $instance = null;

	/** @var LP_Cargonizer_Settings_Service */
	private $settings_service;

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
	}

	public function register_order_statuses() {
		register_post_status('wc-' . self::ORDER_STATUS_WAITING, array(
			'label' => 'Venter Posten label',
			'public' => false,
			'exclude_from_search' => false,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
			'label_count' => _n_noop('Venter Posten label <span class="count">(%s)</span>', 'Venter Posten label <span class="count">(%s)</span>', 'lp-cargonizer'),
		));

		register_post_status('wc-' . self::ORDER_STATUS_CREATED, array(
			'label' => 'Posten label opprettet',
			'public' => false,
			'exclude_from_search' => false,
			'show_in_admin_all_list' => true,
			'show_in_admin_status_list' => true,
			'label_count' => _n_noop('Posten label opprettet <span class="count">(%s)</span>', 'Posten label opprettet <span class="count">(%s)</span>', 'lp-cargonizer'),
		));
	}

	public function add_order_statuses($statuses) {
		if (!is_array($statuses)) {
			$statuses = array();
		}

		$statuses['wc-' . self::ORDER_STATUS_WAITING] = 'Venter Posten label';
		$statuses['wc-' . self::ORDER_STATUS_CREATED] = 'Posten label opprettet';
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
			method_key varchar(191) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'queued',
			worker_id varchar(191) NOT NULL DEFAULT '',
			recipient_json longtext NOT NULL,
			sender_json longtext NOT NULL,
			packages_json longtext NOT NULL,
			shipping_json longtext NOT NULL,
			items_json longtext NOT NULL,
			tracking_number varchar(191) NOT NULL DEFAULT '',
			tracking_url text NULL,
			label_file_path text NULL,
			label_attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
			failure_message text NULL,
			requested_by_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			requested_at_gmt datetime NOT NULL,
			claimed_at_gmt datetime NULL,
			completed_at_gmt datetime NULL,
			failed_at_gmt datetime NULL,
			updated_at_gmt datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY job_id (job_id),
			KEY order_id (order_id),
			KEY status (status),
			KEY method_key (method_key),
			KEY requested_at_gmt (requested_at_gmt)
		) {$charset_collate};";

		dbDelta($sql);
		update_option(self::SCHEMA_VERSION_OPTION, self::SCHEMA_VERSION, false);
	}

	public function register_rest_routes() {
		register_rest_route(self::REST_NAMESPACE, '/posten-label-jobs', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'rest_list_jobs'),
			'permission_callback' => array($this, 'rest_robot_or_admin_permission'),
			'args' => array(
				'status' => array('required' => false),
				'limit' => array('required' => false),
			),
		));

		register_rest_route(self::REST_NAMESPACE, '/posten-label-jobs/(?P<job_id>[A-Za-z0-9_-]+)', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'rest_get_job'),
			'permission_callback' => array($this, 'rest_robot_or_admin_permission'),
		));

		register_rest_route(self::REST_NAMESPACE, '/posten-label-jobs/(?P<job_id>[A-Za-z0-9_-]+)/claim', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'rest_claim_job'),
			'permission_callback' => array($this, 'rest_robot_or_admin_permission'),
		));

		register_rest_route(self::REST_NAMESPACE, '/posten-label-jobs/(?P<job_id>[A-Za-z0-9_-]+)/complete', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'rest_complete_job'),
			'permission_callback' => array($this, 'rest_robot_or_admin_permission'),
		));

		register_rest_route(self::REST_NAMESPACE, '/posten-label-jobs/(?P<job_id>[A-Za-z0-9_-]+)/fail', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array($this, 'rest_fail_job'),
			'permission_callback' => array($this, 'rest_robot_or_admin_permission'),
		));

		register_rest_route(self::REST_NAMESPACE, '/posten-label-jobs/(?P<job_id>[A-Za-z0-9_-]+)/label', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array($this, 'rest_download_label'),
			'permission_callback' => array($this, 'rest_admin_permission'),
		));
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
			'source' => 'admin_modal',
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
		$method_key = LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY;
		$existing = $this->get_active_job_for_order($order_id, $method_key);
		if ($existing) {
			return array(
				'job' => $existing,
				'payload' => $this->format_job_response($existing, true),
				'created' => false,
			);
		}

		$clean_packages = $this->sanitize_packages($packages);
		if (is_wp_error($clean_packages)) {
			return $clean_packages;
		}

		$sender = $this->resolve_sender_profile(isset($args['warehouse_profile_id']) ? $args['warehouse_profile_id'] : '');
		$recipient = $this->build_recipient_snapshot($order);
		$shipping = $this->build_shipping_snapshot($order, $method_payload, $args);
		$items = $this->build_items_snapshot($order);

		if ($recipient['name'] === '' || $recipient['postcode'] === '') {
			return new WP_Error('invalid_recipient', 'Mottaker mangler navn eller postnummer.');
		}

		$now = gmdate('Y-m-d H:i:s');
		$job_id = $this->generate_job_id($order_id);
		global $wpdb;
		$table = $this->get_table_name();
		$inserted = $wpdb->insert($table, array(
			'job_id' => $job_id,
			'order_id' => $order_id,
			'method_key' => $method_key,
			'status' => self::JOB_STATUS_QUEUED,
			'worker_id' => '',
			'recipient_json' => $this->json_encode($recipient),
			'sender_json' => $this->json_encode($sender),
			'packages_json' => $this->json_encode($clean_packages),
			'shipping_json' => $this->json_encode($shipping),
			'items_json' => $this->json_encode($items),
			'tracking_number' => '',
			'tracking_url' => '',
			'label_file_path' => '',
			'label_attachment_id' => 0,
			'failure_message' => '',
			'requested_by_user_id' => get_current_user_id(),
			'requested_at_gmt' => $now,
			'claimed_at_gmt' => null,
			'completed_at_gmt' => null,
			'failed_at_gmt' => null,
			'updated_at_gmt' => $now,
		), array('%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s'));

		if (!$inserted) {
			return new WP_Error('posten_job_insert_failed', 'Kunne ikke opprette Posten labeljobb i databasen.');
		}

		$job = $this->get_job_by_id($job_id);
		if (!$job) {
			return new WP_Error('posten_job_missing_after_insert', 'Posten labeljobb ble opprettet, men kunne ikke leses tilbake.');
		}

		$this->write_order_queue_metadata($order, $job, $recipient, $sender, $clean_packages, $shipping);
		$this->set_order_status_verified($order, self::ORDER_STATUS_WAITING, 'Posten labeljobb opprettet og venter paa robot.');
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
		$tracking_url = isset($payload['tracking_url']) ? (string) $payload['tracking_url'] : '';
		$label_url = isset($payload['label_url']) ? (string) $payload['label_url'] : '';
		$packages = isset($payload['packages']) && is_array($payload['packages']) ? $payload['packages'] : array();

		echo '<div class="lp-posten-label-status" style="clear:both;margin-top:12px;padding:10px 12px;border:1px solid #dcdcde;background:#f6f7f7;">';
		echo '<strong>Posten Norgespakke labeljobb</strong>';
		echo '<div style="margin-top:6px;">Status: ' . esc_html($status !== '' ? $status : '-') . '</div>';
		echo '<div>Jobb-ID: <code>' . esc_html((string) $job->job_id) . '</code></div>';
		echo '<div>Kolli: ' . esc_html((string) count($packages)) . '</div>';
		if ($tracking_url !== '') {
			echo '<div>Tracking: <a href="' . esc_url($tracking_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($tracking_url) . '</a></div>';
		}
		if ($label_url !== '') {
			echo '<div>Label: <a href="' . esc_url($label_url) . '" target="_blank" rel="noopener noreferrer">Last ned PDF</a></div>';
		}
		if (!empty($job->failure_message)) {
			echo '<div style="color:#b32d2e;">Feil: ' . esc_html((string) $job->failure_message) . '</div>';
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
			$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} ORDER BY requested_at_gmt ASC LIMIT %d", $limit));
		} else {
			$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE status = %s ORDER BY requested_at_gmt ASC LIMIT %d", $status, $limit));
		}

		$jobs = array();
		foreach ((array) $rows as $row) {
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
			$worker_id = 'posten_robot';
		}

		global $wpdb;
		$table = $this->get_table_name();
		$now = gmdate('Y-m-d H:i:s');
		$updated = $wpdb->update(
			$table,
			array(
				'status' => self::JOB_STATUS_PROCESSING,
				'worker_id' => $worker_id,
				'claimed_at_gmt' => $now,
				'updated_at_gmt' => $now,
			),
			array(
				'job_id' => $job_id,
				'status' => self::JOB_STATUS_QUEUED,
			),
			array('%s', '%s', '%s', '%s'),
			array('%s', '%s')
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
			return new WP_Error('posten_job_not_processing', 'Posten labeljobb maa claimes for den kan fullfores.', array('status' => 409));
		}

		$worker_id = sanitize_text_field((string) $request->get_param('worker_id'));
		if ($worker_id !== '' && (string) $job->worker_id !== '' && $worker_id !== (string) $job->worker_id) {
			return new WP_Error('posten_job_worker_mismatch', 'Worker-ID matcher ikke claime-jobben.', array('status' => 409));
		}

		$tracking_number = sanitize_text_field((string) $request->get_param('tracking_number'));
		$tracking_url = esc_url_raw((string) $request->get_param('tracking_url'));
		$label_base64 = (string) $request->get_param('label_pdf_base64');
		if ($label_base64 === '') {
			$label_base64 = (string) $request->get_param('label_pdf');
		}
		if ($tracking_number === '' || $tracking_url === '' || $label_base64 === '') {
			return new WP_Error('posten_job_complete_missing_fields', 'tracking_number, tracking_url og label_pdf_base64 er paakrevd.', array('status' => 400));
		}

		$stored_label = $this->store_label_pdf($job_id, $label_base64);
		if (is_wp_error($stored_label)) {
			return $stored_label;
		}

		global $wpdb;
		$table = $this->get_table_name();
		$now = gmdate('Y-m-d H:i:s');
		$wpdb->update($table, array(
			'status' => self::JOB_STATUS_COMPLETED,
			'tracking_number' => $tracking_number,
			'tracking_url' => $tracking_url,
			'label_file_path' => $stored_label['path'],
			'label_attachment_id' => 0,
			'completed_at_gmt' => $now,
			'updated_at_gmt' => $now,
			'failure_message' => '',
		), array('job_id' => $job_id), array('%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s'), array('%s'));

		$updated_job = $this->get_job_by_id($job_id);
		$order = wc_get_order((int) $job->order_id);
		if ($order) {
			$order->update_meta_data(self::META_LABEL_STATUS, self::JOB_STATUS_COMPLETED);
			$order->update_meta_data(self::META_TRACKING_NUMBER, $tracking_number);
			$order->update_meta_data(self::META_TRACKING_URL, $tracking_url);
			$order->update_meta_data(self::META_LABEL_FILE_PATH, $stored_label['path']);
			$order->update_meta_data(self::META_COMPLETED_AT_GMT, $now);
			$order->save();
			$this->add_private_order_note($order, $this->build_completed_order_note($updated_job));
			$this->set_order_status_verified($order, self::ORDER_STATUS_CREATED, 'Posten label opprettet og tracking lagret.');
		}

		return rest_ensure_response($this->format_job_response($updated_job, true));
	}

	public function rest_fail_job($request) {
		$job_id = $this->sanitize_job_id($request['job_id']);
		$job = $this->get_job_by_id($job_id);
		if (!$job) {
			return new WP_Error('posten_job_not_found', 'Posten labeljobb ikke funnet.', array('status' => 404));
		}

		$message = sanitize_textarea_field((string) $request->get_param('message'));
		if ($message === '') {
			$message = 'Posten robot meldte feil uten detalj.';
		}

		global $wpdb;
		$table = $this->get_table_name();
		$now = gmdate('Y-m-d H:i:s');
		$wpdb->update($table, array(
			'status' => self::JOB_STATUS_FAILED,
			'failure_message' => $message,
			'failed_at_gmt' => $now,
			'updated_at_gmt' => $now,
		), array('job_id' => $job_id), array('%s', '%s', '%s', '%s'), array('%s'));

		$updated_job = $this->get_job_by_id($job_id);
		$order = wc_get_order((int) $job->order_id);
		if ($order) {
			$order->update_meta_data(self::META_LABEL_STATUS, self::JOB_STATUS_FAILED);
			$order->save();
			$this->add_private_order_note($order, 'Posten labeljobb feilet. Jobb-ID: ' . $job_id . '. Feil: ' . $message);
		}

		return rest_ensure_response($this->format_job_response($updated_job, true));
	}

	public function rest_download_label($request) {
		$job = $this->get_job_by_id($this->sanitize_job_id($request['job_id']));
		if (!$job || empty($job->label_file_path)) {
			return new WP_Error('posten_label_not_found', 'Label PDF ikke funnet.', array('status' => 404));
		}

		$file = (string) $job->label_file_path;
		if (!$this->is_file_inside_label_dir($file) || !is_readable($file)) {
			return new WP_Error('posten_label_unreadable', 'Label PDF kan ikke leses.', array('status' => 404));
		}

		nocache_headers();
		header('Content-Type: application/pdf');
		header('Content-Disposition: inline; filename="' . sanitize_file_name('posten-label-' . $job->job_id . '.pdf') . '"');
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
			'created_at_gmt' => isset($job->requested_at_gmt) ? (string) $job->requested_at_gmt : '',
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
				'target_status_label' => 'Venter Posten label',
				'message' => 'Ordren er satt til venter Posten label.',
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
			$length = $this->to_float(isset($package['length']) ? $package['length'] : (isset($package['length_cm']) ? $package['length_cm'] : 0));
			$width = $this->to_float(isset($package['width']) ? $package['width'] : (isset($package['width_cm']) ? $package['width_cm'] : 0));
			$height = $this->to_float(isset($package['height']) ? $package['height'] : (isset($package['height_cm']) ? $package['height_cm'] : 0));

			if ($weight <= 0) {
				$errors[] = 'Kolli ' . $index . ' mangler vekt.';
				continue;
			}
			if ($weight > 35) {
				$errors[] = 'Kolli ' . $index . ' er over 35 kg.';
				continue;
			}

			$clean[] = array(
				'colli' => count($clean) + 1,
				'name' => isset($package['name']) ? sanitize_text_field((string) $package['name']) : '',
				'description' => isset($package['description']) ? sanitize_text_field((string) $package['description']) : '',
				'weight_kg' => $weight,
				'weight' => $weight,
				'weight_grams' => (int) round($weight * 1000),
				'length_cm' => max(0, $length),
				'length' => max(0, $length),
				'width_cm' => max(0, $width),
				'width' => max(0, $width),
				'height_cm' => max(0, $height),
				'height' => max(0, $height),
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
		$name = trim((string) $order->get_shipping_first_name() . ' ' . (string) $order->get_shipping_last_name());
		$address_1 = (string) $order->get_shipping_address_1();
		$address_2 = (string) $order->get_shipping_address_2();
		$postcode = (string) $order->get_shipping_postcode();
		$city = (string) $order->get_shipping_city();
		$country = (string) $order->get_shipping_country();

		if ($name === '') {
			$name = trim((string) $order->get_billing_first_name() . ' ' . (string) $order->get_billing_last_name());
		}
		if ($address_1 === '') {
			$address_1 = (string) $order->get_billing_address_1();
			$address_2 = (string) $order->get_billing_address_2();
			$postcode = (string) $order->get_billing_postcode();
			$city = (string) $order->get_billing_city();
			$country = (string) $order->get_billing_country();
		}
		if ($country === '') {
			$country = 'NO';
		}

		return array(
			'name' => sanitize_text_field($name),
			'address_1' => sanitize_text_field($address_1),
			'address_2' => sanitize_text_field($address_2),
			'postcode' => sanitize_text_field($postcode),
			'city' => sanitize_text_field($city),
			'country' => strtoupper(sanitize_text_field($country)),
			'email' => sanitize_email((string) $order->get_billing_email()),
			'phone' => sanitize_text_field((string) $order->get_billing_phone()),
		);
	}

	private function build_shipping_snapshot($order, $method_payload, $args) {
		$checkout_selection = isset($args['checkout_selection']) && is_array($args['checkout_selection']) ? $args['checkout_selection'] : $order->get_meta('_lp_cargonizer_checkout_selection', true);
		$pickup = is_array($checkout_selection) && isset($checkout_selection['pickup_point']) && is_array($checkout_selection['pickup_point'])
			? $checkout_selection['pickup_point']
			: array();

		return array(
			'method_key' => LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY,
			'method_label' => isset($method_payload['label']) && $method_payload['label'] !== '' ? $method_payload['label'] : 'Posten - Norgespakke (manuell)',
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
			$items[] = array(
				'name' => sanitize_text_field((string) $item->get_name()),
				'quantity' => (int) $item->get_quantity(),
				'product_id' => $product && method_exists($product, 'get_id') ? (int) $product->get_id() : 0,
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

	private function get_active_job_for_order($order_id, $method_key) {
		global $wpdb;
		$table = $this->get_table_name();
		$statuses = array(self::JOB_STATUS_QUEUED, self::JOB_STATUS_PROCESSING, self::JOB_STATUS_COMPLETED);
		$placeholders = implode(',', array_fill(0, count($statuses), '%s'));
		$args = array_merge(array(absint($order_id), sanitize_text_field((string) $method_key)), $statuses);
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE order_id = %d AND method_key = %s AND status IN ({$placeholders}) ORDER BY id DESC LIMIT 1", $args));
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

		$response = array(
			'job_id' => (string) $row->job_id,
			'order_id' => (int) $row->order_id,
			'method_key' => (string) $row->method_key,
			'status' => (string) $row->status,
			'worker_id' => (string) $row->worker_id,
			'tracking_number' => (string) $row->tracking_number,
			'tracking_url' => (string) $row->tracking_url,
			'label_url' => !empty($row->label_file_path) ? rest_url(self::REST_NAMESPACE . '/posten-label-jobs/' . rawurlencode((string) $row->job_id) . '/label') : '',
			'failure_message' => (string) $row->failure_message,
			'requested_at_gmt' => (string) $row->requested_at_gmt,
			'claimed_at_gmt' => (string) $row->claimed_at_gmt,
			'completed_at_gmt' => (string) $row->completed_at_gmt,
			'failed_at_gmt' => (string) $row->failed_at_gmt,
			'updated_at_gmt' => (string) $row->updated_at_gmt,
		);

		if ($include_payload) {
			$response['recipient'] = $this->json_decode($row->recipient_json);
			$response['sender'] = $this->json_decode($row->sender_json);
			$response['packages'] = $this->json_decode($row->packages_json);
			$response['shipping'] = $this->json_decode($row->shipping_json);
			$response['items'] = $this->json_decode($row->items_json);
		}

		return $response;
	}

	private function write_order_queue_metadata($order, $job, $recipient, $sender, $packages, $shipping) {
		$order->update_meta_data(self::META_JOB_ID, (string) $job->job_id);
		$order->update_meta_data(self::META_LABEL_STATUS, self::JOB_STATUS_QUEUED);
		$order->update_meta_data(self::META_REQUESTED_AT_GMT, (string) $job->requested_at_gmt);
		$order->update_meta_data('_lp_posten_label_method_key', LP_Cargonizer_Connector::MANUAL_NORGESPAKKE_KEY);
		$order->update_meta_data('_lp_posten_label_recipient_snapshot', $recipient);
		$order->update_meta_data('_lp_posten_label_sender_snapshot', $sender);
		$order->update_meta_data('_lp_posten_label_packages_snapshot', $packages);
		$order->update_meta_data('_lp_posten_label_shipping_snapshot', $shipping);
		$order->save();
	}

	private function build_queue_order_note($job, $recipient, $sender, $packages, $shipping) {
		$lines = array(
			'Posten Norgespakke labeljobb opprettet.',
			'Jobb-ID: ' . (string) $job->job_id,
			'Mottaker: ' . (isset($recipient['name']) ? $recipient['name'] : '') . ', ' . (isset($recipient['postcode']) ? $recipient['postcode'] : ''),
			'Kolli: ' . count($packages),
			'Metode: ' . (isset($shipping['method_label']) ? $shipping['method_label'] : 'Posten Norgespakke'),
			'Estimert pris: ' . (isset($shipping['estimated_price']) && $shipping['estimated_price'] !== '' ? $shipping['estimated_price'] : 'ikke tilgjengelig'),
		);
		if (!empty($sender['name']) || !empty($sender['sender_id'])) {
			$lines[] = 'Sender: ' . trim((isset($sender['name']) ? $sender['name'] : '') . (!empty($sender['sender_id']) ? ' (' . $sender['sender_id'] . ')' : ''));
		}

		return implode("\n", $lines);
	}

	private function build_completed_order_note($job) {
		$payload = $this->format_job_response($job, true);
		$packages = isset($payload['packages']) && is_array($payload['packages']) ? $payload['packages'] : array();
		$lines = array(
			'Posten Norgespakke label opprettet.',
			'Jobb-ID: ' . (string) $job->job_id,
			'Trackingnummer: ' . (string) $job->tracking_number,
			'Tracking URL: <a href="' . esc_url((string) $job->tracking_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html((string) $job->tracking_url) . '</a>',
			'Kolli: ' . count($packages),
		);
		if (!empty($job->label_file_path)) {
			$lines[] = 'Label lagret privat: ' . (string) $job->label_file_path;
		}

		return implode("\n", $lines);
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

	private function store_label_pdf($job_id, $label_base64) {
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
		$file = trailingslashit($dir) . sanitize_file_name('posten-label-' . $job_id . '.pdf');
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
		if (!file_exists($htaccess)) {
			file_put_contents($htaccess, "Deny from all\n");
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

	private function generate_job_id($order_id) {
		$uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(16));
		return substr('posten_' . absint($order_id) . '_' . str_replace('-', '', $uuid), 0, 64);
	}

	private function sanitize_job_id($job_id) {
		return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $job_id);
	}

	private function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'lp_cargonizer_posten_jobs';
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
