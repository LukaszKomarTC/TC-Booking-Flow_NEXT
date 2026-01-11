<?php
namespace TC_BF\Domain;

if ( ! defined('ABSPATH') ) exit;

/**
 * Event Configuration
 *
 * Handles event-specific configuration, particularly early booking discount rules.
 * Extracted from Plugin class to provide focused domain logic.
 */
class EventConfig {

	// Per-event config meta keys
	const META_EB_ENABLED                = 'tc_ebd_enabled';
	const META_EB_RULES_JSON             = 'tc_ebd_rules_json';
	const META_EB_CAP                    = 'tc_ebd_cap';
	const META_EB_PARTICIPATION_ENABLED  = 'tc_ebd_participation_enabled';
	const META_EB_RENTAL_ENABLED         = 'tc_ebd_rental_enabled';

	/**
	 * Get event configuration
	 *
	 * Retrieves and parses early booking discount configuration for a given event.
	 * Supports both legacy array format and new schema v1 with step-based rules.
	 *
	 * @param int $event_id The event post ID
	 * @return array Configuration array with enabled, participation_enabled, rental_enabled, version, global_cap, and steps
	 */
	public static function get_event_config( int $event_id ) : array {

		// NOTE: This config is per sc_event. Rules are stored as JSON (schema v1) in META_EB_RULES_JSON.
		// We keep backwards compatibility with the earlier "[{days,pct},...]" array format.
		$cfg = [
			'enabled' => false,
			'participation_enabled' => true,
			'rental_enabled'        => true,
			'version' => 1,
			'global_cap' => [ 'enabled' => false, 'amount' => 0.0 ],
			'steps' => [],
		];

		$enabled = get_post_meta($event_id, self::META_EB_ENABLED, true);
		if ( $enabled !== '' ) {
			$val = strtolower(trim((string)$enabled));
			$cfg['enabled'] = in_array($val, ['1','yes','true','on'], true);
		}

		// Legacy cap meta (deprecated): if present we interpret it as a GLOBAL cap amount (in currency).
		$cap = get_post_meta($event_id, self::META_EB_CAP, true);
		if ( $cap !== '' && is_numeric($cap) ) {
			$cfg['global_cap'] = [ 'enabled' => true, 'amount' => (float) $cap ];
		}

		$rules_json = (string) get_post_meta($event_id, self::META_EB_RULES_JSON, true);
		if ( $rules_json !== '' ) {
			$decoded = json_decode($rules_json, true);
			if ( is_array($decoded) ) {
				// Schema v1: object with steps.
				if ( isset($decoded['steps']) && is_array($decoded['steps']) ) {
					$cfg['version'] = isset($decoded['version']) ? (int) $decoded['version'] : 1;
					if ( isset($decoded['global_cap']) && is_array($decoded['global_cap']) ) {
						$cfg['global_cap']['enabled'] = ! empty($decoded['global_cap']['enabled']);
						$cfg['global_cap']['amount']  = isset($decoded['global_cap']['amount']) ? (float) $decoded['global_cap']['amount'] : 0.0;
					}
					$steps = [];
					foreach ( $decoded['steps'] as $s ) {
						if ( ! is_array($s) ) continue;
						$min = isset($s['min_days_before']) ? (int) $s['min_days_before'] : 0;
						$type = isset($s['type']) ? strtolower((string) $s['type']) : 'percent';
						$value = isset($s['value']) ? (float) $s['value'] : 0.0;
						if ( $min < 0 || $value <= 0 ) continue;
						if ( ! in_array($type, ['percent','fixed'], true) ) $type = 'percent';
						$cap_s = [ 'enabled' => false, 'amount' => 0.0 ];
						if ( isset($s['cap']) && is_array($s['cap']) ) {
							$cap_s['enabled'] = ! empty($s['cap']['enabled']);
							$cap_s['amount']  = isset($s['cap']['amount']) ? (float) $s['cap']['amount'] : 0.0;
						}
						$steps[] = [
							'min_days_before' => $min,
							'type' => $type,
							'value' => $value,
							'cap' => $cap_s,
						];
					}
					usort($steps, function($a,$b){ return ((int)$b['min_days_before']) <=> ((int)$a['min_days_before']); });
					$cfg['steps'] = $steps;
				} else {
					// Legacy array: [ {days,pct}, ... ]
					$steps = [];
					foreach ( $decoded as $row ) {
						if ( ! is_array($row) ) continue;
						if ( ! isset($row['days'], $row['pct']) ) continue;
						if ( ! is_numeric($row['days']) || ! is_numeric($row['pct']) ) continue;
						$steps[] = [
							'min_days_before' => (int) $row['days'],
							'type' => 'percent',
							'value' => (float) $row['pct'],
							'cap' => [ 'enabled' => false, 'amount' => 0.0 ],
						];
					}
					usort($steps, function($a,$b){ return ((int)$b['min_days_before']) <=> ((int)$a['min_days_before']); });
					$cfg['steps'] = $steps;
				}
			}
		}

		$p = get_post_meta($event_id, self::META_EB_PARTICIPATION_ENABLED, true);
		if ( $p !== '' ) {
			$val = strtolower(trim((string)$p));
			$cfg['participation_enabled'] = in_array($val, ['1','yes','true','on'], true);
		}

		$r = get_post_meta($event_id, self::META_EB_RENTAL_ENABLED, true);
		if ( $r !== '' ) {
			$val = strtolower(trim((string)$r));
			$cfg['rental_enabled'] = in_array($val, ['1','yes','true','on'], true);
		}

		return $cfg;
	}
}
