<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Api_Service {
	/** @var callable */
	private $settings_provider;

	public function __construct($settings_provider) {
		$this->settings_provider = $settings_provider;
	}

	public function get_auth_headers() {
		$settings = call_user_func($this->settings_provider);
		if (!is_array($settings)) {
			$settings = array();
		}

		$api_key = isset($settings['api_key']) ? (string) $settings['api_key'] : '';
		$sender_id = isset($settings['sender_id']) ? (string) $settings['sender_id'] : '';

		return array(
			'X-Cargonizer-Key'    => $api_key,
			'X-Cargonizer-Sender' => $sender_id,
			'Accept'              => 'application/xml',
		);
	}

	public function fetch_transport_agreements() {
		try {
			$url = 'https://api.cargonizer.no/transport_agreements.xml';

			$response = wp_remote_get($url, array(
				'timeout' => 30,
				'headers' => $this->get_auth_headers(),
			));

			if (is_wp_error($response)) {
				return array(
					'success' => false,
					'message' => 'WP Error: ' . $response->get_error_message(),
					'status'  => 0,
					'raw'     => '',
					'data'    => array(),
				);
			}

			$status = wp_remote_retrieve_response_code($response);
			$body   = wp_remote_retrieve_body($response);

			if ($status < 200 || $status >= 300) {
				return array(
					'success' => false,
					'message' => 'Ugyldig respons fra Cargonizer. HTTP-status: ' . $status,
					'status'  => $status,
					'raw'     => $body,
					'data'    => array(),
				);
			}

			if (empty($body)) {
				return array(
					'success' => false,
					'message' => 'Tom respons fra Cargonizer.',
					'status'  => $status,
					'raw'     => '',
					'data'    => array(),
				);
			}

			$xml = $this->safe_simplexml_load_string($body);

			if ($xml === false) {
				$error_messages = $this->collect_libxml_error_messages();
				$error_suffix = !empty($error_messages) ? implode(' | ', $error_messages) : 'XML-utvidelse mangler eller XML kunne ikke leses.';

				return array(
					'success' => false,
					'message' => 'Kunne ikke parse XML-respons: ' . $error_suffix,
					'status'  => $status,
					'raw'     => $body,
					'data'    => array(),
				);
			}

			$parsed = $this->parse_transport_agreements($xml);

			return array(
				'success' => true,
				'message' => 'Autentisering OK og fraktmetoder hentet.',
				'status'  => $status,
				'raw'     => $body,
				'data'    => $parsed,
			);
		} catch (Throwable $exception) {
			return array(
				'success' => false,
				'message' => 'Uventet feil ved henting av fraktmetoder: ' . $exception->getMessage(),
				'status'  => 0,
				'raw'     => '',
				'data'    => array(),
			);
		}
	}

	public function fetch_printers() {
		$result = array(
			'success' => false,
			'http_status' => 0,
			'message' => '',
			'raw' => '',
			'printers' => array(),
		);

		try {
			$url = 'https://api.cargonizer.no/printers.xml';
			$headers = $this->get_auth_headers();
			if (isset($headers['X-Cargonizer-Sender'])) {
				unset($headers['X-Cargonizer-Sender']);
			}
			$headers['Accept'] = 'application/xml';

			$response = wp_remote_get($url, array(
				'timeout' => 30,
				'headers' => $headers,
			));

			if (is_wp_error($response)) {
				$result['message'] = 'WP Error: ' . $response->get_error_message();
				return $result;
			}

			$result['http_status'] = wp_remote_retrieve_response_code($response);
			$result['raw'] = wp_remote_retrieve_body($response);

			if ($result['http_status'] < 200 || $result['http_status'] >= 300) {
				$result['message'] = 'Ugyldig respons fra Cargonizer. HTTP-status: ' . $result['http_status'];
				return $result;
			}

			if ($result['raw'] === '') {
				$result['message'] = 'Tom respons fra Cargonizer.';
				return $result;
			}

			return $this->parse_printers_response($result['raw'], $result['http_status']);
		} catch (Throwable $exception) {
			$result['message'] = 'Uventet feil ved henting av printere: ' . $exception->getMessage();
			return $result;
		}
	}

	public function parse_transport_agreements($xml) {
		$result = array();
		$agreements = array();
		if (!is_object($xml) || !method_exists($xml, 'children')) {
			return $result;
		}

		foreach ($xml->children() as $child) {
			$agreements[] = $child;
		}

		if (empty($agreements) && !empty($xml)) {
			$agreements[] = $xml;
		}

		foreach ($agreements as $agreement) {
			$agreement_id   = $this->xml_value($agreement, array('id', 'transport_agreement_id'));
			$agreement_description = $this->xml_value($agreement, array('description'));
			$agreement_number = $this->xml_value($agreement, array('number'));
			$agreement_name = $agreement_description !== '' ? $agreement_description : $this->xml_value($agreement, array('name', 'title'));
			$carrier_name = $this->xml_value($agreement, array('carrier_name', 'provider_name', 'transporter_name', 'carrier', 'provider', 'transportor', 'transportor_name', 'transportør'));
			$carrier_id = $this->xml_value($agreement, array('carrier_id', 'provider_id', 'transporter_id', 'carrier', 'provider', 'transportor_id'));

			if (isset($agreement->carrier)) {
				if ($carrier_name === '') {
					$carrier_name = $this->xml_value($agreement->carrier, array('name', 'title'));
				}
				if ($carrier_id === '') {
					$carrier_id = $this->xml_value($agreement->carrier, array('id', 'identifier'));
				}
			}

			if (isset($agreement->provider)) {
				if ($carrier_name === '') {
					$carrier_name = $this->xml_value($agreement->provider, array('name', 'title'));
				}
				if ($carrier_id === '') {
					$carrier_id = $this->xml_value($agreement->provider, array('id', 'identifier'));
				}
			}

			$item = array(
				'agreement_id'   => $agreement_id,
				'agreement_name' => $agreement_name,
				'agreement_description' => $agreement_description,
				'agreement_number' => $agreement_number,
				'carrier_id'     => $carrier_id,
				'carrier_name'   => $carrier_name,
				'products'       => array(),
			);

			$product_nodes = array();

			if (isset($agreement->products)) {
				foreach ($agreement->products->children() as $product) {
					$product_nodes[] = $product;
				}
			}

			if (empty($product_nodes)) {
				foreach ($agreement->children() as $child) {
					if ($child->getName() === 'product') {
						$product_nodes[] = $child;
					}
				}
			}

			foreach ($product_nodes as $product) {
				$product_id   = $this->xml_value($product, array('id', 'identifier', 'product'));
				$product_name = $this->xml_value($product, array('name', 'title'));

				$product_item = array(
					'product_id'   => $product_id,
					'product_name' => $product_name,
					'services'     => array(),
				);

				if (isset($product->services)) {
					foreach ($product->services->children() as $service) {
						$product_item['services'][] = array(
							'service_id'   => $this->xml_value($service, array('id', 'identifier', 'service')),
							'service_name' => $this->xml_value($service, array('name', 'title')),
						);
					}
				}

				$item['products'][] = $product_item;
			}

			$result[] = $item;
		}

		return $result;
	}

	public function xml_value($node, $possible_keys = array()) {
		foreach ($possible_keys as $key) {
			if (isset($node->{$key}) && trim((string) $node->{$key}) !== '') {
				return trim((string) $node->{$key});
			}
		}
		return '';
	}

	public function parse_printers_response($body, $http_status = 200) {
		$result = array(
			'success' => false,
			'http_status' => (int) $http_status,
			'message' => '',
			'raw' => (string) $body,
			'printers' => array(),
		);

		if (empty($body)) {
			$result['message'] = 'Tom respons fra printer-endepunktet.';
			return $result;
		}

		$xml = $this->safe_simplexml_load_string($body);
		if ($xml === false) {
			$error_messages = $this->collect_libxml_error_messages();
			$error_suffix = !empty($error_messages) ? implode(' | ', $error_messages) : 'XML-utvidelse mangler eller XML kunne ikke leses.';
			$result['message'] = 'Kunne ikke parse printer-XML: ' . $error_suffix;
			return $result;
		}

		$candidate_nodes = array();
		$paths = array('//printer', '//printers/printer');
		foreach ($paths as $path) {
			$found = $xml->xpath($path);
			if (!empty($found)) {
				$candidate_nodes = $found;
				break;
			}
		}

		if (empty($candidate_nodes)) {
			if (isset($xml->printer)) {
				foreach ($xml->printer as $printer) {
					$candidate_nodes[] = $printer;
				}
			}
		}

		$printers = array();
		foreach ($candidate_nodes as $node) {
			$id = $this->xml_value($node, array('id', 'printer_id', 'identifier', 'number', 'code', 'value'));
			if ($id === '' && isset($node['id'])) {
				$id = trim((string) $node['id']);
			}
			if ($id === '') {
				continue;
			}

			$label = $this->xml_value($node, array('name', 'title', 'description'));
			if ($label === '') {
				$label = $id;
			}

			$printers[] = array(
				'id' => (string) $id,
				'label' => (string) $label,
			);
		}

		if (empty($printers)) {
			$result['message'] = 'Fant ingen printere i XML-responsen.';
			return $result;
		}

		$result['success'] = true;
		$result['message'] = 'Printere hentet.';
		$result['printers'] = $printers;

		return $result;
	}

	public function fetch_servicepartner_options($method) {
		$agreement_id = isset($method['agreement_id']) ? sanitize_text_field((string) $method['agreement_id']) : '';
		$product_id = isset($method['product_id']) ? sanitize_text_field((string) $method['product_id']) : '';
		$country = isset($method['country']) ? $this->sanitize_country_code($method['country']) : '';
		$postcode = isset($method['postcode']) ? $this->sanitize_postcode($method['postcode']) : '';

		$result = array(
			'success' => false,
			'http_status' => 0,
			'error_message' => '',
			'raw_response_body' => '',
			'request_url' => '',
			'options' => array(),
			'custom_params_debug' => array(),
		);

		if ($agreement_id === '' || $product_id === '') {
			$result['error_message'] = 'Mangler agreement_id eller product_id.';
			return $result;
		}

		$query = array(
			'transport_agreement_id' => $agreement_id,
			'product' => $product_id,
		);

		if ($country !== '') {
			$query['country'] = $country;
		}
		if ($postcode !== '') {
			$query['postcode'] = $postcode;
		}

		$custom = $this->detect_servicepartner_custom_params($method);
		if (!empty($custom['params']) && is_array($custom['params'])) {
			foreach ($custom['params'] as $custom_key => $custom_value) {
				$query['custom[params][' . $custom_key . ']'] = $custom_value;
			}
			$result['custom_params_debug'] = $custom['debug'];
		}

		$request_url = add_query_arg($query, 'https://api.cargonizer.no/service_partners.xml');
		$result['request_url'] = $request_url;

		$response = wp_remote_get($request_url, array(
			'timeout' => 30,
			'headers' => $this->get_auth_headers(),
		));

		if (is_wp_error($response)) {
			$result['error_message'] = $response->get_error_message();
			return $result;
		}

		$status = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		$result['http_status'] = $status;
		$result['raw_response_body'] = $body;

		if ($status < 200 || $status >= 300 || $body === '') {
			$error_details = $this->parse_response_error_details($body);
			$result['error_message'] = $error_details['message'] !== '' ? $error_details['message'] : ($body === '' ? 'Tom respons fra API.' : 'Uventet API-respons.');
			return $result;
		}

		$xml = $this->safe_simplexml_load_string($body);
		if ($xml === false) {
			$result['error_message'] = 'Kunne ikke parse XML-respons fra servicepartner-endepunktet.';
			return $result;
		}

		$options = array();
		$nodes = $xml->xpath('//service_partner') ?: array();
		if (empty($nodes)) {
			$nodes = $xml->xpath('//servicepartner') ?: array();
		}
		foreach ($nodes as $node) {
			$value = trim((string) $this->xml_value($node, array('id', 'code', 'number', 'value')));
			$label = trim((string) $this->xml_value($node, array('name', 'title', 'description', 'display_name')));
			if ($value === '') {
				continue;
			}
			if ($label === '') {
				$label = $value;
			}
			$options[] = array('value' => $value, 'label' => $label);
		}

		if (empty($options)) {
			$fallback_nodes = $xml->xpath('//option') ?: array();
			foreach ($fallback_nodes as $node) {
				$value = trim((string) $this->xml_value($node, array('id', 'code', 'value')));
				$label = trim((string) $this->xml_value($node, array('name', 'title', 'label')));
				if ($value === '') {
					continue;
				}
				if ($label === '') {
					$label = $value;
				}
				$options[] = array('value' => $value, 'label' => $label);
			}
		}

		$result['success'] = true;
		$result['options'] = $options;
		if (empty($options)) {
			$result['error_message'] = 'Ingen servicepartnere returnert fra API for denne kombinasjonen av agreement, product, country og postcode';
		}

		return $result;
	}

	public function detect_servicepartner_custom_params($method) {
		$carrier_id = strtolower(sanitize_text_field(isset($method['carrier_id']) ? (string) $method['carrier_id'] : ''));
		$carrier_name = strtolower(sanitize_text_field(isset($method['carrier_name']) ? (string) $method['carrier_name'] : ''));
		$product_id = strtolower(sanitize_text_field(isset($method['product_id']) ? (string) $method['product_id'] : ''));
		$product_name = strtolower(sanitize_text_field(isset($method['product_name']) ? (string) $method['product_name'] : ''));

		$params = array();
		$debug = array();

		$is_bring = strpos($carrier_id, 'bring') !== false || strpos($carrier_name, 'bring') !== false;
		$is_postnord = strpos($carrier_id, 'postnord') !== false || strpos($carrier_name, 'postnord') !== false;

		if ($is_bring) {
			$value = 'pickup_point';
			if (strpos($product_name, 'locker') !== false || strpos($product_id, 'locker') !== false) {
				$value = 'locker';
			}
			$params['pickupPointType'] = $value;
			$debug['pickupPointType'] = array(
				'value' => $value,
				'source' => 'auto_detected_from_carrier_product',
			);
		}

		if ($is_postnord) {
			$value = 'pickup';
			if (strpos($product_name, 'service') !== false || strpos($product_id, 'service') !== false) {
				$value = 'service_point';
			}
			$params['typeId'] = $value;
			$debug['typeId'] = array(
				'value' => $value,
				'source' => 'auto_detected_from_carrier_product',
			);
		}

		return array(
			'params' => $params,
			'debug' => $debug,
		);
	}

	public function sanitize_country_code($value) {
		$country = strtoupper(sanitize_text_field((string) $value));
		if ($country === '' || strlen($country) > 2) {
			return '';
		}
		return $country;
	}

	public function sanitize_postcode($value) {
		return preg_replace('/[^A-Za-z0-9\- ]/', '', sanitize_text_field((string) $value));
	}

	public function build_estimate_request_xml($payload, $method) {
		$recipient = isset($payload['recipient']) && is_array($payload['recipient']) ? $payload['recipient'] : array();
		$packages = isset($payload['packages']) && is_array($payload['packages']) ? $payload['packages'] : array();
		$servicepartner = isset($payload['servicepartner']) ? sanitize_text_field((string) $payload['servicepartner']) : '';
		$use_sms_service = !empty($payload['use_sms_service']);
		$sms_service_id = isset($payload['sms_service_id']) ? sanitize_text_field((string) $payload['sms_service_id']) : '';
		if (!class_exists('SimpleXMLElement')) {
			return '';
		}

		$xml = new SimpleXMLElement('<consignments/>');
		$consignment = $xml->addChild('consignment');
		$consignment->addAttribute('transport_agreement', isset($method['agreement_id']) ? (string) $method['agreement_id'] : '');
		$consignment->addChild('product', (string) (isset($method['product_id']) ? $method['product_id'] : ''));
		if ($use_sms_service && $sms_service_id !== '') {
			$services_node = $consignment->addChild('services');
			$service_node = $services_node->addChild('service');
			$service_node->addAttribute('id', (string) $sms_service_id);
		}

		$parts = $consignment->addChild('parts');
		$consignee = $parts->addChild('consignee');
		$consignee->addChild('name', (string) (isset($recipient['name']) ? $recipient['name'] : ''));
		$consignee->addChild('address1', (string) (isset($recipient['address_1']) ? $recipient['address_1'] : ''));
		$consignee->addChild('address2', (string) (isset($recipient['address_2']) ? $recipient['address_2'] : ''));
		$consignee->addChild('postcode', (string) (isset($recipient['postcode']) ? $recipient['postcode'] : ''));
		$consignee->addChild('city', (string) (isset($recipient['city']) ? $recipient['city'] : ''));
		$consignee->addChild('country', (string) (isset($recipient['country']) ? $recipient['country'] : ''));
		if ($servicepartner !== '') {
			$service_partner = $parts->addChild('service_partner');
			$service_partner->addChild('number', (string) $servicepartner);
		}

		$items = $consignment->addChild('items');
		if (empty($packages)) {
			$packages[] = array();
		}

		foreach ($packages as $package) {
			$weight = isset($package['weight']) ? (float) $package['weight'] : 0;
			$length_cm = isset($package['length']) ? (float) $package['length'] : 0;
			$width_cm = isset($package['width']) ? (float) $package['width'] : 0;
			$height_cm = isset($package['height']) ? (float) $package['height'] : 0;
			$volume_dm3 = ($length_cm * $width_cm * $height_cm) / 1000;
			$description = isset($package['description']) ? trim((string) $package['description']) : '';
			$length_xml = $this->normalize_positive_decimal_for_xml(isset($package['length']) ? $package['length'] : null);
			$width_xml = $this->normalize_positive_decimal_for_xml(isset($package['width']) ? $package['width'] : null);
			$height_xml = $this->normalize_positive_decimal_for_xml(isset($package['height']) ? $package['height'] : null);

			$item = $items->addChild('item');
			$item->addAttribute('type', 'package');
			$item->addAttribute('amount', '1');
			$item->addAttribute('description', (string) $description);
			$item->addAttribute('weight', (string) max(0, $weight));
			$item->addAttribute('volume', (string) max(0, $volume_dm3));
			if ($length_xml !== '') {
				$item->addAttribute('length', $length_xml);
			}
			if ($width_xml !== '') {
				$item->addAttribute('width', $width_xml);
			}
			if ($height_xml !== '') {
				$item->addAttribute('height', $height_xml);
			}

			$this->log_estimate_package_dimensions(array(
				'package_name' => isset($package['name']) ? sanitize_text_field((string) $package['name']) : '',
				'description' => $description,
				'weight' => (string) max(0, $weight),
				'volume' => (string) max(0, $volume_dm3),
				'length' => $length_xml,
				'width' => $width_xml,
				'height' => $height_xml,
			));
		}

		return $xml->asXML();
	}

	public function build_booking_consignment_xml($payload, $method, $options = array()) {
		$recipient = isset($payload['recipient']) && is_array($payload['recipient']) ? $payload['recipient'] : array();
		$packages = isset($payload['packages']) && is_array($payload['packages']) ? $payload['packages'] : array();
		$order_number = isset($payload['order_number']) ? sanitize_text_field((string) $payload['order_number']) : '';
		$servicepartner = isset($payload['servicepartner']) ? sanitize_text_field((string) $payload['servicepartner']) : '';
		$use_sms_service = !empty($payload['use_sms_service']);
		$sms_service_id = isset($payload['sms_service_id']) ? sanitize_text_field((string) $payload['sms_service_id']) : '';
		$transfer = isset($options['transfer']) ? (bool) $options['transfer'] : true;
		$booking_request = isset($options['booking_request']) ? (bool) $options['booking_request'] : false;

		if (!class_exists('SimpleXMLElement')) {
			return '';
		}

		$xml = new SimpleXMLElement('<consignments/>');
		$consignment = $xml->addChild('consignment');
		$consignment->addAttribute('transport_agreement', isset($method['agreement_id']) ? (string) $method['agreement_id'] : '');
		$consignment->addAttribute('print', 'false');
		$consignment->addAttribute('estimate', 'false');
		$consignment->addChild('transfer', $transfer ? 'true' : 'false');
		$consignment->addChild('booking_request', $booking_request ? 'true' : 'false');
		$consignment->addChild('product', (string) (isset($method['product_id']) ? $method['product_id'] : ''));

		$parts = $consignment->addChild('parts');
		$consignee = $parts->addChild('consignee');
		$consignee->addChild('name', (string) (isset($recipient['name']) ? $recipient['name'] : ''));
		$consignee->addChild('address1', (string) (isset($recipient['address_1']) ? $recipient['address_1'] : ''));
		$consignee->addChild('address2', (string) (isset($recipient['address_2']) ? $recipient['address_2'] : ''));
		$consignee->addChild('postcode', (string) (isset($recipient['postcode']) ? $recipient['postcode'] : ''));
		$consignee->addChild('city', (string) (isset($recipient['city']) ? $recipient['city'] : ''));
		$consignee->addChild('country', (string) (isset($recipient['country']) ? $recipient['country'] : ''));

		$email = isset($recipient['email']) ? trim((string) $recipient['email']) : '';
		if ($email !== '') {
			$consignee->addChild('email', $email);
		}

		$mobile = isset($recipient['phone']) ? trim((string) $recipient['phone']) : '';
		if ($mobile === '' && isset($recipient['mobile'])) {
			$mobile = trim((string) $recipient['mobile']);
		}
		if ($mobile !== '') {
			$consignee->addChild('mobile', $mobile);
		}

		if ($servicepartner !== '') {
			$service_partner = $parts->addChild('service_partner');
			$service_partner->addChild('number', (string) $servicepartner);
		}

		$items = $consignment->addChild('items');
		if (empty($packages)) {
			$packages[] = array();
		}

		foreach ($packages as $package) {
			$weight = isset($package['weight']) ? (float) $package['weight'] : 0;
			$length_cm = isset($package['length']) ? (float) $package['length'] : 0;
			$width_cm = isset($package['width']) ? (float) $package['width'] : 0;
			$height_cm = isset($package['height']) ? (float) $package['height'] : 0;
			$volume_dm3 = ($length_cm * $width_cm * $height_cm) / 1000;
			$description = isset($package['description']) ? trim((string) $package['description']) : '';
			$length_xml = $this->normalize_positive_decimal_for_xml(isset($package['length']) ? $package['length'] : null);
			$width_xml = $this->normalize_positive_decimal_for_xml(isset($package['width']) ? $package['width'] : null);
			$height_xml = $this->normalize_positive_decimal_for_xml(isset($package['height']) ? $package['height'] : null);

			$item = $items->addChild('item');
			$item->addAttribute('type', 'package');
			$item->addAttribute('amount', '1');
			$item->addAttribute('weight', (string) max(0, $weight));
			$item->addAttribute('volume', (string) max(0, $volume_dm3));
			$item->addAttribute('description', (string) $description);

			if ($length_xml !== '') {
				$item->addAttribute('length', $length_xml);
			}
			if ($width_xml !== '') {
				$item->addAttribute('width', $width_xml);
			}
			if ($height_xml !== '') {
				$item->addAttribute('height', $height_xml);
			}
		}

		if ($use_sms_service && $sms_service_id !== '') {
			$services_node = $consignment->addChild('services');
			$service_node = $services_node->addChild('service');
			$service_node->addAttribute('id', (string) $sms_service_id);
		}

		$references = $consignment->addChild('references');
		$references->addChild('consignor', (string) $order_number);

		return $xml->asXML();
	}

	public function create_booking_consignment($xml) {
		$result = array(
			'success' => false,
			'http_status' => 0,
			'error' => '',
			'error_code' => '',
			'error_type' => '',
			'error_details' => '',
			'parsed_error_message' => '',
			'raw_response' => '',
			'consignment_number' => '',
			'consignment_id' => '',
			'piece_numbers' => array(),
			'piece_ids' => array(),
			'tracking_url' => '',
			'consignment_pdf_url' => '',
			'waybill_pdf_url' => '',
			'net_cost' => '',
			'gross_cost' => '',
		);

		$response = wp_remote_post('https://api.cargonizer.no/consignments.xml', array(
			'timeout' => 40,
			'headers' => array_merge($this->get_auth_headers(), array(
				'Accept' => 'application/xml',
				'Content-Type' => 'application/xml',
			)),
			'body' => (string) $xml,
		));

		if (is_wp_error($response)) {
			$result['error'] = $response->get_error_message();
			$result['parsed_error_message'] = $result['error'];
			return $result;
		}

		$status = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		$parsed = $this->parse_booking_consignment_response($body);
		$parsed['http_status'] = $status;
		$parsed['raw_response'] = $body;

		if ($status < 200 || $status >= 300) {
			$details = $this->parse_response_error_details($body);
			$parsed['success'] = false;
			$parsed['error'] = 'HTTP ' . $status;
			$parsed['error_code'] = $details['code'];
			$parsed['error_type'] = $details['type'];
			$parsed['error_details'] = $details['details'];
			$parsed['parsed_error_message'] = $details['message'];
			if ($parsed['parsed_error_message'] !== '') {
				$parsed['error'] .= ': ' . $parsed['parsed_error_message'];
			}
		}

		return $parsed;
	}

	public function parse_booking_consignment_response($body) {
		$result = array(
			'success' => false,
			'http_status' => 0,
			'error' => '',
			'error_code' => '',
			'error_type' => '',
			'error_details' => '',
			'parsed_error_message' => '',
			'raw_response' => (string) $body,
			'consignment_number' => '',
			'consignment_id' => '',
			'piece_numbers' => array(),
			'piece_ids' => array(),
			'tracking_url' => '',
			'consignment_pdf_url' => '',
			'waybill_pdf_url' => '',
			'net_cost' => '',
			'gross_cost' => '',
		);

		if (empty($body)) {
			$result['error'] = 'Tom respons fra booking-endepunktet.';
			$result['parsed_error_message'] = $result['error'];
			return $result;
		}

		$xml = $this->safe_simplexml_load_string($body);
		if ($xml === false) {
			$error_messages = $this->collect_libxml_error_messages();
			$error_suffix = !empty($error_messages) ? implode(' | ', $error_messages) : 'XML-utvidelse mangler eller XML kunne ikke leses.';
			$result['error'] = 'Kunne ikke parse booking-respons: ' . $error_suffix;
			$result['parsed_error_message'] = $result['error'];
			return $result;
		}

		$consignment_nodes = $xml->xpath('//consignment');
		$consignment = !empty($consignment_nodes) ? $consignment_nodes[0] : null;
		if (!$consignment) {
			$details = $this->parse_response_error_details($body);
			$result['error_code'] = $details['code'];
			$result['error_type'] = $details['type'];
			$result['error_details'] = $details['details'];
			$result['parsed_error_message'] = $details['message'];
			$result['error'] = $result['parsed_error_message'] !== '' ? $result['parsed_error_message'] : 'Fant ingen consignment i responsen.';
			return $result;
		}

		$result['consignment_number'] = $this->xml_value($consignment, array('number-with-checksum'));
		$result['consignment_id'] = $this->xml_value($consignment, array('id', 'consignment_id', 'identifier'));
		$result['tracking_url'] = $this->xml_value($consignment, array('tracking-url', 'tracking_url'));
		$result['consignment_pdf_url'] = $this->xml_value($consignment, array('consignment-pdf', 'consignment_pdf'));
		$result['waybill_pdf_url'] = $this->xml_value($consignment, array('waybill-pdf', 'waybill_pdf'));

		if (isset($consignment->{'cost-estimate'})) {
			$result['net_cost'] = $this->xml_value($consignment->{'cost-estimate'}, array('net', 'net-amount'));
			$result['gross_cost'] = $this->xml_value($consignment->{'cost-estimate'}, array('gross', 'gross-amount'));
		}

		$piece_number_nodes = $consignment->xpath('./bundles//pieces/number-with-checksum');
		if (!empty($piece_number_nodes)) {
			foreach ($piece_number_nodes as $piece_number_node) {
				$value = trim((string) $piece_number_node);
				if ($value !== '') {
					$result['piece_numbers'][] = $value;
				}
			}
		}

		$piece_id_nodes = $consignment->xpath('./bundles//pieces/id');
		if (!empty($piece_id_nodes)) {
			foreach ($piece_id_nodes as $piece_id_node) {
				$value = trim((string) $piece_id_node);
				if ($value !== '') {
					$result['piece_ids'][] = $value;
				}
			}
		}

		$result['piece_numbers'] = array_values(array_unique($result['piece_numbers']));
		$result['piece_ids'] = array_values(array_unique($result['piece_ids']));
		$result['success'] = true;

		return $result;
	}

	public function normalize_positive_decimal_for_xml($value) {
		if (is_string($value)) {
			$value = str_replace(',', '.', $value);
		}

		if (!is_numeric($value)) {
			return '';
		}

		$number = (float) $value;
		if ($number <= 0) {
			return '';
		}

		$formatted = number_format($number, 3, '.', '');
		$formatted = rtrim(rtrim($formatted, '0'), '.');

		return $formatted;
	}

	public function log_estimate_package_dimensions($data) {
		if (!function_exists('wc_get_logger')) {
			return;
		}

		$logger = wc_get_logger();
		if (!$logger) {
			return;
		}

		$logger->debug('Estimate package dimensions sent to Cargonizer: ' . wp_json_encode($data), array('source' => 'lp-cargonizer-estimate'));
	}

	public function parse_response_error_details($body) {
		$details = array(
			'code' => '',
			'type' => '',
			'message' => '',
			'details' => '',
		);

		if (empty($body)) {
			return $details;
		}

		$xml = $this->safe_simplexml_load_string($body);
		if ($xml === false) {
			return $details;
		}

		$map = array(
			'code' => array('//error/code', '//errors/error/code', '//code', '//error/id'),
			'type' => array('//error/type', '//errors/error/type', '//type', '//error/category'),
			'message' => array('//error/message', '//errors/error/message', '//message', '//errors/error', '//error'),
			'details' => array('//error/details', '//errors/error/details', '//details', '//error/detail', '//errors/error/detail'),
		);

		foreach ($map as $key => $paths) {
			foreach ($paths as $path) {
				$found = $xml->xpath($path);
				if (!empty($found)) {
					$value = trim((string) $found[0]);
					if ($value !== '') {
						$details[$key] = $value;
						break;
					}
				}
			}
		}

		return $details;
	}

	public function parse_response_error_message($body) {
		if (empty($body)) {
			return '';
		}

		$xml = $this->safe_simplexml_load_string($body);
		if ($xml === false) {
			return '';
		}

		$paths = array(
			'//error/message',
			'//message',
			'//errors/error',
			'//error',
		);

		foreach ($paths as $path) {
			$found = $xml->xpath($path);
			if (!empty($found)) {
				$value = trim((string) $found[0]);
				if ($value !== '') {
					return $value;
				}
			}
		}

		return '';
	}

	public function parse_estimate_price_fields($body) {
		$fields = array(
			'estimated_cost' => '',
			'gross_amount' => '',
			'net_amount' => '',
			'fallback_price' => '',
		);

		if (empty($body)) {
			return $fields;
		}

		$xml = $this->safe_simplexml_load_string($body);
		if ($xml === false) {
			return $fields;
		}

		$primary_paths = array(
			'estimated_cost' => array('//estimated-cost'),
			'gross_amount' => array('//gross-amount'),
			'net_amount' => array('//net-amount'),
		);

		foreach ($primary_paths as $key => $paths) {
			foreach ($paths as $path) {
				$found = $xml->xpath($path);
				if (!empty($found)) {
					$value = trim((string) $found[0]);
					if ($value !== '') {
						$fields[$key] = $value;
						break;
					}
				}
			}
		}

		if ($fields['estimated_cost'] === '' && $fields['gross_amount'] === '' && $fields['net_amount'] === '') {
			$fallback_paths = array(
				'//estimated_price',
				'//price',
				'//amount',
				'//total',
			);

			foreach ($fallback_paths as $path) {
				$found = $xml->xpath($path);
				if (!empty($found)) {
					$value = trim((string) $found[0]);
					if ($value !== '') {
						$fields['fallback_price'] = $value;
						break;
					}
				}
			}
		}

		return $fields;
	}

	private function safe_simplexml_load_string($body) {
		if (!function_exists('simplexml_load_string')) {
			return false;
		}

		if (function_exists('libxml_use_internal_errors')) {
			libxml_use_internal_errors(true);
		}

		return simplexml_load_string($body);
	}

	private function collect_libxml_error_messages() {
		if (!function_exists('libxml_get_errors') || !function_exists('libxml_clear_errors')) {
			return array();
		}

		$errors = libxml_get_errors();
		libxml_clear_errors();

		$error_messages = array();
		foreach ($errors as $error) {
			$error_messages[] = trim($error->message);
		}

		return $error_messages;
	}
}
