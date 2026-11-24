<?php
namespace PurgeKit\Core;

final class Planner {
	private string $host;
	private string $home;

	/** @var string[] Regular expressions built from the exclusion patterns. */
	private array $exclude = array();

	/** @param string[] $exclude Path patterns ("/cart/*") that are never purged by an automatic or rule-driven flush. */
	public function __construct( private UrlExpander $expander, string $home_url, array $exclude = array() ) {
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
		foreach ( $changes as $change ) {
			if ( $change->site_wide ) {
				return PurgePlan::everything();
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
		return PurgePlan::forUrls( array_keys( $urls ) );
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
