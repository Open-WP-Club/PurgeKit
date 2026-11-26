<?php
namespace PurgeKit\Detect;

use PurgeKit\Core\Change;
use PurgeKit\Core\Queue;

/** Turns WordPress events into queued Changes. */
final class Hooks {
	public function __construct( private Queue $queue, private ?PostUrlExpander $expander = null, private ?Dependencies $dependencies = null ) {}

	public function register(): void {
		add_action( 'pre_post_update', array( $this, 'before_post' ) );
		add_action( 'before_delete_post', array( $this, 'before_post' ) );
		add_action( 'edit_terms', array( $this, 'before_term' ), 10, 2 );
		add_action( 'pre_delete_term', array( $this, 'before_term' ), 10, 2 );
		add_action( 'delete_term_relationships', array( $this, 'before_post' ) );
		add_filter( 'update_post_metadata', array( $this, 'before_builder_meta' ), 10, 3 );
		add_filter( 'delete_post_metadata', array( $this, 'before_builder_meta' ), 10, 3 );
		add_action( 'added_post_meta', array( $this, 'on_builder_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'on_builder_meta' ), 10, 3 );
		add_action( 'set_object_terms', array( $this, 'on_relationships' ) );
		add_action( 'wp_after_insert_post', array( $this, 'after_post' ), 20 );
		add_action( 'transition_post_status', array( $this, 'on_post_status' ), 10, 3 );
		add_action( 'post_updated', array( $this, 'on_post_updated' ), 10, 3 );
		foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( $this, 'on_term' ), 10, 3 );
		}
		add_action( 'wp_insert_comment', array( $this, 'on_comment_insert' ), 10, 2 );
		add_action( 'transition_comment_status', array( $this, 'on_comment_status' ), 10, 3 );
		add_action( 'updated_option', array( $this, 'on_option' ) );
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock' ) );
		add_action( 'acf/save_post', array( $this, 'on_acf' ) );
		// WooCommerce starts and ends scheduled sales from cron, without any post status change.
		add_action( 'woocommerce_scheduled_sales', array( $this, 'sales_begin' ), 0 );
		add_action( 'woocommerce_scheduled_sales', array( $this, 'sales_end' ), 999 );
		foreach ( array( 'wp_create_nav_menu', 'wp_update_nav_menu', 'wp_update_nav_menu_item', 'wp_delete_nav_menu', 'customize_save_after', 'switch_theme', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete' ) as $hook ) {
			add_action( $hook, array( $this, 'on_site_wide' ) );
		}
	}

	/** Capture URLs and tags while the old author, date, slug and terms are still available. */
	public function before_post( int $post_id ): void {
		$this->after_post( $post_id );
		$post = get_post( $post_id );
		if ( ! $this->expander || ! $post || 'publish' !== $post->post_status || $this->ignored( $post ) ) {
			return;
		}
		$c = Change::forUrls(
			'post',
			array(),
			array(
				'post_id'   => $post_id,
				'post_type' => $post->post_type,
			)
		);
		$this->queue->add(
			Change::forUrls(
				'post',
				$this->expander->expand( $c ),
				$c->context + array(
					'snapshot' => true,
					'tags'     => $this->expander->tags( $c ),
				)
			)
		);
	}

	public function before_builder_meta( mixed $check, int $post_id, string $key ): mixed {
		if ( null === $check ) {
			$this->on_builder_meta( 1, $post_id, $key );
		}
		return $check;
	}

	public function on_builder_meta( int $meta_id, int $post_id, string $key ): void {
		if ( $meta_id > 0 && in_array( $key, array( '_elementor_data', '_fl_builder_data', '_elementor_conditions' ), true ) ) {
			$this->on_relationships( $post_id );
			$this->after_post( $post_id );
		}
	}

	public function on_relationships( int $post_id ): void {
		$post = get_post( $post_id );
		if ( $post && 'publish' === $post->post_status && ! $this->ignored( $post ) ) {
			$this->queue->add(
				Change::forUrls(
					'post',
					array(),
					array(
						'post_id'   => $post_id,
						'post_type' => $post->post_type,
					)
				)
			);
			$this->after_post( $post_id );
		}
	}

	public function before_term( int $term_id, string $taxonomy ): void {
		if ( ! $this->expander ) {
			return;
		}
		$c = Change::forUrls(
			'term',
			array(),
			array(
				'term_id'  => $term_id,
				'taxonomy' => $taxonomy,
			)
		);
		$this->queue->add(
			Change::forUrls(
				'term',
				$this->expander->expand( $c ),
				$c->context + array(
					'snapshot' => true,
					'tags'     => $this->expander->tags( $c ),
				)
			)
		);
	}

	public function after_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $this->dependencies || ! $post || 'publish' !== $post->post_status || ( ! in_array( $post->post_type, array( 'wp_block', 'wp_template', 'wp_template_part', 'elementor_library', 'et_pb_layout', 'fl-builder-template' ), true ) && $this->ignored( $post ) ) || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		$d       = $this->dependencies->affected( $post );
		$context = array(
			'post_id'    => $post_id,
			'post_type'  => $post->post_type,
			'dependency' => true,
		);
		if ( $d['all'] ) {
			$this->queue->add( Change::siteWide( 'dependency', $context ) );
		} elseif ( $d['urls'] ) {
			$this->queue->add( Change::forUrls( 'dependency', $d['urls'], $context ) );
		}
	}

