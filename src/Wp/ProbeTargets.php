<?php
namespace PurgeKit\Wp;

/** The pages the hourly site check looks at: the home page, the newest posts and pages, and the shop page if there is one. */
final class ProbeTargets {
	/** @return string[] */
	public static function urls(): array {
		$urls = array( home_url( '/' ) );
		foreach ( array(
			'post' => 3,
			'page' => 2,
		) as $type => $n ) {
			foreach ( get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => 'publish',
					'numberposts'      => $n,
					'orderby'          => 'date',
					'order'            => 'DESC',
					'has_password'     => false,
					'suppress_filters' => true,
				)
			) as $post ) {
				$link = get_permalink( $post );
				if ( $link ) {
					$urls[] = $link;
				}
			}
		}
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$shop = wc_get_page_permalink( 'shop' );
			if ( $shop ) {
				$urls[] = $shop;
			}
		}
		$urls = (array) apply_filters( 'purgekit_probe_urls', $urls );
		$home = wp_parse_url( home_url() );
		$out  = array();
		foreach ( $urls as $url ) {
			$p = is_string( $url ) ? wp_parse_url( $url ) : false;
			// Only this site: the check must never become a way to make the server fetch other addresses.
			if ( is_array( $p ) && is_array( $home ) && ! empty( $p['host'] ) && 0 === strcasecmp( $p['host'], (string) ( $home['host'] ?? '' ) ) && in_array( $p['scheme'] ?? '', array( 'http', 'https' ), true ) && ! isset( $p['user'] ) ) {
				$out[ $url ] = $url;
			}
		}
		return array_values( $out );
	}
}
