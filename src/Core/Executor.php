<?php
namespace PurgeKit\Core;

use PurgeKit\Layers\Registry;

final class Executor {
	public function __construct( private Registry $registry, private Clock $clock ) {}

	/**
	 * The layers a run would use, without running anything.
	 *
	 * @param string[]|null $only
	 * @return array<int,array{id:string,label:string}>
	 */
	public function layersFor( ?array $only = null ): array {
		return array_map(
			static fn( $l ) => array(
				'id'    => $l->id(),
				'label' => $l->label(),
			),
			$this->select( $only )
		);
	}

	/** @param string[]|null $only Run just these layer ids; null runs every active layer. */
	public function run( PurgePlan $plan, ?array $only = null ): Run {
		$layers = $this->select( $only );
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

	/**
	 * @param string[]|null $only
	 * @return \PurgeKit\Layers\LayerInterface[]
	 */
	private function select( ?array $only ): array {
		$layers = $this->registry->active();
		if ( null !== $only ) {
			$layers = array_values( array_filter( $layers, static fn( $l ) => in_array( $l->id(), $only, true ) ) );
		}
		return $layers;
	}
}
