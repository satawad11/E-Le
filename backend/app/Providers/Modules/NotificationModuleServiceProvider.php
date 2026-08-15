<?php

namespace App\Providers\Modules;

use App\Services\Modules\ModuleRegistry;
use App\Services\NotificationCenter\Adapters\EmailChannelAdapter;
use App\Services\NotificationCenter\Adapters\InAppChannelAdapter;
use App\Services\NotificationCenter\Adapters\LineOaChannelAdapter;
use App\Services\NotificationCenter\Adapters\TelegramChannelAdapter;
use App\Services\NotificationCenter\NotificationDeliveryService;
use App\Services\NotificationCenter\TemplateRenderer;
use Illuminate\Support\ServiceProvider;

class NotificationModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NotificationDeliveryService::class, function ($app): NotificationDeliveryService {
            $adapters = [
                $app->make(InAppChannelAdapter::class),
                $app->make(EmailChannelAdapter::class),
                $app->make(LineOaChannelAdapter::class),
                $app->make(TelegramChannelAdapter::class),
            ];

            return new NotificationDeliveryService(
                collect($adapters)->mapWithKeys(fn ($adapter) => [$adapter->channel() => $adapter])->all(),
                $app->make(TemplateRenderer::class),
                $app->make(ModuleRegistry::class),
            );
        });
    }
}
