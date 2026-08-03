<?php

namespace App\Providers;

use App\Models\ScanHost;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use SocialiteProviders\Authentik\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureScannerTokens();
        $this->configureTrustedProxies();
        $this->configureAuthorization();

        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('authentik', Provider::class);
        });
    }

    protected function configureAuthorization(): void
    {
        Gate::define('admin', fn (User $user): bool => $user->is_admin);
    }

    protected function configureTrustedProxies(): void
    {
        $proxies = config('app.trusted_proxies');

        if (! is_string($proxies) || $proxies === '') {
            return;
        }

        TrustProxies::at($proxies === '*' ? '*' : explode(',', $proxies));
    }

    protected function configureScannerTokens(): void
    {
        Sanctum::authenticateAccessTokensUsing(
            function (PersonalAccessToken $accessToken, bool $isValid): bool {
                $tokenable = $accessToken->tokenable;

                if ($tokenable instanceof ScanHost) {
                    return $isValid && $tokenable->is_active;
                }

                return $isValid;
            }
        );
    }

    protected function configureRateLimiting(): void
    {

        RateLimiter::for('vuln-check', function (Request $request): Limit {
            $tenantId = $request->input('tenant_id');

            $key = is_string($tenantId) && $tenantId !== ''
                ? 'tenant:'.$tenantId
                : 'ip:'.$request->ip();

            return Limit::perMinute(30)->by($key);
        });
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Model::preventLazyLoading(! app()->isProduction());

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
