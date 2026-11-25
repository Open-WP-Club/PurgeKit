<?php
namespace PurgeKit\Core;

/** Sizes for people: 134217728 becomes "128 MB". Pure, so it works in unit tests too. */
final class Bytes {
	public static function human( float|int $bytes ): string {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$last  = count( $units ) - 1;
		$i     = 0;
		$n     = max( 0, (float) $bytes );
		while ( $n >= 1024 && $i < $last ) {
			$n /= 1024;
			++$i;
		}
		return ( $i > 0 && $n < 10 ? number_format( $n, 1, '.', '' ) : number_format( $n, 0, '.', '' ) ) . ' ' . $units[ $i ];
	}
}
