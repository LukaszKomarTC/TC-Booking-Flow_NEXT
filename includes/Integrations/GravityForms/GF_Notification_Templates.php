<?php
namespace TC_BF\Integrations\GravityForms;

use TC_BF\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * GF Notification Templates
 *
 * Manages TCBF notification templates for Gravity Forms.
 * Provides programmatic sync of notifications with validation.
 *
 * Guardrails:
 * - Idempotent upsert: only add/update tcbf_* notifications
 * - Never overwrites or deletes non-TCBF notifications
 * - Validates required fields before sync
 * - Fails safely with clear logging
 *
 * @since 0.6.1
 */
final class GF_Notification_Templates {

	/**
	 * Notification ID prefix for TCBF-managed notifications
	 */
	const ID_PREFIX = 'tcbf_';

	/**
	 * Template version (increment when templates change)
	 */
	const TEMPLATE_VERSION = '1.0.0';

	/**
	 * Available notification templates
	 */
	const TEMPLATES = [
		'tcbf_participant_confirmation_v1' => [
			'type'     => 'participant_confirmation',
			'name'     => 'TCBF: Participant Confirmation',
			'event'    => 'WC___paid',
			'template' => 'participant-confirmation.html',
		],
		'tcbf_partner_notification_v1' => [
			'type'     => 'partner_notification',
			'name'     => 'TCBF: Partner Notification',
			'event'    => 'WC___paid',
			'template' => 'partner-notification.html',
		],
		'tcbf_admin_notification_v1' => [
			'type'     => 'admin_notification',
			'name'     => 'TCBF: Admin Notification',
			'event'    => 'WC___paid',
			'template' => 'admin-notification.html',
		],
	];

	/**
	 * Sync all TCBF notifications for configured forms
	 *
	 * @param bool $dry_run If true, validate only without making changes
	 * @return array{success: bool, forms: array, errors: array}
	 */
	public static function sync_all( bool $dry_run = false ): array {
		$result = [
			'success' => true,
			'forms'   => [],
			'errors'  => [],
		];

		if ( ! class_exists( 'GFAPI' ) ) {
			$result['success'] = false;
			$result['errors'][] = 'Gravity Forms is not active';
			return $result;
		}

		$forms = GF_Notification_Config::get_configured_forms();

		foreach ( $forms as $form_id => $config ) {
			$form_result = self::sync_form( $form_id, $dry_run );
			$result['forms'][ $form_id ] = $form_result;

			if ( ! $form_result['success'] ) {
				$result['success'] = false;
				$result['errors'] = array_merge( $result['errors'], $form_result['errors'] );
			}
		}

		return $result;
	}

	/**
	 * Sync TCBF notifications for a specific form
	 *
	 * @param int  $form_id GF form ID
	 * @param bool $dry_run If true, validate only without making changes
	 * @return array{success: bool, notifications: array, errors: array}
	 */
	public static function sync_form( int $form_id, bool $dry_run = false ): array {
		$result = [
			'success'       => true,
			'notifications' => [],
			'errors'        => [],
		];

		$form = \GFAPI::get_form( $form_id );
		if ( ! $form || is_wp_error( $form ) ) {
			$result['success'] = false;
			$result['errors'][] = "Form {$form_id} not found";
			return $result;
		}

		Logger::log( "TCBF Notification Sync: Starting for form {$form_id}", [
			'form_title' => $form['title'] ?? 'Unknown',
			'dry_run'    => $dry_run,
		], 'info' );

		$notifications = $form['notifications'] ?? [];
		$updated = false;

		foreach ( self::TEMPLATES as $notification_id => $template_config ) {
			$notification_result = self::sync_notification(
				$form_id,
				$form,
				$notification_id,
				$template_config,
				$notifications,
				$dry_run
			);

			$result['notifications'][ $notification_id ] = $notification_result;

			if ( $notification_result['action'] === 'error' ) {
				$result['success'] = false;
				$result['errors'][] = $notification_result['message'];
			} elseif ( $notification_result['action'] !== 'skip' && ! $dry_run ) {
				$notifications[ $notification_id ] = $notification_result['notification'];
				$updated = true;
			}
		}

		// Save form if notifications were updated
		if ( $updated && ! $dry_run ) {
			$form['notifications'] = $notifications;
			$update_result = \GFAPI::update_form( $form );

			if ( is_wp_error( $update_result ) ) {
				$result['success'] = false;
				$result['errors'][] = "Failed to save form: " . $update_result->get_error_message();
				Logger::log( "TCBF Notification Sync: Failed to save form {$form_id}", [
					'error' => $update_result->get_error_message(),
				], 'error' );
			} else {
				Logger::log( "TCBF Notification Sync: Form {$form_id} saved successfully", [], 'info' );
			}
		}

		return $result;
	}

