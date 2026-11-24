<?php
namespace PurgeKit\Core;

use PurgeKit\Layers\Registry;

final class Executor {
	public function __construct( private Registry $registry, private Clock $clock ) {}

	public function run( PurgePlan $plan ): Run {
		$layers = $this->registry->active();
		if ( $plan->isEmpty() || array() === $layers ) {
			return new Run( Run::SKIPPED, array(), $plan );
		}

		$results = array();
		foreach ( $layers as $layer ) {
			$start = $this->clock->now();
			try {
				$result = $plan->all ? $layer->purgeAll() : $layer->purgeUrls( $plan->urls );
			} catch ( \Throwable $e ) {
				$result = new LayerResult( $layer->id(), LayerResult::FAILED, $e->getMessage() );
			}
			$result->ms = $this->clock->now() - $start;
			$results[]  = $result;
		}
		return Run::fromResults( $results, $plan );
	}
}
