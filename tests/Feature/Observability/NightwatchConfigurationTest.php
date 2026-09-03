<?php

use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Facades\Nightwatch;

/**
 * the Nightwatch evaluation: the configuration boundary RateGuru owns.
 *
 * These assert RateGuru's decisions, never the package's behaviour — the
 * vendor is responsible for what it does with a sample rate, we are
 * responsible for which sample rate it is handed and for the fact that
 * telemetry is off unless a target deliberately turns it on.
 */

/**
 * config/nightwatch.php evaluated with every NIGHTWATCH_* variable removed —
 * which is what a developer checkout, CI and any unconfigured environment
 * actually look like, and what `artisan config:cache` would freeze into a
 * release. phpunit.xml sets NIGHTWATCH_ENABLED, so reading the live config
 * would test phpunit.xml rather than the file's own defaults.
 *
 * @return array<string, mixed>
 */
function nightwatchConfigWithoutEnvironment(): array
{
    $keys = [
        'NIGHTWATCH_ENABLED', 'NIGHTWATCH_TOKEN', 'NIGHTWATCH_SERVER',
        'NIGHTWATCH_CAPTURE_EXCEPTION_SOURCE_CODE', 'NIGHTWATCH_CAPTURE_REQUEST_PAYLOAD',
        'NIGHTWATCH_REDACT_PAYLOAD_FIELDS', 'NIGHTWATCH_REDACT_HEADERS',
        'NIGHTWATCH_REQUEST_SAMPLE_RATE', 'NIGHTWATCH_COMMAND_SAMPLE_RATE',
        'NIGHTWATCH_EXCEPTION_SAMPLE_RATE', 'NIGHTWATCH_SCHEDULED_TASK_SAMPLE_RATE',
        'NIGHTWATCH_IGNORE_CACHE_EVENTS', 'NIGHTWATCH_IGNORE_MAIL',
        'NIGHTWATCH_IGNORE_NOTIFICATIONS', 'NIGHTWATCH_IGNORE_OUTGOING_REQUESTS',
        'NIGHTWATCH_IGNORE_QUERIES', 'NIGHTWATCH_LOG_LEVEL',
        'NIGHTWATCH_INGEST_URI', 'NIGHTWATCH_INGEST_TIMEOUT',
        'NIGHTWATCH_INGEST_CONNECTION_TIMEOUT', 'NIGHTWATCH_INGEST_EVENT_BUFFER',
    ];

    $saved = [];

    foreach ($keys as $key) {
        $saved[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, getenv($key)];
        unset($_SERVER[$key], $_ENV[$key]);
        putenv($key);
    }

    try {
        return require base_path('config/nightwatch.php');
    } finally {
        foreach ($saved as $key => [$server, $env, $put]) {
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
            if ($env !== null) {
                $_ENV[$key] = $env;
            }
            if ($put !== false) {
                putenv("{$key}={$put}");
            }
        }
    }
}

it('keeps Nightwatch disabled for the whole test suite', function () {
    // Belt and braces, and deliberately both: config/nightwatch.php defaults
    // `enabled` to false (the package's own default is true), and phpunit.xml
    // pins NIGHTWATCH_ENABLED=false on top of that. Neither depends on a
    // developer remembering anything.
    expect(config('nightwatch.enabled'))->toBeFalse();

    expect(file_get_contents(base_path('phpunit.xml')))
        ->toContain('<env name="NIGHTWATCH_ENABLED" value="false"/>');
});

it('defaults to disabled with no environment configured at all', function () {
    // The failure this prevents is concrete: the package ships
    // `'enabled' => env('NIGHTWATCH_ENABLED', true)`, so installing it without
    // a published config would have switched telemetry on for every checkout
    // and every CI run the moment composer install finished.
    expect(nightwatchConfigWithoutEnvironment()['enabled'])->toBeFalse();
});

it('boots and serves normally with no token configured', function () {
    expect(config('nightwatch.token'))->toBeNull();

    // The package still binds its Core — configuring a boundary must not
    // depend on the boundary being active — and the application still serves.
    expect(app()->bound(Nightwatch::getFacadeAccessor()))->toBeTrue();
    expect(app(Core::class))->toBeInstanceOf(Core::class);

    $this->get('/')->assertSuccessful();
});

it('publishes a complete configuration file, so a cached config cannot fall back to the package defaults', function () {
    // `artisan config:cache` writes what the config/*.php files return, before
    // any service provider registers — so mergeConfigFrom never runs against a
    // cached config. Any key this file omits would silently take the vendor's
    // value inside a production-like release, which for `enabled` means `true`.
    $published = nightwatchConfigWithoutEnvironment();

    expect(array_keys($published))->toEqualCanonicalizing([
        'enabled', 'token', 'deployment', 'server',
        'capture_exception_source_code', 'capture_request_payload',
        'redact_payload_fields', 'redact_headers',
        'sampling', 'filtering', 'ingest',
    ]);

    expect(array_keys($published['sampling']))
        ->toEqualCanonicalizing(['requests', 'commands', 'exceptions', 'scheduled_tasks']);

    expect(array_keys($published['filtering']))->toEqualCanonicalizing([
        'ignore_cache_events', 'ignore_mail', 'ignore_notifications',
        'ignore_outgoing_requests', 'ignore_queries', 'log_level',
    ]);

    expect(array_keys($published['ingest']))
        ->toEqualCanonicalizing(['uri', 'timeout', 'connection_timeout', 'event_buffer']);
});

