<?php
namespace PurgeKit\Core;

use PurgeKit\Inspector\SiteProbe;

/** The hourly job: samples the cache figures, reports new cache problems, and probes the site's pages. */
final class Monitor {
	private const STATE = 'health_state';

	/**
	 * @param callable(array<string,array{label:string,issue:string,message:string}>): void $notify Called with the problems that are new.
	 * @param callable(): string[]                                                          $urls   The pages to probe.
	 */
	public function __construct(
		private MetricSampler $sampler,
		private Store $store,
		private ?SiteProbe $probe,
		private $notify,
		private $urls
	) {}

	/** @return array{sampled:int,alerts:int,probed:bool} */
	public function tick( int $now, bool $alert_on_health, bool $probe_on ): array {
		$sampled = $this->sampler->sample( $now );
		$alerts  = array();
		if ( $alert_on_health ) {
			$step   = HealthWatch::step( (array) $this->store->get( self::STATE, array() ), $this->sampler->checks(), $now );
			$alerts = $step['alerts'];
			$this->store->set( self::STATE, $step['state'] );
			if ( array() !== $alerts ) {
				( $this->notify )( $alerts );
			}
		}
		$probed = false;
		if ( $probe_on && $this->probe ) {
			$probed = null !== $this->probe->run( ( $this->urls )(), $now );
		}
		return array(
			'sampled' => $sampled,
			'alerts'  => count( $alerts ),
			'probed'  => $probed,
		);
	}
}
