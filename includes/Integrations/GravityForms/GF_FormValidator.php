<?php
namespace TC_BF\Integrations\GravityForms;

if ( ! defined('ABSPATH') ) exit;

/**
 * GF_FormValidator - Validate Gravity Forms against required semantic fields
 *
 * Checks that configured forms have the required inputName fields set.
 * Provides admin visibility into form health and migration status.
 *
 * @since TCBF-14
 */
final class GF_FormValidator {

	// Form profiles
	const PROFILE_EVENT   = 'event';
	const PROFILE_BOOKING = 'booking';

	/**
	 * Required semantic keys per form profile
	 *
	 * These are the minimum fields required for each form type to function correctly.
	 */
	const REQUIRED_KEYS = [
		self::PROFILE_EVENT => [
			GF_SemanticFields::KEY_EVENT_ID,
			GF_SemanticFields::KEY_COUPON_CODE,
			GF_SemanticFields::KEY_PARTNER_OVERRIDE_CODE,
			GF_SemanticFields::KEY_PARTNER_USER_ID,
			GF_SemanticFields::KEY_PARTNER_DISCOUNT_PCT,
			GF_SemanticFields::KEY_PARTNER_COMMISSION_PCT,
			GF_SemanticFields::KEY_PARTNER_EMAIL,
		],
		self::PROFILE_BOOKING => [
			GF_SemanticFields::KEY_COUPON_CODE,
			GF_SemanticFields::KEY_PARTNER_OVERRIDE_CODE,
			GF_SemanticFields::KEY_LEDGER_BASE,
			GF_SemanticFields::KEY_LEDGER_EB_PCT,
			GF_SemanticFields::KEY_LEDGER_EB_AMOUNT,
			GF_SemanticFields::KEY_LEDGER_PARTNER_AMOUNT,
			GF_SemanticFields::KEY_LEDGER_TOTAL,
			GF_SemanticFields::KEY_LEDGER_COMMISSION,
		],
	];

	/**
	 * Transient key for storing validation notices
	 */
	const TRANSIENT_NOTICES = 'tcbf_form_validation_notices';

	/**
	 * Validate a form against a profile's required keys
	 *
	 * @param int    $form_id GF form ID
	 * @param string $profile Profile name (use PROFILE_* constants)
	 * @return array Validation result
	 */
	public static function validate( int $form_id, string $profile ) : array {
		$required_keys = self::REQUIRED_KEYS[ $profile ] ?? [];

		if ( empty( $required_keys ) ) {
			return [
				'valid'           => false,
				'error'           => 'Unknown profile: ' . $profile,
				'form_id'         => $form_id,
				'profile'         => $profile,
				'missing'         => [],
				'using_fallback'  => [],
				'using_inputname' => [],
			];
		}

		$map = GF_FieldMap::for_form( $form_id );
		$map_errors = $map->get_errors();

		$missing = [];
		$using_fallback = [];
		$using_inputname = [];

		foreach ( $required_keys as $key ) {
			$from_map = $map->get( $key );

			if ( $from_map !== null ) {
				// Found via inputName - good
				$using_inputname[ $key ] = $from_map;
				continue;
			}

			// Check if fallback exists
			$resolved = GF_SemanticFields::field_id( $form_id, $key );

			if ( $resolved !== null ) {
				$using_fallback[ $key ] = $resolved;
			} else {
				$missing[] = $key;
			}
		}

		return [
			'valid'           => empty( $missing ),
			'form_id'         => $form_id,
			'profile'         => $profile,
			'form_exists'     => $map->is_valid() || ! empty( $map_errors['error'] ),
			'missing'         => $missing,
			'using_fallback'  => $using_fallback,
			'using_inputname' => $using_inputname,
			'duplicates'      => $map_errors['duplicates'] ?? [],
		];
	}

