<?php
namespace PurgeKit\Core;

interface HistoryWriter {
	/** @param Change[] $changes */
	public function record( string $source, Run $run, array $changes ): void;
}
