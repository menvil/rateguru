<?php

use Illuminate\Support\Facades\File;

/**
 * The Nightwatch evaluation runbook is operational, not decorative: an operator has to be
 * able to create the Nightwatch account, configure staging, install the agent,
 * run the acceptance matrix and remove the whole thing from this one page.
 * These assert the facts that would silently rot — paths, program names,
 * variable names and the secret model — against the real files they describe.
 */
function nightwatchRunbook(): string
{
    return File::get(base_path('infrastructure/runbooks/nightwatch-evaluation.md'));
}

it('lives inside the existing runbook structure', function () {
    expect(File::exists(base_path('infrastructure/runbooks/nightwatch-evaluation.md')))->toBeTrue();

    // No parallel docs hierarchy: it sits with the other runbooks, is reachable
    // from the infrastructure index, and is cross-linked from the Sentry
    // runbook it is being compared against.
    expect(File::get(base_path('infrastructure/README.md')))
        ->toContain('runbooks/nightwatch-evaluation.md');

    expect(File::get(base_path('infrastructure/runbooks/sentry-observability.md')))
        ->toContain('nightwatch-evaluation.md');

    expect(File::get(base_path('infrastructure/ROADMAP.md')))
        ->toContain('nightwatch-evaluation.md');
});

it('documents the exact manual Nightwatch account setup', function () {
    expect(nightwatchRunbook())
        ->toContain('RateGuru Backend')
        ->toContain('EU')
        ->toContain('staging-main')
        ->toContain('Staging')
        // One application, environments per deployment target — never a
        // separate application per brand.
        ->toContain('RateGuru staging')
        ->toContain('tits.guru');
});