	/**
	 * Validate all configured TCBF forms
	 *
	 * @return array Validation results for all forms
	 */
	public static function validate_all() : array {
		$results = [];

		// Event form
		$event_form_id = self::get_event_form_id();
		if ( $event_form_id > 0 ) {
			$results['event'] = self::validate( $event_form_id, self::PROFILE_EVENT );
		}

		// Booking form
		$booking_form_id = self::get_booking_form_id();
		if ( $booking_form_id > 0 ) {
			$results['booking'] = self::validate( $booking_form_id, self::PROFILE_BOOKING );
		}

		return $results;
	}

	/**
	 * Get overall health status
	 *
	 * @return array{status: string, message: string, details: array}
	 */
	public static function get_health_status() : array {
		$results = self::validate_all();

		$total_missing = 0;
		$total_fallback = 0;
		$total_inputname = 0;

		foreach ( $results as $result ) {
			$total_missing += count( $result['missing'] ?? [] );
			$total_fallback += count( $result['using_fallback'] ?? [] );
			$total_inputname += count( $result['using_inputname'] ?? [] );
		}

		if ( $total_missing > 0 ) {
			$status = 'error';
			$message = sprintf(
				__( '%d required field(s) missing - forms may not work correctly', 'tc-booking-flow-next' ),
				$total_missing
			);
		} elseif ( $total_fallback > 0 ) {
			$status = 'warning';
			$message = sprintf(
				__( '%d field(s) using legacy fallback - add inputName for full portability', 'tc-booking-flow-next' ),
				$total_fallback
			);
		} else {
			$status = 'good';
			$message = __( 'All forms properly configured with inputName', 'tc-booking-flow-next' );
		}

		return [
			'status'  => $status,
			'message' => $message,
			'details' => $results,
			'counts'  => [
				'missing'    => $total_missing,
				'fallback'   => $total_fallback,
				'inputname'  => $total_inputname,
			],
		];
	}

	/**
	 * Register hooks for admin notices
	 */
	public static function init() : void {
		// Show admin notices on form save
		add_action( 'gform_after_save_form', [ __CLASS__, 'on_form_save' ], 20, 2 );

		// Display stored notices
		add_action( 'admin_notices', [ __CLASS__, 'display_notices' ] );
	}

	/**
	 * Handle form save - validate and store notice if needed
	 *
	 * @param array $form      The form being saved
	 * @param bool  $is_new    Whether this is a new form
	 */
	public static function on_form_save( $form, $is_new ) : void {
		if ( ! is_array( $form ) || empty( $form['id'] ) ) {
			return;
		}

		$form_id = (int) $form['id'];

		// Check if this is one of our configured forms
		$event_form_id = self::get_event_form_id();
		$booking_form_id = self::get_booking_form_id();

		$profile = null;
		if ( $form_id === $event_form_id ) {
			$profile = self::PROFILE_EVENT;
		} elseif ( $form_id === $booking_form_id ) {
			$profile = self::PROFILE_BOOKING;
		}

		if ( $profile === null ) {
			return; // Not a TCBF form
		}

		// Clear cache for this form
		GF_FieldMap::clear_cache( $form_id );

		// Validate
		$result = self::validate( $form_id, $profile );

		// Store notice if there are issues
		if ( ! empty( $result['missing'] ) || ! empty( $result['using_fallback'] ) ) {
			self::store_notice( $form_id, $profile, $result );
		}
	}

	/**
	 * Store a validation notice for display
	 */
	private static function store_notice( int $form_id, string $profile, array $result ) : void {
		$notices = get_transient( self::TRANSIENT_NOTICES ) ?: [];

		$notices[ $form_id ] = [
			'form_id'  => $form_id,
			'profile'  => $profile,
			'result'   => $result,
			'time'     => time(),
		];

		set_transient( self::TRANSIENT_NOTICES, $notices, 60 ); // 60 seconds
	}

