<?php

use Illuminate\Support\Facades\File;

function infrastructureSource(string $path): string
{
    $path = base_path('infrastructure/'.$path);

    expect(File::exists($path))->toBeTrue();

    return File::get($path);
}

it('keeps staging maintenance active without enabling production prematurely', function () {
    $backups = infrastructureSource('config/cron/rateguru-backups');
    $scheduler = infrastructureSource('config/cron/rateguru-staging-scheduler');

    expect($backups)
        ->toContain('backup-cycle --target staging-main')
        ->toContain('staging-local-restore-test.log')
        ->toContain('staging-offsite-restore-test.log')
        ->not->toContain('--environment')
        // The registry's only production target is tits-guru
        // (lifecycle=planned) — the target-selector world's equivalent of
        // the old "--environment production" guard this test's name
        // promises.
        ->not->toContain('tits-guru')
        ->and($scheduler)
        ->toContain('rateguru-staging cd /home/www/rateguru/staging/current')
        ->not->toContain('rateguru-production');
});

it('keeps common free of removed legacy helpers', function () {
    $common = infrastructureSource('scripts/common');

    // Every operational script sources common for target_* helpers,
    // require_root, and the target-only parsing contract. The environment_*
    // selector helpers and the dual-selector parser are gone entirely —
    // this is the final the target-aware migration cutover slice. See
    // BackupTest.php/RestoreTest.php,
    // OffsiteBackupTest.php/OffsiteRetentionTest.php/OffsiteRestoreTest.php
    // and BackupCycleTest.php for their own coverage.
    expect($common)
        ->not->toContain('parse_environment_argument()')
        ->not->toContain('parse_environment_argument')
        ->not->toContain('acquire_operation_lock()')
        ->not->toContain('acquire_operation_lock')
        ->not->toContain('validate_environment()')
        ->not->toContain('environment_root()')
        ->not->toContain('environment_public_hostname()')
        ->not->toContain('parse_selector_args()')
        ->not->toContain('assert_selector_state()')
        ->toContain('parse_target_args()');

    expect(infrastructureSource('scripts/restore-test'))
        ->toContain('exec 9>"${LOCK_FILE}"')
        ->toContain('flock -n 9');
});

it('validates deployment configuration security before sourcing', function () {
    expect(infrastructureSource('scripts/common'))
        ->toContain('! -f "${CONFIG_FILE}"')
        ->toContain('-L "${CONFIG_FILE}"')
        ->toContain('stat -c \'%u:%g\' "${CONFIG_FILE}"')
        ->toContain('0:0')
        ->toContain('stat -c \'%a\' "${CONFIG_FILE}"')
        ->toContain('8#022')
        ->toContain('source "${CONFIG_FILE}"');
});

it('provides a deployable, host-global, non-secret deployment configuration template', function () {
    expect(infrastructureSource('templates/deployment.conf.example'))
        ->toContain('RELEASE_ID_REGEX=')
        ->toContain('PHP_BIN=/usr/bin/php8.5')
        ->toContain('PHP_FPM_SERVICE=php8.5-fpm')
        // Target-specific values live only in the target registry now — this
        // template must never resurrect the legacy per-environment settings.
        ->not->toContain('STAGING_ROOT')
        ->not->toContain('STAGING_RUNTIME_USER')
        ->not->toContain('STAGING_CODE_GROUP')
        ->not->toContain('STAGING_DEPLOY_USER')
        ->not->toContain('STAGING_INCOMING_ARTIFACTS')
        ->not->toContain('STAGING_RELEASE_RETENTION')
        ->not->toContain('PRODUCTION_ROOT')
        ->not->toContain('PRODUCTION_RUNTIME_USER')
        ->not->toContain('PRODUCTION_CODE_GROUP')
        ->not->toContain('PRODUCTION_DEPLOY_USER')
        ->not->toContain('PRODUCTION_INCOMING_ARTIFACTS')
        ->not->toContain('PRODUCTION_RELEASE_RETENTION');
});

