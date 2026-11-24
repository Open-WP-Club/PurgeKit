<?php
namespace PurgeKit\Core;

interface HistoryWriter {
	/**
	 * @param Change[] $changes
	 * @return int The new row id, or 0 when unknown.
	 */
	public function record( string $source, Run $run, array $changes ): int;

	/** Attaches the post-flush verification outcome to an already recorded row. */
	public function annotate( int $id, array $verification ): void;
}
