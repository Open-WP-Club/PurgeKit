<?php
namespace PurgeKit\Layers;

use PurgeKit\Core\LayerResult;
use PurgeKit\Core\Store;

/**
 * Deletes database transients: temporary cached values (remote API answers, query results) that outlive
 * a code change. Off until switched on, and only when everything is purged: plugins rebuild them on
 * demand, but a full rebuild at once can be slow on a busy site. With a persistent object cache the
 * transients live there and the object cache layer already empties them.
 */
final class TransientsLayer extends AbstractLayer {
	public function __construct( private Store $settings, private Transients $transients ) {}

	public function id(): string {
		return 'transients';
	}

	public function label(): string {
		return 'Database transients';
	}

	public function tier(): int {
		return Tier::TRANSIENTS;
	}

	public function detect(): bool {
		return (bool) $this->settings->get( 'transients_enabled', false );
	}

	public function purgeUrls( array $urls ): LayerResult {
		return new LayerResult( $this->id(), LayerResult::SKIPPED, 'Transients are only deleted when everything is purged.' );
	}

	public function purgeAll(): LayerResult {
		if ( $this->transients->usesExternalCache() ) {
			return $this->ok( 'Transients live in the object cache here; the object cache layer empties them.' );
		}
		return $this->ok( sprintf( 'Deleted %d transients.', $this->transients->deleteAll() ) );
	}

	public function test(): LayerResult {
		if ( $this->transients->usesExternalCache() ) {
			return $this->ok( 'A persistent object cache holds the transients; there are none in the database.' );
		}
		$count = $this->transients->count();
		return $this->ok( sprintf( '%d transients in the database, %d of them expired.', $count['total'], $count['expired'] ) );
	}
}
