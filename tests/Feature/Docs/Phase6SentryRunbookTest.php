<?php

use Illuminate\Support\Facades\File;

/**
 * The Sentry runbook is operational, not decorative: an operator has to be
 * able to configure a target and verify a deployment from it. These assert the
 * facts that would silently rot — paths, variable names and the secret model —
 * against the real files they describe.
 */
function sentryRunbook(): string
{
    return File::get(base_path('infrastructure/runbooks/sentry-observability.md'));
}

it('documents the Sentry runbook inside the existing runbook structure', function () {
    expect(File::exists(base_path('infrastructure/runbooks/sentry-observability.md')))->toBeTrue();

    // No parallel docs hierarchy: the runbook sits with the other runbooks and
    // is reachable from the infrastructure index.
    expect(File::get(base_path('infrastructure/README.md')))
        ->toContain('runbooks/sentry-observability.md');
});

it('documents what Sentry observes and what it deliberately does not', function () {
    $runbook = sentryRunbook();

    foreach (['unhandled backend exceptions', 'failed queue jobs', 'Livewire', 'SQL quer', 'breadcrumbs'] as $observed) {
        expect($runbook)->toContain($observed);
    }

    foreach (['Session Replay', 'Nightwatch', 'Datadog', 'PostHog', 'host CPU', 'source maps'] as $notObserved) {
        expect($runbook)->toContain($notObserved);
    }
});

it('documents the four-part metadata model', function () {
    expect(sentryRunbook())
        ->toContain('APP_DEPLOYMENT_TARGET')
        ->toContain('deployment_target')
        ->toContain('release.json')
        ->toContain('source_sha')
        // The environment is the class, never the brand.
        ->toContain('production-tits-guru');
});

it('states explicitly that the Sentry auth token never belongs on the VPS', function () {
    expect(sentryRunbook())
        ->toContain('`SENTRY_AUTH_TOKEN` never belongs on the VPS')
        ->toContain('SENTRY_ORG')
        ->toContain('SENTRY_PROJECT');
});

it('gives the exact staging paths this repository actually uses', function () {
    $runbook = sentryRunbook();

    // The application root and the shared env file the deploy script symlinks.
    expect($runbook)
        ->toContain('/home/www/rateguru/staging')
        ->toContain('/home/www/rateguru/staging/shared/.env');

    // Both must match the committed registry and the deploy script, not a
    // remembered value.
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);

    expect(data_get($registry, 'targets.staging-main.application_root'))
        ->toBe('/home/www/rateguru/staging');

    expect(File::get(base_path('infrastructure/scripts/deploy')))
        ->toContain('"${TARGET_ROOT}/shared/.env"');
});

it('documents every environment variable the staging template actually sets', function () {
    $runbook = sentryRunbook();
    $template = File::get(base_path('infrastructure/templates/environment/staging.env.example'));

    foreach (preg_split('/\R/', $template) ?: [] as $line) {
        $key = strtok(trim($line), '=');

        if (! is_string($key) || ! str_starts_with($key, 'SENTRY_') && $key !== 'APP_DEPLOYMENT_TARGET') {
            continue;
        }

        expect(str_contains($runbook, $key))->toBeTrue("the runbook must document {$key}");
    }
});

it('warns that a redeploy is required because deployments cache configuration', function () {
    expect(sentryRunbook())
        ->toContain('config:cache')
        ->toContain('redeploy is required');

    // The claim has to stay true: the deploy script really does cache config.
    expect(File::get(base_path('infrastructure/scripts/deploy')))
        ->toContain('artisan config:cache');
});

it('documents the manual Sentry UI work code cannot do', function () {
    expect(sentryRunbook())
        ->toContain('Data Scrubber')
        ->toContain('Scrub IP Addresses')
        ->toContain('regression')
        ->toContain('Do **not** configure performance alerts yet');
});

it('documents the deployment marker ordering and the Sentry-outage rule', function () {
    expect(sentryRunbook())
        ->toContain('health check')
        ->toContain('continue-on-error')
        ->toContain('must not be rolled back for this');
});

it('documents the manual-rollback limitation instead of weakening the secret model', function () {
    expect(sentryRunbook())
        ->toContain('cannot** create a Sentry deployment marker')
        ->toContain('accepted, documented limitation');
});

it('gives read-only verification commands that never print a credential', function () {
    $runbook = sentryRunbook();

    expect($runbook)
        ->toContain('artisan about')
        ->toContain('rateguru:observability:health')
        ->toContain('sentry:test')
        ->toContain('never prints the DSN');

    // The commands named must exist: sentry:test ships with the SDK, and the
    // health command is ours.
    expect(File::exists(base_path('vendor/sentry/sentry-laravel/src/Sentry/Laravel/Console/TestCommand.php')))->toBeTrue();
    expect(File::get(base_path('app/Console/Commands/ObservabilityHealthCommand.php')))
        ->toContain("protected \$signature = 'rateguru:observability:health';");
});

it('contains no real DSN, auth token or Sentry ingest host', function () {
    // Documentation is the easiest place for a credential to be pasted by
    // accident, so it gets the same guard the target registry has.
    foreach ([
        'infrastructure/runbooks/sentry-observability.md',
        'docs/observability/external-integrations.md',
    ] as $path) {
        $contents = File::get(base_path($path));

        expect($contents)
            ->not->toMatch('/https:\/\/[0-9a-f]{16,}@/', "{$path} appears to contain a real DSN")
            ->not->toMatch('/sntrys_[A-Za-z0-9]/', "{$path} appears to contain a real Sentry token");
    }
});

it('keeps the Phase 54 external-integrations note honest about what is installed', function () {
    expect(File::get(base_path('docs/observability/external-integrations.md')))
        ->toContain('Sentry — installed')
        ->toContain('infrastructure/runbooks/sentry-observability.md')
        ->toContain('Deliberately not installed');
});
