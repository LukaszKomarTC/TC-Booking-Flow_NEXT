<?php
namespace TC_BF\Domain;

if ( ! defined('ABSPATH') ) exit;

/**
 * Entry Expiry Job — Scheduled cron task to expire abandoned cart entries
 *
 * Handles:
 * - Scheduled WP-Cron job to find and expire old in_cart entries
 * - Batch processing with pagination for performance
 * - Locking mechanism to prevent concurrent execution
 * - Configurable TTL (time to live) for in_cart entries
 * - Comprehensive logging for monitoring
 *
 * Default TTL: 2 hours (7200 seconds)
 * Aligns with WooCommerce cart session expiry and Bookings hold times
 */
final class Entry_Expiry_Job {

	/**
	 * Cron hook name
	 */
	const CRON_HOOK = 'tcbf_expire_abandoned_carts';

	/**
	 * Cron interval (hourly by default)
	 */
	const CRON_INTERVAL = 'hourly';

	/**
	 * Default TTL for in_cart entries (2 hours)
	 */
	const DEFAULT_TTL_SECONDS = 7200;

	/**
	 * Batch size for processing (performance optimization)
	 */
	const BATCH_SIZE = 50;

	/**
	 * Transient key for job lock
	 */
	const LOCK_KEY = 'tcbf_expiry_job_lock';

	/**
	 * Lock timeout (15 minutes)
	 */
	const LOCK_TIMEOUT = 900;

	/**
	 * Initialize expiry job
	 */
	public static function init() : void {

		// Schedule cron event if not already scheduled
		add_action( 'init', [ __CLASS__, 'schedule_cron_job' ] );

		// Hook into cron event
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_expiry_job' ] );

		// Clean up on plugin deactivation
		register_deactivation_hook( TC_BF_PATH . 'tc-booking-flow-next.php', [ __CLASS__, 'unschedule_cron_job' ] );
	}

	/**
	 * Schedule the cron job if not already scheduled
	 */
	public static function schedule_cron_job() : void {

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );

