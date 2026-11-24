<?php
namespace PurgeKit\Layers;

final class Registry {
	/** @var LayerInterface[] */
	private array $layers;

	/** @param LayerInterface[] $layers */
	public function __construct( array $layers ) {
		// usort is stable since PHP 8.0, so equal tiers keep registration order.
		$indexed = array_values( $layers );
		usort( $indexed, static fn( LayerInterface $a, LayerInterface $b ) => $a->tier() <=> $b->tier() );
		$this->layers = $indexed;
	}

	/** @return LayerInterface[] */
	public function all(): array {
		return $this->layers;
	}

	/** @return LayerInterface[] */
	public function active(): array {
		return array_values( array_filter( $this->layers, static fn( LayerInterface $l ) => $l->detect() ) );
	}

	public function find( string $id ): ?LayerInterface {
		foreach ( $this->layers as $layer ) {
			if ( $layer->id() === $id ) {
				return $layer;
			}
		}
		return null;
	}
}