it('never captures request payloads', function () {
    expect(config('nightwatch.capture_request_payload'))->toBeFalse();
    expect(nightwatchConfigWithoutEnvironment()['capture_request_payload'])->toBeFalse();
});

it('redacts every credential-bearing and address-bearing request header', function () {
    $redacted = array_map('strtolower', (array) config('nightwatch.redact_headers'));

    // Credentials and session.
    expect($redacted)
        ->toContain('authorization')
        ->toContain('cookie')
        ->toContain('proxy-authorization')
        ->toContain('x-xsrf-token')
        // Laravel accepts this one alongside X-XSRF-TOKEN, and the package's
        // default list omits it.
        ->toContain('x-csrf-token');

    // Emptying Request::$ip is not enough on its own: a proxied request carries
    // the same precise client address again in these headers, and the package
    // serialises the whole header bag.
    expect($redacted)
        ->toContain('x-forwarded-for')
        ->toContain('x-real-ip')
        ->toContain('forwarded')
        ->toContain('cf-connecting-ip')
        ->toContain('true-client-ip');

    // The feed search term lives in the query string, so a referrer is a
    // second, indirect copy of exactly what the URL redaction removes.
    expect($redacted)->toContain('referer');
});

it('leaves mail and notifications off, because both records identify a recipient', function () {
    expect(config('nightwatch.filtering.ignore_mail'))->toBeTrue();
    expect(config('nightwatch.filtering.ignore_notifications'))->toBeTrue();

    $published = nightwatchConfigWithoutEnvironment();
    expect($published['filtering']['ignore_mail'])->toBeTrue();
    expect($published['filtering']['ignore_notifications'])->toBeTrue();
});

it('keeps the Nightwatch log channel out of the log stack', function () {
    // The package registers a `nightwatch` logging channel whether or not
    // anyone uses it. What decides whether log records are shipped is the
    // stack, and the Nightwatch evaluation deliberately leaves it alone: RateGuru's redaction
    // covers DomainLogger, not the direct Log:: calls scattered through the
    // console commands.
    expect(config('logging.default'))->not->toBe('nightwatch');
    expect((array) config('logging.channels.stack.channels'))->not->toContain('nightwatch');

    expect(file_get_contents(base_path('config/logging.php')))
        ->not->toContain('nightwatch');

    // And if a target ever does add it, it starts at warning, never debug.
    expect(nightwatchConfigWithoutEnvironment()['filtering']['log_level'])->toBe('warning');
});

it('samples requests conservatively by default and never samples exceptions away', function () {
    $published = nightwatchConfigWithoutEnvironment();

    // Nightwatch bills observed events, not requests: one sampled request also
    // brings its queries, cache events and jobs. 0.10 is the documented steady
    // state; a target raises it in its own .env for a controlled window.
    expect($published['sampling']['requests'])->toBe(0.10);
    expect($published['sampling']['commands'])->toBe(1.0);
    expect($published['sampling']['exceptions'])->toBe(1.0);
});

it('sends events to a loopback ingest address', function () {
    expect(config('nightwatch.ingest.uri'))->toBe('127.0.0.1:2407');
    expect(nightwatchConfigWithoutEnvironment()['ingest']['uri'])->toBe('127.0.0.1:2407');
});

it('takes its deploy identity from the canonical release metadata, never from a separate variable', function () {
    // Same value, same source as Sentry's release — so a Nightwatch event and
    // a Sentry event can never disagree about what the server is running.
    expect(config('nightwatch.deployment'))->toBe(config('deployment.release'));

    $source = phpSourceWithoutComments('config/nightwatch.php');

    // The package's own default chains NIGHTWATCH_DEPLOY -> Laravel Cloud ->
    // Forge -> Vapor. None of those describe a RateGuru deployment, and
    // NIGHTWATCH_DEPLOY in a shared .env would have to be rewritten on every
    // single deploy.
    foreach (['NIGHTWATCH_DEPLOY', 'LARAVEL_CLOUD_DEPLOY_UUID', 'FORGE_DEPLOY_COMMIT', 'VAPOR_COMMIT_HASH'] as $rejected) {
        expect(str_contains($source, $rejected))
            ->toBeFalse("config/nightwatch.php must not derive the deploy identity from {$rejected}");
    }

    expect($source)->toContain('DeploymentMetadata');
});

it('reports no deploy rather than a fabricated one when the release metadata is absent', function () {
    // The normal state of a working copy: .gitignore keeps release.json out of
    // one entirely. Nothing may invent a stand-in.
    expect(config('deployment.metadata_state'))->toBe('missing');
    expect(config('nightwatch.deployment'))->toBeNull();

    $this->get('/')->assertSuccessful();
});
