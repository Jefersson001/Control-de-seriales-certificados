<?php

namespace App\Providers;

use App\Jobs\RecoverEmissionDatesBatch;
use App\Models\SystemSetting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app('db')->getSchemaBuilder()->hasTable('system_settings')) {
            config([
                'livewire.payload.max_size' => SystemSetting::livewirePayloadMaxMb() * 1024 * 1024,
            ]);
        }

        Event::listen(MigrationsEnded::class, function (MigrationsEnded $event): void {
            if (! app()->isProduction() || $event->method !== 'up' || ! empty($event->options['pretend']) || ! Schema::hasColumn('certificate_documents', 'issued_on') || ! Schema::hasColumn('vehicle_identification_record_management_certificates', 'issued_on') || ! Schema::hasColumn('ms_certificados', 'issued_on')) {
                return;
            }

            $connection = config('queue.default');
            RecoverEmissionDatesBatch::dispatch()->onConnection(in_array($connection, ['sync', 'null', 'deferred'], true) ? 'database' : $connection);
        });

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
