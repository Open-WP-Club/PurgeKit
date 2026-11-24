<?php
namespace PurgeKit\Core;

final class Planner {
	private string $host;

	public function __construct( private UrlExpander $expander, string $home_url ) {
		$this->host = strtolower( (string) parse_url( $home_url, PHP_URL_HOST ) );
	}

	/** @param Change[] $changes */
	public function plan( array $changes ): PurgePlan {
		$urls = array();
		foreach ( $changes as $change ) {
			if ( $change->site_wide ) {
				return PurgePlan::everything();
			}
			foreach ( array_merge( $change->urls, $this->expander->expand( $change ) ) as $url ) {
				$clean = $this->normalise( (string) $url );
				if ( null !== $clean ) {
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
}
