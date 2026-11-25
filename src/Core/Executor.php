<?php
namespace PurgeKit\Core;

use PurgeKit\Layers\Registry;

final class Executor {
	/** @var callable(\PurgeKit\Layers\LayerInterface): ?string|null */
	private $skip;

	/** @param callable(\PurgeKit\Layers\LayerInterface): ?string|null $skip Returns a reason to leave a layer out (disabled, paused), or null. */
	public function __construct( private Registry $registry, private Clock $clock, ?callable $skip = null ) {
		$this->skip = $skip;
	}

	private function skipReason( \PurgeKit\Layers\LayerInterface $layer ): ?string {
		return $this->skip ? ( $this->skip )( $layer ) : null;
	}

	/**
	 * The layers a run would use, without running anything.
	 *
	 * @param string[]|null $only
	 * @return array<int,array{id:string,label:string,skipped:?string}>
	 */
	public function layersFor( ?array $only = null ): array {
		return array_map(
			fn( $l ) => array(
				'id'      => $l->id(),
				'label'   => $l->label(),
				'skipped' => $this->skipReason( $l ),
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

		$results  = array();
		$left_out = 0;
		foreach ( $layers as $layer ) {
			$why = $this->skipReason( $layer );
			if ( null !== $why ) {
				$results[] = new LayerResult( $layer->id(), LayerResult::SKIPPED, $why );
				++$left_out;
				continue;
			}
			$start = $this->clock->now();
			try {
				$result = $this->purge( $layer, $plan );
			} catch ( \Throwable $e ) {
				$result = new LayerResult( $layer->id(), LayerResult::FAILED, $e->getMessage() );
			}
			$result->ms = $this->clock->now() - $start;
			$results[]  = $result;
		}
		if ( count( $results ) === $left_out ) {
			return new Run( Run::SKIPPED, $results, $plan ); // Every layer was switched off or paused: nothing was purged.
		}
		return Run::fromResults( $results, $plan );
	}

	/** Everything, or the URLs, plus the cache tags on a layer that understands them. */
	private function purge( \PurgeKit\Layers\LayerInterface $layer, PurgePlan $plan ): LayerResult {
		if ( $plan->all ) {
			return $layer->purgeAll();
		}
		$tagged = array() !== $plan->tags && $layer instanceof \PurgeKit\Layers\TagPurger;
		if ( array() === $plan->urls && ! $tagged ) {
			return new LayerResult( $layer->id(), LayerResult::OK, 'Nothing to purge on this layer.' );
		}
		$result = array() === $plan->urls ? new LayerResult( $layer->id(), LayerResult::OK ) : $layer->purgeUrls( $plan->urls );
		if ( $tagged && LayerResult::OK === $result->status ) {
			$tags = $layer->purgeTags( $plan->tags );
			return LayerResult::OK === $tags->status ? new LayerResult( $layer->id(), LayerResult::OK, trim( $result->message . ' ' . $tags->message ) ) : $tags;
		}
		return $result;
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
