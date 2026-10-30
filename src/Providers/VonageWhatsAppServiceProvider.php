<?php

namespace FilippoToso\BotMan\Drivers\VonageWhatsApp\Providers;

use BotMan\BotMan\Drivers\DriverManager;
use FilippoToso\BotMan\Drivers\VonageWhatsApp\VonageWhatsAppDriver;
use BotMan\Studio\Providers\StudioServiceProvider;
use Illuminate\Support\ServiceProvider;

class VonageWhatsAppServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     */
    public function boot(): void
    {
        if (! $this->isRunningInBotManStudio()) {
            $this->loadDrivers();

            $this->publishes([
                __DIR__ . '/../../stubs/vonage_whatsapp.php' => config_path('botman/vonage_whatsapp.php'),
            ]);

            $this->mergeConfigFrom(
                __DIR__ . '/../../stubs/vonage_whatsapp.php',
                'botman.vonage_whatsapp'
            );
        }
    }

    /**
     * Load BotMan drivers.
     */
    protected function loadDrivers(): void
    {
        DriverManager::loadDriver(VonageWhatsAppDriver::class);
    }

    /**
     * @return bool
     */
    protected function isRunningInBotManStudio(): bool
    {
        return class_exists(StudioServiceProvider::class);
    }
}
