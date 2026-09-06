<?php

use App\Models\User;
use App\Providers\ObservabilityServiceProvider;
use Illuminate\Support\Facades\File;
use Sentry\Severity;
use Sentry\State\HubInterface;

use function Sentry\captureMessage;

/*
 * The application-wide Sentry scope. Every value asserted here is what makes a
 * single Sentry issue answerable: which environment, which target, which
 * release, which commit — with no test ever reaching the network.
 */

/**
 * Discards the hub the application booted with and rebuilds it, then re-runs
 * the provider against whatever this test just configured. Without the reset a
 * test could only ever add tags to the scope the previous configuration left
 * behind, so "this value is absent" would be untestable — and absence is
 * precisely what unknown release metadata has to produce.
 */
function bootObservabilityScope(array $config = []): void
{
    config($config);

    app()->forgetInstance(HubInterface::class);
    app()->make(HubInterface::class);

    (new ObservabilityServiceProvider(app()))->boot();
}

beforeEach(function (): void {
    bootObservabilityScope([
        'deployment.target' => 'staging-main',
        'deployment.release' => 'v0.0.0-20260826-120211-ca7d1c7',
        'deployment.commit' => 'ca7d1c75d0f0f5b6c9a1e2d3f4a5b6c7d8e9f0a1',
        'sentry.release' => 'v0.0.0-20260826-120211-ca7d1c7',
        'sentry.environment' => 'staging',
    ]);
});

it('carries environment, deployment target, release and commit on every event', function () {
    $transport = fakeSentryTransport();

    captureMessage('phase-6 correlation probe', Severity::error());

    expect($transport->events)->toHaveCount(1);

    $event = $transport->events[0];

    expect($event->getEnvironment())->toBe('staging')
        ->and($event->getRelease())->toBe('v0.0.0-20260826-120211-ca7d1c7')
        ->and($event->getTags())
        ->toMatchArray([
            'deployment_target' => 'staging-main',
            'commit' => 'ca7d1c75d0f0f5b6c9a1e2d3f4a5b6c7d8e9f0a1',
            'app' => 'RateGuru',
        ]);
});

it('separates the environment class from the deployment target', function () {
    // A production brand is `environment: production` plus a target tag —
    // never an environment named after the brand.
    bootObservabilityScope(['deployment.target' => 'tits-guru', 'sentry.environment' => 'production']);

    $transport = fakeSentryTransport();

    captureMessage('production target probe', Severity::error());

    $event = $transport->events[0];

    expect($event->getEnvironment())->toBe('production')
        ->and($event->getTags()['deployment_target'])->toBe('tits-guru')
        ->and($event->getEnvironment())->not->toContain('tits-guru');
});

it('omits the target and commit tags entirely when the metadata is unknown', function () {
    // A working copy, or a target whose .env predates the observability work. Nothing may be
    // invented to fill the gap — an absent tag is the honest answer.
    bootObservabilityScope(['deployment.target' => null, 'deployment.commit' => null, 'sentry.release' => null]);

    $transport = fakeSentryTransport();

    captureMessage('unknown deployment probe', Severity::error());

    $event = $transport->events[0];

    expect($event->getRelease())->toBeNull()
        ->and($event->getTags())->not->toHaveKey('deployment_target')
        ->and($event->getTags())->not->toHaveKey('commit');
});

it('attaches the internal user ID, and nothing else about the user', function () {
    $transport = fakeSentryTransport();

    $user = User::factory()->create([
        'email' => 'privacy-probe@example.com',
        'username' => 'privacy_probe',
        'name' => 'Privacy Probe',
    ]);

    $this->actingAs($user);

    // Force the Authenticated event the session guard fires on a real request.
    auth()->user();

    captureMessage('authenticated probe', Severity::error());

    $userContext = $transport->events[0]->getUser();

    expect($userContext)->not->toBeNull()
        ->and($userContext->getId())->toBe((string) $user->id)
        ->and($userContext->getEmail())->toBeNull()
        ->and($userContext->getUsername())->toBeNull()
        ->and($userContext->getIpAddress())->toBeNull();

    $serialized = json_encode($userContext->getMetadata()) ?: '';

    foreach (['privacy-probe@example.com', 'privacy_probe', 'Privacy Probe'] as $forbidden) {
        expect(str_contains($serialized, $forbidden))->toBeFalse("user metadata leaked {$forbidden}");
    }
});

it('sends no user context at all for an anonymous request', function () {
    $transport = fakeSentryTransport();

    captureMessage('anonymous probe', Severity::error());

    expect($transport->events[0]->getUser())->toBeNull();
});

it('keeps the SDK from collecting identity on its own', function () {
    // With send_default_pii false the Laravel SDK does not subscribe to auth
    // events, which is exactly why the user ID has to be added deliberately.
    // If that gate ever moves, this catches it at the vendor boundary.
    expect(File::get(base_path('vendor/sentry/sentry-laravel/src/Sentry/Laravel/ServiceProvider.php')))
        ->toContain("if (isset(\$userConfig['send_default_pii']) && \$userConfig['send_default_pii'] !== false) {")
        ->toContain('$handler->subscribeAuthEvents($dispatcher);');
});

it('adds no high-cardinality or sensitive tag', function () {
    $transport = fakeSentryTransport();

    captureMessage('cardinality probe', Severity::error());

    $tags = $transport->events[0]->getTags();

    // The whole tag set is pinned: a tag added without thought is the usual way
    // a query string, an exception message or an email ends up in Sentry.
    expect(array_keys($tags))->toEqualCanonicalizing(['deployment_target', 'commit', 'app']);

    foreach (['email', 'username', 'name', 'password', 'authorization', 'cookie', 'token', 'url', 'query', 'ip'] as $forbidden) {
        expect($tags)->not->toHaveKey($forbidden);
    }
});

it('configures the Sentry scope in exactly one place', function () {
    // Scattered configureScope() calls are how common context rots. There is
    // one owner, and no application file anywhere may capture to Sentry or
    // touch the scope by hand — not even the one that is allowed to read SDK
    // state for diagnostics.
    $offenders = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getFilename() === 'ObservabilityServiceProvider.php') {
            continue;
        }

        $contents = $file->getContents();

        foreach (['configureScope', 'captureException', 'captureMessage', 'SentrySdk'] as $needle) {
            if (str_contains($contents, $needle)) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).": {$needle}";
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('lets only the provider and the read-only health command reference the SDK at all', function () {
    // A closed list, so a new Sentry touchpoint has to be a deliberate edit
    // here. ObservabilityHealthCommand is allowed because it only *reads*
    // whether the SDK registered its middleware — the assertion above already
    // proves it never captures or mutates the scope.
    $referencing = [];

    foreach (File::allFiles(app_path()) as $file) {
        if (str_contains($file->getContents(), 'Sentry\\')) {
            $referencing[] = str_replace(base_path().'/', '', $file->getPathname());
        }
    }

    expect($referencing)->toEqualCanonicalizing([
        'app/Providers/ObservabilityServiceProvider.php',
        'app/Console/Commands/ObservabilityHealthCommand.php',
    ]);
});

it('does not break the application when Sentry is not configured at all', function () {
    // The normal local and CI state: no DSN. Booting the provider must be a
    // no-op that throws nothing, and the hub must still be resolvable.
    bootObservabilityScope(['sentry.dsn' => null]);

    expect(app()->bound(HubInterface::class))->toBeTrue();

    $this->get('/')->assertOk();
});