			\TC_BF\Support\Logger::log( 'expiry_job.scheduled', [
				'hook'     => self::CRON_HOOK,
				'interval' => self::CRON_INTERVAL,
			] );
		}
	}

	/**
	 * Unschedule the cron job
	 */
	public static function unschedule_cron_job() : void {

		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );

			\TC_BF\Support\Logger::log( 'expiry_job.unscheduled', [
				'hook' => self::CRON_HOOK,
			] );
		}
	}

	/**
	 * Run the expiry job
	 *
	 * Main entry point called by WP-Cron.
	 * Processes entries in batches with locking.
	 */
	public static function run_expiry_job() : void {

		// Try to acquire lock
		if ( ! self::acquire_lock() ) {
			\TC_BF\Support\Logger::log( 'expiry_job.skipped.locked', [
				'reason' => 'Another instance is already running',
			] );
			return;
		}

		\TC_BF\Support\Logger::log( 'expiry_job.started', [
			'timestamp' => current_time( 'mysql' ),
		] );

		$start_time = microtime( true );
		$form_id = self::get_form_id();
		$ttl = self::get_ttl_seconds();

		if ( $form_id <= 0 ) {
			self::release_lock();
			\TC_BF\Support\Logger::log( 'expiry_job.skipped.no_form', [
				'reason' => 'No form ID configured',
			] );
			return;
		}

		try {
			$stats = self::process_expired_entries( $form_id, $ttl );

			$duration = round( ( microtime( true ) - $start_time ) * 1000, 2 );

			\TC_BF\Support\Logger::log( 'expiry_job.completed', [
				'form_id'       => $form_id,
				'ttl_seconds'   => $ttl,
				'expired_count' => $stats['expired'],
				'checked_count' => $stats['checked'],
				'duration_ms'   => $duration,
			] );

		} catch ( \Exception $e ) {
			\TC_BF\Support\Logger::log( 'expiry_job.error', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
			] );
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Process expired entries in batches
	 *
	 * @param int $form_id     GF form ID
	 * @param int $ttl_seconds Time to live in seconds
	 * @return array Stats [expired, checked]
	 */
	private static function process_expired_entries( int $form_id, int $ttl_seconds ) : array {

		$offset = 0;
		$expired_count = 0;
		$checked_count = 0;

		do {
			// Get batch of potentially expired entries
			$entries = Entry_State::get_expired_entries( $form_id, $ttl_seconds, self::BATCH_SIZE, $offset );

			$batch_size = count( $entries );
			$checked_count += $batch_size;

			foreach ( $entries as $entry ) {
				$entry_id = isset( $entry['id'] ) ? (int) $entry['id'] : 0;
				if ( $entry_id <= 0 ) {
					continue;
				}

				// Double-check state before expiring
				$current_state = Entry_State::get_state( $entry_id );
				if ( $current_state !== Entry_State::STATE_IN_CART ) {
					// State changed since query, skip
					continue;
				}

				// Mark as expired
				if ( Entry_State::mark_expired( $entry_id ) ) {
					$expired_count++;

					\TC_BF\Support\Logger::log( 'expiry_job.entry_expired', [
						'entry_id' => $entry_id,
					] );
				}
			}

			$offset += self::BATCH_SIZE;

			// Continue if we got a full batch (might be more)
		} while ( $batch_size === self::BATCH_SIZE );

		return [
			'expired' => $expired_count,
			'checked' => $checked_count,
		];
	}

	/**
	 * Acquire job lock
	 *
	 * Uses transient to prevent concurrent execution.
	 *
	 * @return bool Lock acquired
	 */
	private static function acquire_lock() : bool {

		// Try to set lock
		$locked = set_transient( self::LOCK_KEY, time(), self::LOCK_TIMEOUT );

		if ( ! $locked ) {
			// Lock already exists, check if stale
			$lock_time = (int) get_transient( self::LOCK_KEY );
			$now = time();

			// If lock is older than timeout, force release and retry
			if ( $lock_time > 0 && ( $now - $lock_time ) > self::LOCK_TIMEOUT ) {
				delete_transient( self::LOCK_KEY );
				$locked = set_transient( self::LOCK_KEY, time(), self::LOCK_TIMEOUT );

				\TC_BF\Support\Logger::log( 'expiry_job.lock_forced', [
					'reason' => 'Stale lock detected and cleared',
					'lock_age_seconds' => $now - $lock_time,
				] );
			}
		}

		return (bool) $locked;
	}

	/**
	 * Release job lock
	 */
	private static function release_lock() : void {
		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Get form ID from plugin settings
	 *
	 * @return int Form ID
	 */
	private static function get_form_id() : int {

		if ( class_exists( '\\TC_BF\\Admin\\Settings' ) ) {
			return \TC_BF\Admin\Settings::get_form_id();
		}

		// Fallback: hardcoded form ID
		return defined( '\\TC_BF\\Plugin::GF_FORM_ID' ) ? (int) \TC_BF\Plugin::GF_FORM_ID : 44;
	}

	/**
	 * Get TTL seconds from settings
	 *
	 * Filterable for customization.
	 *
	 * @return int TTL in seconds
	 */
	private static function get_ttl_seconds() : int {

		$ttl = apply_filters( 'tcbf_entry_expiry_ttl_seconds', self::DEFAULT_TTL_SECONDS );

		// Validate: minimum 30 minutes, maximum 24 hours
		$ttl = max( 1800, min( 86400, (int) $ttl ) );

		return $ttl;
	}

	/**
	 * Manually trigger expiry job (for testing/debugging)
	 *
	 * Bypasses lock check.
	 */
	public static function run_manual() : void {

		\TC_BF\Support\Logger::log( 'expiry_job.manual_run', [
			'timestamp' => current_time( 'mysql' ),
		] );

		$form_id = self::get_form_id();
		$ttl = self::get_ttl_seconds();

		if ( $form_id <= 0 ) {
			\TC_BF\Support\Logger::log( 'expiry_job.manual_run.no_form', [
				'reason' => 'No form ID configured',
			] );
			return;
		}

		$stats = self::process_expired_entries( $form_id, $ttl );

		\TC_BF\Support\Logger::log( 'expiry_job.manual_run.completed', [
			'form_id'       => $form_id,
			'ttl_seconds'   => $ttl,
			'expired_count' => $stats['expired'],
			'checked_count' => $stats['checked'],
		] );
	}

	/**
	 * Get next scheduled run time
	 *
	 * @return int|false Timestamp or false if not scheduled
	 */
	public static function get_next_run() {
		return wp_next_scheduled( self::CRON_HOOK );
	}

	/**
	 * Check if job is currently locked
	 *
	 * @return bool Is locked
	 */
	public static function is_locked() : bool {
		return (bool) get_transient( self::LOCK_KEY );
	}
}
