<?php

namespace App\Providers;

use App\Services\Offers\Images\GdImageProcessor;
use App\Services\Offers\Images\ImageProcessor;
use App\Services\Offers\ServiceTypeCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The offer catalogue is configuration, so it is built from `config/`
        // instead of letting the container guess its scalar constructor args.
        $this->app->singleton(ServiceTypeCatalog::class, fn (): ServiceTypeCatalog => ServiceTypeCatalog::fromConfig());

        $this->app->bind(ImageProcessor::class, function (): ImageProcessor {
            $driver = (string) config('ride_offer_ocr.preprocess.driver', '');

            return match ($driver) {
                '', 'auto', 'gd' => new GdImageProcessor,
                default => throw new InvalidArgumentException(
                    "Unsupported ride offer image driver [{$driver}].",
                ),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
