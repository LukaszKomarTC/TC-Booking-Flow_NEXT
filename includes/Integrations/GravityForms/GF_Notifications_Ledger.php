<?php
namespace TC_BF\Integrations\GravityForms;

if ( ! defined('ABSPATH') ) exit;

/**
 * GF Notifications Ledger
 *
 * Records notification send/fail status in entry meta for operational visibility.
 * Used by GF_Participants_List to display "Info status" badge.
 *
 * Hooks:
 * - gform_after_email: After notification sent successfully
 * - gform_send_email_failed: When notification fails to send
 *
 * Meta structure (tcbf_notif_ledger):
 * [
 *     'last_event' => 'sent'|'failed',
 *     'last_at'    => '2026-01-18 14:33:12',
 *     'sent'       => [
 *         '<notification_id>' => '2026-01-18 14:33:12',
 *     ],
 *     'failed'     => [
 *         '<notification_id>' => [
 *             'at'    => '2026-01-18 14:33:12',
 *             'error' => '...',
 *         ],
 *     ],
 * ]
 *
 * @since 0.6.0
 */
final class GF_Notifications_Ledger {

	/**
	 * Entry meta key for notification ledger
	 */
	const META_KEY = 'tcbf_notif_ledger';

	/**
	 * Initialize hooks
	 */
	public static function init() : void {
		// Hook after email sent successfully
		// gform_after_email signature varies by GF version, use 12 args for safety
		add_action( 'gform_after_email', [ __CLASS__, 'after_email' ], 10, 12 );

		// Hook on email send failure
		add_action( 'gform_send_email_failed', [ __CLASS__, 'send_email_failed' ], 10, 3 );
	}

	/**
	 * Record successful notification send
	 *
	 * Gravity Forms gform_after_email hook signature (GF 2.5+):
	 * do_action( 'gform_after_email', $is_success, $to, $subject, $message, $headers,
	 *            $attachments, $message_format, $from, $from_name, $bcc, $reply_to,
	 *            $entry, $notification, $email )
	 *
	 * Older versions may pass fewer args. We inspect available args defensively.
	 *
	 * @param mixed ...$args Variable args from GF hook
	 */
	public static function after_email( ...$args ) : void {
		// Defensive: ensure we have GFAPI
		if ( ! function_exists( 'gform_update_meta' ) || ! function_exists( 'gform_get_meta' ) ) {
			return;
		}

		// Extract entry and notification from args
		// In GF 2.5+: $entry is arg index 11, $notification is arg index 12
		$entry        = null;
		$notification = null;

		// Try to find entry array in args
		foreach ( $args as $arg ) {
			if ( is_array( $arg ) && isset( $arg['id'] ) && isset( $arg['form_id'] ) ) {
				$entry = $arg;
				break;
			}
		}

		// Try to find notification array in args
		foreach ( $args as $arg ) {
			if ( is_array( $arg ) && isset( $arg['id'] ) && isset( $arg['name'] ) && isset( $arg['event'] ) ) {
				$notification = $arg;
				break;
			}
		}

		if ( empty( $entry ) || ! isset( $entry['id'] ) ) {
			return;
		}

		$entry_id = (int) $entry['id'];
		if ( $entry_id <= 0 ) {
			return;
		}

		// Get notification ID (fallback to 'unknown' if not available)
		$notification_id = 'unknown';
		if ( ! empty( $notification ) && isset( $notification['id'] ) ) {
			$notification_id = (string) $notification['id'];
		}

		// Get current timestamp
		$timestamp = current_time( 'mysql', true ); // GMT

		// Update ledger
		self::update_ledger( $entry_id, 'sent', $notification_id, $timestamp );
	}

	/**
	 * Record failed notification send
	 *
	 * Gravity Forms gform_send_email_failed hook signature:
	 * do_action( 'gform_send_email_failed', $email, $form, $entry )
	 *
	 * @param mixed $email Email object or array (contains notification data)
	 * @param mixed $form  GF form array
	 * @param mixed $entry GF entry array
	 */
	public static function send_email_failed( $email, $form, $entry ) : void {
		// Defensive: ensure we have GFAPI
		if ( ! function_exists( 'gform_update_meta' ) || ! function_exists( 'gform_get_meta' ) ) {
			return;
		}

		if ( empty( $entry ) || ! is_array( $entry ) || ! isset( $entry['id'] ) ) {
			return;
		}

		$entry_id = (int) $entry['id'];
		if ( $entry_id <= 0 ) {
			return;
		}

		// Try to get notification ID from email object
		$notification_id = 'unknown';
		$error_message   = 'Email send failed';

		if ( is_array( $email ) ) {
			if ( isset( $email['notification_id'] ) ) {
				$notification_id = (string) $email['notification_id'];
			} elseif ( isset( $email['notification']['id'] ) ) {
				$notification_id = (string) $email['notification']['id'];
			}
		} elseif ( is_object( $email ) ) {
			if ( isset( $email->notification_id ) ) {
				$notification_id = (string) $email->notification_id;
			} elseif ( isset( $email->notification ) && isset( $email->notification['id'] ) ) {
				$notification_id = (string) $email->notification['id'];
			}
		}

		// Get current timestamp
		$timestamp = current_time( 'mysql', true ); // GMT

		// Update ledger with failure
		self::update_ledger( $entry_id, 'failed', $notification_id, $timestamp, $error_message );
	}

	/**
	 * Update the notification ledger for an entry
	 *
	 * @param int    $entry_id        GF entry ID
	 * @param string $event           'sent' or 'failed'
	 * @param string $notification_id Notification ID
	 * @param string $timestamp       MySQL timestamp (GMT)
	 * @param string $error           Error message (for failed events)
	 */
	private static function update_ledger( int $entry_id, string $event, string $notification_id, string $timestamp, string $error = '' ) : void {
		// Get existing ledger or initialize
		$ledger = gform_get_meta( $entry_id, self::META_KEY );

		if ( empty( $ledger ) || ! is_array( $ledger ) ) {
			$ledger = [
				'last_event' => '',
				'last_at'    => '',
				'sent'       => [],
				'failed'     => [],
			];
		}

		// Ensure arrays exist
		if ( ! isset( $ledger['sent'] ) || ! is_array( $ledger['sent'] ) ) {
			$ledger['sent'] = [];
		}
		if ( ! isset( $ledger['failed'] ) || ! is_array( $ledger['failed'] ) ) {
			$ledger['failed'] = [];
		}

		// Update ledger based on event
		if ( $event === 'sent' ) {
			$ledger['sent'][ $notification_id ] = $timestamp;
			$ledger['last_event'] = 'sent';
			$ledger['last_at']    = $timestamp;
		} elseif ( $event === 'failed' ) {
			$ledger['failed'][ $notification_id ] = [
				'at'    => $timestamp,
				'error' => $error,
			];
			$ledger['last_event'] = 'failed';
			$ledger['last_at']    = $timestamp;
		}

		// Save updated ledger
		gform_update_meta( $entry_id, self::META_KEY, $ledger );
	}
}
