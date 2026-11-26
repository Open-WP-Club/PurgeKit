<?php
namespace PurgeKit\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use PurgeKit\Settings\Settings;
use PurgeKit\Settings\SettingsPorter;
use PurgeKit\Settings\ConfigOverrides;
use PurgeKit\Core\RuleStore;
use PurgeKit\Tests\Fakes\FakeStore;

final class BatchSettingsTest extends TestCase {
	public function test_defaults_and_bounds(): void {
		$s = new Settings( new FakeStore() );
		$this->assertSame( 20, $s->batchDelay() );
		$this->assertSame( 300, $s->batchMaximum() );
		$s->update( array( 'batch_delay' => 9999, 'batch_maximum' => -1 ) );
		$this->assertSame( 120, $s->batchDelay() );
		$this->assertSame( 120, $s->batchMaximum(), 'The hard deadline cannot precede the quiet window.' );
		$s->update( array( 'batch_delay' => 0, 'batch_maximum' => 9999 ) );
		$this->assertSame( 0, $s->batchDelay() );
		$this->assertSame( 3600, $s->batchMaximum() );
		$s->update( array( 'batch_delay' => array(), 'batch_maximum' => 'invalid' ) );
		$this->assertSame( 0, $s->batchDelay() );
		$this->assertSame( 3600, $s->batchMaximum() );
	}

	public function test_priority_urls_are_filtered_deduplicated_and_capped(): void {
		$s = new Settings( new FakeStore() );
		$s->update( array( 'warm_priority_urls' => array( 'https://a.test/', 'https://a.test/', '', array(), 'invalid' ) ) );
		$this->assertSame( array( 'https://a.test/' ), $s->warmPriorityUrls() );
		$s->update( array( 'warm_priority_urls' => array_map( static fn( $n ) => 'https://a.test/' . $n, range( 1, 100 ) ) ) );
		$this->assertCount( 50, $s->warmPriorityUrls() );
	}

	public function test_settings_are_portable_and_configuration_overrides_are_immutable(): void {
		$store = new FakeStore();
		$s = new Settings( $store );
		$s->update( array( 'batch_delay' => 30, 'batch_maximum' => 600, 'warm_priority_urls' => array( 'https://a.test/' ) ) );
		$export = ( new SettingsPorter( $s, $store, new RuleStore( $store ) ) )->export();
		$next = new FakeStore();
		$config = new ConfigOverrides( $next, array( 'batch_delay' => 45 ) );
		$copy = new Settings( $config );
		$p = new SettingsPorter( $copy, $config, new RuleStore( $config ) );
		$p->import( $export, array(), array( 'batch_delay' ) );
		$this->assertSame( 45, $copy->batchDelay() );
		$this->assertSame( 600, $copy->batchMaximum() );
		$this->assertSame( array( 'https://a.test/' ), $copy->warmPriorityUrls() );
	}
}
