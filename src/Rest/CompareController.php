<?php
namespace PurgeKit\Rest;

use PurgeKit\Wp\CompareData;
use PurgeKit\Wp\ProbeTargets;

/** The Compare page: every layer side by side, and which pages of the site no cache answers. */
final class CompareController extends AbstractController {
	public function register(): void {
		register_rest_route(
			self::NS,
			'/compare',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get' ),
				'permission_callback' => array( $this, 'can' ),
				'args'                => array(
					'days' => array(
						'type'    => 'integer',
						'default' => 30,
						'enum'    => array( 7, 30, 90 ),
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/compare/probe',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'probe' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
	}

	public function get( \WP_REST_Request $req ): array {
		$days = (int) $req['days'];
		return array(
			'days'          => $days,
			'layers'        => ( new CompareData( $this->services ) )->rows( $days ),
			'probe'         => $this->services->probe->latest(),
			'probe_series'  => $this->services->probe->series(),
			'probe_enabled' => $this->services->settings->siteProbe(),
		);
	}

	/** Checks the pages now. Takes a while: every page is fetched twice, like a first and a second visitor. */
	public function probe(): array {
		$this->services->probe->run( ProbeTargets::urls(), time(), true );
		return array(
			'probe'        => $this->services->probe->latest(),
			'probe_series' => $this->services->probe->series(),
		);
	}
}
