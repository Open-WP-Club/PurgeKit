<?php
namespace PurgeKit\Tests\Fakes;

use PurgeKit\Core\Http;
use PurgeKit\Core\HttpException;
use PurgeKit\Core\HttpResponse;

final class FakeHttp implements Http {
	public array $requests = array();
	private array $queue   = array();

	public function queue( HttpResponse|HttpException ...$items ): self {
		array_push( $this->queue, ...$items );
		return $this;
	}

	public int $batches = 0;

	public function requestMany( string $method, array $urls, array $args = array() ): array {
		++$this->batches;
		$out = array();
		foreach ( $urls as $i => $url ) {
			try {
				$out[ $i ] = $this->request( $method, $url, $args );
			} catch ( HttpException $e ) {
				$out[ $i ] = $e;
			}
		}
		return $out;
	}

	public function request( string $method, string $url, array $args = array() ): HttpResponse {
		$this->requests[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
		$next = array_shift( $this->queue ) ?? new HttpResponse( 200, '{"success":true}' );
		if ( $next instanceof HttpException ) {
			throw $next;
		}
		return $next;
	}
}
