<?php
namespace PurgeKit\Core;

final class Run {
	public const OK      = 'ok';
	public const PARTIAL = 'partial';
	public const FAILED  = 'failed';
	public const SKIPPED = 'skipped';
	public const LOCKED  = 'locked';

	/** @var string[] Names of the rules that decided this run. */
	public array $rules = array();
	/** @var array<int,array<string,mixed>> Bounded execution plan for history. */
	public array $jobs = array();
	/** @var int Jobs a rule postponed (they run later and are recorded as their own flush). */
	public int $deferred = 0;
	/** @var array<string,mixed>|null What the check after the flush found (Verifier output). */
	public ?array $verification = null;

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