	/**
	 * Display stored admin notices
	 */
	public static function display_notices() : void {
		$notices = get_transient( self::TRANSIENT_NOTICES );

		if ( empty( $notices ) || ! is_array( $notices ) ) {
			return;
		}

		// Clear the transient
		delete_transient( self::TRANSIENT_NOTICES );

		foreach ( $notices as $notice ) {
			self::render_notice( $notice );
		}
	}

	/**
	 * Render a single admin notice
	 */
	private static function render_notice( array $notice ) : void {
		$result = $notice['result'] ?? [];
		$profile = $notice['profile'] ?? '';
		$form_id = $notice['form_id'] ?? 0;

		$missing = $result['missing'] ?? [];
		$fallback = $result['using_fallback'] ?? [];

		if ( empty( $missing ) && empty( $fallback ) ) {
			return;
		}

		$profile_label = $profile === self::PROFILE_EVENT
			? __( 'Event Form', 'tc-booking-flow-next' )
			: __( 'Booking Form', 'tc-booking-flow-next' );

		$class = ! empty( $missing ) ? 'notice-error' : 'notice-warning';

		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible">';
		echo '<p><strong>TCBF ' . esc_html( $profile_label ) . ' (#' . esc_html( (string) $form_id ) . '):</strong></p>';

		if ( ! empty( $missing ) ) {
			echo '<p style="color: #d63638;">';
			echo esc_html__( 'Missing fields (no inputName, no fallback):', 'tc-booking-flow-next' ) . ' ';
			echo '<code>' . esc_html( implode( '</code>, <code>', $missing ) ) . '</code>';
			echo '</p>';
		}

		if ( ! empty( $fallback ) ) {
			echo '<p style="color: #dba617;">';
			echo esc_html__( 'Using legacy fallback (add inputName for portability):', 'tc-booking-flow-next' ) . ' ';
			$items = [];
			foreach ( $fallback as $key => $fid ) {
				$items[] = $key . ' → field ' . $fid;
			}
			echo '<code>' . esc_html( implode( '</code>, <code>', $items ) ) . '</code>';
			echo '</p>';
		}

		echo '</div>';
	}

