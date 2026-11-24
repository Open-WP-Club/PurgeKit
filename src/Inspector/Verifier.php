<?php
namespace PurgeKit\Inspector;

use PurgeKit\Core\Run;

// This class runs without WordPress in unit tests, so wp_parse_url() is not available.
// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url
/**
 * After a purge, checks the home page and a couple of purged URLs and reports whether a cache layer
 * still serves a stale copy. It only ever requests URLs on the site's own host.
 */
final class Verifier {
	public const VERIFIED  = 'verified';
	public const STALE     = 'stale';
	public const UNCHECKED = 'unchecked';

	/** Pages checked per flush (each costs two requests in the Inspector). */
	public const MAX_PAGES = 3;

	private string $host;
	private string $home;

	public function __construct( private Inspector $inspector, string $home_url ) {
		$this->host = strtolower( (string) parse_url( $home_url, PHP_URL_HOST ) );
		$this->home = rtrim( $home_url, '/' ) . '/';
	}

	/** @return array{status:string,message:string,layer:?string,checked:array<int,array{url:string,verdict:string}>} */
	public function verify( Run $run ): array {
		$urls = array( $this->home );
		if ( $run->plan && ! $run->plan->all ) {
			foreach ( $run->plan->urls as $url ) {
				$url = (string) $url;
				if ( count( $urls ) >= self::MAX_PAGES ) {
					break;
				}
				if ( $this->isOwn( $url ) && ! in_array( $url, $urls, true ) ) {
					$urls[] = $url;
				}
			}
		}

		$checked = array();
		$stale   = null;
		$ok      = 0;
		foreach ( $urls as $url ) {
			$result    = $this->inspector->inspect( $url );
			$checked[] = array(
				'url'     => $url,
				'verdict' => $result['verdict'],
			);
			if ( Inspector::STALE === $result['verdict'] && null === $stale ) {
				$label = $result['layer'];
				foreach ( $result['served_by'] as $row ) {
					if ( $row['layer'] === $result['layer'] ) {
						$label = $row['label'];
					}
				}
				$stale = array(
					'layer' => $result['layer'],
					'label' => (string) $label,
					'url'   => $url,
				);
			}
			if ( Inspector::ERROR !== $result['verdict'] ) {
				++$ok;
			}
		}

		if ( $stale ) {
			return $this->outcome( self::STALE, "{$stale['label']} still serves a stale copy of {$stale['url']}.", $stale['layer'], $checked );
		}
		if ( 0 === $ok ) {
			return $this->outcome( self::UNCHECKED, 'Could not check: the pages did not respond.', null, $checked );
		}
		return $this->outcome( self::VERIFIED, sprintf( 'Verified %d of %d pages.', $ok, count( $urls ) ), null, $checked );
	}

	/** Same rule as the REST inspector: http(s), no credentials, this site's host only. */
	private function isOwn( string $url ): bool {
		$parts = parse_url( $url );
		return is_array( $parts )
			&& in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true )
			&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] )
			&& '' !== $this->host
			&& strtolower( $parts['host'] ?? '' ) === $this->host;
	}

	private function outcome( string $status, string $message, ?string $layer, array $checked ): array {
		return array(
			'status'  => $status,
			'message' => $message,
			'layer'   => $layer,
			'checked' => $checked,
		);
	}
}
