<?php
namespace PurgeKit\Layers;

/** Small helpers for building header-based "did this layer serve it?" checks. */
final class Fingerprint {
	/** @param array<string, string|string[]> $headers */
	public static function value( array $headers, string $name ): string {
		$v = $headers[ strtolower( $name ) ] ?? '';
		return trim( is_array( $v ) ? implode( ', ', $v ) : (string) $v );
	}

	/** Matches a signature the plugin stamps into the HTML (usually a comment) of pages it caches. */
	public static function body( string $pattern, string $label ): callable {
		return static function ( array $headers, string $body = '' ) use ( $pattern, $label ): ?string {
			return '' !== $body && 1 === preg_match( $pattern, $body ) ? "$label (signature in page HTML)" : null;
		};
	}

	/** Checks that `$header` matches `$pattern` (e.g. a HIT value) and labels the result. */
	public static function header( string $header, string $pattern, string $label ): callable {
		return static function ( array $headers ) use ( $header, $pattern, $label ): ?string {
			$value = self::value( $headers, $header );
			return '' !== $value && 1 === preg_match( $pattern, $value ) ? "$label ($header: $value)" : null;
		};
	}
}