	/**
	 * Sync a single notification
	 *
	 * @param int    $form_id          GF form ID
	 * @param array  $form             GF form array
	 * @param string $notification_id  Notification ID
	 * @param array  $template_config  Template configuration
	 * @param array  $notifications    Existing notifications
	 * @param bool   $dry_run          If true, validate only
	 * @return array{action: string, message: string, notification?: array}
	 */
	private static function sync_notification(
		int $form_id,
		array $form,
		string $notification_id,
		array $template_config,
		array $notifications,
		bool $dry_run
	): array {
		$type = $template_config['type'];

		// Validate required fields
		$validation = GF_Notification_Config::validate_form_fields( $form_id, $type );
		if ( ! $validation['valid'] ) {
			$message = "Skipping {$notification_id}: missing fields - " . implode( ', ', $validation['missing'] );
			Logger::log( "TCBF Notification Sync: {$message}", [], 'warning' );
			return [
				'action'  => 'error',
				'message' => $message,
			];
		}

		// Load HTML template
		$template_html = self::load_template( $template_config['template'] );
		if ( $template_html === null ) {
			$message = "Skipping {$notification_id}: template file not found - {$template_config['template']}";
			Logger::log( "TCBF Notification Sync: {$message}", [], 'warning' );
			return [
				'action'  => 'error',
				'message' => $message,
			];
		}

		// Build notification array
		$notification = self::build_notification(
			$form_id,
			$notification_id,
			$template_config,
			$template_html
		);

		if ( $notification === null ) {
			$message = "Skipping {$notification_id}: failed to build notification (missing conditional logic)";
			Logger::log( "TCBF Notification Sync: {$message}", [], 'warning' );
			return [
				'action'  => 'error',
				'message' => $message,
			];
		}

		// Determine action
		$exists = isset( $notifications[ $notification_id ] );
		$action = $exists ? 'update' : 'add';

		$message = $dry_run
			? "[DRY RUN] Would {$action} {$notification_id}"
			: "{$action}d {$notification_id}";

		Logger::log( "TCBF Notification Sync: {$message}", [], 'info' );

		return [
			'action'       => $action,
			'message'      => $message,
			'notification' => $notification,
		];
	}

	/**
	 * Build notification array for a specific template
	 *
	 * @param int    $form_id         GF form ID
	 * @param string $notification_id Notification ID
	 * @param array  $template_config Template configuration
	 * @param string $template_html   HTML template content
	 * @return array|null Notification array or null on failure
	 */
	private static function build_notification(
		int $form_id,
		string $notification_id,
		array $template_config,
		string $template_html
	): ?array {
		$field_map = GF_Notification_Config::get_field_map( $form_id );
		$email_settings = GF_Notification_Config::get_email_settings( $form_id );
		$type = $template_config['type'];

		// Build base notification
		$notification = [
			'id'                => $notification_id,
			'isActive'          => true,
			'name'              => $template_config['name'],
			'service'           => 'wordpress',
			'event'             => $template_config['event'],
			'fromName'          => $email_settings['from_name'],
			'from'              => $email_settings['from_email'],
			'replyTo'           => $email_settings['reply_to'],
			'bcc'               => $email_settings['bcc'],
			'cc'                => '',
			'disableAutoformat' => true,
			'enableAttachments' => false,
			'message'           => $template_html,
		];

		// Add type-specific settings
		switch ( $type ) {
			case 'participant_confirmation':
				$notification = self::configure_participant_notification( $notification, $form_id, $field_map );
				break;

			case 'partner_notification':
				$notification = self::configure_partner_notification( $notification, $form_id, $field_map );
				break;

			case 'admin_notification':
				$notification = self::configure_admin_notification( $notification, $form_id, $field_map, $email_settings );
				break;

			default:
				return null;
		}

		return $notification;
	}

