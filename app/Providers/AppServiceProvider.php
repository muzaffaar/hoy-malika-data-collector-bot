<?php

namespace App\Providers;

use App\Contracts\BackupDestination;
use App\Services\Backup\GoogleDriveDestination;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(BackupDestination::class, GoogleDriveDestination::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Behind a TLS-terminating proxy Laravel sees plain HTTP and would emit http:// asset/route URLs,
        // which browsers block as mixed content. Generated URLs follow APP_URL's scheme instead.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
        Paginator::defaultView('components.pagination');
    }
}
