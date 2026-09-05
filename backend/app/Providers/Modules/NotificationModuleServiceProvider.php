<?php

namespace App\Providers\Modules;

use App\Services\Modules\ModuleRegistry;
use App\Services\NotificationCenter\Adapters\EmailChannelAdapter;
use App\Services\NotificationCenter\Adapters\InAppChannelAdapter;
use App\Services\NotificationCenter\Adapters\LineOaChannelAdapter;
use App\Services\NotificationCenter\Adapters\TelegramChannelAdapter;
use App\Services\NotificationCenter\Adapters\WebPushChannelAdapter;
use App\Services\NotificationCenter\ChannelAdapterRegistry;
use App\Services\NotificationCenter\NotificationDeliveryService;
use App\Services\NotificationCenter\TemplateRenderer;
use Illuminate\Support\ServiceProvider;

class NotificationModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ChannelAdapterRegistry::class, fn ($app): ChannelAdapterRegistry => ChannelAdapterRegistry::of([
            $app->make(InAppChannelAdapter::class),
            $app->make(EmailChannelAdapter::class),
            $app->make(LineOaChannelAdapter::class),
            $app->make(TelegramChannelAdapter::class),
            $app->make(WebPushChannelAdapter::class),
        ]));

        $this->app->bind(NotificationDeliveryService::class, fn ($app): NotificationDeliveryService => new NotificationDeliveryService(
            $app->make(ChannelAdapterRegistry::class),
            $app->make(TemplateRenderer::class),
            $app->make(ModuleRegistry::class),
        ));
    }
}
