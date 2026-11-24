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
		// A variation has no page of its own; what changes is its product.
		if ( $post && 'product_variation' === $post->post_type && $post->post_parent > 0 ) {
			$post = get_post( (int) $post->post_parent );
		}
		if ( ! $post ) {
			return array();
		}
		$urls = array( (string) get_permalink( $post ), home_url( '/' ), get_feed_link() );

		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $tax ) {
			if ( ! $tax->public ) {
				continue;
			}
			foreach ( (array) get_the_terms( $post, $tax->name ) as $term ) {
				if ( $term instanceof \WP_Term ) {
					array_push( $urls, ...$this->term_pages( $term->term_id, $tax->name ) );
				}
			}
		}
		array_push( $urls, ...$this->shop( $post ) );

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
		return $this->paged( $lists );
	}

	/** The listing itself plus its first pages: a new or changed item can shift everything after it. */
	private function paged( array $lists ): array {
		global $wp_rewrite;
		$urls  = $lists;
		$depth = max( 0, min( 5, (int) apply_filters( 'purgekit_pagination_depth', 2 ) ) );
		foreach ( $lists as $list ) {
			for ( $page = 2; $page <= $depth + 1; $page++ ) {
				// Pretty permalinks use /page/2/, plain ones ?paged=2; building the wrong form would purge nothing.
				$urls[] = $wp_rewrite->using_permalinks()
					? trailingslashit( $list ) . user_trailingslashit( $wp_rewrite->pagination_base . "/$page", 'paged' )
					: add_query_arg( 'paged', $page, $list );
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

	/** A term's archive, the archives of its parents (they list the same items) and their first pages. */
	private function term_pages( int $term_id, string $taxonomy ): array {
		$links = array();
		foreach ( array_merge( array( $term_id ), array_map( 'intval', get_ancestors( $term_id, $taxonomy, 'taxonomy' ) ) ) as $id ) {
			$link = get_term_link( $id, $taxonomy );
			if ( is_string( $link ) ) {
				$links[] = $link;
			}
		}
		return $this->paged( $links );
	}

	/** WooCommerce: the shop page and the products shown next to this one (upsells and cross-sells). */
	private function shop( \WP_Post $post ): array {
		$urls = array();
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$urls[] = (string) wc_get_page_permalink( 'shop' );
		}
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			if ( is_object( $product ) && method_exists( $product, 'get_upsell_ids' ) && method_exists( $product, 'get_cross_sell_ids' ) ) {
				foreach ( array_slice( array_merge( (array) $product->get_upsell_ids(), (array) $product->get_cross_sell_ids() ), 0, 20 ) as $id ) {
					$link = get_permalink( (int) $id );
					if ( $link ) {
						$urls[] = $link;
					}
				}
			}
		}
		return $urls;
	}

	private function term_urls( int $term_id, string $taxonomy ): array {
		return array_merge( array( home_url( '/' ) ), $this->term_pages( $term_id, $taxonomy ) );
	}
}
