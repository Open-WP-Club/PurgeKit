<?php
namespace PurgeKit\Detect;

/** Finds pages using shared blocks, template parts, Elementor templates and Query Loops. */
// Content may have just changed; cached dependency query results would be stale.
// phpcs:disable WordPress.DB.DirectDatabaseQuery
final class Dependencies {
	private const LIMIT = 500;

	/** @return array{urls:string[],all:bool} */
	public function affected( \WP_Post $changed ): array {
		global $wpdb;
		$shared = in_array( $changed->post_type, array( 'wp_block', 'wp_template', 'wp_template_part', 'elementor_library', 'et_pb_layout', 'fl-builder-template' ), true );
		// A bounded scan of relevant content, including builder data; overflow takes the safe full-purge route.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID, p.post_content FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON p.ID = m.post_id AND m.meta_key IN ('_elementor_data', '_fl_builder_data') WHERE p.post_status = 'publish' AND (p.post_content LIKE %s OR p.post_content LIKE %s OR p.post_content LIKE %s OR p.post_content LIKE %s OR m.meta_id IS NOT NULL) ORDER BY p.ID LIMIT %d",
				'%' . $wpdb->esc_like( '<!-- wp:query' ) . '%',
				'%' . $wpdb->esc_like( '<!-- wp:block ' ) . '%',
				'%' . $wpdb->esc_like( '<!-- wp:template-part ' ) . '%',
				'%' . $wpdb->esc_like( '[elementor-template' ) . '%',
				self::LIMIT + 1
			)
		);
		if ( count( $rows ) > self::LIMIT ) {
			return array(
				'urls' => array(),
				'all'  => true,
			);
		}
		$urls   = array();
		$ids    = array( $changed->ID );
		$global = false;
		// Fixed point: a shared block may reference another shared block.
		do {
			$found = false;
			foreach ( $rows as $row ) {
				$id = (int) $row->ID;
				if ( in_array( $id, $ids, true ) ) {
					continue;
				}
				$hit = $this->blocks( parse_blocks( $row->post_content ), $changed, $ids, $shared );
				if ( $shared && ! $hit ) {
					$data = get_post_meta( $id, '_elementor_data', true );
					$hit  = $this->builder( is_string( $data ) ? json_decode( $data, true ) : $data, $ids );
					if ( preg_match_all( '/\[elementor-template\s+[^\]]*id=["\x27]?(\d+)/', $row->post_content, $matches ) ) {
						$hit = $hit || array() !== array_intersect( $ids, array_map( 'intval', $matches[1] ) );
					}
					$hit = $hit || $this->builder( get_post_meta( $id, '_fl_builder_data', true ), $ids );
				}
				if ( $hit ) {
					$ids[]  = $id;
					$found  = true;
					$type   = get_post_type( $id );
					$global = $global || in_array( $type, array( 'wp_template', 'wp_template_part', 'et_pb_layout' ), true );
					if ( is_post_type_viewable( $type ) ) {
						$link = get_permalink( $id );
						if ( $link ) {
							$urls[] = $link;
						}
					}
				}
			}
		} while ( $found );
		// Global template assignment cannot always be mapped to URLs (theme conditions, builder display rules).
		$all = $global || in_array( $changed->post_type, array( 'wp_template', 'wp_template_part', 'et_pb_layout' ), true ) || ( 'elementor_library' === $changed->post_type && (bool) get_post_meta( $changed->ID, '_elementor_conditions', true ) );
		return array(
			'urls' => array_values( array_unique( $urls ) ),
			'all'  => $all,
		);
	}

	private function blocks( array $blocks, \WP_Post $changed, array $ids, bool $shared ): bool {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? '';
			$a    = $block['attrs'] ?? array();
			if ( ( ( 'core/block' === $name && in_array( (int) ( $a['ref'] ?? 0 ), $ids, true ) ) || ( $shared && 'core/template-part' === $name && ( $a['slug'] ?? '' ) === $changed->post_name ) ) ) {
				return true;
			}
			if ( ! $shared && 'core/query' === $name ) {
				$q = $a['query'] ?? array();
				// Deliberately include all loops of this type: old terms and offsets can also change their results.
				if ( ! empty( $q['inherit'] ) || ( $q['postType'] ?? 'post' ) === $changed->post_type ) {
					return true;
				}
			}
			if ( $this->blocks( $block['innerBlocks'] ?? array(), $changed, $ids, $shared ) ) {
				return true;
			}
		}
		return false;
	}

	private function builder( mixed $data, array $ids ): bool {
		foreach ( is_array( $data ) || is_object( $data ) ? (array) $data : array() as $key => $value ) {
			if ( in_array( $key, array( 'template_id', 'templateId', 'node_template_id' ), true ) && is_scalar( $value ) && in_array( (int) $value, $ids, true ) ) {
				return true;
			}
			if ( ( is_array( $value ) || is_object( $value ) ) && $this->builder( $value, $ids ) ) {
				return true;
			}
		}
		return false;
	}
}
