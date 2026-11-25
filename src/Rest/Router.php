<?php
namespace PurgeKit\Rest;

use PurgeKit\Services;

final class Router {
	public function __construct( private Services $services ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		foreach ( array( StatusController::class, FlushController::class, LayersController::class, HistoryController::class, InspectController::class, CloudflareController::class, RulesController::class, ScheduledController::class, AssetsController::class, HealthController::class, SettingsController::class, WebhookController::class ) as $class ) {
			( new $class( $this->services ) )->register();
		}
	}
}
