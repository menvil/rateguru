<?php

use App\Providers\ObservabilityServiceProvider;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Route;
use Sentry\Event;
use Sentry\EventType;
use Sentry\Laravel\Http\FlushEventsMiddleware;
use Sentry\Laravel\Http\SetRequestMiddleware;
use Sentry\Laravel\ServiceProvider;
use Sentry\Laravel\Tracing\Middleware as TracingMiddleware;
use Sentry\Options;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Tests\TestCase;

/*
 * The regression the original the observability work suite was missing entirely.
 *
 * Every earlier Sentry test configured the DSN *after* the application had
 * booted, which means the two official service providers had already decided —
 * correctly, for a DSN-less app — to register no instrumentation at all. Those
 * tests could prove the client worked; they could not prove that a real browser
 * request produces a transaction, because no request in the suite ever went
 * through the tracing middleware.
 *
 * These boot the way staging boots and drive real requests through the HTTP
 * kernel. No transaction is ever started by hand: if the SDK does not create
 * one, these fail.
 */
beforeAll(function (): void {
    // PHPUnit runs this before the first setUp(), so it is in place before any
    // application in this file is built. Configuration rather than environment
    // variables: Laravel re-applies .env on every refresh and would reset any
    // key it defines — and .env.example, which CI copies, defines all of these.
    TestCase::$bootConfiguration = [
        // RFC 2606 .invalid host: never resolved, never contacted — every test
        // below swaps in an in-memory transport before anything is sent.
        'sentry.dsn' => 'https://bootrecorder@sentry.invalid/1',
        'sentry.environment' => 'staging',
        'sentry.traces_sample_rate' => 1.0,
        'deployment.target' => 'staging-main',
    ];
});

afterAll(function (): void {
    TestCase::$bootConfiguration = [];
});

/** @return list<Event> */
function transactionEvents(RecordingSentryTransport $transport): array
{
    return array_values(array_filter(
        $transport->events,
        static fn (Event $event): bool => $event->getType() === EventType::transaction(),
    ));
}

/** @return list<Span> */
function spansWithOp(Event $event, string $op): array
{
    return array_values(array_filter(
        $event->getSpans(),
        static fn (Span $span): bool => $span->getOp() === $op,
    ));
}

it('boots both official Sentry service providers', function () {
    $loaded = $this->app->getLoadedProviders();

    expect($loaded)->toHaveKey(ServiceProvider::class)
        ->and($loaded)->toHaveKey(Sentry\Laravel\Tracing\ServiceProvider::class);

    // Auto-discovery, not manual registration — bootstrap/providers.php must
    // never duplicate what the package already declares to Composer.
    expect(file_get_contents(base_path('bootstrap/providers.php')))
        ->not->toContain('Sentry\\Laravel\\ServiceProvider')
        ->not->toContain('Sentry\\Laravel\\Tracing\\ServiceProvider');
});

it('registers the Sentry HTTP middleware on the real kernel when a DSN is present at boot', function () {
    $kernel = $this->app->make(HttpKernelContract::class);

    expect($kernel)->toBeInstanceOf(HttpKernel::class);

    // Public API, not the kernel's internal array — and presence only. The SDK
    // prepends the tracing middleware so the transaction wraps as much of the
    // request as possible, but no fixed position is a contract: another package
    // may legitimately prepend later, and tracing would still be correct. That
    // it runs early enough is asserted where it actually matters, by the
    // transaction below carrying app.bootstrap and middleware.handle spans.
    expect($kernel->hasMiddleware(TracingMiddleware::class))->toBeTrue()
        ->and($kernel->hasMiddleware(SetRequestMiddleware::class))->toBeTrue()
        ->and($kernel->hasMiddleware(FlushEventsMiddleware::class))->toBeTrue();
});

