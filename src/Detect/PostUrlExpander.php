<?php
namespace PurgeKit\Detect;

use PurgeKit\Core\Change;
use PurgeKit\Core\UrlExpander;

/** Expands a post/term change into the archive URLs it affects. */
final class PostUrlExpander implements UrlExpander {
	public function expand( Change $change ): array {
		$urls = array();
		if ( isset( $change->context['post_id'] ) ) {
			$urls = $this->post_urls( (int) $change->context['post_id'] );
		} elseif ( isset( $change->context['term_id'], $change->context['taxonomy'] ) ) {
			$urls = $this->term_urls( (int) $change->context['term_id'], (string) $change->context['taxonomy'] );
		}
		return array_values( array_unique( array_filter( $urls ) ) );
	}

	private function post_urls( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}
		$urls = array( (string) get_permalink( $post ), home_url( '/' ), get_feed_link() );

		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
			if ( ! $tax->public ) {
				continue;
			}
			foreach ( (array) get_the_terms( $post, $tax->name ) as $term ) {
				$link = $term instanceof \WP_Term ? get_term_link( $term ) : null;
				if ( is_string( $link ) ) {
					$urls[] = $link;
				}
			}
		}

		$urls[] = get_author_posts_url( (int) $post->post_author );
		$time   = strtotime( $post->post_date );
		if ( $time ) {
			list( $y, $m, $d ) = array( (int) gmdate( 'Y', $time ), (int) gmdate( 'n', $time ), (int) gmdate( 'j', $time ) );
			array_push( $urls, get_year_link( $y ), get_month_link( $y, $m ), get_day_link( $y, $m, $d ) );
		}

		$filtered = apply_filters( 'purgekit_post_urls', $urls, $post_id );
		return is_array( $filtered ) ? $filtered : $urls;
	}

	private function term_urls( int $term_id, string $taxonomy ): array {
		$link = get_term_link( $term_id, $taxonomy );
		return array( home_url( '/' ), is_string( $link ) ? $link : '' );
	}
}