it('keeps deployment failure recovery active until terminal history is written', function () {
    $deploy = infrastructureSource('scripts/deploy');

    expect($deploy)
        ->toContain('DEPLOYMENT_STARTED=false')
        ->toContain('DEPLOYMENT_STARTED=true')
        ->toContain('TERMINAL_HISTORY_WRITTEN=false')
        ->toContain('CURRENT_SWITCHED=false')
        ->toContain('ORIGINAL_CURRENT_PATH=')
        ->toContain('handle_deployment_exit()')
        ->toContain('restore_deployment_links()')
        ->toContain('FAILURE_STATUS="failed-preparation"')
        ->toContain('FAILURE_STATUS="failed-health-check"')
        ->not->toContain('migrate:rollback')
        // The terminal history event has a name, because a controlled
        // deployment must never record "deployment-finished success" for a
        // target that is still held. The literal appears once, as that name's
        // default; the name itself is what the ordinary success path, each
        // controlled mode's finalizer and the failure handler write — four
        // call sites, one event name between them.
        ->and(substr_count($deploy, 'DEPLOY_HISTORY_FINISH_EVENT="deployment-finished"'))->toBe(1)
        ->and(substr_count($deploy, '"${DEPLOY_HISTORY_FINISH_EVENT}"'))->toBe(4)
        ->and(substr_count($deploy, 'trap - EXIT'))->toBe(1);
});

it('blocks deployment when sharing urls do not match the public hostname', function () {
    $deploy = infrastructureSource('scripts/deploy');
    $registry = infrastructureSource('config/deployment-targets.json');

    expect($deploy)
        ->toContain('PUBLIC_HOSTNAME="$(target_primary_public_hostname "${TARGET_ID}")"')
        ->toContain('artisan rateguru:sharing:verify')
        ->toContain('--expected-host="${PUBLIC_HOSTNAME}"')
        ->and($registry)
        ->toContain('"rateguru.staging.myprojects.pp.ua"')
        ->toContain('"tits.guru"');
});

