<?php
namespace TC_BF\Integrations\GravityForms;

if ( ! defined('ABSPATH') ) exit;

/**
 * GF_FieldMap - Semantic Field ID Resolution for Gravity Forms
 *
 * Maps semantic keys (e.g., 'coupon_code', 'partner_user_id') to GF field IDs
 * using the field's inputName property.
 *
 * Resolution order:
 * 1. inputName exact match (case-sensitive)
 * 2. adminLabel exact match (case-sensitive fallback)
 *
 * Usage:
 *   $map = GF_FieldMap::for_form(44);
 *   $field_id = $map->get('coupon_code'); // returns int or null
 *   $field_id = $map->require('coupon_code'); // returns int or 0 with logging
 *
 * @since TCBF-14
 */
final class GF_FieldMap {

	/**
	 * In-memory cache of form maps (per-request)
	 * @var array<int, self>
	 */
	private static array $instances = [];

	/**
	 * Tracks whether fallback was logged this request (avoid spam)
	 * @var array<string, bool>
	 */
	private static array $logged_fallbacks = [];

	/**
	 * The form ID this map is for
	 */
	private int $form_id;

	/**
	 * Map of semantic key => field ID
	 * @var array<string, int>
	 */
	private array $map = [];

	/**
	 * Duplicate keys detected during build (validation errors)
	 * @var array<string, array>
	 */
	private array $duplicates = [];

	/**
	 * Whether the map was successfully built
	 */
	private bool $valid = false;

	/**
	 * Error message if map build failed
	 */
	private string $error = '';

	/**
	 * Get or create a field map for a form
	 *
	 * @param int $form_id GF form ID
	 * @return self
	 */
	public static function for_form( int $form_id ) : self {
		if ( ! isset( self::$instances[ $form_id ] ) ) {
			self::$instances[ $form_id ] = new self( $form_id );
		}
		return self::$instances[ $form_id ];
	}

	/**
	 * Clear cached instances (useful for testing or after form updates)
	 *
	 * @param int|null $form_id Specific form ID to clear, or null for all
	 */
	public static function clear_cache( ?int $form_id = null ) : void {
		if ( $form_id === null ) {
			self::$instances = [];
		} else {
			unset( self::$instances[ $form_id ] );
		}
	}

	/**
	 * Private constructor - use for_form() instead
	 */
	private function __construct( int $form_id ) {
		$this->form_id = $form_id;
		$this->build_map();
	}

	/**
	 * Get field ID for a semantic key
	 *
	 * @param string $key Semantic key (e.g., 'coupon_code')
	 * @return int|null Field ID or null if not found
	 */
	public function get( string $key ) : ?int {
		$key = trim( $key );
		return $this->map[ $key ] ?? null;
	}

	/**
	 * Get field ID for a semantic key, with logging if not found
	 *
	 * @param string $key Semantic key
	 * @return int Field ID or 0 if not found
	 */
	public function require( string $key ) : int {
		$key = trim( $key );
		$field_id = $this->map[ $key ] ?? null;

		if ( $field_id === null ) {
			self::log_missing_key( $this->form_id, $key );
			return 0;
		}

		return $field_id;
	}

	/**
	 * Check if a semantic key exists in this form
	 *
	 * @param string $key Semantic key
	 * @return bool
	 */
	public function has( string $key ) : bool {
		return isset( $this->map[ trim( $key ) ] );
	}

	/**
	 * Get all mapped keys for this form
	 *
	 * @return array<string, int>
	 */
	public function all() : array {
		return $this->map;
	}

	/**
	 * Check if the map is valid (no errors during build)
	 *
	 * @return bool
	 */
	public function is_valid() : bool {
		return $this->valid && empty( $this->duplicates );
	}

	/**
	 * Get validation errors
	 *
	 * @return array{valid: bool, error: string, duplicates: array}
	 */
	public function get_errors() : array {
		return [
			'valid'      => $this->valid,
			'error'      => $this->error,
			'duplicates' => $this->duplicates,
		];
	}

	/**
	 * Get debug report for this form's field map
	 *
	 * @return array
	 */
	public function debug_report() : array {
		return [
			'form_id'    => $this->form_id,
			'valid'      => $this->valid,
			'error'      => $this->error,
			'map'        => $this->map,
			'duplicates' => $this->duplicates,
			'key_count'  => count( $this->map ),
		];
	}