	/**
	 * Configure participant confirmation notification
	 */
	private static function configure_participant_notification(
		array $notification,
		int $form_id,
		array $field_map
	): ?array {
		// Recipient: participant email field
		$notification['toType'] = 'field';
		$notification['to'] = (string) $field_map['participant_email'];
		$notification['toField'] = (string) $field_map['participant_email'];
		$notification['toEmail'] = '';
		$notification['routing'] = null;

		// Subject
		$notification['subject'] = self::get_participant_subject( $field_map );

		// Conditional logic: checkbox must be checked
		$condition = GF_Notification_Config::build_checkbox_condition( $form_id );
		if ( ! $condition ) {
			return null;
		}

		$notification['conditionalLogic'] = [
			'actionType' => 'show',
			'logicType'  => 'all',
			'rules'      => [ $condition ],
		];
		$notification['notification_conditional_logic'] = '1';
		$notification['notification_conditional_logic_object'] = $notification['conditionalLogic'];

		return $notification;
	}

	/**
	 * Configure partner notification
	 */
	private static function configure_partner_notification(
		array $notification,
		int $form_id,
		array $field_map
	): ?array {
		// Recipient: partner email field (merge tag)
		$partner_email_field = $field_map['partner_email'] ?? null;
		if ( ! $partner_email_field ) {
			return null;
		}

		$notification['toType'] = 'email';
		$notification['to'] = "{Partner email:{$partner_email_field}}";
		$notification['toEmail'] = "{Partner email:{$partner_email_field}}";
		$notification['toField'] = '';
		$notification['routing'] = null;

		// Subject
		$notification['subject'] = self::get_partner_subject( $field_map );

		// Conditional logic: discount code not empty
		$conditions = GF_Notification_Config::build_partner_condition( $form_id );
		if ( ! $conditions ) {
			return null;
		}

		$notification['conditionalLogic'] = [
			'actionType' => 'show',
			'logicType'  => 'all',
			'rules'      => $conditions,
		];
		$notification['notification_conditional_logic'] = '1';
		$notification['notification_conditional_logic_object'] = $notification['conditionalLogic'];

		return $notification;
	}

	/**
	 * Configure admin notification
	 */
	private static function configure_admin_notification(
		array $notification,
		int $form_id,
		array $field_map,
		array $email_settings
	): array {
		// Recipient: admin email (static)
		$notification['toType'] = 'email';
		$notification['to'] = $email_settings['from_email']; // Same as from for admin
		$notification['toEmail'] = $email_settings['from_email'];
		$notification['toField'] = '';
		$notification['routing'] = null;

		// Subject
		$notification['subject'] = self::get_admin_subject( $field_map );

		// No conditional logic for admin (always send on WC___paid)
		$notification['conditionalLogic'] = null;
		$notification['notification_conditional_logic'] = '0';
		$notification['notification_conditional_logic_object'] = '';

		return $notification;
	}

	/**
	 * Get participant notification subject
	 */
	private static function get_participant_subject( array $field_map ): string {
		$event_title = $field_map['event_title'] ?? 1;
		$start_date = $field_map['start_date'] ?? 131;

		return "[:es]Confirmación de reserva[:en]Booking Confirmation[:] | {Event:{$event_title}} | {Start date:{$start_date}}";
	}

