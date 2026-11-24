<?php
namespace PurgeKit\Core;

interface Http {
	/** @throws HttpException On transport failure. */
	public function request( string $method, string $url, array $args = array() ): HttpResponse;

	/**
	 * Fire the same request at many URLs concurrently.
	 *
	 * @param string[] $urls
	 * @return array<int, HttpResponse|HttpException> Same order and keys as $urls; failures are returned, not thrown.
	 */
	public function requestMany( string $method, array $urls, array $args = array() ): array;
}
