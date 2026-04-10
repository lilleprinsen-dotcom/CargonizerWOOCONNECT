<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Krokedil_Pickup_Meta_Helper {
	public static function encode_pickup_points_for_meta($points) {
		$normalized = self::normalize_pickup_points($points);
		$canonical = array();
		foreach ($normalized as $point) {
			$canonical[] = self::to_canonical_krokedil_pickup_point($point);
		}
		return wp_json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	public static function encode_pickup_point_for_meta($point) {
		$normalized = self::normalize_pickup_point($point);
		$canonical = self::to_canonical_krokedil_pickup_point($normalized);
		return wp_json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	public static function decode_pickup_points_meta($value) {
		$decoded = self::decode_to_array($value);
		if (empty($decoded)) {
			return array();
		}

		if (self::is_associative_array($decoded)) {
			$point = self::normalize_pickup_point($decoded);
			return empty($point['id']) ? array() : array($point);
		}

		$normalized = array();
		foreach ($decoded as $point) {
			if (!is_array($point)) {
				continue;
			}
			$normalized_point = self::normalize_pickup_point($point);
			if (empty($normalized_point['id'])) {
				continue;
			}
			$normalized[] = $normalized_point;
		}
		return $normalized;
	}

	public static function decode_pickup_point_meta($value) {
		$decoded = self::decode_to_array($value);
		if (empty($decoded)) {
			return array();
		}

		if (self::is_associative_array($decoded)) {
			return self::normalize_pickup_point($decoded);
		}

		$first = reset($decoded);
		return is_array($first) ? self::normalize_pickup_point($first) : array();
	}

	public static function normalize_pickup_points($value) {
		return self::decode_pickup_points_meta($value);
	}

	public static function normalize_pickup_point($value) {
		$decoded = self::decode_to_array($value);
		if (empty($decoded) || !is_array($decoded)) {
			return array();
		}
		if (!self::is_associative_array($decoded)) {
			$first = reset($decoded);
			$decoded = is_array($first) ? $first : array();
		}
		if (empty($decoded) || !is_array($decoded)) {
			return array();
		}

		$is_canonical = isset($decoded['address']) || isset($decoded['coordinates']) || isset($decoded['meta_data']);
		$meta = isset($decoded['meta_data']) && is_array($decoded['meta_data']) ? $decoded['meta_data'] : array();
		$address = isset($decoded['address']) && is_array($decoded['address']) ? $decoded['address'] : array();
		$coordinates = isset($decoded['coordinates']) && is_array($decoded['coordinates']) ? $decoded['coordinates'] : array();
		$eta = isset($decoded['eta']) && is_array($decoded['eta']) ? $decoded['eta'] : array();
		$open_hours = isset($decoded['open_hours']) && is_array($decoded['open_hours']) ? array_values($decoded['open_hours']) : array();

		$id = self::as_string(isset($decoded['id']) ? $decoded['id'] : '');
		$name = self::as_string(isset($decoded['name']) ? $decoded['name'] : '');
		$address1 = self::as_string($is_canonical ? (isset($meta['address1']) ? $meta['address1'] : (isset($address['street']) ? $address['street'] : '')) : (isset($decoded['address1']) ? $decoded['address1'] : ''));
		$address2 = self::as_string($is_canonical ? (isset($meta['address2']) ? $meta['address2'] : '') : (isset($decoded['address2']) ? $decoded['address2'] : ''));
		$postcode = self::as_string($is_canonical ? (isset($address['postcode']) ? $address['postcode'] : '') : (isset($decoded['postcode']) ? $decoded['postcode'] : ''));
		$city = self::as_string($is_canonical ? (isset($address['city']) ? $address['city'] : '') : (isset($decoded['city']) ? $decoded['city'] : ''));
		$country = self::as_string($is_canonical ? (isset($address['country']) ? $address['country'] : '') : (isset($decoded['country']) ? $decoded['country'] : ''));
		$latitude = self::as_float_or_zero($is_canonical ? (isset($coordinates['latitude']) ? $coordinates['latitude'] : (isset($decoded['latitude']) ? $decoded['latitude'] : 0)) : (isset($decoded['latitude']) ? $decoded['latitude'] : 0));
		$longitude = self::as_float_or_zero($is_canonical ? (isset($coordinates['longitude']) ? $coordinates['longitude'] : (isset($decoded['longitude']) ? $decoded['longitude'] : 0)) : (isset($decoded['longitude']) ? $decoded['longitude'] : 0));
		$customer_number = self::as_string($is_canonical ? (isset($meta['customer_number']) ? $meta['customer_number'] : '') : (isset($decoded['customer_number']) ? $decoded['customer_number'] : ''));
		$distance_meters = self::as_numeric_or_zero($is_canonical ? (isset($meta['distance_meters']) ? $meta['distance_meters'] : 0) : (isset($decoded['distance_meters']) ? $decoded['distance_meters'] : 0));
		$label = self::as_string($is_canonical ? (isset($meta['label']) ? $meta['label'] : $id) : (isset($decoded['label']) ? $decoded['label'] : $id));
		$opening_hours = self::as_string($is_canonical ? (isset($meta['opening_hours']) ? $meta['opening_hours'] : '') : (isset($decoded['opening_hours']) ? $decoded['opening_hours'] : ''));
		$description = self::as_string(isset($decoded['description']) ? $decoded['description'] : '');
		$eta_utc = self::as_string(isset($eta['utc']) ? $eta['utc'] : '');
		$eta_local = self::as_string(isset($eta['local']) ? $eta['local'] : '');

		return array(
			'id' => $id,
			'name' => $name,
			'address1' => $address1,
			'address2' => $address2,
			'postcode' => $postcode,
			'city' => $city,
			'country' => $country,
			'customer_number' => $customer_number,
			'distance_meters' => $distance_meters,
			'label' => $label,
			'opening_hours' => $opening_hours,
			'latitude' => $latitude,
			'longitude' => $longitude,
			'description' => $description,
			'open_hours' => $open_hours,
			'eta' => array(
				'utc' => $eta_utc,
				'local' => $eta_local,
			),
			'address' => array(
				'street' => $address1,
				'city' => $city,
				'postcode' => $postcode,
				'country' => $country,
			),
			'coordinates' => array(
				'latitude' => $latitude,
				'longitude' => $longitude,
			),
			'meta_data' => array(
				'address1' => $address1,
				'address2' => $address2,
				'customer_number' => $customer_number,
				'distance_meters' => $distance_meters,
				'label' => $label,
				'opening_hours' => $opening_hours,
			),
		);
	}

	public static function to_canonical_krokedil_pickup_point($point) {
		$normalized = self::normalize_pickup_point($point);
		if (empty($normalized)) {
			return array();
		}

		return array(
			'id' => self::as_string(isset($normalized['id']) ? $normalized['id'] : ''),
			'name' => self::as_string(isset($normalized['name']) ? $normalized['name'] : ''),
			'description' => self::as_string(isset($normalized['description']) ? $normalized['description'] : ''),
			'address' => array(
				'street' => self::as_string(isset($normalized['address1']) ? $normalized['address1'] : ''),
				'city' => self::as_string(isset($normalized['city']) ? $normalized['city'] : ''),
				'postcode' => self::as_string(isset($normalized['postcode']) ? $normalized['postcode'] : ''),
				'country' => self::as_string(isset($normalized['country']) ? $normalized['country'] : ''),
			),
			'coordinates' => array(
				'latitude' => self::as_float_or_zero(isset($normalized['latitude']) ? $normalized['latitude'] : 0),
				'longitude' => self::as_float_or_zero(isset($normalized['longitude']) ? $normalized['longitude'] : 0),
			),
			'open_hours' => isset($normalized['open_hours']) && is_array($normalized['open_hours']) ? array_values($normalized['open_hours']) : array(),
			'eta' => array(
				'utc' => self::as_string(isset($normalized['eta']['utc']) ? $normalized['eta']['utc'] : ''),
				'local' => self::as_string(isset($normalized['eta']['local']) ? $normalized['eta']['local'] : ''),
			),
			'meta_data' => array(
				'address1' => self::as_string(isset($normalized['address1']) ? $normalized['address1'] : ''),
				'address2' => self::as_string(isset($normalized['address2']) ? $normalized['address2'] : ''),
				'customer_number' => self::as_string(isset($normalized['customer_number']) ? $normalized['customer_number'] : ''),
				'distance_meters' => self::as_numeric_or_zero(isset($normalized['distance_meters']) ? $normalized['distance_meters'] : 0),
				'label' => self::as_string(isset($normalized['label']) ? $normalized['label'] : ''),
				'opening_hours' => self::as_string(isset($normalized['opening_hours']) ? $normalized['opening_hours'] : ''),
			),
		);
	}

	private static function decode_to_array($value) {
		if (is_array($value)) {
			return $value;
		}

		if (!is_string($value)) {
			return array();
		}

		$trimmed = trim($value);
		if ($trimmed === '') {
			return array();
		}

		$decoded = json_decode($trimmed, true);
		return is_array($decoded) ? $decoded : array();
	}

	private static function as_string($value) {
		if (is_string($value)) {
			return $value;
		}
		if (is_scalar($value)) {
			return (string) $value;
		}
		return '';
	}

	private static function as_float_or_zero($value) {
		return is_numeric($value) ? (float) $value : 0.0;
	}

	private static function as_numeric_or_zero($value) {
		if (!is_numeric($value)) {
			return 0;
		}
		$float = (float) $value;
		$int = (int) $float;
		return ((float) $int === $float) ? $int : $float;
	}

	private static function is_associative_array($value) {
		if (!is_array($value)) {
			return false;
		}
		if (array() === $value) {
			return false;
		}
		return array_keys($value) !== range(0, count($value) - 1);
	}
}
