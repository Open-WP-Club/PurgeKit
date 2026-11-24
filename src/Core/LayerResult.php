<?php
namespace PurgeKit\Core;

final class LayerResult {
	public const OK      = 'ok';
	public const SKIPPED = 'skipped';
	public const FAILED  = 'failed';

	public function __construct(
		public string $layer,
		public string $status,
		public string $message = '',
		public int $ms = 0
	) {}
}
