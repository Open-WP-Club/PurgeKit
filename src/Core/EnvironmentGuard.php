<?php
namespace PurgeKit\Core;

/**
 * Stops purges that reach outside this server (CDNs, hosted firewalls) when the site is not the real
 * production site. A staging copy cloned with the production API token would otherwise purge the
 * production CDN every time somebody edits a post on staging.
 */
final class EnvironmentGuard {
	/** Layers at or above this tier talk to a third party with account credentials. */
	public const EXTERNAL_TIER = 50;

	public function __construct(
		private string $environment,
		private string $host,
		private string $recorded_host,
		private bool $allowed
	) {}

	/** Why external purges are paused, or null when they may run. */
	public function reason(): ?string {
		if ( $this->allowed ) {
			return null;
		}
		if ( '' !== $this->environment && 'production' !== $this->environment ) {
			return "this site runs in the '{$this->environment}' environment";
		}
		if ( '' !== $this->recorded_host && '' !== $this->host && strtolower( $this->recorded_host ) !== strtolower( $this->host ) ) {
			return "the site address changed from {$this->recorded_host} to {$this->host} (a cloned or moved site?)";
		}
		return null;
	}

	/** Reason to skip a layer of this tier, or null. */
	public function reasonFor( int $tier ): ?string {
		$reason = $tier >= self::EXTERNAL_TIER ? $this->reason() : null;
		return null === $reason ? null : "Paused: $reason. Allow it in Settings if this is the real site.";
	}
}
