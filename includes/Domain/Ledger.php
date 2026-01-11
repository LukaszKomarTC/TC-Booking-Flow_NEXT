<?php
namespace TC_BF\Domain;

if ( ! defined('ABSPATH') ) exit;

/**
 * Ledger
 *
 * Early Booking (EB) calculation logic.
 * Extracted from Plugin class to provide focused domain logic.
 */
class Ledger {

	/**
	 * Cache for calculate_for_event results
	 * @var array
	 */
	private static $calc_cache = [];

	/**
	 * Select the appropriate EB step based on days before event
	 *
	 * @param int $days_before Number of days before the event
	 * @param array $steps Array of EB step configurations (sorted desc by min_days_before)
	 * @return array The selected step configuration, or empty array if none match
	 */
	public static function select_eb_step( int $days_before, array $steps ) : array {
		if ( ! $steps ) return [];
		// steps already sorted desc by min_days_before
		foreach ( $steps as $s ) {
			$min = (int) ($s['min_days_before'] ?? 0);
			if ( $days_before >= $min ) return (array) $s;
		}
		return [];
	}

	/**
	 * Compute the EB discount amount from a base total and step configuration
	 *
	 * @param float $base_total The base total to calculate discount from
	 * @param array $step The selected EB step configuration
	 * @param array $global_cap Global cap configuration
	 * @return array Array with 'amount' and 'effective_pct' keys
	 */
	public static function compute_eb_amount( float $base_total, array $step, array $global_cap ) : array {
		$base_total = max(0.0, $base_total);
		if ( $base_total <= 0 || ! $step ) return [ 'amount' => 0.0, 'effective_pct' => 0.0 ];
		$type = strtolower((string)($step['type'] ?? 'percent'));
		$value = (float) ($step['value'] ?? 0.0);
		if ( $value <= 0 ) return [ 'amount' => 0.0, 'effective_pct' => 0.0 ];
		$amount = 0.0;
		if ( $type === 'fixed' ) {
			$amount = $value;
		} else {
			$amount = $base_total * ($value / 100);
		}
		// Step cap (amount)
		if ( isset($step['cap']) && is_array($step['cap']) && ! empty($step['cap']['enabled']) ) {
			$cap_amt = (float) ($step['cap']['amount'] ?? 0.0);
			if ( $cap_amt > 0 ) $amount = min($amount, $cap_amt);
		}
		// Global cap (amount)
		if ( isset($global_cap['enabled']) && $global_cap['enabled'] ) {
			$g = (float) ($global_cap['amount'] ?? 0.0);
			if ( $g > 0 ) $amount = min($amount, $g);
		}
		$amount = min($amount, $base_total);
		$effective_pct = $base_total > 0 ? (($amount / $base_total) * 100) : 0.0;
		return [ 'amount' => (float) $amount, 'effective_pct' => (float) $effective_pct ];
	}

	/**
	 * Calculate early booking discount for an event
	 *
	 * @param int $event_id The event post ID
	 * @return array Calculation result with enabled, pct, days_before, event_start_ts, cfg, and step keys
	 */
	public static function calculate_for_event( int $event_id ) : array {

		if ( $event_id <= 0 ) return ['enabled'=>false,'pct'=>0.0,'days_before'=>0,'event_start_ts'=>0,'cfg'=>[]];

		if ( isset(self::$calc_cache[$event_id]) ) return self::$calc_cache[$event_id];

		$cfg = EventConfig::get_event_config($event_id);
		if ( empty($cfg['enabled']) ) {
			return self::$calc_cache[$event_id] = [
				'enabled'=>false,'pct'=>0.0,'days_before'=>0,'event_start_ts'=>0,'cfg'=>$cfg
			];
		}

		$start_ts = 0;
		// canonical helper from your system
		if ( function_exists('tc_sc_event_dates') ) {
			$d = tc_sc_event_dates($event_id);
			if ( is_array($d) && ! empty($d['start_ts']) ) $start_ts = (int) $d['start_ts'];
		}
		// fallback
		if ( $start_ts <= 0 ) $start_ts = (int) get_post_meta($event_id, 'sc_event_date_time', true);

		$now_ts = (int) current_time('timestamp'); // site TZ
		$days_before = 0;
		if ( $start_ts > 0 ) {
			$days_before = (int) floor( ($start_ts - $now_ts) / DAY_IN_SECONDS );
			if ( $days_before < 0 ) $days_before = 0;
		}

		$step = self::select_eb_step($days_before, (array) ($cfg['steps'] ?? []));
		// For GF display we only expose percent steps. Fixed discounts are handled server-side.
		$pct = 0.0;
		if ( $step && strtolower((string)($step['type'] ?? 'percent')) === 'percent' ) {
			$pct = (float) ($step['value'] ?? 0.0);
			if ( $pct < 0 ) $pct = 0.0;
		}

		return self::$calc_cache[$event_id] = [
			'enabled'=>true,
			'pct'=>(float)$pct,
			'days_before'=>$days_before,
			'event_start_ts'=>$start_ts,
			'cfg'=>$cfg,
			'step'=>$step,
		];
	}

}