it('gives the real staging paths and program names this repository uses', function () {
    $runbook = nightwatchRunbook();
    $registry = json_decode(
        (string) File::get(base_path('infrastructure/config/deployment-targets.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $target = $registry['targets']['staging-main'];

    expect($runbook)
        ->toContain($target['application_root'])
        ->toContain($target['application_root'].'/shared/.env')
        ->toContain($target['application_root'].'/current')
        ->toContain($target['runtime_user'])
        ->toContain('rateguru-staging-nightwatch')
        ->toContain('/etc/supervisor/conf.d/rateguru-staging-nightwatch.conf')
        ->toContain($target['application_root'].'/shared/storage/logs/nightwatch-agent.log');

    // The installer it tells the operator to run actually exists, and is
    // runnable — a committed file that lost its executable bit is precisely
    // the failure InfrastructureScriptExecutableModesTest exists for.
    expect($runbook)->toContain('infrastructure/scripts/install-nightwatch-agent');
    expect(is_file(base_path('infrastructure/scripts/install-nightwatch-agent')))->toBeTrue();
    expect(is_executable(base_path('infrastructure/scripts/install-nightwatch-agent')))->toBeTrue();
});

it('names only environment variables the installed package actually supports', function () {
    $runbook = nightwatchRunbook();

    // Every NIGHTWATCH_* variable the runbook mentions must be one
    // config/nightwatch.php really reads — an invented one would be silently
    // ignored on a live server.
    expect(preg_match_all('/\bNIGHTWATCH_[A-Z_]+\b/', $runbook, $matches))->toBeGreaterThan(0);

    // Comments stripped: config/nightwatch.php names NIGHTWATCH_DEPLOY in
    // prose, to say it is deliberately *not* read. Matching against the raw
    // file would accept a runbook that told an operator to set it.
    $config = phpSourceWithoutComments('config/nightwatch.php');

    foreach (array_unique($matches[0]) as $variable) {
        expect(str_contains($config, $variable))
            ->toBeTrue("the runbook names {$variable}, which config/nightwatch.php does not read");
    }
});

it('states the secret model: the token lives in shared .env and nowhere else', function () {
    expect(nightwatchRunbook())
        ->toContain('NIGHTWATCH_TOKEN')
        ->toContain('runtime secret')
        ->toContain('Never in the repository')
        // And the repository practises it: no committed file carries a value.
        ->toContain('never printed');

    foreach ([
        '.env.example',
        'infrastructure/templates/environment/staging.env.example',
    ] as $path) {
        expect(File::get(base_path($path)))->toContain('NIGHTWATCH_TOKEN=');
        expect(File::get(base_path($path)))->not->toMatch('/NIGHTWATCH_TOKEN=\S/');
    }
});

it('explains why a shared .env change needs a deployment', function () {
    // RateGuru caches Laravel configuration inside each immutable release, so
    // editing shared .env changes nothing for the release already serving.
    expect(nightwatchRunbook())
        ->toContain('config:cache')
        ->toContain('redeploy')
        ->toContain('Do **not** run `artisan config:cache` by hand');
});

it('documents the acceptance matrix, including what is deliberately off', function () {
    $runbook = nightwatchRunbook();

    foreach ([
        'Requests', 'Queries', 'Commands', 'Cache', 'Outgoing HTTP',
        'Mail and notifications', 'Logs', 'Scheduled tasks',
    ] as $section) {
        expect($runbook)->toContain($section);
    }

    // The sentinel values the SQL privacy claim is proven with, in the runbook
    // and in the test that proves it.
    expect($runbook)
        ->toContain('NW_PRIVATE_SENTINEL_123')
        ->toContain('nightwatch-secret@example.invalid');

    expect(File::get(base_path('tests/Feature/Observability/NightwatchIngestPrivacyTest.php')))
        ->toContain('NW_PRIVATE_SENTINEL_123')
        ->toContain('nightwatch-secret@example.invalid');
});

it('regenerates the controlled queue-failure scenario from current code', function () {
    $runbook = nightwatchRunbook();
    $job = File::get(base_path('app/Jobs/RunMediaAuditJob.php'));

    // The lock identity, the store and the failure message all come from the
    // job as it is today — not copied forward from the Sentry runbook.
    expect($runbook)
        ->toContain('media-audit:full')
        ->toContain('A full media audit is already running.')
        ->toContain('queue:forget');

    expect($job)
        ->toContain("lock('media-audit:full'")
        ->toContain("Cache::store('database')");

    expect(File::get(base_path('app/Services/Media/Exceptions/MediaAuditAlreadyRunningException.php')))
        ->toContain('A full media audit is already running.');

    // Never the blunt instrument: it would delete unrelated failures.
    expect($runbook)->toContain('Never `queue:flush`');
});

it('documents the loopback constraint and the future per-target port problem', function () {
    expect(nightwatchRunbook())
        ->toContain('127.0.0.1:2407')
        ->toContain('0.0.0.0:2407')
        ->toContain('loopback')
        // One agent = one environment token = one local ingest port. The
        // per-target ports are described, and deliberately not yet added to
        // the registry.
        ->toContain('2408')
        ->toContain('2409')
        ->toContain('not** in the target registry');
});

it('documents the disable and removal procedure at every level', function () {
    $runbook = nightwatchRunbook();

    expect($runbook)
        ->toContain('NIGHTWATCH_ENABLED=false')
        ->toContain('install-nightwatch-agent --remove --target staging-main')
        ->toContain('composer remove laravel/nightwatch')
        // Every file a future removal PR would touch is listed, and each one
        // must actually exist today.
        ->toContain('config/nightwatch.php')
        ->toContain('app/Support/Observability/NightwatchPrivacy.php')
        ->toContain('infrastructure/config/supervisor/rateguru-staging-nightwatch.conf');

    foreach ([
        'config/nightwatch.php',
        'app/Support/Observability/NightwatchPrivacy.php',
        'infrastructure/config/supervisor/rateguru-staging-nightwatch.conf',
        'infrastructure/scripts/install-nightwatch-agent',
    ] as $path) {
        expect(is_file(base_path($path)))->toBeTrue("the removal list names {$path}, which does not exist");
    }
});

it('states the overhead procedure honestly, without inventing precision', function () {
    expect(nightwatchRunbook())
        ->toContain('p50')
        ->toContain('p95')
        ->toContain('Sentry-only baseline')
        ->toContain('ranges and uncertainty')
        // No destructive or high-concurrency load test against staging.
        ->toContain('non-destructive');
});

it('records that production is untouched and that Phase 6C makes the decision', function () {
    expect(nightwatchRunbook())
        ->toContain('Phase 6C')
        ->toContain('on `staging-main` only')
        ->toContain('tits-guru')
        ->toContain('untouched');
});
