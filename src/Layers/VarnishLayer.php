<?php
namespace PurgeKit\Layers;

use PurgeKit\Core\Http;
use PurgeKit\Core\HttpException;
use PurgeKit\Core\LayerResult;
use PurgeKit\Core\Store;

final class VarnishLayer extends AbstractLayer {
	public function __construct( private Http $http, private Store $settings, private string $home_url ) {}

	public function id(): string {
		return 'varnish';
	}
	public function label(): string {
		return 'Varnish';
	}
	public function tier(): int {
		return Tier::PROXY;
	}
	public function detect(): bool {
		return (bool) $this->settings->get( 'varnish_enabled', false );
	}

	public function purgeAll(): LayerResult {
		return $this->purge( rtrim( $this->home_url, '/' ) . '/.*', array( 'X-Purge-Method' => 'regex' ) );
	}

	public function purgeUrls( array $urls ): LayerResult {
		foreach ( $urls as $url ) {
			$result = $this->purge( (string) $url );
			if ( LayerResult::OK !== $result->status ) {
				return $result;
			}
		}
		return $this->ok();
	}

	private function purge( string $url, array $headers = array() ): LayerResult {
		try {
			$response = $this->http->request( 'PURGE', $url, array( 'headers' => $headers ) );
		} catch ( HttpException $e ) {
			return $this->fail( $e->getMessage() );
		}
		return $response->status >= 200 && $response->status < 300 ? $this->ok() : $this->fail( 'HTTP ' . $response->status );
	}
}
