<?php

use App\Support\Deployment\DeploymentMetadata;
use Illuminate\Support\Facades\File;

/**
 * These assert RateGuru's own decisions, not the vendor config file. Anything
 * we deliberately departed from the SDK default on is pinned here, so an SDK
 * upgrade that changes a default cannot silently change our posture.
 */
it('never probabilistically drops a backend error', function () {
    expect(config('sentry.sample_rate'))->toBe(1.0);
});

it('leaves tracing off unless a target opts in, and keeps the rate configurable', function () {
    // Local and CI have no SENTRY_TRACES_SAMPLE_RATE, so no transactions are
    // ever built there. The value itself is never hardcoded in PHP.
    expect(config('sentry.traces_sample_rate'))->toBeNull();

    // Every one of these stays driven by the environment rather than a literal
    // in PHP — read through the blank-safe helpers, never a bare env() whose
    // `=== null` check would mistake an empty value for a configured one.
    expect(File::get(base_path('config/sentry.php')))
        ->toContain("\$sentryFloat('SENTRY_TRACES_SAMPLE_RATE', null)")
        ->toContain("\$sentryString('SENTRY_ENVIRONMENT')")
        ->toContain("\$sentryString('SENTRY_LARAVEL_DSN', env('SENTRY_DSN'))");
});

it('keeps profiling disabled', function () {
    expect(config('sentry.profiles_sample_rate'))->toBe(0.0);
});

