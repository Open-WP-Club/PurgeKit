<?php
namespace PurgeKit\Core;

/** Compact storage of a purge's URL list so one huge purge does not create a huge history row. */
final class UrlList {
	public const MAX = 200;

	/** A plain JSON list while small; `{"total":N,"urls":[first MAX]}` once it exceeds MAX. */
	public static function encode( array $urls ): string {
		$urls = array_values( $urls );
		if ( count( $urls ) <= self::MAX ) {
			return (string) json_encode( $urls ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		}
		return (string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
			array(
				'total' => count( $urls ),
				'urls'  => array_slice( $urls, 0, self::MAX ),
			)
		);
	}

	/** @return array{urls: string[], total: int} Reads both the plain and the truncated format. */
	public static function decode( string $json ): array {
		$data = json_decode( $json, true );
		if ( is_array( $data ) && isset( $data['urls'] ) && is_array( $data['urls'] ) ) {
			return array(
				'urls'  => array_values( $data['urls'] ),
				'total' => max( count( $data['urls'] ), (int) ( $data['total'] ?? 0 ) ),
			);
		}
		$list = is_array( $data ) ? array_values( $data ) : array();
		return array(
			'urls'  => $list,
			'total' => count( $list ),
		);
	}
}
