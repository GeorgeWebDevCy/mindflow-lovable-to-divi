<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DMF_Divi_Import_Logger {

	const OPTION_KEY = 'dmf_divi_importer_log';

	const MAX_ENTRIES = 200;

	private static $request_id = null;

	public static function log( $level, $message, array $context = [] ) {
		$level   = self::normalize_level( $level );
		$message = trim( (string) $message );

		if ( '' === $message ) {
			return;
		}

		$entries = self::get_all_entries();
		array_unshift(
			$entries,
			[
				'timestamp'  => current_time( 'mysql' ),
				'level'      => $level,
				'message'    => $message,
				'context'    => self::sanitize_context( $context ),
				'request_id' => self::get_request_id(),
				'user_id'    => get_current_user_id(),
			]
		);

		$entries = array_slice( $entries, 0, self::MAX_ENTRIES );
		self::persist_entries( $entries );
	}

	public static function get_entries( $limit = 100 ) {
		$limit = max( 1, (int) $limit );

		return array_slice( self::get_all_entries(), 0, $limit );
	}

	public static function count() {
		return count( self::get_all_entries() );
	}

	public static function clear() {
		delete_option( self::OPTION_KEY );
		self::$request_id = null;
	}

	private static function get_all_entries() {
		$entries = get_option( self::OPTION_KEY, [] );

		return is_array( $entries ) ? array_values( $entries ) : [];
	}

	private static function persist_entries( array $entries ) {
		if ( false === get_option( self::OPTION_KEY, false ) ) {
			add_option( self::OPTION_KEY, $entries, '', false );
			return;
		}

		update_option( self::OPTION_KEY, $entries, false );
	}

	private static function get_request_id() {
		if ( null === self::$request_id ) {
			self::$request_id = function_exists( 'wp_generate_uuid4' )
				? wp_generate_uuid4()
				: uniqid( 'dmf_', true );
		}

		return self::$request_id;
	}

	private static function normalize_level( $level ) {
		$level = strtolower( trim( (string) $level ) );

		if ( ! in_array( $level, [ 'info', 'warning', 'error' ], true ) ) {
			return 'info';
		}

		return $level;
	}

	private static function sanitize_context( array $context ) {
		$sanitized = [];

		foreach ( $context as $key => $value ) {
			$sanitized[ sanitize_key( (string) $key ) ] = self::sanitize_value( $value );
		}

		return $sanitized;
	}

	private static function sanitize_value( $value ) {
		if ( is_null( $value ) || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return self::clip_string( $value );
		}

		if ( is_array( $value ) ) {
			$sanitized = [];

			foreach ( $value as $key => $item ) {
				$sanitized[ is_int( $key ) ? $key : sanitize_key( (string) $key ) ] = self::sanitize_value( $item );
			}

			return $sanitized;
		}

		if ( $value instanceof WP_Error ) {
			return [
				'type'    => 'WP_Error',
				'code'    => $value->get_error_code(),
				'message' => $value->get_error_message(),
			];
		}

		if ( is_object( $value ) ) {
			if ( $value instanceof WP_Post ) {
				return [
					'type'      => 'WP_Post',
					'id'        => (int) $value->ID,
					'post_type' => (string) $value->post_type,
					'post_name' => (string) $value->post_name,
					'title'     => (string) $value->post_title,
				];
			}

			if ( method_exists( $value, '__toString' ) ) {
				return self::clip_string( (string) $value );
			}

			return [
				'type' => get_class( $value ),
			];
		}

		return self::clip_string( (string) wp_json_encode( $value ) );
	}

	private static function clip_string( $value ) {
		$value = (string) $value;

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, 4000 );
		}

		return substr( $value, 0, 4000 );
	}
}
