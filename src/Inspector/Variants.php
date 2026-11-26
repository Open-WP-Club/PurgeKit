<?php
namespace PurgeKit\Inspector;

/** Explicit anonymous cache variants; no arbitrary request headers or cookies. */
final class Variants {
	public const DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0 Safari/537.36';
	public const MOBILE  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1';

	/**
	 * @return array<string,string>
	 * @throws \InvalidArgumentException When the device or language is invalid.
	 */
	public static function headers( string $device, string $language ): array {
		if ( ! in_array( $device, array( 'desktop', 'mobile' ), true ) || ( '' !== $language && 1 !== preg_match( '/^[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{1,8})*$/', $language ) ) || strlen( $language ) > 40 ) {
			throw new \InvalidArgumentException( 'Choose desktop or mobile and a valid language code.' );
		}
		$out = array( 'User-Agent' => 'mobile' === $device ? self::MOBILE : self::DESKTOP );
		if ( '' !== $language ) {
			$out['Accept-Language'] = $language;
		}
		return $out;
	}
}