it('treats a key present with an empty value as not configured', function (string $key, string $configKey, mixed $expected) {
    // .env.example and both environment templates ship these keys blank —
    // that is the repository's convention for "not configured for this
    // target", and CI builds its .env straight from .env.example. env()
    // returns '' for a blank key, not null, so a naive `=== null` check
    // silently turns SENTRY_TRACES_SAMPLE_RATE= into 0.0 — and a non-null
    // rate makes the SDK build and discard a transaction on every request.
    $_ENV[$key] = '';
    $_SERVER[$key] = '';
    putenv("{$key}=");

    try {
        $config = require base_path('config/sentry.php');
    } finally {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    expect(data_get($config, $configKey))->toBe($expected);
})->with([
    ['SENTRY_TRACES_SAMPLE_RATE', 'traces_sample_rate', null],
    ['SENTRY_ENVIRONMENT', 'environment', null],
    ['SENTRY_LARAVEL_DSN', 'dsn', null],
    // A blank rate must fall back to the intended default, not to 0.0/null.
    ['SENTRY_SAMPLE_RATE', 'sample_rate', 1.0],
    ['SENTRY_PROFILES_SAMPLE_RATE', 'profiles_sample_rate', 0.0],
]);

it('still honours an explicitly configured rate, including a deliberate zero', function (string $key, string $configKey, string $raw, float $expected) {
    $_ENV[$key] = $raw;
    $_SERVER[$key] = $raw;
    putenv("{$key}={$raw}");

    try {
        $config = require base_path('config/sentry.php');
    } finally {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    expect(data_get($config, $configKey))->toBe($expected);
})->with([
    ['SENTRY_TRACES_SAMPLE_RATE', 'traces_sample_rate', '1.0', 1.0],
    ['SENTRY_TRACES_SAMPLE_RATE', 'traces_sample_rate', '0.10', 0.10],
    // An explicit 0 is a real, different instruction from a blank value.
    ['SENTRY_TRACES_SAMPLE_RATE', 'traces_sample_rate', '0', 0.0],
    ['SENTRY_SAMPLE_RATE', 'sample_rate', '0.5', 0.5],
]);

it('produces the intended configuration from the committed environment templates', function (string $template, ?string $expectedEnvironment, ?float $expectedTraces) {
    // The end-to-end version of the two tests above: parse a real committed
    // template exactly as an operator would install it, and assert what the
    // application actually ends up configured with.
    $applied = [];

    foreach (preg_split('/\R/', File::get(base_path($template))) ?: [] as $line) {
        if (! str_contains($line, '=') || str_starts_with(trim($line), '#')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);

        if (! str_starts_with($key, 'SENTRY_')) {
            continue;
        }

        $applied[] = $key;
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    expect($applied)->not->toBeEmpty("{$template} must configure Sentry");

    try {
        $config = require base_path('config/sentry.php');
    } finally {
        foreach ($applied as $key) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
        }
    }

    expect($config['environment'])->toBe($expectedEnvironment)
        ->and($config['traces_sample_rate'])->toBe($expectedTraces)
        ->and($config['sample_rate'])->toBe(1.0)
        ->and($config['profiles_sample_rate'])->toBe(0.0)
        ->and($config['send_default_pii'])->toBeFalse()
        ->and($config['enable_logs'])->toBeFalse()
        ->and($config['enable_metrics'])->toBeFalse()
        // The template ships an empty DSN; a real one is pasted in by hand.
        ->and($config['dsn'])->toBeNull();
})->with([
    ['infrastructure/templates/environment/staging.env.example', 'staging', 1.0],
    ['infrastructure/templates/environment/production.env.example', 'production', 0.10],
]);

it('keeps Sentry structured logs disabled so Sentry never becomes the log store', function () {
    expect(config('sentry.enable_logs'))->toBeFalse();
});

it('explicitly disables Sentry metrics rather than inheriting the SDK default', function () {
    expect(config('sentry.enable_metrics'))->toBeFalse();

    // The vendor default is `true`. If that ever stops being the case the
    // explicit override is still correct — but this proves we are overriding
    // rather than coincidentally agreeing with the package.
    expect(File::get(base_path('vendor/sentry/sentry-laravel/config/sentry.php')))
        ->toContain("'enable_metrics' => env('SENTRY_ENABLE_METRICS', true)");

    expect(File::get(base_path('config/sentry.php')))
        ->toContain("'enable_metrics' => env('SENTRY_ENABLE_METRICS', false)");
});

it('never sends default PII', function () {
    expect(config('sentry.send_default_pii'))->toBeFalse();
});

it('never captures SQL bindings, in breadcrumbs or in spans', function () {
    expect(config('sentry.breadcrumbs.sql_bindings'))->toBeFalse()
        ->and(config('sentry.tracing.sql_bindings'))->toBeFalse();

    // Hardcoded rather than env-driven on purpose: there must be no switch an
    // operator could flip to start shipping user data in query parameters.
    $source = File::get(base_path('config/sentry.php'));

    expect($source)
        ->not->toContain('SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED')
        ->not->toContain('SENTRY_TRACE_SQL_BINDINGS_ENABLED');
});

it('keeps SQL query text itself, which is what makes a trace useful', function () {
    expect(config('sentry.breadcrumbs.sql_queries'))->toBeTrue()
        ->and(config('sentry.tracing.sql_queries'))->toBeTrue();
});

it('keeps the breadcrumb categories that make an error diagnosable', function () {
    foreach (['logs', 'cache', 'livewire', 'sql_queries', 'queue_info', 'command_info', 'http_client_requests', 'notifications'] as $category) {
        expect(config("sentry.breadcrumbs.{$category}"))->toBeTrue("breadcrumb category {$category} must stay enabled");
    }
});

it('traces the backend operations the observability work cares about', function () {
    foreach (['queue_job_transactions', 'queue_jobs', 'sql_queries', 'views', 'livewire', 'http_client_requests', 'cache', 'notifications'] as $feature) {
        expect(config("sentry.tracing.{$feature}"))->toBeTrue("tracing feature {$feature} must stay enabled");
    }

    // Redis stays off: the cache and queue already produce their own spans.
    expect(config('sentry.tracing.redis_commands'))->toBeFalse();
});

it('excludes the health endpoint from performance monitoring, and only that endpoint', function () {
    // /up is the route bootstrap/app.php registers as `health:` and the exact
    // path infrastructure/scripts/health-check probes on every deploy.
    expect(config('sentry.ignore_transactions'))->toBe(['/up']);

    expect(File::get(base_path('bootstrap/app.php')))->toContain("health: '/up'");
    expect(File::get(base_path('infrastructure/scripts/health-check')))->toContain('/up');
});

it('suppresses no exception class at all', function () {
    // Sentry only ever sees what Laravel already considered reportable, so
    // ordinary user mistakes are excluded upstream. A local ignore list here
    // could only hide genuine 5xx failures.
    expect(config('sentry.ignore_exceptions', []))->toBe([]);
});

it('derives the Sentry release from the canonical deployment metadata, not from an env variable', function () {
    // The value in this environment (no release.json in a working copy) is
    // null, and the derivation is the same call config/deployment.php makes.
    expect(config('sentry.release'))
        ->toBe(DeploymentMetadata::fromBasePath(base_path())->release())
        ->toBe(config('deployment.release'));

    $source = File::get(base_path('config/sentry.php'));

    expect($source)
        ->toContain('DeploymentMetadata::fromBasePath(dirname(__DIR__))->release()')
        // No second, independently maintained release identity may exist.
        ->not->toContain("env('SENTRY_RELEASE')");

    foreach (['.env.example', 'infrastructure/templates/environment/staging.env.example', 'infrastructure/templates/environment/production.env.example'] as $path) {
        expect(File::get(base_path($path)))
            ->not->toContain('SENTRY_RELEASE')
            ->not->toContain('APP_RELEASE')
            ->not->toContain('APP_VERSION');
    }
});

it('survives config caching with real release metadata', function () {
    // Deployments run `artisan config:cache`, so every value above has to be
    // representable in a cached config file. Nothing here may be a closure or
    // an object, and the release must be frozen at the value the release
    // directory carries rather than re-derived at request time.
    $cacheable = [
        config('sentry.release'),
        config('sentry.environment'),
        config('sentry.sample_rate'),
        config('sentry.traces_sample_rate'),
        config('sentry.profiles_sample_rate'),
        config('sentry.enable_logs'),
        config('sentry.enable_metrics'),
        config('sentry.send_default_pii'),
        config('sentry.ignore_transactions'),
        config('sentry.breadcrumbs'),
        config('sentry.tracing'),
        config('deployment'),
    ];

    $exported = var_export($cacheable, true);

    expect($exported)
        ->not->toContain('Closure')
        ->not->toContain('\\Object')
        ->and(eval("return {$exported};"))->toBe($cacheable);
});

it('exposes the deployment target as configuration, never as a hardcoded target ID', function () {
    // One list, used for both halves: a target the source scan below forgot
    // would be exactly the one a hardcoded reference could hide in.
    $targets = ['staging-main', 'tits-guru', 'food-guru', 'animals-guru'];

    // Every active and planned target must work without a code change.
    foreach ($targets as $target) {
        config()->set('deployment.target', $target);
        expect(config('deployment.target'))->toBe($target);
    }

    foreach ([
        'config/deployment.php',
        'config/sentry.php',
        'config/nightwatch.php',
        'app/Providers/ObservabilityServiceProvider.php',
        'app/Support/Deployment/DeploymentMetadata.php',
        'app/Support/Observability/NightwatchPrivacy.php',
    ] as $path) {
        $code = phpSourceWithoutComments($path);

        foreach ($targets as $target) {
            expect(str_contains($code, $target))
                ->toBeFalse("{$path} must not special-case the target {$target}");
        }
    }
});

it('rejects a target ID that is not registry-shaped instead of tagging events with junk', function (mixed $raw, ?string $expected) {
    // config/deployment.php normalizes the raw env value; re-running that exact
    // expression is what this asserts, without mutating the process env.
    $normalized = is_string($raw) && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $raw) === 1 ? $raw : null;

    expect($normalized)->toBe($expected);

    expect(File::get(base_path('config/deployment.php')))
        ->toContain("preg_match('/^[a-z0-9]+(-[a-z0-9]+)*\$/', \$target)");
})->with([
    ['staging-main', 'staging-main'],
    ['tits-guru', 'tits-guru'],
    ['STAGING-MAIN', null],
    ['staging main', null],
    ['staging/main', null],
    ['-staging', null],
    ['', null],
    [null, null],
]);
