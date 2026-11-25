<?php
namespace PurgeKit\Core;

/**
 * Decides which cache problems are new, so each one is reported once and not every hour.
 * A check that was skipped (for example a cache that cannot be read from the command line) says nothing,
 * so what was known stays as it was.
 */
final class HealthWatch {
	/**
	 * @param array<string,array{issue:string,since:int}>                                   $state  Open problems by layer id.
	 * @param array<string,array{label:string,status:string,warn:bool,message:string}>       $checks Latest check of each layer.
	 * @return array{state:array<string,array{issue:string,since:int}>,alerts:array<string,array{label:string,issue:string,message:string}>}
	 */
	public static function step( array $state, array $checks, int $now ): array {
		$alerts = array();
		foreach ( $checks as $id => $c ) {
			if ( LayerResult::SKIPPED === $c['status'] ) {
				continue;
			}
			$issue = LayerResult::FAILED === $c['status'] ? 'failed' : ( $c['warn'] ? 'warn' : '' );
			if ( '' === $issue ) {
				unset( $state[ $id ] );
				continue;
			}
			$before = $state[ $id ]['issue'] ?? '';
			if ( $before !== $issue ) {
				// New, or a warning that turned into a failure. A failure that eases into a warning is not announced again.
				if ( '' === $before || 'failed' === $issue ) {
					$alerts[ $id ] = array(
						'label'   => $c['label'],
						'issue'   => $issue,
						'message' => $c['message'],
					);
				}
				$state[ $id ] = array(
					'issue' => $issue,
					'since' => $now,
				);
			}
		}
		return array(
			'state'  => $state,
			'alerts' => $alerts,
		);
	}
}
