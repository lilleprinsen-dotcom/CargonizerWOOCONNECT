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
						$service_attributes = array();
						if (isset($service->attributes)) {
							foreach ($service->attributes->children() as $attribute_node) {
								$attribute_values = array();
								if (isset($attribute_node->values)) {
									foreach ($attribute_node->values->children() as $value_node) {
										$attribute_values[] = array(
											'value' => trim((string) $value_node),
											'description' => isset($value_node['description']) ? trim((string) $value_node['description']) : '',
										);
									}
								}
								$service_attributes[] = array(
									'identifier' => $this->xml_value($attribute_node, array('identifier', 'id', 'name')),
									'type' => $this->xml_value($attribute_node, array('type')),
									'required' => $this->xml_value($attribute_node, array('required')),
									'min' => $this->xml_value($attribute_node, array('min')),
									'max' => $this->xml_value($attribute_node, array('max')),
									'values' => $attribute_values,
								);
							}
						}
						$product_item['services'][] = array(
							'service_id'   => $this->xml_value($service, array('id', 'identifier', 'service')),
							'service_name' => $this->xml_value($service, array('name', 'title')),
							'attributes' => $service_attributes,
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
		$carrier_id = isset($method['carrier_id']) ? sanitize_text_field((string) $method['carrier_id']) : '';
		$country = isset($method['country']) ? $this->sanitize_country_code($method['country']) : '';
		$postcode = isset($method['postcode']) ? $this->sanitize_postcode($method['postcode']) : '';
		$city = isset($method['city']) ? sanitize_text_field((string) $method['city']) : '';
		$address = isset($method['address']) ? sanitize_text_field((string) $method['address']) : '';
		$name = isset($method['name']) ? sanitize_text_field((string) $method['name']) : '';

		$result = array(
			'success' => false,
			'http_status' => 0,
			'error_message' => '',
			'raw_response_body' => '',
			'request_url' => '',
			'options' => array(),
			'carrier_family' => 'unknown',
			'omitted_params' => array(),
			'custom_params_debug' => array(),
			'attempts' => array(),
			'winning_attempt' => null,
			'winning_attempt_label' => '',
			'last_nonempty_http_status' => 0,
			'parser_debug' => array(),
		);

		if ($country === '' || $postcode === '') {
			$result['error_message'] = 'Mangler country eller postcode.';
			if ($country === '') {
				$result['omitted_params'][] = 'country';
			}
			if ($postcode === '') {
				$result['omitted_params'][] = 'postcode';
			}
			return $result;
		}

		if ($carrier_id === '' && $agreement_id === '') {
			$result['error_message'] = 'Mangler carrier_id eller agreement_id.';
			$result['omitted_params'][] = 'carrier';
			$result['omitted_params'][] = 'transport_agreement_id';
			return $result;
		}

		$custom = $this->detect_servicepartner_custom_params($method);
		$result['carrier_family'] = isset($custom['carrier_family']) ? (string) $custom['carrier_family'] : 'unknown';
		$result['custom_params_debug'] = isset($custom['debug']) && is_array($custom['debug']) ? $custom['debug'] : array();
		$custom_params = isset($custom['params']) && is_array($custom['params']) ? $custom['params'] : array();

		$base_query = array(
			'carrier' => $carrier_id,
			'transport_agreement_id' => $agreement_id,
			'product' => $product_id,
			'country' => $country,
			'postcode' => $postcode,
			'city' => $city,
			'address' => $address,
			'name' => $name,
		);

		$attempts = array(
			array('label' => 'A', 'fields' => array('carrier', 'product', 'transport_agreement_id', 'country', 'postcode', 'city', 'address', 'name'), 'use_custom' => true),
			array('label' => 'B', 'fields' => array('carrier', 'product', 'transport_agreement_id', 'country', 'postcode', 'address', 'name'), 'use_custom' => true),
			array('label' => 'C', 'fields' => array('transport_agreement_id', 'product', 'country', 'postcode', 'city', 'address', 'name'), 'use_custom' => true),
			array('label' => 'D', 'fields' => array('transport_agreement_id', 'product', 'country', 'postcode', 'address', 'name'), 'use_custom' => true),
			array('label' => 'E', 'fields' => array('carrier', 'product', 'country', 'postcode', 'city', 'address', 'name'), 'use_custom' => true),
			array('label' => 'F', 'fields' => array('carrier', 'product', 'country', 'postcode', 'address', 'name'), 'use_custom' => true),
			array('label' => 'G', 'fields' => array('carrier', 'product', 'country', 'postcode', 'city', 'address', 'name'), 'use_custom' => false),
			array('label' => 'H', 'fields' => array('carrier', 'product', 'country', 'postcode', 'address', 'name'), 'use_custom' => false),
			array('label' => 'I', 'fields' => array('carrier', 'country', 'postcode', 'city', 'address', 'name'), 'use_custom' => false),
			array('label' => 'J', 'fields' => array('carrier', 'country', 'postcode', 'address', 'name'), 'use_custom' => false),
		);

		$last_error_message = '';
		$last_request_url = '';
		$last_raw_response_body = '';
		$last_http_status = 0;

		foreach ($attempts as $attempt_definition) {
			$attempt_result = $this->execute_servicepartner_lookup_attempt(
				$attempt_definition['label'],
				$base_query,
				$attempt_definition['fields'],
				!empty($attempt_definition['use_custom']),
				$custom_params,
				$result['carrier_family']
			);

			$result['attempts'][] = $attempt_result['attempt_debug'];
			$result['omitted_params'] = $attempt_result['attempt_debug']['omitted_params'];
			$result['request_url'] = $attempt_result['attempt_debug']['request_url'];
			$result['http_status'] = $attempt_result['attempt_debug']['http_status'];
			$result['raw_response_body'] = $attempt_result['raw_response_body'];
			$result['parser_debug'] = $attempt_result['attempt_debug']['parser_result_summary'];

			if (!empty($attempt_result['attempt_debug']['http_status'])) {
				$result['last_nonempty_http_status'] = (int) $attempt_result['attempt_debug']['http_status'];
			}

			$last_request_url = $attempt_result['attempt_debug']['request_url'];
			$last_raw_response_body = $attempt_result['raw_response_body'];
			$last_http_status = (int) $attempt_result['attempt_debug']['http_status'];

			if (!empty($attempt_result['options'])) {
				$result['success'] = true;
				$result['options'] = $attempt_result['options'];
				$result['winning_attempt'] = count($result['attempts']) - 1;
				$result['winning_attempt_label'] = $attempt_definition['label'];
				$result['error_message'] = '';
				return $result;
			}

			if ($attempt_result['error_message'] !== '') {
				$last_error_message = $attempt_result['error_message'];
			}

			if ($attempt_result['is_terminal_error']) {
				break;
			}
		}

		$result['request_url'] = $last_request_url;
		$result['raw_response_body'] = $last_raw_response_body;
		$result['http_status'] = $last_http_status;
		$result['error_message'] = $last_error_message !== '' ? $last_error_message : 'Ingen servicepartnere returnert fra API etter progressive fallback-forsøk.';

		return $result;
	}

	private function execute_servicepartner_lookup_attempt($label, $base_query, $included_fields, $use_custom_params, $custom_params, $carrier_family) {
		$query = array();
		$omitted_params = array();

		foreach ($base_query as $param_name => $param_value) {
			if (!in_array($param_name, $included_fields, true)) {
				$omitted_params[] = $param_name;
				continue;
			}
			if ($param_value === '') {
				$omitted_params[] = $param_name;
				continue;
			}
			$query[$param_name] = $param_value;
		}

		$custom_params_used = array();
		if ($use_custom_params && !empty($custom_params)) {
			foreach ($custom_params as $custom_key => $custom_value) {
				$query['custom[params][' . $custom_key . ']'] = $custom_value;
				$custom_params_used[$custom_key] = $custom_value;
			}
		}

		$request_url = add_query_arg($query, 'https://api.cargonizer.no/service_partners.xml');

		$attempt_debug = array(
			'label' => $label,
			'query_args' => $query,
			'request_url' => $request_url,
			'http_status' => 0,
			'raw_response_excerpt' => '',
			'parsed_option_count' => 0,
			'carrier_family' => $carrier_family,
			'custom_params_used' => $custom_params_used,
			'omitted_params' => array_values(array_unique($omitted_params)),
			'parser_result_summary' => array(),
			'overfiltering_likely' => false,
		);

		$response = wp_remote_get($request_url, array(
			'timeout' => 30,
			'headers' => $this->get_auth_headers(),
		));

		if (is_wp_error($response)) {
			$attempt_debug['parser_result_summary'] = array('error' => $response->get_error_message());
			return array(
				'attempt_debug' => $attempt_debug,
				'raw_response_body' => '',
				'options' => array(),
				'error_message' => $response->get_error_message(),
				'is_terminal_error' => false,
			);
		}

		$http_status = (int) wp_remote_retrieve_response_code($response);
		$body = (string) wp_remote_retrieve_body($response);
		$attempt_debug['http_status'] = $http_status;
		$attempt_debug['raw_response_excerpt'] = substr($body, 0, 500);

		if ($http_status < 200 || $http_status >= 300 || $body === '') {
			$error_details = $this->parse_response_error_details($body);
			$error_message = $error_details['message'] !== '' ? $error_details['message'] : ($body === '' ? 'Tom respons fra API.' : 'Uventet API-respons.');
			$attempt_debug['parser_result_summary'] = array('error' => $error_message);
			$is_terminal_error = in_array($http_status, array(401, 403), true);
			if (!$is_terminal_error && $http_status >= 400 && $http_status < 500 && ($http_status !== 404 && $http_status !== 422)) {
				$is_terminal_error = true;
			}
			return array(
				'attempt_debug' => $attempt_debug,
				'raw_response_body' => $body,
				'options' => array(),
				'error_message' => $error_message,
				'is_terminal_error' => $is_terminal_error,
			);
		}

		$parsed = $this->parse_servicepartner_options_from_xml($body);
		$attempt_debug['parsed_option_count'] = count($parsed['options']);
		$attempt_debug['parser_result_summary'] = $parsed['parser_debug'];
		$attempt_debug['overfiltering_likely'] = !empty($parsed['parser_debug']['xml_parsed']) && !empty($parsed['parser_debug']['candidate_nodes_found']) && $attempt_debug['parsed_option_count'] === 0;

		return array(
			'attempt_debug' => $attempt_debug,
			'raw_response_body' => $body,
			'options' => $parsed['options'],
			'error_message' => $parsed['error_message'],
			'is_terminal_error' => !empty($parsed['is_terminal_error']),
		);
	}

	private function parse_servicepartner_options_from_xml($body) {
		$parsed = array(
			'options' => array(),
			'error_message' => '',
			'parser_debug' => array(
				'xml_parsed' => false,
				'xpath_match' => '',
				'matched_xpath' => '',
				'candidate_nodes' => 0,
				'candidate_nodes_found' => false,
				'container_xpath_match' => '',
				'node_shape_supported' => true,
				'produced_options' => 0,
			),
			'is_terminal_error' => false,
		);

		$xml = $this->safe_simplexml_load_string($body);
		if ($xml === false) {
			$parsed['error_message'] = 'Kunne ikke parse XML-respons fra servicepartner-endepunktet.';
			$parsed['parser_debug']['error'] = $parsed['error_message'];
			$parsed['is_terminal_error'] = true;
			return $parsed;
		}

		$parsed['parser_debug']['xml_parsed'] = true;

		$node_paths = array(
			'//*[local-name()="service_partner"]',
			'//*[local-name()="service-partner"]',
			'//*[local-name()="servicepartner"]',
			'//*[local-name()="option"]',
			'//*[local-name()="service_partners"]/*[local-name()="service_partner"]',
			'//*[local-name()="service-partners"]/*[local-name()="service-partner"]',
			'//*[local-name()="service_partners"]/*[local-name()="servicepartner"]',
			'//*[local-name()="service-partners"]/*[local-name()="servicepartner"]',
		);
		$candidate_nodes = array();
		$matched_xpath = '';
		foreach ($node_paths as $path) {
			$nodes = $xml->xpath($path) ?: array();
			if (!empty($nodes)) {
				$candidate_nodes = $nodes;
				$matched_xpath = $path;
				break;
			}
		}

		$parsed['parser_debug']['xpath_match'] = $matched_xpath;
		$parsed['parser_debug']['matched_xpath'] = $matched_xpath;
		$parsed['parser_debug']['candidate_nodes'] = count($candidate_nodes);
		$parsed['parser_debug']['candidate_nodes_found'] = !empty($candidate_nodes);

		$container_paths = array(
			'//*[local-name()="service_partners"]',
			'//*[local-name()="service-partners"]',
		);
		foreach ($container_paths as $container_path) {
			$container_nodes = $xml->xpath($container_path) ?: array();
			if (!empty($container_nodes)) {
				$parsed['parser_debug']['container_xpath_match'] = $container_path;
				break;
			}
		}

		if ($parsed['parser_debug']['container_xpath_match'] !== '' && empty($candidate_nodes)) {
			$parsed['parser_debug']['node_shape_supported'] = false;
		}

		foreach ($candidate_nodes as $node) {
			$value = $this->xml_value_or_attribute($node, array('number', 'id', 'code', 'value'));
			if ($value === '') {
				continue;
			}

			$name = $this->xml_value_or_attribute($node, array('name', 'title', 'display_name', 'description'));
			$address1 = $this->xml_value_or_attribute($node, array('address1', 'address_1', 'street', 'address'));
			$address2 = $this->xml_value_or_attribute($node, array('address2', 'address_2'));
			$postcode = $this->xml_value_or_attribute($node, array('postcode', 'postalcode', 'zip'));
			$city = $this->xml_value_or_attribute($node, array('city', 'post_area', 'municipality'));
			$country = $this->xml_value_or_attribute($node, array('country', 'country_code'));
			$customer_number = $this->xml_value_or_attribute($node, array('customer-number', 'customer_number', 'customernumber'));

			$label_parts = array();
			if ($name !== '') {
				$label_parts[] = $name;
			}
			$location_parts = array();
			if ($address1 !== '') {
				$location_parts[] = $address1;
			}
			if ($postcode !== '' || $city !== '') {
				$location_parts[] = trim($postcode . ' ' . $city);
			}

			$label = '';
			if (!empty($label_parts) && !empty($location_parts)) {
				$label = $label_parts[0] . ' – ' . implode(', ', $location_parts);
			} elseif (!empty($label_parts)) {
				$label = $label_parts[0];
			} elseif (!empty($location_parts)) {
				$label = implode(', ', $location_parts);
			} else {
				$label = $value;
			}

			$option = array(
				'value' => $value,
				'label' => $label,
				'number' => $value,
				'name' => $name,
				'address1' => $address1,
				'address2' => $address2,
				'postcode' => $postcode,
				'city' => $city,
				'country' => $country,
			);
			if ($customer_number !== '') {
				$option['customer_number'] = $customer_number;
			}

			$parsed['options'][] = array(
				'value' => $option['value'],
				'label' => $option['label'],
				'customer_number' => isset($option['customer_number']) ? $option['customer_number'] : '',
				'raw' => $option,
			);
		}

		$parsed['parser_debug']['produced_options'] = count($parsed['options']);
		if (empty($parsed['options'])) {
			$parsed['error_message'] = 'Ingen servicepartnere returnert fra API etter progressive fallback-forsøk.';
		}

		return $parsed;
	}

	private function xml_value_or_attribute($node, $possible_keys = array()) {
		$value = $this->xml_value($node, $possible_keys);
		if ($value !== '') {
			return $value;
		}

		foreach ($possible_keys as $key) {
			if (isset($node[$key]) && trim((string) $node[$key]) !== '') {
				return trim((string) $node[$key]);
			}
		}

		return '';
	}

	public function detect_servicepartner_custom_params($method) {
		$carrier_id = strtolower(sanitize_text_field(isset($method['carrier_id']) ? (string) $method['carrier_id'] : ''));
		$carrier_name = strtolower(sanitize_text_field(isset($method['carrier_name']) ? (string) $method['carrier_name'] : ''));
		$product_id = strtolower(sanitize_text_field(isset($method['product_id']) ? (string) $method['product_id'] : ''));
		$product_name = strtolower(sanitize_text_field(isset($method['product_name']) ? (string) $method['product_name'] : ''));

		$params = array();
		$debug = array(
			'detected_carrier_family' => 'unknown',
		);

		$is_bring = strpos($carrier_id, 'bring') !== false || strpos($carrier_name, 'bring') !== false || strpos($carrier_id, 'bring2') !== false || strpos($carrier_name, 'bring2') !== false;
		$is_postnord = strpos($carrier_id, 'postnord') !== false || strpos($carrier_name, 'postnord') !== false || strpos($carrier_id, 'tollpost_globe') !== false || strpos($carrier_name, 'tollpost_globe') !== false;

		$is_locker_product = strpos($product_name, 'locker') !== false || strpos($product_id, 'locker') !== false || strpos($product_name, 'pakkeboks') !== false || strpos($product_id, 'pakkeboks') !== false || strpos($product_name, 'parcel locker') !== false || strpos($product_id, 'parcel_locker') !== false || strpos($product_id, 'box') !== false || strpos($product_name, 'box') !== false;
		$is_bring_exact_locker_mapping = in_array($product_id, array('bring_pickup_point_9000', 'bring_pickup_point_9300', 'pickuppoint_9000', 'pickuppoint_9300'), true);
		$is_postnord_box_mapping = in_array($product_id, array('mypack_small', 'postnord_parcel_locker', 'postnord_mypack_small'), true);
		$is_explicit_home_delivery = $this->is_method_explicitly_home_delivery($method);
		$is_explicit_pickup_point = $this->is_method_explicitly_pickup_point($method);

		$carrier_family = 'unknown';

		if ($is_bring) {
			$carrier_family = 'bring';
			$debug['detected_carrier_family'] = 'bring';
			if ($is_locker_product || $is_bring_exact_locker_mapping) {
				$params['pickupPointType'] = 'locker';
				$debug['pickupPointType'] = array(
					'value' => 'locker',
					'reason' => $is_bring_exact_locker_mapping ? 'exact Bring product mapping to locker pickup points' : 'explicit locker-like signal for Bring/bring2',
				);
			} else {
				$debug['pickupPointType'] = array(
					'value' => '',
					'reason' => 'omitted to avoid over-filtering; no strong locker signal or exact mapping detected',
				);
			}
		}

		if ($is_postnord) {
			$carrier_family = 'postnord';
			$debug['detected_carrier_family'] = 'postnord';
			$is_box_style = ($is_postnord_box_mapping || strpos($product_id, 'box') !== false || strpos($product_name, 'box') !== false || $is_locker_product) && !$is_explicit_home_delivery;
			if ($is_box_style) {
				$params['typeId'] = '2';
				$debug['typeId'] = array(
					'value' => '2',
					'reason' => $is_postnord_box_mapping ? 'exact PostNord locker mapping' : 'explicit box/locker-style signal for PostNord',
				);
			} else {
				$debug['typeId'] = array(
					'value' => '',
					'reason' => $is_explicit_home_delivery ? 'omitted because method is explicitly home-delivery (including Home/Home Small variants)' : ($is_explicit_pickup_point ? 'omitted because pickup-point method lacks strict locker/box signal' : 'omitted to avoid over-filtering; no strict locker/box signal'),
				);
			}
		}

		if ($carrier_family === 'unknown') {
			$debug['custom_params'] = array(
				'value' => '',
				'reason' => 'omitted because carrier family is not Bring/bring2 or PostNord/tollpost_globe',
			);
		}

		return array(
			'params' => $params,
			'debug' => $debug,
			'carrier_family' => $carrier_family,
		);
	}

	public function is_method_explicitly_pickup_point($method) {
		$product_id = strtolower(sanitize_text_field(isset($method['product_id']) ? (string) $method['product_id'] : ''));
		$product_name = strtolower(sanitize_text_field(isset($method['product_name']) ? (string) $method['product_name'] : ''));
		$delivery_to_pickup_point = !empty($method['delivery_to_pickup_point']);
		$delivery_to_home = !empty($method['delivery_to_home']);
		$strict_pickup_product_ids = array(
			'mypack_collect',
			'mypack_small',
			'mypack_service_point',
			'postnord_service_point',
			'postnord_parcel_locker',
			'postnord_mypack_collect',
			'postnord_mypack_service_point',
			'postnord_mypack_small',
			'bring_pickup_point_9000',
			'bring_pickup_point_9300',
			'pickuppoint_9000',
			'pickuppoint_9300',
			'parcel_pickup_point',
		);
		$has_strict_pickup_id = in_array($product_id, $strict_pickup_product_ids, true);
		$has_pickup_phrase = strpos($product_name, 'service point') !== false || strpos($product_name, 'pickup point') !== false || strpos($product_name, 'parcel locker') !== false || strpos($product_name, 'pakkeboks') !== false || strpos($product_name, 'hentested') !== false;
		if ($delivery_to_pickup_point && $delivery_to_home) {
			return $has_strict_pickup_id;
		}
		return $delivery_to_pickup_point || $has_strict_pickup_id || $has_pickup_phrase;
	}

	public function is_method_explicitly_home_delivery($method) {
		$product_id = strtolower(sanitize_text_field(isset($method['product_id']) ? (string) $method['product_id'] : ''));
		$product_name = strtolower(sanitize_text_field(isset($method['product_name']) ? (string) $method['product_name'] : ''));
		$delivery_to_home = !empty($method['delivery_to_home']);
		$strict_home_product_ids = array(
			'mypack_home',
			'mypack_small_home',
			'postnord_mypack_home',
			'postnord_mypack_small_home',
		);
		$has_home_phrase = strpos($product_name, 'home attended') !== false || strpos($product_name, 'home groupage') !== false || strpos($product_name, 'mypack home') !== false || strpos($product_name, 'home small') !== false || strpos($product_name, 'home') !== false;
		return $delivery_to_home || in_array($product_id, $strict_home_product_ids, true) || $has_home_phrase;
	}

	private function extract_servicepartner_selection($payload, $method) {
		$selection_value = isset($payload['servicepartner']) ? sanitize_text_field((string) $payload['servicepartner']) : '';
		$customer_number = isset($payload['servicepartner_customer_number']) ? sanitize_text_field((string) $payload['servicepartner_customer_number']) : '';
		if ($customer_number === '' && isset($method['servicepartner_customer_number'])) {
			$customer_number = sanitize_text_field((string) $method['servicepartner_customer_number']);
		}

		return array(
			'number' => $selection_value,
			'customer_number' => $customer_number,
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
		$servicepartner_selection = $this->extract_servicepartner_selection($payload, $method);
		$use_sms_service = !empty($payload['use_sms_service']);
		$sms_service_id = isset($payload['sms_service_id']) ? sanitize_text_field((string) $payload['sms_service_id']) : '';
		$selected_service_ids = isset($payload['selected_service_ids']) && is_array($payload['selected_service_ids']) ? $payload['selected_service_ids'] : array();
		if (!class_exists('SimpleXMLElement')) {
			return '';
		}

		$xml = new SimpleXMLElement('<consignments/>');
		$consignment = $xml->addChild('consignment');
		$consignment->addAttribute('transport_agreement', isset($method['agreement_id']) ? (string) $method['agreement_id'] : '');
		$consignment->addChild('product', (string) (isset($method['product_id']) ? $method['product_id'] : ''));
		$parts = $consignment->addChild('parts');
		$consignee = $parts->addChild('consignee');
		$consignee->addChild('name', (string) (isset($recipient['name']) ? $recipient['name'] : ''));
		$consignee->addChild('address1', (string) (isset($recipient['address_1']) ? $recipient['address_1'] : ''));
		$consignee->addChild('address2', (string) (isset($recipient['address_2']) ? $recipient['address_2'] : ''));
		$consignee->addChild('postcode', (string) (isset($recipient['postcode']) ? $recipient['postcode'] : ''));
		$consignee->addChild('city', (string) (isset($recipient['city']) ? $recipient['city'] : ''));
		$consignee->addChild('country', (string) (isset($recipient['country']) ? $recipient['country'] : ''));
		if ($servicepartner_selection['number'] !== '') {
			$service_partner = $parts->addChild('service_partner');
			$service_partner->addChild('number', (string) $servicepartner_selection['number']);
			if ($servicepartner_selection['customer_number'] !== '') {
				$service_partner->addChild('customer-number', (string) $servicepartner_selection['customer_number']);
			}
		}

		$all_service_ids = array();
		foreach ($selected_service_ids as $selected_service_id) {
			$clean_service_id = sanitize_text_field((string) $selected_service_id);
			if ($clean_service_id !== '') {
				$all_service_ids[] = $clean_service_id;
			}
		}
		if ($use_sms_service && $sms_service_id !== '') {
			$all_service_ids[] = $sms_service_id;
		}
		$all_service_ids = array_values(array_unique($all_service_ids));
		if (!empty($all_service_ids)) {
			$services_node = $consignment->addChild('services');
			foreach ($all_service_ids as $service_id) {
				$service_node = $services_node->addChild('service');
				$service_node->addAttribute('id', (string) $service_id);
			}
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
		$servicepartner_selection = $this->extract_servicepartner_selection($payload, $method);
		$use_sms_service = !empty($payload['use_sms_service']);
		$sms_service_id = isset($payload['sms_service_id']) ? sanitize_text_field((string) $payload['sms_service_id']) : '';
		$selected_service_ids = isset($payload['selected_service_ids']) && is_array($payload['selected_service_ids']) ? $payload['selected_service_ids'] : array();
		$notify_email_to_consignee = !empty($payload['notify_email_to_consignee']);
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
		$consignment->addChild('email-notification-to-consignee', $notify_email_to_consignee ? 'true' : 'false');
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

		if ($servicepartner_selection['number'] !== '') {
			$service_partner = $parts->addChild('service_partner');
			$service_partner->addChild('number', (string) $servicepartner_selection['number']);
			if ($servicepartner_selection['customer_number'] !== '') {
				$service_partner->addChild('customer-number', (string) $servicepartner_selection['customer_number']);
			}
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

		$all_service_ids = array();
		foreach ($selected_service_ids as $selected_service_id) {
			$clean_service_id = sanitize_text_field((string) $selected_service_id);
			if ($clean_service_id !== '') {
				$all_service_ids[] = $clean_service_id;
			}
		}
		if ($use_sms_service && $sms_service_id !== '') {
			$all_service_ids[] = $sms_service_id;
		}
		$all_service_ids = array_values(array_unique($all_service_ids));

		if (!empty($all_service_ids)) {
			$services_node = $consignment->addChild('services');
			foreach ($all_service_ids as $service_id) {
				$service_node = $services_node->addChild('service');
				$service_node->addAttribute('id', (string) $service_id);
			}
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

	public function fetch_authenticated_binary_url($url) {
		$result = array(
			'success' => false,
			'http_status' => 0,
			'error' => '',
			'raw_response' => '',
			'binary' => '',
		);

		$url = esc_url_raw((string) $url);
		if ($url === '') {
			$result['error'] = 'Mangler URL for PDF-henting.';
			return $result;
		}

		$response = wp_remote_get($url, array(
			'timeout' => 40,
			'headers' => array_merge($this->get_auth_headers(), array(
				'Accept' => 'application/pdf',
			)),
		));

		if (is_wp_error($response)) {
			$result['error'] = $response->get_error_message();
			return $result;
		}

		$result['http_status'] = wp_remote_retrieve_response_code($response);
		$result['raw_response'] = wp_remote_retrieve_body($response);
		if ($result['http_status'] < 200 || $result['http_status'] >= 300) {
			$result['error'] = 'HTTP ' . $result['http_status'];
			return $result;
		}

		$result['success'] = true;
		$result['binary'] = $result['raw_response'];
		return $result;
	}

	public function print_pdf_to_printer($printer_id, $pdf_binary) {
		$result = array(
			'success' => false,
			'http_status' => 0,
			'error' => '',
			'raw_response' => '',
		);

		$printer_id = sanitize_text_field((string) $printer_id);
		if ($printer_id === '') {
			$result['error'] = 'Mangler printer-id.';
			return $result;
		}

		$headers = $this->get_auth_headers();
		if (isset($headers['X-Cargonizer-Sender'])) {
			unset($headers['X-Cargonizer-Sender']);
		}
		$headers['Content-Type'] = 'application/pdf';
		$headers['Accept'] = 'application/json';

		$url = add_query_arg(array(
			'print[printer][id]' => $printer_id,
		), 'https://api.cargonizer.no/prints');

		$response = wp_remote_post($url, array(
			'timeout' => 40,
			'headers' => $headers,
			'body' => (string) $pdf_binary,
		));

		if (is_wp_error($response)) {
			$result['error'] = $response->get_error_message();
			return $result;
		}

		$result['http_status'] = wp_remote_retrieve_response_code($response);
		$result['raw_response'] = wp_remote_retrieve_body($response);

		if ($result['http_status'] < 200 || $result['http_status'] >= 300) {
			$result['error'] = 'HTTP ' . $result['http_status'];
			if ($result['raw_response'] !== '') {
				$result['error'] .= ': ' . $result['raw_response'];
			}
			return $result;
		}

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