	/**
	 * Render Form Health section for settings page
	 */
	public static function render_health_section() : void {
		$health = self::get_health_status();
		$status = $health['status'];
		$details = $health['details'];

		$status_colors = [
			'good'    => '#00a32a',
			'warning' => '#dba617',
			'error'   => '#d63638',
		];

		$status_icons = [
			'good'    => '✓',
			'warning' => '⚠',
			'error'   => '✗',
		];

		?>
		<h3><?php echo esc_html__( 'Form Health', 'tc-booking-flow-next' ); ?></h3>

		<p style="font-size: 14px; color: <?php echo esc_attr( $status_colors[ $status ] ); ?>;">
			<strong><?php echo esc_html( $status_icons[ $status ] ); ?></strong>
			<?php echo esc_html( $health['message'] ); ?>
		</p>

		<table class="widefat striped" style="max-width: 900px; margin: 15px 0;">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Form', 'tc-booking-flow-next' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'tc-booking-flow-next' ); ?></th>
					<th><?php echo esc_html__( 'inputName', 'tc-booking-flow-next' ); ?></th>
					<th><?php echo esc_html__( 'Fallback', 'tc-booking-flow-next' ); ?></th>
					<th><?php echo esc_html__( 'Missing', 'tc-booking-flow-next' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $details as $profile => $result ) : ?>
					<?php
					$label = $profile === self::PROFILE_EVENT
						? __( 'Event Form', 'tc-booking-flow-next' )
						: __( 'Booking Form', 'tc-booking-flow-next' );

					$form_id = $result['form_id'] ?? 0;
					$missing_count = count( $result['missing'] ?? [] );
					$fallback_count = count( $result['using_fallback'] ?? [] );
					$inputname_count = count( $result['using_inputname'] ?? [] );

					if ( $missing_count > 0 ) {
						$row_status = 'error';
					} elseif ( $fallback_count > 0 ) {
						$row_status = 'warning';
					} else {
						$row_status = 'good';
					}
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $label ); ?></strong>
							<br><small>#<?php echo esc_html( (string) $form_id ); ?></small>
						</td>
						<td style="color: <?php echo esc_attr( $status_colors[ $row_status ] ); ?>;">
							<strong><?php echo esc_html( $status_icons[ $row_status ] ); ?></strong>
							<?php
							if ( $row_status === 'good' ) {
								echo esc_html__( 'OK', 'tc-booking-flow-next' );
							} elseif ( $row_status === 'warning' ) {
								echo esc_html__( 'Fallback', 'tc-booking-flow-next' );
							} else {
								echo esc_html__( 'Error', 'tc-booking-flow-next' );
							}
							?>
						</td>
						<td>
							<span style="color: #00a32a;"><?php echo esc_html( (string) $inputname_count ); ?></span>
							<?php if ( $inputname_count > 0 ) : ?>
								<details style="margin-top: 4px;">
									<summary style="cursor: pointer; font-size: 11px;"><?php echo esc_html__( 'show', 'tc-booking-flow-next' ); ?></summary>
									<code style="font-size: 10px; display: block; margin-top: 4px;">
										<?php
										foreach ( $result['using_inputname'] as $key => $fid ) {
											echo esc_html( $key ) . ' → ' . esc_html( (string) $fid ) . '<br>';
										}
										?>
									</code>
								</details>
							<?php endif; ?>
						</td>
						<td>
							<span style="color: <?php echo $fallback_count > 0 ? '#dba617' : '#666'; ?>;">
								<?php echo esc_html( (string) $fallback_count ); ?>
							</span>
							<?php if ( $fallback_count > 0 ) : ?>
								<details style="margin-top: 4px;">
									<summary style="cursor: pointer; font-size: 11px;"><?php echo esc_html__( 'show', 'tc-booking-flow-next' ); ?></summary>
									<code style="font-size: 10px; display: block; margin-top: 4px; color: #dba617;">
										<?php
										foreach ( $result['using_fallback'] as $key => $fid ) {
											echo esc_html( $key ) . ' → ' . esc_html( (string) $fid ) . '<br>';
										}
										?>
									</code>
								</details>
							<?php endif; ?>
						</td>
						<td>
							<span style="color: <?php echo $missing_count > 0 ? '#d63638' : '#666'; ?>;">
								<?php echo esc_html( (string) $missing_count ); ?>
							</span>
							<?php if ( $missing_count > 0 ) : ?>
								<details style="margin-top: 4px;">
									<summary style="cursor: pointer; font-size: 11px;"><?php echo esc_html__( 'show', 'tc-booking-flow-next' ); ?></summary>
									<code style="font-size: 10px; display: block; margin-top: 4px; color: #d63638;">
										<?php echo esc_html( implode( ', ', $result['missing'] ) ); ?>
									</code>
								</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p class="description">
			<?php echo esc_html__( 'inputName = field has inputName set (portable). Fallback = using hardcoded field ID (fragile). Missing = field not found.', 'tc-booking-flow-next' ); ?>
		</p>
		<?php
	}

	/**
	 * Get event form ID from settings
	 */
	private static function get_event_form_id() : int {
		if ( class_exists( '\\TC_BF\\Admin\\Settings' ) && method_exists( '\\TC_BF\\Admin\\Settings', 'get_form_id' ) ) {
			return \TC_BF\Admin\Settings::get_form_id();
		}
		return 44;
	}

	/**
	 * Get booking form ID from settings
	 */
	private static function get_booking_form_id() : int {
		if ( class_exists( '\\TC_BF\\Admin\\Settings' ) && method_exists( '\\TC_BF\\Admin\\Settings', 'get_booking_form_id' ) ) {
			return \TC_BF\Admin\Settings::get_booking_form_id();
		}
		return 55;
	}
}