it('captures an automatically instrumented transaction for a real HTTP request', function () {
    $transport = fakeSentryTransport();

    $this->get('/')->assertOk();

    $transactions = transactionEvents($transport);

    expect($transactions)->toHaveCount(1, 'a real HTTP request must produce exactly one Sentry transaction');

    $event = $transactions[0];

    expect($event->getTransaction())->toBe('/')
        ->and($event->getEnvironment())->toBe('staging')
        ->and($event->getTags())->toMatchArray([
            'deployment_target' => 'staging-main',
            'app' => 'RateGuru',
        ]);

    // Sampled: an unsampled transaction is never handed to the transport at
    // all, so arriving here is the assertion.
    expect($event->getSpans())->not->toBeEmpty();
});

it('carries the canonical release and commit on the transaction when metadata is available', function () {
    config([
        'sentry.release' => 'v0.0.0-20260827-131008-ccbd414',
        'deployment.commit' => 'ccbd414708b1117a893e5f587f21c355395d3949',
        'deployment.target' => 'staging-main',
    ]);

    (new ObservabilityServiceProvider($this->app))->boot();

    $transport = fakeSentryTransport();

    $this->get('/')->assertOk();

    $event = transactionEvents($transport)[0];

    expect($event->getRelease())->toBe('v0.0.0-20260827-131008-ccbd414')
        ->and($event->getTags()['commit'])->toBe('ccbd414708b1117a893e5f587f21c355395d3949')
        ->and($event->getTags()['deployment_target'])->toBe('staging-main');
});

it('records SQL spans on a database-backed request without ever including bindings', function () {
    $transport = fakeSentryTransport();

    // A real application route that queries the database on every render.
    $this->get('/')->assertOk();

    $event = transactionEvents($transport)[0];
    $sqlSpans = spansWithOp($event, 'db.sql.query');

    expect($sqlSpans)->not->toBeEmpty('a DB-backed request must produce SQL spans');

    foreach ($sqlSpans as $span) {
        // The query text is what makes a trace diagnosable and must be present.
        expect($span->getDescription())->not->toBeEmpty();

        // The bindings key the SDK would use when sql_bindings is enabled must
        // never appear — that is where emails, names and search text live.
        expect($span->getData())->not->toHaveKey('db.sql.bindings');
    }

    // Belt and braces: no span payload anywhere in the transaction may carry it.
    foreach ($event->getSpans() as $span) {
        expect($span->getData())->not->toHaveKey('db.sql.bindings');
    }
});

it('produces no transaction for the health endpoint', function () {
    $transport = fakeSentryTransport();

    $this->get('/up')->assertOk();

    expect(transactionEvents($transport))->toBe([], '/up must not pollute performance monitoring');
});

it('still reports a genuine exception raised while Sentry is fully booted', function () {
    // Proves /up exclusion and tracing did not quietly disable error reporting:
    // this goes through the same fully-instrumented kernel.
    Route::middleware('web')->get('/__tracing-exception-probe', function (): never {
        throw new RuntimeException('tracing probe failure');
    });

    $transport = fakeSentryTransport();

    $this->get('/__tracing-exception-probe')->assertStatus(500);

    $errors = $transport->errorEvents();

    expect($errors)->toHaveCount(1, 'an unhandled exception must be captured exactly once')
        ->and($errors[0]->getExceptions()[0]->getValue())->toBe('tracing probe failure');
});

it('does not enable tracing when no target opts in', function () {
    // The other half of the contract: a target that never sets
    // SENTRY_TRACES_SAMPLE_RATE must not pay for transaction machinery.
    $options = new Options(['traces_sample_rate' => null]);

    expect($options->isTracingEnabled())->toBeFalse();

    $enabled = new Options(['traces_sample_rate' => config('sentry.traces_sample_rate')]);

    expect(config('sentry.traces_sample_rate'))->toBe(1.0)
        ->and($enabled->isTracingEnabled())->toBeTrue();
});

it('reports tracing as enabled on the live client', function () {
    $client = $this->app->make(HubInterface::class)->getClient();

    expect($client)->not->toBeNull()
        ->and($client->getOptions()->getDsn())->not->toBeNull()
        ->and($client->getOptions()->isTracingEnabled())->toBeTrue()
        ->and($client->getOptions()->getTracesSampleRate())->toBe(1.0);
});
