<?php
namespace PurgeKit\Core;

/** Finds, for every layer, the most recent real result in a list of history rows (newest first). */
final class LastResults {
	/**
	 * Skipped results say nothing about whether a layer works, so an older real result stays visible.
	 *
	 * @param array<int,array<string,mixed>> $rows History rows as returned by the repository.
	 * @return array<string,array{status:string,message:string,ms:int,at:string,source:string}>
	 */
	public static function fromRows( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			foreach ( (array) ( $row['results'] ?? array() ) as $result ) {
				if ( ! is_array( $result ) ) {
					continue;
				}
				$id     = (string) ( $result['layer'] ?? '' );
				$status = (string) ( $result['status'] ?? '' );
				if ( '' === $id || isset( $out[ $id ] ) || LayerResult::SKIPPED === $status || '' === $status ) {
					continue;
				}
				$out[ $id ] = array(
					'status'  => $status,
					'message' => (string) ( $result['message'] ?? '' ),
					'ms'      => (int) ( $result['ms'] ?? 0 ),
					'at'      => (string) ( $row['created_at'] ?? '' ),
					'source'  => (string) ( $row['source'] ?? '' ),
				);
			}
		}
		return $out;
	}
}
