<?php
namespace PurgeKit\Layers;

final class Tier {
	public const OBJECT = 10;
	public const PLUGIN = 20;
	public const HOST   = 30;
	public const PROXY  = 40;
	public const CDN    = 50;

	/** Plain-language name of the group a layer belongs to, for the layer map. */
	public static function label( int $tier ): string {
		return match ( true ) {
			$tier <= self::OBJECT => 'Object cache',
			$tier <= self::PLUGIN => 'Cache plugin',
			$tier <= self::HOST   => 'Host cache',
			$tier <= self::PROXY  => 'Reverse proxy',
			default               => 'CDN / edge',
		};
	}
}
