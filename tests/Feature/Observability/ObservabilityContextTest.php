<?php

use App\Jobs\GenerateMediaVariantsJob;
use App\Providers\ObservabilityServiceProvider;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * the Nightwatch evaluation: the correlation metadata, and the one carrier it travels on.
 *
 * Laravel's Context is the framework's own correlation channel: Monolog writes
 * it into every log record's `extra`, the queue dehydrates it into a job
 * payload and rehydrates it in the worker, and Nightwatch serializes
 * `Context::all()` onto its execution records. So RateGuru publishes the
 * deployment facts there once, from the config that already owns them, rather
 * than inventing a second correlation system for a second vendor.
 */
beforeAll(function (): void {
    TestCase::$bootConfiguration = [
        'deployment.target' => 'staging-main',
        'deployment.release' => 'v1.2.3-20260101-000000-abc1234',
        'deployment.commit' => 'abc1234',
    ];
});

afterAll(function (): void {
    TestCase::$bootConfiguration = [];
});

it('publishes the deployment identity on Laravel Context exactly once, from the canonical config', function () {
    expect(Context::all())->toMatchArray([
        'app' => 'RateGuru',
        'environment' => 'testing',
        'deployment_target' => 'staging-main',
        'release' => 'v1.2.3-20260101-000000-abc1234',
        'commit' => 'abc1234',
    ]);
});

it('publishes the request ID on the same channel, from the middleware that mints it', function () {
    $response = $this->get('/');

    $response->assertSuccessful();

    $header = $response->headers->get('X-Request-Id');

    expect($header)->not->toBeNull();
    expect(Context::get('request_id'))->toBe($header);
});

it('honours an inbound request ID rather than minting a second one', function () {
    $response = $this->withHeaders(['X-Request-Id' => 'inbound-correlation-id'])->get('/');

    expect(Context::get('request_id'))->toBe('inbound-correlation-id');
    expect($response->headers->get('X-Request-Id'))->toBe('inbound-correlation-id');
});

it('carries the deployment identity and the request ID across the queue boundary', function () {
    // Verified, not assumed: this is the mechanism Nightwatch relies on to
    // attach a job to the request that dispatched it, and RateGuru had no
    // Context at all before the Nightwatch evaluation.
    $this->withHeaders(['X-Request-Id' => 'crosses-the-queue'])->get('/')->assertSuccessful();

    Queue::connection('database')->push(new GenerateMediaVariantsJob(1));

    $row = DB::table('jobs')->orderByDesc('id')->first();
    expect($row)->not->toBeNull();

    $payload = json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR);

    expect($payload)->toHaveKey('illuminate:log:context');

    // The worker's side of the same mechanism: Laravel rehydrates Context from
    // the payload when it dispatches JobProcessing. Driving that event with the
    // real popped job is the round trip, without spawning a worker process
    // that would not have this test's classes loaded.
    Context::flush();
    expect(Context::all())->toBe([]);

    $job = Queue::connection('database')->pop();
    expect($job)->not->toBeNull();

    event(new JobProcessing('database', $job));

    expect(Context::all())->toMatchArray([
        'app' => 'RateGuru',
        'deployment_target' => 'staging-main',
        'release' => 'v1.2.3-20260101-000000-abc1234',
        'commit' => 'abc1234',
        'request_id' => 'crosses-the-queue',
    ]);

    $job->delete();
});

it('omits a deployment fact rather than sending an empty one', function () {
    // Locally and in CI there is no release. "Absent" is the honest answer;
    // an empty string would be a value that looks like a value.
    config()->set('deployment.target', null);
    config()->set('deployment.release', null);
    config()->set('deployment.commit', null);

    Context::flush();
    app(ObservabilityServiceProvider::class, ['app' => app()])->boot();

    expect(Context::all())
        ->not->toHaveKey('deployment_target')
        ->not->toHaveKey('release')
        ->not->toHaveKey('commit')
        ->toHaveKey('app');
});

it('adds context from exactly two places, so no fact has two owners', function () {
    // One authoritative source per piece of metadata: the provider owns the
    // deployment facts, AttachRequestId owns the request ID, and nothing else
    // in the application writes to Context at all.
    $writers = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(base_path().'/', '', $file->getPathname());

        if (str_contains(phpSourceWithoutComments($relative), 'Context::add')) {
            $writers[] = $relative;
        }
    }

    sort($writers);

    expect($writers)->toBe([
        'app/Http/Middleware/AttachRequestId.php',
        'app/Providers/ObservabilityServiceProvider.php',
    ]);
});
