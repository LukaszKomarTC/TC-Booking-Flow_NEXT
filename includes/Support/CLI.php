<?php
namespace TC_BF\Support;

use TC_BF\Integrations\GravityForms\GF_Notification_Templates;
use TC_BF\Integrations\GravityForms\GF_Notification_Config;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WP-CLI Commands for TC Booking Flow
 *
 * Usage:
 *   wp tcbf notifications sync [--dry-run]
 *   wp tcbf notifications status
 *   wp tcbf notifications remove <form_id>
 *
 * @since 0.6.1
 */
final class CLI {

	/**
	 * Register CLI commands
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command( 'tcbf notifications', __CLASS__ );
	}

	/**
	 * Sync TCBF notifications to configured Gravity Forms
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Validate without making changes
	 *
	 * [--form=<form_id>]
	 * : Sync only a specific form ID
	 *
	 * ## EXAMPLES
	 *
	 *     # Sync all configured forms
	 *     wp tcbf notifications sync
	 *
	 *     # Dry run to see what would change
	 *     wp tcbf notifications sync --dry-run
	 *
	 *     # Sync specific form
	 *     wp tcbf notifications sync --form=48
	 *
	 * @param array $args       Positional arguments
	 * @param array $assoc_args Named arguments
	 */
	public function sync( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$form_id = isset( $assoc_args['form'] ) ? (int) $assoc_args['form'] : null;

		if ( $dry_run ) {
			\WP_CLI::log( '=== DRY RUN MODE ===' );
		}

		\WP_CLI::log( 'Starting TCBF notification sync...' );
		\WP_CLI::log( '' );

		if ( $form_id ) {
			// Sync single form
			$result = GF_Notification_Templates::sync_form( $form_id, $dry_run );
			$this->display_form_result( $form_id, $result );
		} else {
			// Sync all configured forms
			$result = GF_Notification_Templates::sync_all( $dry_run );

			foreach ( $result['forms'] as $fid => $form_result ) {
				$this->display_form_result( $fid, $form_result );
			}
		}

		\WP_CLI::log( '' );

		if ( isset( $result['success'] ) && $result['success'] ) {
			if ( $dry_run ) {
				\WP_CLI::success( 'Dry run complete. No changes made.' );
			} else {
				\WP_CLI::success( 'Notification sync complete.' );
			}
		} else {
			\WP_CLI::error( 'Sync completed with errors. Check logs above.' );
		}
	}

	/**
	 * Display sync status for configured forms
	 *
	 * ## EXAMPLES
	 *
	 *     wp tcbf notifications status
	 *
	 * @param array $args       Positional arguments
	 * @param array $assoc_args Named arguments
	 */
	public function status( array $args, array $assoc_args ): void {
		$status = GF_Notification_Templates::get_status();

		\WP_CLI::log( 'TCBF Notification Status' );
		\WP_CLI::log( '========================' );
		\WP_CLI::log( "Template Version: {$status['template_version']}" );
		\WP_CLI::log( '' );

		if ( isset( $status['error'] ) ) {
			\WP_CLI::warning( $status['error'] );
			return;
		}

		foreach ( $status['forms'] as $form_id => $form_status ) {
			\WP_CLI::log( "Form {$form_id}:" );

			if ( ! $form_status['exists'] ) {
				\WP_CLI::warning( "  Form not found: {$form_status['error']}" );
				continue;
			}

			\WP_CLI::log( "  Title: {$form_status['title']}" );
			\WP_CLI::log( "  TCBF Notifications:" );

			if ( empty( $form_status['notifications'] ) ) {
				\WP_CLI::log( "    (none installed)" );
			} else {
				foreach ( $form_status['notifications'] as $id => $notif ) {
					$active = $notif['isActive'] ? 'active' : 'inactive';
					\WP_CLI::log( "    - {$id}: {$notif['name']} [{$active}] (event: {$notif['event']})" );
				}
			}

			if ( ! empty( $form_status['missing'] ) ) {
				\WP_CLI::log( "  Missing templates:" );
				foreach ( $form_status['missing'] as $missing ) {
					\WP_CLI::log( "    - {$missing}" );
				}
			}

			\WP_CLI::log( '' );
		}
	}

