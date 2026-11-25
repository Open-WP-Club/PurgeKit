<?php
namespace PurgeKit\Rest;

use PurgeKit\Core\Run;
use PurgeKit\Services;

abstract class AbstractController {
	public const NS = 'purgekit/v1';

	public function __construct( protected Services $services ) {}

	abstract public function register(): void;

	public function can(): bool {
		return current_user_can( apply_filters( 'purgekit_capability', 'manage_options' ) );
	}

	protected function run_response( Run $run ): \WP_REST_Response {
		$data = array(
			'status'       => $run->status,
			'all'          => (bool) ( $run->plan && $run->plan->all ),
			'urls'         => $run->plan ? $run->plan->urls : array(),
			'rules'        => $run->rules,
			'deferred'     => $run->deferred,
			'verification' => $run->verification,
			'results'      => array_map(
				static fn( $r ) => array(
					'layer'   => $r->layer,
					'status'  => $r->status,
					'message' => $r->message,
					'ms'      => $r->ms,
				),
				$run->results
			),
		);
		return new \WP_REST_Response( $data, Run::LOCKED === $run->status ? 409 : 200 );
	}

	/** Run a flush for the request: given URLs, else everything. */
	protected function flush_for( \WP_REST_Request $req, string $source ): \WP_REST_Response {
		$urls = array_values( array_filter( (array) $req->get_param( 'urls' ), 'is_string' ) );
		$run  = $urls ? $this->services->flusher->flushUrls( $source, $urls ) : $this->services->flusher->flushAll( $source );
		return $this->run_response( $run );
	}
}