it('normalizes release permissions while preserving the executable bit', function () {
    $deploy = infrastructureSource('scripts/deploy');

    // Extract the real normalization block from the script and run it against a
    // fixture, so this proves the shipped code's behavior, not a copy of it.
    expect(preg_match(
        '/# --- normalize release permissions \(begin\) ---\n(.*?)\n\s*# --- normalize release permissions \(end\) ---/s',
        $deploy,
        $matches,
    ))->toBe(1, 'could not locate the release permission normalization block in scripts/deploy');

    $block = $matches[1];

    // Structural guard (always runs, even where execution is skipped below):
    // the release-extraction step must key on the executable bit and never
    // blanket-chmod every file, so it must not force world modes.
    expect($block)
        ->toContain('-perm /111 \\'."\n".'        -exec chmod 0750 {} +')
        ->toContain('! -perm /111 \\'."\n".'        -exec chmod 0640 {} +')
        // Every `-type f` in the block is guarded by a `-perm` clause; a
        // `-type f` leading straight into `-exec` would be the old blanket step.
        ->not->toMatch('/-type f\s*\\\\\n\s*-exec/')
        ->not->toContain('chmod 0777');

    // The block uses GNU find's `-perm /MODE` (the deploy target is Linux).
    // Skip execution where the host find lacks it (e.g. BSD find on macOS);
    // Linux CI still exercises the real behavior.
    exec('find '.escapeshellarg(sys_get_temp_dir()).' -maxdepth 0 -perm /111 >/dev/null 2>&1', $probe, $probeExit);
    if ($probeExit !== 0) {
        test()->markTestSkipped('host find does not support `-perm /111` (GNU find only)');
    }

    $root = sys_get_temp_dir().'/deploy-perms-'.uniqid();

    // Fixture => expected mode after normalization. Covers each exec bit
    // independently (owner/group/other), because `-perm /111` means *any* of
    // them, and both common non-executable modes.
    $executable = [
        'bin/install-mail-capture' => 0o755,   // typical Git-executable script
        'bin/owner-exec-only' => 0o700,        // exec bit only for the owner
        'group-exec-only' => 0o010,            // exec bit only for the group
        'other-exec-only' => 0o001,            // exec bit only for others
    ];

    $nonExecutable = [
        'config.php' => 0o644,
        'bin/readme.md' => 0o600,
    ];

    try {
        mkdir($root.'/bin', 0o700, true);

        foreach ($executable + $nonExecutable as $path => $mode) {
            file_put_contents($root.'/'.$path, "fixture\n");
            chmod($root.'/'.$path, $mode);
        }

        $script = 'set -euo pipefail'."\n"
            .'TEMP_RELEASE_ROOT='.escapeshellarg($root)."\n"
            .$block;

        $output = [];
        $exit = 0;
        exec('bash -c '.escapeshellarg($script).' 2>&1', $output, $exit);
        expect($exit)->toBe(0, "normalization block failed:\n".implode("\n", $output));

        clearstatcache();

        $mode = fn (string $path): int => fileperms($root.'/'.$path) & 0o777;

        // 1. anything carrying an executable bit ends up 0750 — still runnable.
        foreach (array_keys($executable) as $path) {
            expect($mode($path))->toBe(0o750, "executable bit lost on {$path}");
        }

        // 2. plain files end up 0640 — readable, never executable.
        foreach (array_keys($nonExecutable) as $path) {
            expect($mode($path))->toBe(0o640, "wrong mode on {$path}");
            expect($mode($path) & 0o111)->toBe(0, "exec bit granted to {$path}");
        }

        // 3. directories stay traversable at 0750.
        expect($mode('bin'))->toBe(0o750);
        expect($mode(''))->toBe(0o750);

        // 4. no world bits anywhere, on any entry.
        foreach ([...array_keys($executable), ...array_keys($nonExecutable), 'bin', ''] as $path) {
            expect($mode($path) & 0o007)->toBe(0, "world bits set on {$path}");
        }
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});

it('documents immutable offsite upload recovery', function () {
    expect(infrastructureSource('runbooks/backups.md'))
        ->toContain('offsite-backup')
        ->toContain('--immutable')
        ->toContain('stale remote object')
        ->toContain('manual cleanup');
});

it('handles an absent migrations table in both restore tests', function () {
    foreach (['restore-test', 'offsite-restore-test'] as $script) {
        expect(infrastructureSource('scripts/'.$script))
            ->toContain("WHEN to_regclass('public.migrations') IS NULL")
            ->toContain('ELSE (SELECT count(*) FROM migrations)');
    }
});

it('restores both original links for every failed rollback switch', function () {
    $rollback = infrastructureSource('scripts/rollback');

    expect($rollback)
        ->toContain('ORIGINAL_CURRENT_PATH=')
        ->toContain('ORIGINAL_PREVIOUS_PATH=')
        ->toContain('ORIGINAL_PREVIOUS_PRESENT=false')
        ->toContain('ROLLBACK_STARTED=false')
        ->toContain('SWITCH_STARTED=false')
        ->toContain('TERMINAL_HISTORY_WRITTEN=false')
        ->toContain('handle_rollback_exit()')
        ->toContain('restore_original_links()')
        ->toContain('systemctl reload "${PHP_FPM_SERVICE}"')
        ->toContain('FAILURE_STATUS="failed-health-check"')
        ->and(substr_count($rollback, '"rollback-finished"'))->toBe(2)
        ->and(substr_count($rollback, 'trap - EXIT'))->toBe(1)
        // Both the normal and recovery health-check calls go through the
        // gated HEALTH_CHECK_BIN override with the target resolved once in
        // resolve_target (HEALTH_CHECK_ARGS), the same pattern deploy
        // already establishes for its own health-check call. toContain
        // would only prove one occurrence exists; substr_count proves both
        // call sites (normal switch and recovery) use it.
        ->and(substr_count($rollback, '"${HEALTH_CHECK_BIN}" "${HEALTH_CHECK_ARGS[@]}"'))->toBe(2);
});

it('continues status output when release metadata or history is malformed', function () {
    expect(infrastructureSource('scripts/status'))
        ->toContain('if RELEASE_METADATA="$(jq . "${CURRENT_LINK}/release.json" 2>/dev/null)"')
        ->toContain('release.json is malformed')
        ->toContain('echo "Health"')
        ->toContain('echo "Recent deployment history"')
        ->toContain('while IFS= read -r history_entry')
        ->toContain('if ! jq . <<<"${history_entry}"')
        ->toContain('Malformed history entry:');
});

it('provides required production environment settings', function () {
    expect(infrastructureSource('templates/environment/production.env.example'))
        ->toContain('APP_ENV=production')
        ->toContain('APP_DEBUG=false')
        ->toContain('APP_LOCALE=en')
        ->toContain('APP_FALLBACK_LOCALE=en')
        ->toContain('MAIL_FROM_ADDRESS=')
        ->toContain('MAIL_FROM_NAME=')
        ->toContain('QUEUE_CONNECTION=redis')
        ->toContain('MEDIA_PUBLIC_DISK=public')
        ->toContain('REDIS_QUEUE=')
        ->toContain('REDIS_QUEUE_CONNECTION=')
        ->toContain('REDIS_QUEUE_RETRY_AFTER=')
        ->toContain('SESSION_SECURE_COOKIE=true');
});

it('uses safe defaults in the staging environment template', function () {
    expect(infrastructureSource('templates/environment/staging.env.example'))
        ->toContain('APP_ENV=staging')
        ->toContain('APP_DEBUG=false')
        ->toContain('APP_LOCALE=en')
        ->toContain('APP_FALLBACK_LOCALE=en')
        ->toContain('QUEUE_CONNECTION=redis')
        ->toContain('MEDIA_PUBLIC_DISK=')
        ->toContain('SESSION_SECURE_COOKIE=true');
});
