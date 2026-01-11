<?php
/**
 * Logger utility class for TC Booking Flow
 *
 * @package TC_Booking_Flow
 */

namespace TC_BF\Support;

/**
 * Logger class
 */
class Logger {

	/* =========================================================
	 * Debug logger
	 * ========================================================= */

	public static function is_debug() : bool {
		return class_exists('TC_BF\Admin\Settings') && \TC_BF\Admin\Settings::is_debug();
	}

	public static function log( string $context, array $data = [], string $level = 'info' ) : void {
		if ( ! self::is_debug() ) return;
		$row = [
			'time'    => (string) current_time('mysql'),
			'context' => $context,
			'data'    => (string) wp_json_encode($data, JSON_UNESCAPED_SLASHES),
		];
		\TC_BF\Admin\Settings::append_log($row, 50);
		if ( function_exists('wc_get_logger') ) {
			try {
				wc_get_logger()->log($level, $row['context'].' '.$row['data'], ['source' => 'tc-booking-flow']);
			} catch ( \Throwable $e ) {}
		}
	}

}