	public function on_post_status( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}
		if ( $this->ignored( $post ) ) {
			return;
		}
		$this->queue->add(
			Change::forUrls(
				'post',
				array(),
				array(
					'post_id'   => $post->ID,
					'post_type' => $post->post_type,
				)
			)
		);
	}

	public function on_post_updated( int $post_id, \WP_Post $after, \WP_Post $before ): void {
		if ( 'publish' !== $before->post_status || $before->post_name === $after->post_name || $this->ignored( $after ) ) {
			return;
		}
		$old = get_permalink( $before );
		if ( $old ) {
			$this->queue->add( Change::forUrls( 'post_slug', array( $old ), array( 'post_id' => $post_id ) ) );
		}
	}

	public function on_term( int $term_id, int $tt_id = 0, string $taxonomy = '' ): void {
		$tax = get_taxonomy( $taxonomy );
		if ( $tax && $tax->public ) {
			$this->queue->add(
				Change::forUrls(
					'term',
					array(),
					array(
						'term_id'  => $term_id,
						'taxonomy' => $taxonomy,
					)
				)
			);
		}
	}

	public function on_comment_insert( int $comment_id, \WP_Comment $comment ): void {
		if ( '1' === (string) $comment->comment_approved ) {
			$this->queue_comment_post( $comment_id );
		}
	}

	public function on_comment_status( string $new_status, string $old_status, \WP_Comment $comment ): void {
		if ( 'approved' === $new_status || 'approved' === $old_status ) {
			$this->queue_comment_post( (int) $comment->comment_ID );
		}
	}

	public function on_option( string $option ): void {
		$watched = apply_filters(
			'purgekit_watched_options',
			array( 'blogname', 'blogdescription', 'page_on_front', 'show_on_front', 'permalink_structure', 'sidebars_widgets' )
		);
		if ( in_array( $option, (array) $watched, true ) ) {
			$this->queue->add( Change::siteWide( 'option', array( 'option' => $option ) ) );
		}
	}

	public function on_stock( mixed $product ): void {
		if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
			$this->queue->add( Change::forUrls( 'stock', array(), array( 'post_id' => (int) $product->get_id() ) ) );
		}
	}

	public function sales_begin(): void {
		add_action( 'woocommerce_update_product', array( $this, 'on_product_saved' ) );
	}

	public function sales_end(): void {
		remove_action( 'woocommerce_update_product', array( $this, 'on_product_saved' ) );
	}

	/** Only hooked while WooCommerce runs its scheduled sales, so it does not duplicate the normal post hooks. */
	public function on_product_saved( mixed $product_id ): void {
		$this->queue->add(
			Change::forUrls(
				'post',
				array(),
				array(
					'post_id'   => (int) $product_id,
					'post_type' => 'product',
				)
			)
		);
	}

	public function on_acf( mixed $post_id ): void {
		if ( 'options' === $post_id ) {
			$this->queue->add( Change::siteWide( 'acf_options' ) );
		}
	}

	public function on_site_wide(): void {
		$this->queue->add( Change::siteWide( current_action() ) );
	}

	private function queue_comment_post( int $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( $comment ) {
			$this->queue->add( Change::forUrls( 'comment', array(), array( 'post_id' => (int) $comment->comment_post_ID ) ) );
		}
	}

	private function ignored( \WP_Post $post ): bool {
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || 'auto-draft' === $post->post_status ) {
			return true;
		}
		$type = get_post_type_object( $post->post_type );
		if ( ! $type || ! $type->public ) {
			return true;
		}
		return in_array( $post->post_type, (array) apply_filters( 'purgekit_ignored_post_types', array() ), true );
	}
}
