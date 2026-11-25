<?php
namespace PurgeKit\Core;

// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url -- runs without WordPress in unit tests.
/**
 * Adds a version to the address of the site's own CSS and JavaScript files, so browsers fetch them again.
 * A browser cache cannot be purged from the server, but a new address makes it irrelevant.
 */
final class AssetVersion {
	public const PARAM = 'pkv';

	/** @param string $host The site's own host; files on other hosts are left alone. */
	public static function rewrite( string $src, int $version, string $host ): string {
		if ( $version <= 0 || '' === $src ) {
			return $src;
		}
		$parts = parse_url( $src );
		if ( ! is_array( $parts ) ) {
			return $src;
		}
		$own          = ! isset( $parts['host'] ) ? isset( $parts['path'] ) : strtolower( $parts['host'] ) === strtolower( $host );
		$other_scheme = isset( $parts['scheme'] ) && ! in_array( $parts['scheme'], array( 'http', 'https' ), true );
		if ( ! $own || $other_scheme ) {
			return $src;
		}
		$fragment = '';
		$pos      = strpos( $src, '#' );
		if ( false !== $pos ) {
			$fragment = substr( $src, $pos );
			$src      = substr( $src, 0, $pos );
		}
		// Drop an older value, then add the current one.
		$src = (string) preg_replace( '/([?&])' . self::PARAM . '=[^&]*(&|$)/', '$1', $src );
		$src = rtrim( $src, '?&' );
		return $src . ( str_contains( $src, '?' ) ? '&' : '?' ) . self::PARAM . '=' . $version . $fragment;
	}
}
