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
		array_push( $urls, ...$this->archives( $post ), ...$this->ancestors( $post ), ...$this->translations( $post ), ...$this->sitemaps() );
		$time = strtotime( $post->post_date );
		if ( $time ) {
			list( $y, $m, $d ) = array( (int) gmdate( 'Y', $time ), (int) gmdate( 'n', $time ), (int) gmdate( 'j', $time ) );
			array_push( $urls, get_year_link( $y ), get_month_link( $y, $m ), get_day_link( $y, $m, $d ) );
		}

		$filtered = apply_filters( 'purgekit_post_urls', $urls, $post_id );
		return is_array( $filtered ) ? $filtered : $urls;
	}

	/** The custom post type archive and the blog page, plus the first pages of every listing (a new post shifts them). */
	private function archives( \WP_Post $post ): array {
		$lists = array( home_url( '/' ) );
		$link  = get_post_type_archive_link( $post->post_type );
		if ( is_string( $link ) ) {
			$lists[] = $link;
		}
		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page > 0 && 'post' === $post->post_type ) {
			$permalink = get_permalink( $posts_page );
			if ( $permalink ) {
				$lists[] = $permalink;
			}
		}
		$urls  = $lists;
		$depth = max( 0, min( 5, (int) apply_filters( 'purgekit_pagination_depth', 2 ) ) );
		if ( '' !== (string) get_option( 'permalink_structure' ) ) {
			foreach ( $lists as $list ) {
				for ( $page = 2; $page <= $depth + 1; $page++ ) {
					$urls[] = trailingslashit( $list ) . user_trailingslashit( "page/$page", 'paged' );
				}
			}
		}
		return $urls;
	}

	/** A parent page often lists its children, so changing a child changes the parent. */
	private function ancestors( \WP_Post $post ): array {
		$urls = array();
		foreach ( get_post_ancestors( $post ) as $id ) {
			$link = get_permalink( (int) $id );
			if ( $link ) {
				$urls[] = $link;
			}
		}
		return $urls;
	}

	/** The same content in other languages (Polylang, WPML), plus the language front pages. */
	private function translations( \WP_Post $post ): array {
		$urls = array();
		if ( function_exists( 'pll_get_post_translations' ) ) {
			foreach ( (array) pll_get_post_translations( $post->ID ) as $id ) {
				$link = get_permalink( (int) $id );
				if ( $link && (int) $id !== $post->ID ) {
					$urls[] = $link;
				}
			}
			if ( function_exists( 'pll_languages_list' ) && function_exists( 'pll_home_url' ) ) {
				foreach ( (array) pll_languages_list() as $lang ) {
					$urls[] = (string) pll_home_url( (string) $lang );
				}
			}
		} elseif ( has_filter( 'wpml_active_languages' ) ) {
			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own API.
			foreach ( array_keys( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) ) as $lang ) {
				$id = apply_filters( 'wpml_object_id', $post->ID, $post->post_type, false, $lang );
				if ( $id && (int) $id !== $post->ID ) {
					$link = get_permalink( (int) $id );
					if ( $link ) {
						$urls[] = $link;
					}
				}
				$urls[] = (string) apply_filters( 'wpml_permalink', home_url( '/' ), $lang );
			}
			// phpcs:enable
		}
		return $urls;
	}

	/** The sitemap indexes; sub-sitemaps are not known here, so SEO plugins should add theirs with the filter. */
	private function sitemaps(): array {
		return array_map( static fn( $path ) => home_url( '/' . $path ), \PurgeKit\Warm\SitemapReader::PATHS );
	}

	private function term_urls( int $term_id, string $taxonomy ): array {
		$link = get_term_link( $term_id, $taxonomy );
		return array( home_url( '/' ), is_string( $link ) ? $link : '' );
	}
}