	/**
	 * Build the semantic key => field ID map from form metadata
	 */
	private function build_map() : void {
		if ( ! class_exists( '\\GFAPI' ) ) {
			$this->error = 'GFAPI not available';
			$this->valid = false;
			return;
		}

		try {
			$form = \GFAPI::get_form( $this->form_id );
		} catch ( \Throwable $e ) {
			$this->error = 'Failed to get form: ' . $e->getMessage();
			$this->valid = false;
			return;
		}

		if ( ! is_array( $form ) || empty( $form['fields'] ) ) {
			$this->error = 'Form not found or has no fields';
			$this->valid = false;
			return;
		}

		$input_name_map = [];  // inputName => field_id (primary)
		$admin_label_map = []; // adminLabel => field_id (fallback)

		foreach ( $form['fields'] as $field ) {
			$field_id    = $this->get_field_property( $field, 'id', 0 );
			$input_name  = trim( (string) $this->get_field_property( $field, 'inputName', '' ) );
			$admin_label = trim( (string) $this->get_field_property( $field, 'adminLabel', '' ) );

			if ( $field_id <= 0 ) {
				continue;
			}

			// Register inputName (primary)
			if ( $input_name !== '' ) {
				if ( isset( $input_name_map[ $input_name ] ) ) {
					// Duplicate inputName - record error
					$this->record_duplicate( $input_name, $field_id, $input_name_map[ $input_name ], 'inputName' );
				} else {
					$input_name_map[ $input_name ] = $field_id;
				}
			}

			// Register adminLabel (fallback, only if no inputName conflict)
			if ( $admin_label !== '' && ! isset( $input_name_map[ $admin_label ] ) ) {
				if ( isset( $admin_label_map[ $admin_label ] ) ) {
					// Duplicate adminLabel - record but don't error (lower priority)
					$this->record_duplicate( $admin_label, $field_id, $admin_label_map[ $admin_label ], 'adminLabel' );
				} else {
					$admin_label_map[ $admin_label ] = $field_id;
				}
			}
		}

		// Merge: inputName takes priority over adminLabel
		$this->map = $input_name_map;
		foreach ( $admin_label_map as $key => $field_id ) {
			if ( ! isset( $this->map[ $key ] ) ) {
				$this->map[ $key ] = $field_id;
			}
		}

		$this->valid = true;

		// Log duplicates as warnings
		if ( ! empty( $this->duplicates ) ) {
			self::log_duplicates( $this->form_id, $this->duplicates );
		}
	}

	/**
	 * Get a property from a field (handles both object and array)
	 *
	 * @param mixed $field GF field (object or array)
	 * @param string $prop Property name
	 * @param mixed $default Default value
	 * @return mixed
	 */
	private function get_field_property( $field, string $prop, $default = null ) {
		if ( is_object( $field ) ) {
			return $field->$prop ?? $default;
		}
		if ( is_array( $field ) ) {
			return $field[ $prop ] ?? $default;
		}
		return $default;
	}

	/**
	 * Record a duplicate key detection
	 */
	private function record_duplicate( string $key, int $field_id, int $existing_id, string $source ) : void {
		if ( ! isset( $this->duplicates[ $key ] ) ) {
			$this->duplicates[ $key ] = [
				'key'        => $key,
				'source'     => $source,
				'field_ids'  => [ $existing_id ],
			];
		}
		$this->duplicates[ $key ]['field_ids'][] = $field_id;
	}

	/**
	 * Log when a required key is missing
	 */
	private static function log_missing_key( int $form_id, string $key ) : void {
		$log_key = $form_id . ':' . $key;
		if ( isset( self::$logged_fallbacks[ $log_key ] ) ) {
			return; // Already logged this request
		}
		self::$logged_fallbacks[ $log_key ] = true;

		self::log( sprintf(
			'GF_FieldMap: missing key "%s" on form_id=%d',
			$key,
			$form_id
		) );
	}

	/**
	 * Log duplicate key detection
	 */
	private static function log_duplicates( int $form_id, array $duplicates ) : void {
		foreach ( $duplicates as $key => $info ) {
			self::log( sprintf(
				'GF_FieldMap: duplicate %s "%s" on form_id=%d, field_ids=[%s]',
				$info['source'],
				$key,
				$form_id,
				implode( ', ', $info['field_ids'] )
			) );
		}
	}

	/**
	 * Internal logging helper
	 */
	private static function log( string $msg ) : void {
		try {
			if ( class_exists( '\\TC_BF\\Admin\\Settings' ) && method_exists( '\\TC_BF\\Admin\\Settings', 'append_log' ) ) {
				\TC_BF\Admin\Settings::append_log( $msg );
			}
		} catch ( \Throwable $e ) {
			// Silent fail - never break due to logging
		}
	}

	/**
	 * Hook into GF form save to clear cache
	 */
	public static function register_cache_invalidation_hooks() : void {
		add_action( 'gform_after_save_form', function( $form ) {
			$form_id = is_array( $form ) ? ( $form['id'] ?? 0 ) : 0;
			if ( $form_id > 0 ) {
				self::clear_cache( $form_id );
			}
		}, 10, 1 );

		add_action( 'gform_post_form_duplicated', function( $form_id ) {
			self::clear_cache( (int) $form_id );
		}, 10, 1 );
	}
}
