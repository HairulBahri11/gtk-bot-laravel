<?php

namespace App\Providers;

use App\Services\Whatsapp\WahaWhatsAppService;
use App\Services\Whatsapp\WhatsAppServiceInterface;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Fase 1 (WAHA). Migrasi ke Meta API: ganti binding ini ke
        // implementasi baru, tanpa mengubah logic AI/database/dashboard.
        $this->app->bind(WhatsAppServiceInterface::class, WahaWhatsAppService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
