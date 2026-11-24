<?php
namespace PurgeKit\Layers;

/** Database transients behind a small interface, so the layer can be tested without a database. */
interface Transients {
	/** True when a persistent object cache holds transients, so the database has none to delete. */
	public function usesExternalCache(): bool;

	/** @return array{total:int,expired:int} */
	public function count(): array;

	/** Deletes every transient except the ones WordPress and PurgeKit need to keep; returns how many. */
	public function deleteAll(): int;
}
