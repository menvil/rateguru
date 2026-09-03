<?php

use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Route;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Facades\Nightwatch;
use Sentry\Laravel\Http\FlushEventsMiddleware;
use Sentry\Laravel\Http\SetRequestMiddleware;
use Sentry\Laravel\Tracing\Middleware as TracingMiddleware;
use Sentry\State\HubInterface;
use Tests\TestCase;

/**
 * the Nightwatch evaluation: Sentry and Nightwatch in the same application.
 *
 * Two vendors, two official integrations, no shared abstraction, and both
 * enabled at once — which is the configuration staging will actually run
 * during the evaluation, and the one nobody had ever booted before this PR.
 *
 * These assert observable framework behaviour. Neither SaaS is faked wholesale
 * and no vendor event listener is inspected: what matters is that the
 * application still boots, still serves, still has exactly one Sentry capture
 * path, and produces one event per provider for one exception.
 */
beforeAll(function (): void {
    TestCase::$bootConfiguration = [
        // RFC 2606 .invalid host: never resolved, never contacted — the
        // in-memory transport below replaces the real one before anything is
        // sent, exactly as the other Sentry tests do.
        'sentry.dsn' => 'https://coexistence@sentry.invalid/1',
        'sentry.environment' => 'staging',
        'sentry.traces_sample_rate' => 1.0,

        'nightwatch.enabled' => true,
        'nightwatch.token' => 'phase-6b-test-token',
        'nightwatch.sampling.exceptions' => 1.0,

        'deployment.target' => 'staging-main',
    ];
});

afterAll(function (): void {
    TestCase::$bootConfiguration = [];
});

it('boots and serves a normal request with both products enabled', function () {
    expect(app()->bound(HubInterface::class))->toBeTrue();
    expect(app()->bound(Nightwatch::getFacadeAccessor()))->toBeTrue();
    expect(app(Core::class)->enabled())->toBeTrue();

    $this->get('/')->assertSuccessful();
});

it('leaves the Sentry integration exactly as the Sentry integration configured it', function () {
    // Frozen for the duration of the comparison: if adding a second vendor
    // moved any of this, the comparison would be measuring the change instead
    // of the products.
    expect(config('sentry.send_default_pii'))->toBeFalse();
    expect(config('sentry.breadcrumbs.sql_bindings'))->toBeFalse();
    expect(config('sentry.tracing.sql_bindings'))->toBeFalse();
    expect(config('sentry.enable_logs'))->toBeFalse();
    expect(config('sentry.enable_metrics'))->toBeFalse();
    expect((array) config('sentry.ignore_transactions'))->toBe(['/up']);

    // Both products name the release from the same artifact metadata, so they
    // can never disagree about what the server is running — including when
    // there is no release at all, which is the state of a working copy.
    expect(config('nightwatch.deployment'))->toBe(config('sentry.release'));

    // And the SDK still attached its HTTP instrumentation, which it decides in
    // boot() and only when a DSN is configured.
    $kernel = app(HttpKernelContract::class);
    expect($kernel)->toBeInstanceOf(HttpKernel::class);
    expect($kernel->hasMiddleware(TracingMiddleware::class))->toBeTrue();
    expect($kernel->hasMiddleware(SetRequestMiddleware::class))->toBeTrue();
    expect($kernel->hasMiddleware(FlushEventsMiddleware::class))->toBeTrue();
});

it('reports one exception once to each provider, and never twice to either', function () {
    Route::middleware('web')->get('/__phase6b-coexistence-probe', function (): never {
        throw new RuntimeException('phase-6b-coexistence-probe');
    });

    $sentry = fakeSentryTransport();
    $nightwatch = captureNightwatchIngest();

    $this->get('/__phase6b-coexistence-probe')->assertStatus(500);

    Nightwatch::digest();

    // One event per provider is correct. Two Nightwatch events for the same
    // exception would mean a manual report() had been added on top of the
    // native integration.
    $sentryEvents = array_values(array_filter(
        $sentry->errorEvents(),
        static fn ($event): bool => collect($event->getExceptions())
            ->contains(static fn ($exception): bool => $exception->getValue() === 'phase-6b-coexistence-probe'),
    ));

    $nightwatchEvents = array_values(array_filter(
        $nightwatch->ofType('exception'),
        static fn (array $record): bool => str_contains((string) json_encode($record), 'phase-6b-coexistence-probe'),
    ));

    expect($sentryEvents)->toHaveCount(1);
    expect($nightwatchEvents)->toHaveCount(1);
});

it('introduces no second exception-capture path', function () {
    // the Sentry integration's rule, unchanged: bootstrap/app.php's `Integration::handles()`
    // is the whole Sentry capture path, and Nightwatch hooks Laravel's handler
    // itself. Neither vendor is invoked manually from application code.
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(base_path().'/', '', $file->getPathname());
        $code = phpSourceWithoutComments($relative);

        foreach (['Nightwatch::report(', 'captureException', 'captureMessage', 'SentrySdk'] as $forbidden) {
            if (str_contains($code, $forbidden)) {
                $offenders[] = "{$relative}: {$forbidden}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('never asks Nightwatch to observe framework and vendor commands', function () {
    // captureDefaultVendorCommands() would add noise with no comparison value:
    // Sentry does not report on vendor commands either, so turning it on would
    // make the two products differ for a reason that is our doing.
    $provider = phpSourceWithoutComments('app/Providers/ObservabilityServiceProvider.php');

    expect($provider)->not->toContain('captureDefaultVendorCommands');
});
