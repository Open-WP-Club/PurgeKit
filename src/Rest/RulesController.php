<?php
namespace PurgeKit\Rest;

use PurgeKit\Core\Rule;
use PurgeKit\Core\RuleSet;

final class RulesController extends AbstractController {
	public function register(): void {
		register_rest_route(
			self::NS,
			'/rules',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( $this, 'can' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'put' ),
					'permission_callback' => array( $this, 'can' ),
				),
			)
		);
	}

	public function get(): array {
		return array(
			'rules'    => $this->services->rules->load()->toArray(),
			'triggers' => Rule::TRIGGERS,
			'actions'  => Rule::ACTIONS,
			'max'      => RuleSet::MAX_RULES,
		);
	}

	public function put( \WP_REST_Request $req ): array|\WP_Error {
		if ( ! is_array( $req->get_param( 'rules' ) ) ) {
			return new \WP_Error( 'purgekit_bad_rules', 'rules must be a list', array( 'status' => 400 ) );
		}
		$known = array_map( static fn( $l ) => $l->id(), $this->services->registry->all() );
		$set   = RuleSet::fromArray( $req->get_param( 'rules' ), $known );
		$this->services->rules->save( $set );
		return $this->get();
	}
}
