<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Minimal, fail-closed JSON Schema validation for MCP tool arguments. */
class RankOut_Connector_Schema_Validator {

	/** @return string Empty when valid, otherwise a user-safe validation error. */
	public static function validate( $value, array $schema, $path = 'arguments' ) {
		if ( isset( $schema['type'] ) && ! self::matches_type( $value, $schema['type'] ) ) {
			return sprintf( '%s must be %s.', $path, $schema['type'] );
		}
		if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			return sprintf( '%s must be one of: %s.', $path, implode( ', ', $schema['enum'] ) );
		}
		if ( 'object' !== ( $schema['type'] ?? null ) || ! is_array( $value ) ) {
			return '';
		}

		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		foreach ( $schema['required'] ?? array() as $required ) {
			if ( ! array_key_exists( $required, $value ) ) {
				return sprintf( '%s.%s is required.', $path, $required );
			}
		}
		foreach ( $value as $key => $child ) {
			if ( ! array_key_exists( $key, $properties ) ) {
				if ( ! array_key_exists( 'additionalProperties', $schema ) || false === $schema['additionalProperties'] ) {
					return sprintf( '%s.%s is not a supported field.', $path, $key );
				}
				if ( is_array( $schema['additionalProperties'] ) ) {
					$error = self::validate( $child, $schema['additionalProperties'], $path . '.' . $key );
					if ( $error ) {
						return $error;
					}
				}
				continue;
			}
			$error = self::validate( $child, $properties[ $key ], $path . '.' . $key );
			if ( $error ) {
				return $error;
			}
		}
		return '';
	}

	private static function matches_type( $value, $type ) {
		switch ( $type ) {
			case 'object': return is_array( $value ) && self::is_associative( $value );
			case 'array': return is_array( $value ) && ! self::is_associative( $value );
			case 'integer': return is_int( $value );
			case 'number': return is_int( $value ) || is_float( $value );
			case 'string': return is_string( $value );
			case 'boolean': return is_bool( $value );
			case 'null': return null === $value;
			default: return false;
		}
	}

	private static function is_associative( array $value ) {
		return empty( $value ) || array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}
}
