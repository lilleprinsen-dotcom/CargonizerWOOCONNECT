<?php

if (!defined('ABSPATH')) {
	exit;
}

class LP_Cargonizer_Krokedil_Pickup_Meta_Helper {
	public static function encode_pickup_points_for_meta($points) {
		$normalized = self::decode_pickup_points_meta($points);
		return wp_json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	public static function encode_pickup_point_for_meta($point) {
		$normalized = self::decode_pickup_point_meta($point);
		return wp_json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	public static function decode_pickup_points_meta($value) {
		$decoded = self::decode_to_array($value);
		if (empty($decoded)) {
			return array();
		}

		if (self::is_associative_array($decoded)) {
			return empty($decoded['id']) ? array() : array($decoded);
		}

		$normalized = array();
		foreach ($decoded as $point) {
			if (!is_array($point)) {
				continue;
			}
			$normalized[] = $point;
		}
		return $normalized;
	}

	public static function decode_pickup_point_meta($value) {
		$decoded = self::decode_to_array($value);
		if (empty($decoded)) {
			return array();
		}

		if (self::is_associative_array($decoded)) {
			return $decoded;
		}

		$first = reset($decoded);
		return is_array($first) ? $first : array();
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
