<?php
namespace PurgeKit\Core;

/** An ordered list of rules. The first enabled rule matching a change decides what happens to it. */
final class RuleSet {
	public const MAX_RULES = 50;

	/** @param Rule[] $rules */
	public function __construct( private array $rules = array() ) {}

	/**
	 * Builds a set from untrusted data. Invalid rules are dropped, unknown triggers, actions and
	 * layers are removed. Pass $known_layers to drop layer ids that do not exist.
	 *
	 * @param string[]|null $known_layers
	 */
	public static function fromArray( mixed $raw, ?array $known_layers = null ): self {
		$rules = array();
		$seen  = array();
		foreach ( is_array( $raw ) ? $raw : array() as $item ) {
			if ( ! is_array( $item ) || count( $rules ) >= self::MAX_RULES ) {
				continue;
			}
			$name = is_string( $item['name'] ?? null ) ? trim( $item['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$action = $item['action'] ?? Rule::DEFAULT;
			if ( ! is_string( $action ) || ! in_array( $action, Rule::ACTIONS, true ) ) {
				continue;
			}
			$id = (string) ( $item['id'] ?? '' );
			if ( 1 !== preg_match( '/^[a-z0-9]{4,16}$/', $id ) || isset( $seen[ $id ] ) ) {
				$id = bin2hex( random_bytes( 4 ) );
			}
			$seen[ $id ] = true;
			$layers      = self::strings( $item['layers'] ?? array(), '/^[a-z0-9\-]+$/' );
			if ( null !== $known_layers ) {
				$layers = array_values( array_intersect( $layers, $known_layers ) );
			}
			$rules[] = new Rule(
				$id,
				mb_substr( $name, 0, 80 ),
				filter_var( $item['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN ),
				array_values( array_intersect( self::strings( $item['on'] ?? array() ), array_keys( Rule::TRIGGERS ) ) ),
				self::strings( $item['post_types'] ?? array(), '/^[a-z0-9_\-]{1,20}$/' ),
				$action,
				$layers
			);
		}
		return new self( $rules );
	}

	/** @return Rule[] */
	public function rules(): array {
		return $this->rules;
	}

	public function isEmpty(): bool {
		return array() === $this->rules;
	}

	public function toArray(): array {
		return array_map( static fn( Rule $r ) => $r->toArray(), $this->rules );
	}

	/**
	 * Groups the changes by the rule that handles them and returns one job per distinct outcome.
	 * Changes without a matching rule are planned the normal way, on every layer.
	 *
	 * @param Change[] $changes
	 * @return array<int,array{plan:PurgePlan,layers:?array}>
	 */
	public function route( array $changes, Planner $planner ): array {
		return array_map(
			static function ( array $job ): array {
				unset( $job['rules'] );
				return $job;
			},
			$this->routeDetailed( $changes, $planner )['jobs']
		);
	}

	/**
	 * Like route(), but also names the rules behind each job and lists the changes a rule skipped.
	 * An empty `rules` list means the changes took the default route (no rule matched).
	 *
	 * @param Change[] $changes
	 * @return array{jobs:array<int,array{plan:PurgePlan,layers:?array,rules:string[]}>,skipped:array<int,array{type:string,rule:string,urls:string[]}>}
	 */
	public function routeDetailed( array $changes, Planner $planner ): array {
		$buckets = array();
		$skipped = array();
		foreach ( $changes as $change ) {
			$rule   = $this->match( $change );
			$action = $rule ? $rule->action : Rule::DEFAULT;
			if ( Rule::SKIP === $action ) {
				$skipped[] = array(
					'type' => $change->type,
					'rule' => $rule ? $rule->name : '',
					'urls' => array_values( array_map( 'strval', $change->urls ) ),
				);
				continue;
			}
			$layers = $rule ? $rule->layers : array();
			sort( $layers );
			$key = $action . '|' . implode( ',', $layers );
			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = array(
					'action'  => $action,
					'layers'  => $layers,
					'changes' => array(),
					'rules'   => array(),
				);
			}
			$buckets[ $key ]['changes'][] = $change;
			if ( $rule ) {
				$buckets[ $key ]['rules'][ $rule->name ] = true;
			}
		}

		$jobs = array();
		foreach ( $buckets as $bucket ) {
			$plan = match ( $bucket['action'] ) {
				Rule::ALL  => PurgePlan::everything(),
				Rule::HOME => $planner->plan( array( Change::forUrls( 'rule', array( $planner->home() ) ) ) ),
				default    => $planner->plan( $bucket['changes'] ),
			};
			if ( $plan->isEmpty() ) {
				continue;
			}
			$job = array(
				'plan'   => $plan,
				'layers' => array() === $bucket['layers'] ? null : $bucket['layers'],
				'rules'  => array_map( 'strval', array_keys( $bucket['rules'] ) ),
			);
			if ( $plan->all && null === $job['layers'] ) {
				return array(
					'jobs'    => array( $job ), // Purging everything everywhere covers all other jobs.
					'skipped' => $skipped,
				);
			}
			$jobs[] = $job;
		}
		return array(
			'jobs'    => $jobs,
			'skipped' => $skipped,
		);
	}

	private function match( Change $change ): ?Rule {
		foreach ( $this->rules as $rule ) {
			if ( $rule->matches( $change ) ) {
				return $rule;
			}
		}
		return null;
	}

	/** @return string[] */
	private static function strings( mixed $value, string $pattern = '' ): array {
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $v ) {
			if ( is_string( $v ) && ( '' === $pattern || 1 === preg_match( $pattern, $v ) ) ) {
				$out[ $v ] = true;
			}
		}
		return array_keys( $out );
	}
}
