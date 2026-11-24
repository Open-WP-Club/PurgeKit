<?php
namespace PurgeKit\Layers;

use PurgeKit\Core\LayerResult;
use PurgeKit\Core\Store;

// Deleting nginx cache files is the whole point of this layer, and it runs outside WordPress's own file API.
// phpcs:disable WordPress.WP.AlternativeFunctions
/**
 * Purges an nginx `fastcgi_cache` (or `proxy_cache`) directly on disk. Needs no nginx module.
 *
 * Safety: only files whose name is a 32-character hex digest AND whose content carries nginx's
 * "KEY: " header are ever deleted, symlinks are never followed, and the configured directory must exist.
 * The PHP user must be able to write to the cache directory, which is not the case on every host.
 */
final class NginxFastcgiLayer extends AbstractLayer implements ServesFromCache {
	public const DEFAULT_KEY    = '$scheme$request_method$host$request_uri';
	public const DEFAULT_LEVELS = '1:2';

	public function __construct( private Store $settings ) {}

	public function id(): string {
		return 'nginx-fastcgi';
	}
	public function label(): string {
		return 'Nginx FastCGI cache';
	}
	public function tier(): int {
		return Tier::PROXY;
	}
	public function detect(): bool {
		return '' !== $this->path() && is_dir( $this->path() );
	}

	public function test(): LayerResult {
		$path = $this->path();
		if ( '' === $path ) {
			return new LayerResult( $this->id(), LayerResult::SKIPPED, 'Not detected' );
		}
		if ( ! is_dir( $path ) ) {
			return $this->fail( 'The cache directory does not exist or PHP cannot see it (open_basedir?).' );
		}
		if ( ! is_writable( $path ) ) {
			return $this->fail( 'The cache directory is not writable by the PHP user, so files cannot be purged.' );
		}
		return $this->ok();
	}

	public function purgeAll(): LayerResult {
		if ( ! $this->detect() ) {
			return $this->fail( 'Cache directory not found.' );
		}
		$deleted = 0;
		$failed  = 0;
		$iter    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->path(), \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::LEAVES_ONLY );
		foreach ( $iter as $file ) {
			if ( $this->is_cache_file( $file->getPathname(), null ) ) {
				if ( $this->delete( $file->getPathname() ) ) {
					++$deleted;
				} else {
					++$failed;
				}
			}
		}
		return $failed ? $this->fail( "Deleted $deleted files, could not delete $failed (permissions?)." ) : $this->ok( "$deleted cached pages removed." );
	}

	public function purgeUrls( array $urls ): LayerResult {
		if ( ! $this->detect() ) {
			return $this->fail( 'Cache directory not found.' );
		}
		$failed = 0;
		foreach ( $urls as $url ) {
			$key = $this->key_for( (string) $url );
			if ( null === $key ) {
				continue;
			}
			$file = $this->file_for( $key );
			if ( is_file( $file ) && $this->is_cache_file( $file, $key ) && ! $this->delete( $file ) ) {
				++$failed;
			}
		}
		return $failed ? $this->fail( "Could not delete $failed cache file(s) (permissions?)." ) : $this->ok();
	}

	public function servedFrom( array $headers, string $body = '' ): ?string {
		return Fingerprint::header( 'x-fastcgi-cache', '/^(HIT|STALE|UPDATING)$/i', 'Nginx FastCGI cache' )( $headers );
	}

	/** Builds the cache key nginx would use for a GET of this URL, from the configured key template. */
	public function key_for( string $url ): ?string {
		$parts = parse_url( $url ); // Not wp_parse_url(): keeps this class testable without WordPress.
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return null;
		}
		$path = ( $parts['path'] ?? '/' );
		$args = $parts['query'] ?? '';
		$uri  = $path . ( '' !== $args ? '?' . $args : '' );
		$host = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		return strtr(
			$this->template(),
			array(
				'$scheme'         => $parts['scheme'],
				'$request_method' => 'GET',
				'$host'           => $host,
				'$http_host'      => $host,
				'$request_uri'    => $uri,
				'$uri'            => $path,
				'$is_args'        => '' !== $args ? '?' : '',
				'$args'           => $args,
			)
		);
	}

	/** Path of the cache file nginx stores for a key, following the configured `levels`. */
	public function file_for( string $key ): string {
		$hash   = md5( $key );
		$dir    = rtrim( $this->path(), '/' );
		$offset = strlen( $hash );
		foreach ( $this->levels() as $len ) {
			$offset -= $len;
			$dir    .= '/' . substr( $hash, $offset, $len );
		}
		return $dir . '/' . $hash;
	}

	/** Checks writability first so a permission problem is reported as a failure, not as a PHP warning. */
	private function delete( string $file ): bool {
		return is_writable( dirname( $file ) ) && unlink( $file );
	}

	private function is_cache_file( string $file, ?string $key ): bool {
		if ( is_link( $file ) || ! is_file( $file ) || 1 !== preg_match( '/^[0-9a-f]{32}$/', basename( $file ) ) ) {
			return false;
		}
		$head = (string) file_get_contents( $file, false, null, 0, 4096 );
		if ( null !== $key ) {
			return str_contains( $head, "\nKEY: $key\n" );
		}
		return 1 === preg_match( "/\nKEY: [^\n]+\n/", $head );
	}

	private function path(): string {
		$path = trim( (string) $this->settings->get( 'nginx_cache_path', '' ) );
		return '' !== $path && str_starts_with( $path, '/' ) && ! str_contains( $path, '..' ) ? rtrim( $path, '/' ) : '';
	}

	private function template(): string {
		$t = trim( (string) $this->settings->get( 'nginx_cache_key', '' ) );
		return '' !== $t ? $t : self::DEFAULT_KEY;
	}

	/** @return int[] */
	private function levels(): array {
		$raw    = (string) $this->settings->get( 'nginx_cache_levels', '' );
		$raw    = '' !== trim( $raw ) ? trim( $raw ) : self::DEFAULT_LEVELS;
		$levels = array_map( 'intval', explode( ':', $raw ) );
		foreach ( $levels as $len ) {
			if ( $len < 1 || $len > 2 ) {
				return array( 1, 2 );
			}
		}
		return array_slice( $levels, 0, 3 );
	}
}
