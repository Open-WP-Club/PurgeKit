<?php
namespace PurgeKit\Core;

interface Http {
	/** @throws HttpException On transport failure. */
	public function request( string $method, string $url, array $args = array() ): HttpResponse;
}
