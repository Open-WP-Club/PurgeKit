<?php
namespace PurgeKit\Layers;

/**
 * A layer that can show live numbers about itself (entries, hit rate, memory). Local and cheap: a layer
 * that would have to call a remote service does not implement it.
 */
interface Metrics {
	/**
	 * A figure may carry a percent (0-100) and a meter kind: "usage" (high is bad, like memory) or "quality"
	 * (low is bad, like a hit rate), which the admin draws as a bar.
	 *
	 * @return array<int,array{label:string,value:string,percent?:float,meter?:string}> Empty when the cache is not reachable from this process. */
	public function metrics(): array;
}
