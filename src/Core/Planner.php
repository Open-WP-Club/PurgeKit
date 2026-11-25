<?php
namespace PurgeKit\Core;

final class Planner {
	private string $host;
	private string $home;

	/** @var string[] Regular expressions built from the exclusion patterns. */
	private array $exclude = array();

	/** @var callable(Change): string[]|null */
	private $tagger;

	/**
	 * @param string[]                         $exclude Path patterns ("/cart/*") that are never purged by an automatic or rule-driven flush.
	 * @param callable(Change): string[]|null  $tagger  Names the cache tags a change invalidates. Null turns tag purging off.
	 */
	public function __construct( private UrlExpander $expander, string $home_url, array $exclude = array(), ?callable $tagger = null ) {
		$this->tagger = $tagger;
		foreach ( $exclude as $pattern ) {
			$pattern = trim( (string) $pattern );
			if ( '' !== $pattern ) {
				$this->exclude[] = '#^' . str_replace( '\\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
			}
		}
		$this->host = strtolower( (string) parse_url( $home_url, PHP_URL_HOST ) );
		$this->home = rtrim( $home_url, '/' ) . '/';
	}

	public function home(): string {
		return $this->home;
	}

	/** @param Change[] $changes */
	public function plan( array $changes ): PurgePlan {
		$urls = array();
		$tags = array();
		foreach ( $changes as $change ) {
			if ( $change->site_wide ) {
				return PurgePlan::everything();
			}
			if ( $this->tagger ) {
				foreach ( ( $this->tagger )( $change ) as $tag ) {
					$tags[ (string) $tag ] = true;
				}
			}
			// A URL somebody asked for by hand, or a rule's own target, is never filtered out.
			$explicit = in_array( $change->type, array( 'manual', 'rule' ), true );
			foreach ( array_merge( $change->urls, $this->expander->expand( $change ) ) as $url ) {
				$clean = $this->normalise( (string) $url );
				if ( null !== $clean && ( $explicit || ! $this->excluded( $clean ) ) ) {
					$urls[ $clean ] = true;
				}
			}
		}
		return PurgePlan::forUrls( array_keys( $urls ), array_keys( $tags ) );
	}

	/**
	 * The simplest tagger: the post's own tag and the term's own tag. A page carries the tag of every post it
	 * lists, so one tag reaches the post and every archive, feed and home page that shows it.
	 *
	 * @return string[]
	 */
	public static function tagsFor( Change $change ): array {
		$out = array();
		if ( isset( $change->context['post_id'] ) && (int) $change->context['post_id'] > 0 ) {
			$out[] = 'p-' . (int) $change->context['post_id'];
		}
		if ( isset( $change->context['term_id'] ) && (int) $change->context['term_id'] > 0 ) {
			$out[] = 't-' . (int) $change->context['term_id'];
		}
		return $out;
	}

	private function normalise( string $url ): ?string {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) ) {
			return null;
		}
		if ( strtolower( $parts['host'] ?? '' ) !== $this->host ) {
			return null;
		}
		unset( $parts['fragment'] );
		return $this->build( $parts );
	}

	private function build( array $p ): string {
		return $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' )
			. ( $p['path'] ?? '' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
	}

	private function excluded( string $url ): bool {
		$parts = parse_url( $url );
		$path  = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		foreach ( $this->exclude as $regex ) {
			if ( 1 === preg_match( $regex, $path ) ) {
				return true;
			}
		}
		return false;
	}
}
