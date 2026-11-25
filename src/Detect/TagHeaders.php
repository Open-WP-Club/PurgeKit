<?php
namespace PurgeKit\Detect;

use PurgeKit\Settings\Settings;

/**
 * Puts cache tags on the page: `Surrogate-Key` (Fastly and others) and `Cache-Tag` (Cloudflare Enterprise).
 * The names match PostUrlExpander::tags(), so a changed post can be purged by tag.
 */
final class TagHeaders {
	public const MAX = 60;

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		// After the main query has run (template_redirect), and before any output.
		add_action( 'template_redirect', array( $this, 'send' ), 99 );
	}

	public function send(): void {
		if ( ! $this->settings->cacheTags() || headers_sent() || is_admin() || is_404() || is_search() ) {
			return;
		}
		$tags = apply_filters( 'purgekit_page_tags', $this->tags() );
		$tags = array_slice( array_values( array_unique( array_filter( (array) $tags, static fn( $t ) => is_string( $t ) && 1 === preg_match( '/^[A-Za-z0-9_\-]{1,64}$/', $t ) ) ) ), 0, self::MAX );
		if ( array() === $tags ) {
			return;
		}
		$list = implode( ' ', $tags );
		header( 'Surrogate-Key: ' . $list );
		header( 'Cache-Tag: ' . implode( ',', $tags ) );
	}

	/** The tags of the page being served (the main query must have run). @return string[] */
	public function tags(): array {
		global $wp_query;
		$tags = array();
		if ( is_front_page() || is_home() ) {
			$tags[] = 'home';
		}
		if ( is_feed() && ! is_singular() ) {
			$tags[] = 'home';
		}
		$object = get_queried_object();
		if ( ( is_category() || is_tag() || is_tax() ) && $object instanceof \WP_Term ) {
			$tags[] = 't-' . $object->term_id;
		}
		if ( is_author() ) {
			$tags[] = 'a-' . (int) get_queried_object_id();
		}
		if ( is_post_type_archive() ) {
			$type   = get_query_var( 'post_type' );
			$tags[] = 'pt-' . ( is_array( $type ) ? (string) reset( $type ) : (string) $type );
		}
		if ( is_singular() ) {
			$tags[] = 'p-' . (int) get_queried_object_id();
		}
		if ( $wp_query instanceof \WP_Query ) {
			foreach ( array_slice( (array) $wp_query->posts, 0, self::MAX ) as $post ) {
				if ( $post instanceof \WP_Post ) {
					$tags[] = 'p-' . $post->ID;
				}
			}
		}
		return $tags;
	}
}
