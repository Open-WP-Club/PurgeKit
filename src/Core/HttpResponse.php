<?php
namespace PurgeKit\Core;

final class HttpResponse {
	public function __construct( public int $status, public string $body = '', public array $headers = array() ) {}
}
