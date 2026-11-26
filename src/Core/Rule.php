<?php
namespace PurgeKit\Core;

/** One saved flush rule: when a kind of change happens, do this on these layers. */
final class Rule {
	public const DEFAULT = 'default';
	public const ALL     = 'all';
	public const HOME    = 'home';
	public const SKIP    = 'skip';

	public const ACTIONS = array( self::DEFAULT, self::ALL, self::HOME, self::SKIP );

	/** When a rule's job runs: always, or only when an earlier job of the same flush had a failing layer. */
	public const ALWAYS     = 'always';
	public const ON_FAILURE = 'on_failure';
	public const WHEN       = array( self::ALWAYS, self::ON_FAILURE );

	/** Longest wait a rule can ask for, in seconds. */
	public const MAX_DELAY = 3600;

	/** Change types a rule can listen for, with a label for the UI. */
	public const TRIGGERS = array(
		'dependency'                => 'A shared block, template or Query Loop dependency changes',
		'post'                      => 'A post or page is published, updated or unpublished',
		'post_slug'                 => 'A published post changes its URL',
		'term'                      => 'A category, tag or other term changes',
		'comment'                   => 'A comment is approved or unapproved',
		'option'                    => 'A watched site setting changes (title, front page, permalinks, widgets)',
		'stock'                     => 'WooCommerce stock changes',
		'acf_options'               => 'ACF options page is saved',
		'wp_update_nav_menu'        => 'A menu is saved',
		'wp_create_nav_menu'        => 'A menu is created',
		'wp_delete_nav_menu'        => 'A menu is deleted',
		'customize_save_after'      => 'The Customizer is saved',
		'switch_theme'              => 'The theme is switched',
		'activated_plugin'          => 'A plugin is activated',
		'deactivated_plugin'        => 'A plugin is deactivated',
		'upgrader_process_complete' => 'Something is updated or installed',
	);

	/**
	 * @param string[] $on         Change types; empty means any.
	 * @param string[] $post_types Only for post-like changes; empty means any.
	 * @param string[] $layers     Layer ids to run; empty means every active layer.
	 */
	public function __construct(
		public string $id,
		public string $name,
		public bool $enabled,
		public array $on,
		public array $post_types,
		public string $action,
		public array $layers,
		public int $delay = 0,
		public string $when = self::ALWAYS
	) {}

	public function matches( Change $change ): bool {
		if ( ! $this->enabled ) {
			return false;
		}
		if ( array() !== $this->on && ! in_array( $change->type, $this->on, true ) ) {
			return false;
		}
		if ( array() !== $this->post_types ) {
			$type = $change->context['post_type'] ?? null;
			return is_string( $type ) && in_array( $type, $this->post_types, true );
		}
		return true;
	}

	public function toArray(): array {
		return array(
			'id'         => $this->id,
			'name'       => $this->name,
			'enabled'    => $this->enabled,
			'on'         => $this->on,
			'post_types' => $this->post_types,
			'action'     => $this->action,
			'layers'     => $this->layers,
			'delay'      => $this->delay,
			'when'       => $this->when,
		);
	}
}
