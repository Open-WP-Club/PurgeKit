<?php
namespace PurgeKit\Layers;

/** What WordPress's object cache is and, when it is Redis, what the server reports. Behind an interface so the layer can be tested without Redis. */
interface ObjectCacheInfo {
	/**
	 * @return array{persistent:bool,backend:string,driver:string,client:string,request_hits:int,request_misses:int,flush_scope:string}
	 *         backend is "redis" (also Valkey and other Redis-compatible servers), "memcached", "other" or "none".
	 */
	public function describe(): array;

	/**
	 * Normalised server figures, or null when there is no server to ask or it did not answer.
	 *
	 * @return array{name:string,version:string,used_memory:int,max_memory:int,policy:string,hits:int,misses:int,keys:int,evicted:int,clients:int,uptime:int}|null
	 */
	public function server(): ?array;

	/** False when a Redis connection is down, or Memcached clients are there but none answers; null when there is nothing to ask. */
	public function responding(): ?bool;
}
