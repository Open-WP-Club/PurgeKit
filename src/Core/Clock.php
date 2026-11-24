<?php
namespace PurgeKit\Core;

interface Clock {
	/** Milliseconds, monotonic enough for durations. */
	public function now(): int;
}