	/**
	 * Get partner notification subject
	 */
	private static function get_partner_subject( array $field_map ): string {
		$event_title = $field_map['event_title'] ?? 1;
		$start_date = $field_map['start_date'] ?? 131;

		return "[:es]Nueva reserva con tu código[:en]New booking with your code[:] | {Event:{$event_title}} | {Start date:{$start_date}}";
	}

	/**
	 * Get admin notification subject
	 */
	private static function get_admin_subject( array $field_map ): string {
		$event_title = $field_map['event_title'] ?? 1;
		$start_date = $field_map['start_date'] ?? 131;
		$first_name = $field_map['participant_name_first'] ?? '2.3';

		return "[TCBF] New Booking | {Event:{$event_title}} | {Start date:{$start_date}} | {:{$first_name}}";
	}

	/**
	 * Load HTML template from file
	 *
	 * @param string $template_name Template filename
	 * @return string|null Template content or null if not found
	 */
	private static function load_template( string $template_name ): ?string {
		$template_path = TC_BF_PATH . 'templates/emails/' . $template_name;

		if ( ! file_exists( $template_path ) ) {
			return null;
		}

		$content = file_get_contents( $template_path );
		if ( $content === false ) {
			return null;
		}

		return $content;
	}

	/**
	 * Remove all TCBF notifications from a form
	 * (Use with caution - primarily for testing/reset)
	 *
	 * @param int $form_id GF form ID
	 * @return bool Success
	 */
	public static function remove_all( int $form_id ): bool {
		if ( ! class_exists( 'GFAPI' ) ) {
			return false;
		}

		$form = \GFAPI::get_form( $form_id );
		if ( ! $form || is_wp_error( $form ) ) {
			return false;
		}

		$notifications = $form['notifications'] ?? [];
		$updated = false;

		foreach ( array_keys( $notifications ) as $id ) {
			if ( strpos( $id, self::ID_PREFIX ) === 0 ) {
				unset( $notifications[ $id ] );
				$updated = true;
				Logger::log( "TCBF Notification Sync: Removed {$id} from form {$form_id}", [], 'info' );
			}
		}

		if ( $updated ) {
			$form['notifications'] = $notifications;
			$result = \GFAPI::update_form( $form );
			return ! is_wp_error( $result );
		}

		return true;
	}

	/**
	 * Get sync status for all configured forms
	 *
	 * @return array Status information
	 */
	public static function get_status(): array {
		$status = [
			'template_version' => self::TEMPLATE_VERSION,
			'forms'            => [],
		];

		if ( ! class_exists( 'GFAPI' ) ) {
			$status['error'] = 'Gravity Forms not active';
			return $status;
		}

		$forms = GF_Notification_Config::get_configured_forms();

		foreach ( $forms as $form_id => $config ) {
			$form = \GFAPI::get_form( $form_id );
			if ( ! $form || is_wp_error( $form ) ) {
				$status['forms'][ $form_id ] = [
					'exists' => false,
					'error'  => 'Form not found',
				];
				continue;
			}

			$notifications = $form['notifications'] ?? [];
			$tcbf_notifications = [];

			foreach ( $notifications as $id => $notif ) {
				if ( strpos( $id, self::ID_PREFIX ) === 0 ) {
					$tcbf_notifications[ $id ] = [
						'name'     => $notif['name'] ?? 'Unknown',
						'isActive' => $notif['isActive'] ?? false,
						'event'    => $notif['event'] ?? 'Unknown',
					];
				}
			}

			$status['forms'][ $form_id ] = [
				'exists'        => true,
				'title'         => $form['title'] ?? 'Unknown',
				'notifications' => $tcbf_notifications,
				'missing'       => array_diff(
					array_keys( self::TEMPLATES ),
					array_keys( $tcbf_notifications )
				),
			];
		}

		return $status;
	}
}
