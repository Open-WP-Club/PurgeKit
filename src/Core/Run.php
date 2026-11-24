<?php
namespace PurgeKit\Core;

final class Run {
	public const OK      = 'ok';
	public const PARTIAL = 'partial';
	public const FAILED  = 'failed';
	public const SKIPPED = 'skipped';
	public const LOCKED  = 'locked';

	public function __construct(
		public string $status,
		public array $results = array(),
		public ?PurgePlan $plan = null
	) {}

	public static function fromResults( array $results, PurgePlan $plan ): self {
		$failed = count( array_filter( $results, static fn( LayerResult $r ) => LayerResult::FAILED === $r->status ) );
		$ran    = count( array_filter( $results, static fn( LayerResult $r ) => LayerResult::SKIPPED !== $r->status ) );

		if ( 0 === $failed ) {
			$status = self::OK;
		} elseif ( $failed === $ran ) {
			$status = self::FAILED;
		} else {
			$status = self::PARTIAL;
		}
		return new self( $status, $results, $plan );
	}
}