	/**
	 * Remove all TCBF notifications from a form
	 *
	 * ## OPTIONS
	 *
	 * <form_id>
	 * : The form ID to remove notifications from
	 *
	 * [--yes]
	 * : Skip confirmation prompt
	 *
	 * ## EXAMPLES
	 *
	 *     wp tcbf notifications remove 48
	 *
	 * @param array $args       Positional arguments
	 * @param array $assoc_args Named arguments
	 */
	public function remove( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			\WP_CLI::error( 'Please provide a form ID.' );
			return;
		}

		$form_id = (int) $args[0];

		if ( ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::confirm( "Remove all TCBF notifications from form {$form_id}?", $assoc_args );
		}

		$result = GF_Notification_Templates::remove_all( $form_id );

		if ( $result ) {
			\WP_CLI::success( "Removed all TCBF notifications from form {$form_id}." );
		} else {
			\WP_CLI::error( "Failed to remove notifications from form {$form_id}." );
		}
	}

	/**
	 * Validate form field configuration
	 *
	 * ## OPTIONS
	 *
	 * <form_id>
	 * : The form ID to validate
	 *
	 * ## EXAMPLES
	 *
	 *     wp tcbf notifications validate 48
	 *
	 * @param array $args       Positional arguments
	 * @param array $assoc_args Named arguments
	 */
	public function validate( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			\WP_CLI::error( 'Please provide a form ID.' );
			return;
		}

		$form_id = (int) $args[0];

		\WP_CLI::log( "Validating form {$form_id}..." );
		\WP_CLI::log( '' );

		$notification_types = [ 'participant_confirmation', 'partner_notification', 'admin_notification' ];
		$all_valid = true;

		foreach ( $notification_types as $type ) {
			$validation = GF_Notification_Config::validate_form_fields( $form_id, $type );

			\WP_CLI::log( "{$type}:" );

			if ( $validation['valid'] ) {
				\WP_CLI::log( "  Status: VALID" );
			} else {
				\WP_CLI::log( "  Status: INVALID" );
				$all_valid = false;

				if ( ! empty( $validation['missing'] ) ) {
					\WP_CLI::log( "  Missing fields:" );
					foreach ( $validation['missing'] as $field ) {
						\WP_CLI::log( "    - {$field}" );
					}
				}

				if ( ! empty( $validation['errors'] ) ) {
					\WP_CLI::log( "  Errors:" );
					foreach ( $validation['errors'] as $error ) {
						\WP_CLI::log( "    - {$error}" );
					}
				}
			}

			\WP_CLI::log( '' );
		}

		if ( $all_valid ) {
			\WP_CLI::success( "Form {$form_id} is valid for all notification types." );
		} else {
			\WP_CLI::warning( "Form {$form_id} has validation errors." );
		}
	}

	/**
	 * Display form sync result
	 *
	 * @param int   $form_id Form ID
	 * @param array $result  Sync result
	 */
	private function display_form_result( int $form_id, array $result ): void {
		\WP_CLI::log( "Form {$form_id}:" );

		if ( ! empty( $result['errors'] ) ) {
			foreach ( $result['errors'] as $error ) {
				\WP_CLI::warning( "  Error: {$error}" );
			}
		}

		if ( ! empty( $result['notifications'] ) ) {
			foreach ( $result['notifications'] as $id => $notif_result ) {
				$action = $notif_result['action'] ?? 'unknown';
				$message = $notif_result['message'] ?? '';

				switch ( $action ) {
					case 'add':
						\WP_CLI::log( "  + Added: {$id}" );
						break;
					case 'update':
						\WP_CLI::log( "  ~ Updated: {$id}" );
						break;
					case 'skip':
						\WP_CLI::log( "  - Skipped: {$id}" );
						break;
					case 'error':
						\WP_CLI::warning( "  ! Error: {$message}" );
						break;
					default:
						\WP_CLI::log( "  ? {$id}: {$message}" );
				}
			}
		}
	}
}
