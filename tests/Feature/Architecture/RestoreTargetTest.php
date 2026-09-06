<?php

use Illuminate\Support\Facades\File;

/**
 * Restore Target Data: `restore-target` — the whole live data restore, end to end.
 *
 * Every test here runs the REAL orchestrator against the REAL fetch-backup,
 * verify-backup, restore-database and restore-storage it drives — only the
 * host boundary is stubbed (a file-backed fake PostgreSQL, this target's own
 * Supervisor program, Laravel's maintenance mode, the existing backup /
 * restore-test / health-check implementations it reuses). That is what makes
 * "the emergency backup happened before the first live mutation", "the swap
 * was compensated" and "the runtime came back exactly as it was" statements
 * about behaviour rather than about log lines.
 */
function restoreTargetScript(): string
{
    return base_path('infrastructure/scripts/restore-target');
}

/**
 * The full fixture: target tree, fake catalog, cron entry, local backup to
 * restore from, and the emergency-backup template the `backup` stub copies.
 *
 * @return array<string, string>
 */
function restoreTargetFixture(string $scratch, array $options = []): array
{
    targetTreeFixture($scratch, [
        'source_sha' => $options['current_source_sha'] ?? FIXTURE_SOURCE_SHA,
        'release' => $options['current_release'] ?? FIXTURE_RELEASE,
    ]);
    installFakePostgres($scratch, $options['postgres'] ?? []);
    installTargetRuntimeStubs($scratch);

    // The backup being restored from, and a byte-identical template the
    // emergency `backup` stub copies into place as the new latest backup.
    buildBackupFixture($scratch.'/backups/parity', '20260115-120000', $options['backup'] ?? []);
    buildBackupFixture($scratch.'/emergency-src', '20260116-090000');
    exec('mv '.escapeshellarg($scratch.'/emergency-src/20260116-090000').' '.escapeshellarg($scratch.'/emergency-template'));

    if (($options['scheduler'] ?? true) === true) {
        mkdir($scratch.'/cron.d', 0o755, true);
        file_put_contents(
            $scratch.'/cron.d/parity-scheduler',
            "* * * * * runtime cd /target/current && php artisan schedule:run\n",
        );
    }

    [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

    return ['registry' => $registryPath, 'targets' => $targetsPath];
}

/**
 * @return array{exit: int, output: string}
 */
function restoreTargetRun(string $scratch, array $arguments, array $envOverrides = []): array
{
    [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

    $env = infraScriptEnv($scratch, $registryPath, $targetsPath, array_merge(
        fakePostgresEnv($scratch),
        targetRuntimeEnv($scratch),
        [
            'RATEGURU_RESTORE_FETCH_BACKUP_BIN' => patchedInfraScript($scratch, 'fetch-backup'),
            'RATEGURU_RESTORE_VERIFY_BACKUP_BIN' => patchedInfraScript($scratch, 'verify-backup'),
            'RATEGURU_RESTORE_DATABASE_BIN' => patchedInfraScript($scratch, 'restore-database'),
            'RATEGURU_RESTORE_STORAGE_BIN' => patchedInfraScript($scratch, 'restore-storage'),
        ],
        $envOverrides,
    ));

    [$exit, $output] = runInfraScript(patchedInfraScript($scratch, 'restore-target'), $arguments, $env);

    return ['exit' => $exit, 'output' => $output];
}

function restoreTargetApply(string $scratch, array $envOverrides = []): array
{
    return restoreTargetRun($scratch, [
        '--apply', '--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000',
    ], $envOverrides);
}

/** @return list<array<string, mixed>> */
function restoreTargetHistory(string $scratch): array
{
    $path = $scratch.'/restores/restore-history.jsonl';

    if (! is_file($path)) {
        return [];
    }

    return array_map(
        static fn (string $line): array => json_decode($line, true),
        array_values(array_filter(preg_split('/\R/', trim(File::get($path))))),
    );
}

function restoreTargetStorage(string $scratch): string
{
    return $scratch.'/target/shared/storage';
}

function restoreTargetMaintenanceActive(string $scratch): bool
{
    return is_file(restoreTargetStorage($scratch).'/framework/down');
}

function restoreTargetQueueState(string $scratch): string
{
    return trim(File::get($scratch.'/supervisor-state'));
}

function restoreTargetSchedulerPresent(string $scratch): bool
{
    return is_file($scratch.'/cron.d/parity-scheduler');
}

// =============================================================================
// Selection contract
// =============================================================================

it('requires an exact backup and a source, and offers no latest', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $noBackup = restoreTargetRun($scratch, ['--apply', '--target', 'parity-target', '--source', 'local']);
        expect($noBackup['exit'])->not->toBe(0);
        expect($noBackup['output'])->toContain("there is no 'latest'");

        $noSource = restoreTargetRun($scratch, ['--apply', '--target', 'parity-target', '--backup', '20260115-120000']);
        expect($noSource['exit'])->not->toBe(0);
        expect($noSource['output'])->toContain('--source is required');

        $noMode = restoreTargetRun($scratch, ['--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000']);
        expect($noMode['exit'])->not->toBe(0);
        expect($noMode['output'])->toContain('exactly one of --apply, --inspect or --resume is required');

        $bothModes = restoreTargetRun($scratch, ['--apply', '--resume', '--target', 'parity-target']);
        expect($bothModes['exit'])->not->toBe(0);
        expect($bothModes['output'])->toContain('only one of --apply, --inspect or --resume may be given');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// The aligned happy path
// =============================================================================

it('restores database and storage, resumes the target, and reports an aligned restore', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('RESTORE DATA COMPLETE: YES')
            ->toContain('CODE ALIGNMENT: ALIGNED')
            ->toContain('TARGET RESUMED: YES')
            ->toContain('BACKUP SOURCE SHA: '.FIXTURE_SOURCE_SHA);

        // The data actually changed.
        $storage = restoreTargetStorage($scratch);
        expect(is_file($storage.'/app/restored-marker.txt'))->toBeTrue();
        expect(is_file($storage.'/app/live-marker.txt'))->toBeFalse();

        // The canonical database is the restored one; the pre-restore copy
        // was dropped at commit and no staging leftovers remain.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(array_values(array_diff(scandir($storage), ['.', '..'])))
            ->toEqualCanonicalizing(['app', 'framework']);

        // The runtime is back exactly as it was.
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');
        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();
        expect(File::get($scratch.'/health-check.log'))->toContain('--target parity-target');
    } finally {
        removeScratchDir($scratch);
    }
});

it('never rewrites shared/.env, the current link, the previous link or any server configuration', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $envBefore = File::get($scratch.'/target/shared/.env');
        $currentBefore = readlink($scratch.'/target/current');

        expect(restoreTargetApply($scratch)['exit'])->toBe(0);

        expect(File::get($scratch.'/target/shared/.env'))->toBe($envBefore);
        expect(File::get($scratch.'/target/shared/.env'))->not->toContain('from-backup-never-applied');
        expect(readlink($scratch.'/target/current'))->toBe($currentBefore);
        expect(file_exists($scratch.'/target/previous'))->toBeFalse();

        // The release tree itself is untouched: no code was deployed.
        expect(File::get($scratch.'/target/releases/'.FIXTURE_RELEASE.'/release.json'))
            ->toContain(FIXTURE_SOURCE_SHA);
    } finally {
        removeScratchDir($scratch);
    }
});

it('writes a restore history record carrying operational identity and no secret', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        expect(restoreTargetApply($scratch)['exit'])->toBe(0);

        $history = restoreTargetHistory($scratch);
        expect($history)->toHaveCount(1);

        $record = $history[0];

        expect($record)->toMatchArray([
            'status' => 'completed',
            'target' => 'parity-target',
            'environment' => 'staging',
            'backup_namespace' => 'parity',
            'source' => 'local',
            'backup' => '20260115-120000',
            'backup_release' => FIXTURE_RELEASE,
            'backup_source_sha' => FIXTURE_SOURCE_SHA,
            'current_release_before' => FIXTURE_RELEASE,
            'current_source_sha_before' => FIXTURE_SOURCE_SHA,
            'emergency_backup' => '20260116-090000',
            'code_alignment' => 'ALIGNED',
            'runtime_resumed' => 'yes',
            'failed_step' => null,
        ]);

        expect($record)->toHaveKeys(['operation_id', 'started_at', 'completed_at', 'compensation_status']);

        $raw = File::get($scratch.'/restores/restore-history.jsonl');
        expect($raw)
            ->not->toContain('s3cr3t-not-logged')
            ->not->toContain('DB_PASSWORD')
            ->not->toContain('from-backup-never-applied');

        expect(substr(sprintf('%o', fileperms($scratch.'/restores/restore-history.jsonl')), -4))->toBe('0600');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Ordering: everything heavy happens before downtime, and the emergency
// backup happens before the first live mutation
// =============================================================================

it('stages and verifies everything before quiescing, and takes the emergency backup before any live mutation', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        $output = $result['output'];

        $positions = [];
        foreach ([
            'stage backup',
            'verify backup',
            'stage database',
            'stage storage',
            'quiesce target',
            'emergency pre-restore backup',
            'activate database',
            'activate storage',
            'verify restored data',
            'commit',
        ] as $step) {
            $position = mb_strpos($output, 'step: '.$step);
            expect($position)->not->toBeFalse("step never ran: {$step}");
            $positions[$step] = $position;
        }

        $ordered = array_keys($positions);
        for ($i = 1; $i < count($ordered); $i++) {
            expect($positions[$ordered[$i]])
                ->toBeGreaterThan($positions[$ordered[$i - 1]], "{$ordered[$i]} must run after {$ordered[$i - 1]}");
        }

        // The emergency backup is created AND verified before the first live
        // mutation, and the restore-test that verified it ran against the
        // emergency backup rather than against some older one.
        expect(File::get($scratch.'/restore-test.log'))->toContain('--target parity-target');
        expect($positions['emergency pre-restore backup'])->toBeLessThan($positions['activate database']);
    } finally {
        removeScratchDir($scratch);
    }
});

it('performs no live mutation when the emergency backup fails', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch, ['RGTEST_BACKUP_EXIT' => '1']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('no live data was mutated');

        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();

        // The runtime went back to exactly what it was.
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');
        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();

        expect(restoreTargetHistory($scratch)[0])->toMatchArray([
            'status' => 'failed',
            'failed_step' => 'emergency pre-restore backup',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('performs no live mutation when the emergency backup fails its restore test', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch, ['RGTEST_RESTORE_TEST_EXIT' => '1']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('failed its restore test');

        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('performs no live mutation when the emergency backup cannot be unambiguously identified', function (string $ids, string $expected) {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch, ['RGTEST_EMERGENCY_BACKUP_IDS' => $ids]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain($expected);
        expect($result['output'])->toContain('no live data was mutated');

        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'two new backups' => ['20260116-090000 20260116-091500', '(2 new backups appeared)'],
    'no new backup' => ['none', '(0 new backups appeared)'],
]);

it('keeps the selected backup usable even though the emergency backup applies retention', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // The exact hazard: `backup` applies local retention right after
        // creating a backup, and the operator is restoring an OLD one.
        $backupStub = File::get($scratch.'/bin/backup-stub');
        file_put_contents(
            $scratch.'/bin/backup-stub',
            $backupStub."\nrm -rf \"\${RGTEST_BACKUP_NAMESPACE_ROOT}/20260115-120000\"\n",
        );

        $result = restoreTargetApply($scratch);

        expect($result['exit'])->toBe(0, $result['output']);
        expect(is_dir($scratch.'/backups/parity/20260115-120000'))->toBeFalse('retention removed the source backup');
        expect(is_file(restoreTargetStorage($scratch).'/app/restored-marker.txt'))->toBeTrue('the restore still used it');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Runtime quiesce: preserve the ORIGINAL state, never assume it
// =============================================================================

it('brings the target down for the restore and back up afterwards when it was up before', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        $php = File::get($scratch.'/php.log');
        expect($php)->toContain('artisan down');
        expect($php)->toContain('artisan up');
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('leaves a target that was already in maintenance in maintenance', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);
        file_put_contents(restoreTargetStorage($scratch).'/framework/down', "{}\n");

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        expect($result['output'])->toContain('was already in maintenance before this restore');
        expect(File::get($scratch.'/php.log'))->not->toContain('artisan up');
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();

        expect(restoreTargetHistory($scratch)[0]['status'])->toBe('completed');
    } finally {
        removeScratchDir($scratch);
    }
});

it('stops a running queue program and starts it again, touching only this target group', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        $supervisor = File::get($scratch.'/supervisor.log');
        expect($supervisor)->toContain('stop parity-queue:*');
        expect($supervisor)->toContain('start parity-queue:*');
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');

        // Never a global Supervisor operation, never another project's group.
        foreach (['stop all', 'start all', 'restart all', 'shutdown', 'reread', 'update'] as $forbidden) {
            expect($supervisor)->not->toContain($forbidden);
        }
    } finally {
        removeScratchDir($scratch);
    }
});

it('leaves a queue program that was already stopped stopped', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);
        file_put_contents($scratch.'/supervisor-state', "STOPPED\n");

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        expect($result['output'])->toContain('was already fully stopped before this restore');
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('start parity-queue');
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
    } finally {
        removeScratchDir($scratch);
    }
});

it('stops a queue group that is only partly running, and leaves it stopped afterwards', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // One worker RUNNING, one FATAL: not fully running, and emphatically
        // not safe to swap data underneath.
        file_put_contents($scratch.'/supervisor-second-state', "FATAL\n");

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        expect($result['output'])->toContain('neither fully RUNNING nor fully STOPPED');
        expect(File::get($scratch.'/supervisor.log'))->toContain('stop parity-queue:*');

        // Left stopped, because it was never fully running to begin with —
        // and said out loud rather than silently.
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(trim(File::get($scratch.'/supervisor-second-state')))->toBe('STOPPED');
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('start parity-queue');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to restore when the target queue program cannot be observed at all', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // supervisorctl cannot answer: supervisord down, or the program not
        // registered. "Cannot see it" is never "it is not running". Supervisor
        // reports both as exit code 4, distinct from the 3 it uses for a group
        // that is merely stopped.
        $result = restoreTargetApply($scratch, [
            'RGTEST_SUPERVISOR_STATUS_FAILURE' => 'unix:///var/run/supervisor.sock refused connection',
        ]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('cannot observe the target queue program parity-queue');
        expect($result['output'])->toContain('supervisorctl status exited 4');
        expect($result['output'])->toContain('refused connection');

        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('holds this target cron entry outside cron.d for the restore and restores it exactly', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $before = File::get($scratch.'/cron.d/parity-scheduler');
        $modeBefore = substr(sprintf('%o', fileperms($scratch.'/cron.d/parity-scheduler')), -4);

        // An unrelated project's cron entry, which must never be touched.
        file_put_contents($scratch.'/cron.d/cataloghub-scheduler', "* * * * * root true\n");

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();
        expect(File::get($scratch.'/cron.d/parity-scheduler'))->toBe($before);
        expect(substr(sprintf('%o', fileperms($scratch.'/cron.d/parity-scheduler')), -4))->toBe($modeBefore);
        expect(is_file($scratch.'/cron.d/cataloghub-scheduler'))->toBeTrue();

        // A running schedule:run is interrupted and waited out — never by
        // stopping the global cron daemon.
        expect(File::get($scratch.'/php.log'))->toContain('artisan schedule:interrupt');
        expect(File::get($scratch.'/pgrep.log'))->toContain('artisan schedule:run');
        expect($result['output'])->not->toContain('systemctl stop cron');
    } finally {
        removeScratchDir($scratch);
    }
});

it('never invents a cron entry that did not exist before', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, ['scheduler' => false]);
        mkdir($scratch.'/cron.d', 0o755, true);

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        expect($result['output'])->toContain('leaving the scheduler exactly as it was');
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to swap data underneath a scheduler process that will not stop', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch, ['RGTEST_PGREP_EXIT' => '0']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('refusing to swap data underneath a writer');

        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();

        // The runtime was put back, including the held cron entry.
        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Partial runtime transitions: held must mean held, and resumed must mean
// resumed, decided from what is ACTUALLY running
// =============================================================================

it('holds a queue whose start took effect but never reached RUNNING', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);
        restoreTargetAlignCode($scratch);

        // The resume's `supervisorctl start` takes effect — the worker is
        // BACKOFF, not STOPPED — but never reaches RUNNING, so the wait
        // fails AFTER the group has come back to life. The flag that says
        // "this restore stopped it" is still true at that moment, and a hold
        // that trusted the flag would leave a live worker writing to a target
        // it reports as held.
        $result = restoreTargetRun(
            $scratch,
            ['--resume', '--target', 'parity-target', '--operation', $operation],
            ['RGTEST_SUPERVISOR_START_STATE' => 'BACKOFF'],
        );

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('did not reach RUNNING within the wait budget');
        expect($result['output'])->toContain('MANUAL RECOVERY REQUIRED');

        // Held means held: the group was stopped again from its observed
        // state, not skipped because a flag claimed it was already stopped.
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();

        expect(restoreTargetHistory($scratch)[1])->toMatchArray(['status' => 'failed-held']);
    } finally {
        removeScratchDir($scratch);
    }
});

it('restores a queue whose stop took effect but never confirmed STOPPED', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // The quiesce's `supervisorctl stop` takes effect but the group lands
        // in FATAL rather than STOPPED, so the confirmation times out and the
        // "this restore stopped it" flag is never set. The queue was RUNNING
        // before, so the failure path must still bring it back.
        $result = restoreTargetApply($scratch, ['RGTEST_SUPERVISOR_STOP_STATE' => 'FATAL']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('did not reach STOPPED within the wait budget');

        // No live mutation, and the queue that was running before is running
        // again — decided from observed state, not from the unset flag.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();

        expect(restoreTargetHistory($scratch)[0])->toMatchArray(['status' => 'failed']);
    } finally {
        removeScratchDir($scratch);
    }
});

it('re-holds a cron entry that was moved back before its metadata could be restored', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);
        restoreTargetAlignCode($scratch);

        // The resume moves the cron entry back into /etc/cron.d and then
        // fails on the ownership step. The entry is live again at that
        // moment while the "still held" flag is untouched — the exact shape
        // that used to leave a scheduled writer running against a target
        // reported as held.
        $sabotaged = $scratch.'/sabotaged-restore-target';
        file_put_contents($sabotaged, str_replace(
            '    chown "${owner}" "${SCHEDULER_FILE}" || {',
            '    false || {',
            File::get(patchedInfraScript($scratch, 'restore-target')),
        ));
        chmod($sabotaged, 0o755);

        [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

        $env = infraScriptEnv($scratch, $registryPath, $targetsPath, array_merge(
            fakePostgresEnv($scratch),
            targetRuntimeEnv($scratch),
            [
                'RATEGURU_RESTORE_FETCH_BACKUP_BIN' => patchedInfraScript($scratch, 'fetch-backup'),
                'RATEGURU_RESTORE_VERIFY_BACKUP_BIN' => patchedInfraScript($scratch, 'verify-backup'),
                'RATEGURU_RESTORE_DATABASE_BIN' => patchedInfraScript($scratch, 'restore-database'),
                'RATEGURU_RESTORE_STORAGE_BIN' => patchedInfraScript($scratch, 'restore-storage'),
            ],
        ));

        [$exit, $output] = runInfraScript($sabotaged, ['--resume', '--target', 'parity-target', '--operation', $operation], $env);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('could not restore the scheduler cron entry ownership');
        expect($output)->toContain('MANUAL RECOVERY REQUIRED');

        // The entry that had already gone back is taken out again.
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

it('does not call a resume successful when the target stays down despite artisan up', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);
        restoreTargetAlignCode($scratch);

        // `artisan up` exits 0 and the target is still down: reporting the
        // command's exit status as the outcome would call this a resume.
        $result = restoreTargetRun(
            $scratch,
            ['--resume', '--target', 'parity-target', '--operation', $operation],
            ['RGTEST_ARTISAN_UP_INEFFECTIVE' => '1'],
        );

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('still in maintenance after artisan up');
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// The scheduler barrier is fail-closed
// =============================================================================

it('refuses to restore when the scheduler cannot be observed', function (array $env, string $expected) {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch, $env);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain($expected);

        // The connection barrier only covers PostgreSQL; the storage swap has
        // no equivalent, so an unprovable scheduler stops the restore before
        // anything live is touched.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();

        // And the runtime is put back.
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');
        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    // pgrep exit 2 is a usage error, 3 a fatal one: the observation is broken,
    // which is never the same as "no process matched".
    'a broken pgrep' => [['RGTEST_PGREP_EXIT' => '2'], 'cannot observe'],
    'no pgrep at all' => [['RATEGURU_RESTORE_PGREP_BIN' => '/definitely/not/pgrep'], 'is unavailable'],
]);

// =============================================================================
// Compensation
// =============================================================================

it('compensates a failed storage activation, restoring both the database and the storage tree', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // The staged tree disappears between the database activation and the
        // storage activation: the swap fails after the database was already
        // switched, which is exactly the state compensation exists for.
        $storageBin = patchedInfraScript($scratch, 'restore-storage');
        $sabotaged = $scratch.'/sabotaged-restore-storage';
        file_put_contents($sabotaged, str_replace(
            '    log "${LABEL} activating restored storage tree"',
            '    log "${LABEL} activating restored storage tree"'."\n".'    rm -rf "${STAGED_APP}"',
            File::get($storageBin),
        ));
        chmod($sabotaged, 0o755);

        $result = restoreTargetApply($scratch, ['RATEGURU_RESTORE_STORAGE_BIN' => $sabotaged]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('compensating');

        // Both halves are back, and the staged copies were discarded rather
        // than left on the host.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(trim(File::get($scratch.'/pg/db/parity_db')))->toBe('parity_app t');
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
        expect(is_file(restoreTargetStorage($scratch).'/app/restored-marker.txt'))->toBeFalse();

        // And the target is serving again.
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');
        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();

        expect(restoreTargetHistory($scratch)[0])->toMatchArray([
            'status' => 'failed-recovered',
            'compensation_status' => 'complete',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('compensates a database activation that failed between its two renames', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // The very first rename fails, so activation never reaches the phase
        // update. Compensation must still be allowed there — refusing it
        // would leave a fully recoverable target held.
        $result = restoreTargetApply($scratch, ['RGTEST_RENAME_FAIL_TO_PREFIX' => 'rateguru_pre_']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('could not rename parity_db aside');
        expect($result['output'])->toContain('nothing to undo');

        // The live database survived, and the connection barrier a failed
        // activation left behind was lifted.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(trim(File::get($scratch.'/pg/db/parity_db')))->toBe('parity_app t');
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();

        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');
        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();

        expect(restoreTargetHistory($scratch)[0])->toMatchArray([
            'status' => 'failed-recovered',
            'compensation_status' => 'complete',
            'failed_step' => 'activate database',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('compensates a failed final verification and does not mask the original error', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // A storage tree whose mode normalization is undone after the swap:
        // final verification refuses it, and both halves compensate.
        $storageBin = patchedInfraScript($scratch, 'restore-storage');
        $sabotaged = $scratch.'/sabotaged-verify-restore-storage';
        file_put_contents($sabotaged, str_replace(
            '    restore_state_set "${STATE_FILE}" phase storage-activated',
            '    chmod 0755 "${LIVE_APP}"'."\n".'    restore_state_set "${STATE_FILE}" phase storage-activated',
            File::get($storageBin),
        ));
        chmod($sabotaged, 0o755);

        $result = restoreTargetApply($scratch, ['RATEGURU_RESTORE_STORAGE_BIN' => $sabotaged]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('restored storage tree has mode');
        expect($result['output'])->toContain('expected 2710');
        expect($result['output'])->toContain('compensating');

        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();

        expect(restoreTargetHistory($scratch)[0])->toMatchArray([
            'status' => 'failed-recovered',
            'compensation_status' => 'complete',
            'failed_step' => 'verify restored data',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('holds the target and demands manual recovery when compensation itself cannot complete', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // The swap completes, then the staging parent disappears and the
        // live tree's mode is broken: final verification refuses the result,
        // and the storage half can no longer be moved back out of the way —
        // compensation is genuinely impossible, not merely unnecessary.
        $storageBin = patchedInfraScript($scratch, 'restore-storage');
        $sabotaged = $scratch.'/broken-restore-storage';
        file_put_contents($sabotaged, str_replace(
            '    restore_state_set "${STATE_FILE}" phase storage-activated',
            '    rm -rf "${STAGE_PARENT}"'."\n".'    chmod 0755 "${LIVE_APP}"'."\n".'    restore_state_set "${STATE_FILE}" phase storage-activated',
            File::get($storageBin),
        ));
        chmod($sabotaged, 0o755);

        $result = restoreTargetApply($scratch, ['RATEGURU_RESTORE_STORAGE_BIN' => $sabotaged]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('MANUAL RECOVERY REQUIRED')
            ->toContain('The target is intentionally NOT serving traffic.');

        // Held: not resumed, still down, queue still stopped.
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();

        expect(restoreTargetHistory($scratch)[0])->toMatchArray([
            'status' => 'failed-held',
            'compensation_status' => 'incomplete',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Code alignment
// =============================================================================

it('completes the data restore but holds the runtime when the code does not match the backup', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $result = restoreTargetApply($scratch);

        // The requested DATA restore succeeded, so this is a success.
        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('RESTORE DATA COMPLETE: YES')
            ->toContain('CODE ALIGNMENT: REQUIRED')
            ->toContain('TARGET RESUMED: NO')
            ->toContain('BACKUP SOURCE SHA: '.FIXTURE_SOURCE_SHA)
            ->toContain('CURRENT SOURCE SHA: '.FIXTURE_OTHER_SOURCE_SHA)
            ->toContain('restore-target --resume --target parity-target --operation');

        // The data IS restored and committed.
        expect(is_file(restoreTargetStorage($scratch).'/app/restored-marker.txt'))->toBeTrue();
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);

        // The runtime is intentionally held.
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();

        // No release was switched and no migration was run.
        expect(readlink($scratch.'/target/current'))->toBe($scratch.'/target/releases/'.FIXTURE_OTHER_RELEASE);
        expect(File::get($scratch.'/php.log'))->not->toContain('migrate');
        expect(File::get($scratch.'/health-check.log'))->toBe('', 'a held target is not health checked as if it were serving');

        expect(restoreTargetHistory($scratch)[0])->toMatchArray([
            'status' => 'held',
            'code_alignment' => 'REQUIRED',
            'runtime_resumed' => 'no',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Resume
// =============================================================================

/** Runs an apply that ends held, and returns its operation ID. */
function restoreTargetHeldOperation(string $scratch): string
{
    $result = restoreTargetApply($scratch);
    expect($result['exit'])->toBe(0, $result['output']);
    expect($result['output'])->toContain('CODE ALIGNMENT: REQUIRED');

    $operations = operationIdsIn($result['output']);
    expect($operations)->toHaveCount(1);

    return $operations[0];
}

/** Deploys the aligned release, the way the controlled alignment deploy would. */
function restoreTargetAlignCode(string $scratch): void
{
    $aligned = $scratch.'/target/releases/'.FIXTURE_RELEASE;
    mkdir($aligned, 0o755, true);
    file_put_contents($aligned.'/artisan', "<?php\n");
    file_put_contents(
        $aligned.'/release.json',
        json_encode(['project' => 'rateguru', 'release' => FIXTURE_RELEASE, 'source_sha' => FIXTURE_SOURCE_SHA]),
    );

    unlink($scratch.'/target/current');
    symlink($aligned, $scratch.'/target/current');
}

it('resumes a held target once the deployed code carries the backup source_sha', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);
        restoreTargetAlignCode($scratch);

        $result = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('CODE ALIGNMENT: ALIGNED')
            ->toContain('TARGET RESUMED: YES');

        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');
        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();
        expect(File::get($scratch.'/health-check.log'))->toContain('--target parity-target');

        $history = restoreTargetHistory($scratch);
        expect($history)->toHaveCount(2);
        expect($history[1])->toMatchArray(['status' => 'resumed', 'runtime_resumed' => 'yes']);

        // The completed operation's workspace is cleaned up.
        expect(is_dir($scratch.'/run/restores/parity-target/'.$operation))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to resume while the code still does not match, and leaves the target held', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);

        $result = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('still does not carry the backup');
        expect($result['output'])->toContain('the target stays held');

        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to resume an unknown operation, another target operation, or one that is not held', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $unknown = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', '20260101-000000-ffffff']);
        expect($unknown['exit'])->not->toBe(0);
        expect($unknown['output'])->toContain('restore operation workspace does not exist');

        $operation = restoreTargetHeldOperation($scratch);
        $state = restoreOperationState($scratch.'/run/restores/parity-target/'.$operation);

        $state['target'] = 'someone-else';
        file_put_contents($scratch.'/run/restores/parity-target/'.$operation.'/state.json', json_encode($state));

        $wrongTarget = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);
        expect($wrongTarget['exit'])->not->toBe(0);
        expect($wrongTarget['output'])->toContain('belongs to target someone-else');

        $state['target'] = 'parity-target';
        $state['status'] = 'completed';
        file_put_contents($scratch.'/run/restores/parity-target/'.$operation.'/state.json', json_encode($state));

        $notHeld = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);
        expect($notHeld['exit'])->not->toBe(0);
        expect($notHeld['output'])->toContain('--resume only applies to an operation whose data restore completed');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a target whose releases root is a symlink, on both apply and resume', function () {
    // A symlinked releases root plus a current pointing inside it resolves to
    // a self-consistent pair — both readlink -f to the same foreign parent —
    // so the containment check alone would accept a release tree this target
    // does not own, and the whole code-alignment decision is made against
    // that identity. deploy, rollback and cleanup all hold the same line.
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        exec('mv '.escapeshellarg($scratch.'/target/releases').' '.escapeshellarg($scratch.'/foreign-releases'));
        symlink($scratch.'/foreign-releases', $scratch.'/target/releases');

        $result = restoreTargetApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('releases root must be a real directory, not a symlink');

        expect(is_dir($scratch.'/run/restores'))->toBeFalse('nothing may be staged for an uncontained target');
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }

    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);
        restoreTargetAlignCode($scratch);

        // The aligning deploy is genuine, but the releases root has been
        // replaced with a link to a foreign tree: resume must refuse it too,
        // and the target must stay held.
        exec('mv '.escapeshellarg($scratch.'/target/releases').' '.escapeshellarg($scratch.'/foreign-releases'));
        symlink($scratch.'/foreign-releases', $scratch.'/target/releases');

        $result = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('releases root must be a real directory, not a symlink');

        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to resume when current is malformed or resolves outside the releases tree', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);

        // current points outside releases/ — a broken deployment, never
        // something a restore reasons about.
        mkdir($scratch.'/rogue-release', 0o755, true);
        file_put_contents($scratch.'/rogue-release/artisan', "<?php\n");
        file_put_contents(
            $scratch.'/rogue-release/release.json',
            json_encode(['release' => FIXTURE_RELEASE, 'source_sha' => FIXTURE_SOURCE_SHA]),
        );
        unlink($scratch.'/target/current');
        symlink($scratch.'/rogue-release', $scratch.'/target/current');

        $result = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('carries no usable release/source_sha');
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

it('does not resume a target whose health check fails, and holds it instead', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);
        restoreTargetAlignCode($scratch);

        $result = restoreTargetRun(
            $scratch,
            ['--resume', '--target', 'parity-target', '--operation', $operation],
            ['RGTEST_HEALTH_CHECK_EXIT' => '1'],
        );

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('health check failed after resume');
        expect($result['output'])->toContain('MANUAL RECOVERY REQUIRED');

        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');

        // Held means held: the scheduler cron entry the resume had already
        // put back is taken out of /etc/cron.d again, so nothing writes to
        // the database while an operator investigates.
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();

        expect(restoreTargetHistory($scratch)[1])->toMatchArray(['status' => 'failed-held']);
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Supervisor status observation
//
// Real behaviour observed on staging during the first live Restore Target Data restore:
// `supervisorctl status <group>:*` returns EXIT CODE 3 while correctly printing
// the process as STOPPED. Treating every non-zero exit as "cannot observe"
// made the quiesce wait out its whole budget and abort the restore, even though
// the queue had stopped exactly as asked.
//
// The codes come from the pinned supervisor 4.2.1 the host runs
// (supervisorctl.py LSBStatusExitStatuses, states.py STOPPED_STATES):
//   0  every matched process is running-ish
//   3  at least one is STOPPED, EXITED, FATAL or UNKNOWN
//   4  upcheck() failed, or the name matched nothing
// =============================================================================

/** The supervisorctl calls a run made, in order. */
function restoreTargetSupervisorLog(string $scratch): array
{
    $path = $scratch.'/supervisor.log';

    if (! is_file($path)) {
        return [];
    }

    return array_values(array_filter(explode("\n", trim((string) file_get_contents($path)))));
}

it('accepts a STOPPED group reported with exit code 3, and does not wait out the budget', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // Exactly the staging sequence: RUNNING (rc 0) before the quiesce, then
        // STOPPED (rc 3) after `supervisorctl stop`. The stub derives both exit
        // codes the way supervisor does, so this fails against the old
        // `|| return 1` on any non-zero status.
        $result = restoreTargetApply($scratch);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])->not->toContain('did not reach STOPPED within the wait budget');

        // The quiesce moved on rather than looping: it stopped the group and
        // continued to the scheduler and the emergency backup.
        $steps = $result['output'];
        expect($steps)->toContain('queue program parity-queue STOPPED');
        expect(mb_strpos($steps, 'step: quiesce target'))
            ->toBeLessThan(mb_strpos($steps, 'step: emergency pre-restore backup'));

        // And the whole restore completed, which it could not have done while
        // a correctly stopped queue was being read as unobservable.
        expect($result['output'])->toContain('restore completed and the target is serving again');
    } finally {
        removeScratchDir($scratch);
    }
});

it('classifies every state the observation can legitimately report', function (
    string $first,
    ?string $second,
    bool $running,
    bool $fullyStopped,
) {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);
        [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

        file_put_contents($scratch.'/supervisor-state', $first."\n");

        if ($second !== null) {
            file_put_contents($scratch.'/supervisor-second-state', $second."\n");
        }

        [$exit, $output] = runInfraHarness(
            $scratch,
            patchedInfraScript($scratch, 'restore-target'),
            <<<'BASH'
                SUPERVISOR_PROGRAM=parity-queue

                if observe_queue_program; then
                    echo "OBSERVED: ${QUEUE_PROCESS_STATES[*]}"
                else
                    echo "OBSERVATION FAILED: ${QUEUE_OBSERVATION_ERROR}"
                fi

                queue_program_running && echo "RUNNING: yes" || echo "RUNNING: no"
                queue_program_fully_stopped && echo "STOPPED: yes" || echo "STOPPED: no"
                BASH,
            infraScriptEnv($scratch, $registryPath, $targetsPath, array_merge(
                fakePostgresEnv($scratch),
                targetRuntimeEnv($scratch),
            )),
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('OBSERVED: '.trim($first.' '.($second ?? '')));
        expect($output)->toContain('RUNNING: '.($running ? 'yes' : 'no'));
        expect($output)->toContain('STOPPED: '.($fullyStopped ? 'yes' : 'no'));
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    // A: rc 0, RUNNING -> valid, fully running.
    'all running' => ['RUNNING', null, true, false],
    // B: rc 3, STOPPED -> valid, fully stopped. The staging case.
    'all stopped' => ['STOPPED', null, false, true],
    // C: rc 3, FATAL is a real Supervisor state -> valid, neither.
    'fatal' => ['FATAL', null, false, false],
    'exited' => ['EXITED', null, false, false],
    'starting' => ['STARTING', null, false, false],
    'backoff' => ['BACKOFF', null, false, false],
    'stopping' => ['STOPPING', null, false, false],
    // UNKNOWN is a process state, not an observation failure.
    'unknown state' => ['UNKNOWN', null, false, false],
    // D: mixed groups settle as neither, whichever way round.
    'stopped and running' => ['STOPPED', 'RUNNING', false, false],
    'running and stopped' => ['RUNNING', 'STOPPED', false, false],
    'stopped and fatal' => ['STOPPED', 'FATAL', false, false],
    'stopped and starting' => ['STOPPED', 'STARTING', false, false],
    'both running' => ['RUNNING', 'RUNNING', true, false],
    'both stopped' => ['STOPPED', 'STOPPED', false, true],
]);

it('fails the observation closed on anything that is not a real answer about this group', function (
    array $environment,
    string $expected,
) {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);
        [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

        [$exit, $output] = runInfraHarness(
            $scratch,
            patchedInfraScript($scratch, 'restore-target'),
            <<<'BASH'
                SUPERVISOR_PROGRAM=parity-queue

                if observe_queue_program; then
                    echo "UNEXPECTED: observation succeeded with ${QUEUE_PROCESS_STATES[*]}"
                else
                    echo "OBSERVATION FAILED: ${QUEUE_OBSERVATION_ERROR}"
                fi

                # An unobservable group is never "not running" and never
                # "stopped": both classifiers must refuse it too.
                queue_program_running && echo "RUNNING: yes" || echo "RUNNING: no"
                queue_program_fully_stopped && echo "STOPPED: yes" || echo "STOPPED: no"
                BASH,
            infraScriptEnv($scratch, $registryPath, $targetsPath, array_merge(
                fakePostgresEnv($scratch),
                targetRuntimeEnv($scratch),
                $environment,
            )),
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('OBSERVATION FAILED');
        expect($output)->toContain($expected);
        expect($output)->toContain('RUNNING: no');
        expect($output)->toContain('STOPPED: no');
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    // E: nothing printed at all.
    'empty output' => [
        ['RGTEST_SUPERVISOR_STATUS_STDOUT' => ' ', 'RGTEST_SUPERVISOR_STATUS_RC' => '0'],
        'printed nothing',
    ],
    // F: the group is not registered — Supervisor's own wording and code.
    'no such group' => [
        ['RGTEST_SUPERVISOR_STATUS_STDOUT' => 'parity-queue: ERROR (no such group)', 'RGTEST_SUPERVISOR_STATUS_RC' => '4'],
        'supervisorctl status exited 4',
    ],
    // …and rejected on its output even if the code were acceptable.
    'no such group with an acceptable exit code' => [
        ['RGTEST_SUPERVISOR_STATUS_STDOUT' => 'parity-queue: ERROR (no such group)', 'RGTEST_SUPERVISOR_STATUS_RC' => '0'],
        'is not a Supervisor process state',
    ],
    // G: transport failure.
    'connection refused' => [
        ['RGTEST_SUPERVISOR_STATUS_FAILURE' => 'unix:///var/run/supervisor.sock refused connection'],
        'supervisorctl status exited 4',
    ],
    'supervisord not running' => [
        ['RGTEST_SUPERVISOR_STATUS_FAILURE' => 'unix:///var/run/supervisor.sock no such file', 'RGTEST_SUPERVISOR_STATUS_FAILURE_RC' => '7'],
        'supervisorctl status exited 7',
    ],
    // H: somebody else's program.
    'another program' => [
        ['RGTEST_SUPERVISOR_STATUS_STDOUT' => 'other-queue:other-queue_00       RUNNING   pid 1, uptime 0:00:01', 'RGTEST_SUPERVISOR_STATUS_RC' => '0'],
        'is not part of parity-queue',
    ],
    // I: a state token Supervisor does not have.
    'unrecognized state' => [
        ['RGTEST_SUPERVISOR_STATUS_STDOUT' => 'parity-queue:parity-queue_00     WOBBLING  pid 1', 'RGTEST_SUPERVISOR_STATUS_RC' => '0'],
        'is not a Supervisor process state',
    ],
    'truncated line' => [
        ['RGTEST_SUPERVISOR_STATUS_STDOUT' => 'parity-queue:parity-queue_00', 'RGTEST_SUPERVISOR_STATUS_RC' => '0'],
        'is not a Supervisor process state',
    ],
]);

it('leaves live data untouched when the queue cannot be quiesced', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // The staging failure mode, forced: the stop lands somewhere other than
        // STOPPED, so the confirmation never comes. What matters is WHERE the
        // restore gives up — before the emergency backup, the restore guard and
        // both activations.
        $result = restoreTargetApply($scratch, ['RGTEST_SUPERVISOR_STOP_STATE' => 'FATAL']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('did not reach STOPPED within the wait budget');

        expect($result['output'])->not->toContain('step: emergency pre-restore backup');
        expect($result['output'])->not->toContain('step: write restore guard');
        expect($result['output'])->not->toContain('step: activate database');
        expect($result['output'])->not->toContain('step: activate storage');

        // Live data exactly as it was, and no guard left behind.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
        expect(is_file(restoreGuardFile($scratch)))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// A hold has to be a hold, including against a scheduler that started DURING
// the operation
// =============================================================================

it('interrupts and proves the absence of a scheduler that cron started after the resume', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);
        restoreTargetAlignCode($scratch);

        // The window this closes: --resume puts the cron entry back and leaves
        // maintenance, cron fires a schedule:run, and only THEN does the
        // health check fail. Re-holding the cron entry stops the next run; it
        // does nothing about the one already writing to PostgreSQL and
        // storage.
        file_put_contents($scratch.'/pgrep.log', '');
        file_put_contents($scratch.'/php.log', '');

        $result = restoreTargetRun(
            $scratch,
            ['--resume', '--target', 'parity-target', '--operation', $operation],
            ['RGTEST_HEALTH_CHECK_EXIT' => '1', 'RGTEST_PGREP_EXIT' => '0'],
        );

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('health check failed after resume');

        // The hold asked the running scheduler to stop...
        expect(File::get($scratch.'/php.log'))->toContain('schedule:interrupt');

        // ...then actually looked for it, rather than assuming the cron move
        // was enough.
        $pgrep = File::get($scratch.'/pgrep.log');
        expect($pgrep)->toContain('artisan schedule:run');
        expect(substr_count($pgrep, 'artisan schedule:run'))->toBe(3, 'the hold must spend its whole observation budget');

        // And it says out loud that the hold is not proven, rather than
        // reporting a clean-looking held target with a writer still running.
        expect($result['output'])->toContain('a scheduler process for');
        expect($result['output'])->toContain('still running after the interrupt budget');
        expect($result['output'])->toContain('WARNING: one or more writers could NOT be proven stopped');

        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();
        expect(restoreTargetHistory($scratch)[1])->toMatchArray(['status' => 'failed-held']);

        // And the data is still the backup's while current serves something
        // else, so the hold marker stays and backups stay refused.
        expect(is_file($scratch.'/run/restores/parity-target/restore-guard'))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

it('reports a clean hold when no scheduler process remains', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);
        restoreTargetAlignCode($scratch);

        // Same failure, but nothing is running: the hold is proven, and says so
        // without the warning.
        $result = restoreTargetRun(
            $scratch,
            ['--resume', '--target', 'parity-target', '--operation', $operation],
            ['RGTEST_HEALTH_CHECK_EXIT' => '1'],
        );

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('no scheduler process is running against this target');
        expect($result['output'])->not->toContain('WARNING: one or more writers could NOT be proven stopped');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// The restore hold marker: a held target's data does not match its code, and
// an ordinary backup would label it with that code anyway
// =============================================================================

/** The marker restore-target writes for a held target. */
function restoreGuardFile(string $scratch): string
{
    return $scratch.'/run/restores/parity-target/restore-guard';
}

/**
 * Runs the REAL backup script against the same scratch host, patched only for
 * root exactly like every other Restore Target Data subject.
 *
 * The assertions below are about the restore-hold guard, not about the backup
 * pipeline: `backup` is refused before it creates anything, or it gets past the
 * guard and reaches its first real step. What happens after that belongs to
 * BackupTest, which owns the full pipeline and its database fakes.
 *
 * @return array{exit: int, output: string, blocked: bool, reached_backup: bool}
 */
function restoreHoldRunBackup(string $scratch): array
{
    [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

    [$exit, $output] = runInfraScript(
        patchedInfraScript($scratch, 'backup'),
        ['--target', 'parity-target'],
        infraScriptEnv($scratch, $registryPath, $targetsPath, fakePostgresEnv($scratch)),
    );

    return [
        'exit' => $exit,
        'output' => $output,
        'blocked' => str_contains($output, 'is held after restore operation'),
        'reached_backup' => str_contains($output, 'Backing up PostgreSQL database'),
    ];
}

it('writes the guard before the first live mutation, as a prerequisite of it', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        // The ordering is the whole protection: a guard written after the
        // activation cannot cover the activation.
        $steps = $result['output'];

        expect(mb_strpos($steps, 'step: emergency pre-restore backup'))
            ->toBeLessThan(mb_strpos($steps, 'step: write restore guard'));
        expect(mb_strpos($steps, 'step: write restore guard'))
            ->toBeLessThan(mb_strpos($steps, 'step: activate database'));
        expect($steps)->toContain('status=in-progress');
    } finally {
        removeScratchDir($scratch);
    }
});

it('survives an unhandled kill mid-activation, which is the window a trap cannot cover', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // A SIGKILL runs no EXIT/ERR trap and the kernel drops every flock, so
        // the host's own backup cron — not the Laravel scheduler, and not held
        // by this operation — is free to run against half-restored data. The
        // storage activation kills its own parent to reproduce exactly that.
        $killer = $scratch.'/bin/restore-storage-killer';
        writeExecutable($killer, <<<'BASH'
            #!/bin/bash
            case "$*" in
                *--activate*) kill -9 "${PPID}"; sleep 5 ;;
            esac
            exec "${RGTEST_REAL_RESTORE_STORAGE}" "$@"
            BASH);

        $result = restoreTargetRun(
            $scratch,
            ['--apply', '--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000'],
            [
                'RATEGURU_RESTORE_STORAGE_BIN' => $killer,
                'RGTEST_REAL_RESTORE_STORAGE' => patchedInfraScript($scratch, 'restore-storage'),
            ],
        );

        // Killed, so no terminal handler ran and nothing was reported.
        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->not->toContain('MANUAL RECOVERY REQUIRED');

        // The guard is on disk anyway, because it was written before the first
        // activation rather than by a handler that never got to run.
        expect(is_file(restoreGuardFile($scratch)))->toBeTrue(
            'the guard must survive a kill that no handler can observe');

        expect(json_decode(File::get(restoreGuardFile($scratch)), true))
            ->toMatchArray(['target' => 'parity-target', 'status' => 'in-progress']);

        // And the cron that would otherwise run next is refused.
        $backup = restoreHoldRunBackup($scratch);
        expect($backup['blocked'])->toBeTrue();
        expect($backup['reached_backup'])->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to mutate live data when the guard cannot be written', function () {
    // Two halves, because a filesystem this process owns cannot be made
    // genuinely unwritable to it: the writer reports failure, and the call site
    // turns that failure into a refusal.
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);
        [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

        // A run root whose `restores` component is a regular file: `install -d`
        // cannot create the guard's directory, whoever owns it.
        $brokenRoot = $scratch.'/broken-run';
        mkdir($brokenRoot, 0o700, true);
        file_put_contents($brokenRoot.'/restores', "not a directory\n");

        [$exit, $output] = runInfraHarness(
            $scratch,
            patchedInfraScript($scratch, 'restore-target'),
            <<<'BASH'
                TARGET_ID=parity-target
                OPERATION_ID=20260115-024512-3f9ac1
                BACKUP_SOURCE_SHA=deadbeefdeadbeefdeadbeefdeadbeefdeadbeef
                LABEL=parity-target

                if write_restore_guard in-progress; then
                    echo "UNEXPECTED: the writer reported success"
                    exit 0
                fi

                echo "WRITER REPORTED FAILURE"
                echo "guard written flag: ${RESTORE_GUARD_WRITTEN}"
                BASH,
            infraScriptEnv($scratch, $registryPath, $targetsPath, ['RATEGURU_RUN_ROOT' => $brokenRoot]),
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('WRITER REPORTED FAILURE');
        expect($output)->toContain('the restore guard is NOT in place');
        expect($output)->toContain('guard written flag: false');

        // And the call site refuses rather than continuing: the guard is a
        // prerequisite of the activation, not a best effort beside it.
        $source = File::get(restoreTargetScript());

        expect($source)->toContain(
            "write_restore_guard in-progress \\\n        || fail \"could not write the restore guard",
        );
        expect(mb_strpos($source, 'write_restore_guard in-progress'))
            ->toBeLessThan(mb_strpos($source, 'MUTATION_STAGE=activating'));
    } finally {
        removeScratchDir($scratch);
    }
});

it('clears the guard when an aligned restore comes up healthy', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])->toContain('step: clear restore guard');
        expect(is_file(restoreGuardFile($scratch)))->toBeFalse();

        $backup = restoreHoldRunBackup($scratch);
        expect($backup['blocked'])->toBeFalse();
        expect($backup['reached_backup'])->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses an ordinary backup while the target is held, and says which commit it is waiting for', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);

        // The marker is the part of the hold that outlives the process.
        expect(is_file(restoreGuardFile($scratch)))->toBeTrue();

        $marker = json_decode(File::get(restoreGuardFile($scratch)), true);
        expect($marker)->toMatchArray([
            'operation' => $operation,
            'target' => 'parity-target',
            'required_source_sha' => FIXTURE_SOURCE_SHA,
            'status' => 'held',
        ]);

        // Identity only — nothing an operator has to redact.
        expect(array_keys($marker))->toEqualCanonicalizing([
            'operation', 'target', 'required_source_sha', 'status', 'created_at',
        ]);

        // A backup here would take its DATA from the restored disk and its
        // release identity from current/release.json, which still names the
        // OTHER commit — a backup asserting that this data belongs to code it
        // does not.
        $backup = restoreHoldRunBackup($scratch);

        expect($backup['exit'])->not->toBe(0);
        expect($backup['blocked'])->toBeTrue();
        expect($backup['output'])->toContain(FIXTURE_SOURCE_SHA);
        expect($backup['output'])->toContain('would record the wrong source_sha');

        // Refused before anything was created: it never reached its first real
        // step, and the namespace holds exactly the backups it held before.
        expect($backup['reached_backup'])->toBeFalse();

        $namespace = $scratch.'/backups/parity';
        expect(array_values(array_diff(scandir($namespace), ['.', '..'])))
            ->toBe(['20260115-120000', '20260116-090000']);
    } finally {
        removeScratchDir($scratch);
    }
});

it('clears the hold marker only once a resume has proven the data and the code agree', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);
        expect(is_file(restoreGuardFile($scratch)))->toBeTrue();
        expect(restoreHoldRunBackup($scratch)['blocked'])->toBeTrue();

        restoreTargetAlignCode($scratch);

        $resumed = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($resumed['exit'])->toBe(0, $resumed['output']);

        // Cleared only after the health check AND the restored-data
        // verification, never merely because the code now matches.
        $steps = $resumed['output'];
        expect(mb_strpos($steps, 'step: verify restored data'))
            ->toBeLessThan(mb_strpos($steps, 'step: clear restore guard'));
        expect(mb_strpos($steps, 'step: health check'))
            ->toBeLessThan(mb_strpos($steps, 'step: clear restore guard'));

        expect(is_file(restoreGuardFile($scratch)))->toBeFalse();

        // And ordinary backups are no longer refused.
        expect(restoreHoldRunBackup($scratch)['blocked'])->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('writes a hold marker when a failure leaves replaced data behind, and none when nothing was touched', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // Failed before any live mutation: the data still matches the code, so
        // blocking backups would strand the target for no reason.
        $result = restoreTargetApply($scratch, ['RGTEST_BACKUP_EXIT' => '1']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('emergency pre-restore backup failed');

        // The guard is written after the emergency backup and before the first
        // activation, so a failure here leaves none — and must not, since the
        // data still matches the code.
        expect(is_file(restoreGuardFile($scratch)))->toBeFalse();

        $backup = restoreHoldRunBackup($scratch);
        expect($backup['blocked'])->toBeFalse();
        expect($backup['reached_backup'])->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to start a second restore on a target that is already held', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);

        // The second restore would take an emergency "pre-restore" backup of
        // data that does not match its code — and be refused for it, halfway
        // in. It is refused before anything is staged instead.
        $second = restoreTargetApply($scratch);

        expect($second['exit'])->not->toBe(0);
        expect($second['output'])->toContain('check restore hold');
        expect($second['output'])->toContain('is held after restore operation '.$operation);
        expect($second['output'])->not->toContain('stage backup');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Locking, lifecycle and preconditions
// =============================================================================

it('serializes against another restore for the same backup namespace', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        mkdir($scratch.'/run', 0o755, true);
        $lockFile = $scratch.'/run/restore-target-parity.lock';
        touch($lockFile);

        // flock -n against a lock another process already holds.
        $holder = proc_open(
            ['flock', '-x', $lockFile, 'sleep', '20'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        usleep(300000);

        try {
            $result = restoreTargetApply($scratch);

            expect($result['exit'])->not->toBe(0);
            expect($result['output'])->toContain('another restore operation is already running');
        } finally {
            proc_terminate($holder);
            proc_close($holder);
        }
    } finally {
        removeScratchDir($scratch);
    }
});

it('serializes against a deploy, rollback or cleanup through the existing target deployment lock', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $lockFile = $scratch.'/target/locks/deployment.lock';
        touch($lockFile);

        $holder = proc_open(
            ['flock', '-x', $lockFile, 'sleep', '20'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        usleep(300000);

        try {
            $result = restoreTargetApply($scratch);

            expect($result['exit'])->not->toBe(0);
            expect($result['output'])->toContain('another deployment operation is already running');

            // Nothing live was touched, and nothing was quiesced.
            expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
            expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        } finally {
            proc_terminate($holder);
            proc_close($holder);
        }
    } finally {
        removeScratchDir($scratch);
    }

    // It is the EXISTING lock, not a new incompatible one.
    expect(File::get(base_path('infrastructure/scripts/restore-target')))
        ->toContain('acquire_deployment_lock "${TARGET_ROOT}"');
});

it('rejects a planned target before creating a workspace, quiescing anything or downloading a backup', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetRun($scratch, [
            '--apply', '--target', 'planned-target', '--source', 'local', '--backup', '20260115-120000',
        ]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('lifecycle=planned');

        expect(is_dir($scratch.'/run/restores'))->toBeFalse();
        expect(File::get($scratch.'/supervisor.log'))->toBe('');
        expect(File::get($scratch.'/php.log'))->toBe('');
        expect(File::get($scratch.'/backup.log'))->toBe('');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to restore a target with no deployed release', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);
        unlink($scratch.'/target/current');

        $result = restoreTargetApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('a live data restore requires a deployed target');
        expect(is_dir($scratch.'/run/restores'))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a backup that cannot identify the code its data belongs to', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'backup' => ['release_json' => "{}\n", 'manifest' => backupManifestFixture(['release' => 'unknown'])],
        ]);

        $result = restoreTargetApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('carries no release');

        // Nothing was quiesced and nothing live was touched.
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Hard invariants
// =============================================================================

it('never runs a migration, a schema reset or a release switch', function () {
    foreach (['restore-target', 'restore-database', 'restore-storage', 'fetch-backup', 'verify-backup', 'restore-common'] as $script) {
        $source = File::get(base_path('infrastructure/scripts/'.$script));

        foreach ([
            'artisan migrate',
            'migrate --force',
            'migrate:fresh',
            'migrate:refresh',
            'migrate:reset',
            'db:wipe',
            'schema:dump',
            'DROP SCHEMA',
        ] as $forbidden) {
            expect($source)->not->toContain($forbidden, "{$script} must never run {$forbidden}");
        }

        // No release switching: the current/previous links are read, never
        // rewritten.
        expect($source)->not->toMatch('/ln\s+-sfn?.*current/');
        expect($source)->not->toContain('mv -Tf');
    }
});

it('never applies environment.env or server-configuration.tar.gz', function () {
    foreach (['restore-target', 'restore-database', 'restore-storage', 'restore-common'] as $script) {
        $source = File::get(base_path('infrastructure/scripts/'.$script));

        foreach (preg_split('/\R/', $source) as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // The two files may be NAMED (they are part of the required file
            // set and are checksum-verified) but never extracted, copied,
            // installed or sourced.
            foreach (['environment.env', 'server-configuration.tar.gz'] as $neverApplied) {
                if (! str_contains($trimmed, $neverApplied)) {
                    continue;
                }

                expect($trimmed)->not->toMatch(
                    '/(^|[;&|]\s*)(cp|install|mv|ln|source|eval)\s/',
                    "{$script} must never copy, install, move, link or source {$neverApplied}: {$trimmed}",
                );

                expect($trimmed)->not->toMatch(
                    '/(^|[;&|]\s*)tar\s+[^;&|]*-x/',
                    "{$script} must never extract {$neverApplied}: {$trimmed}",
                );
            }
        }
    }
});

it('stops no global service and touches no unrelated project', function () {
    foreach (['restore-target', 'restore-database', 'restore-storage', 'fetch-backup', 'verify-backup', 'restore-common'] as $script) {
        $source = File::get(base_path('infrastructure/scripts/'.$script));

        foreach ([
            'systemctl stop',
            'systemctl restart',
            'service nginx',
            'supervisorctl stop all',
            'supervisorctl shutdown',
            'pg_ctl',
            'CatalogHub',
            'cataloghub',
            'Polymarket',
            'polymarket',
        ] as $forbidden) {
            expect($source)->not->toContain($forbidden, "{$script} must never contain {$forbidden}");
        }

        expect($source)->not->toMatch('#rm\s+-rf\s+/(etc|home|var|opt|usr)(/\S*)?\s*$#m');
        expect($source)->not->toMatch('#psql.*FROM\s+pg_database\s*;#');
    }
});

it('requires root for a real invocation', function () {
    $source = File::get(restoreTargetScript());

    expect($source)->toContain("main() {\n    require_root\n    parse_restore_target_args");
});

// =============================================================================
// the controlled code alignment: the machine-readable result contract
// =============================================================================
//
// The human summary above is for operators and stays exactly as it was. This
// is the line the GitHub restore action branches on — whether the target came
// back or is waiting for its code, and which commit it is waiting for — and
// the properties asserted here are precisely the ones a consumer relies on:
// exactly one object, every field present, and no secret anywhere in it.

/** The single machine-readable result line a terminal restore printed. */
function restoreTargetResult(string $output): array
{
    preg_match_all('/^RATEGURU_RESTORE_RESULT=(.*)$/m', $output, $matches);

    expect($matches[1])->toHaveCount(1, "expected exactly one machine-readable result in:\n".$output);

    $decoded = json_decode(trim($matches[1][0]), true);

    expect($decoded)->toBeArray("machine-readable result is not a JSON object: {$matches[1][0]}");

    return $decoded;
}

/** @return array<string, string> */
function restoreTargetGuard(string $scratch): array
{
    return json_decode(File::get(restoreGuardFile($scratch)), true);
}

/** @param  array<string, string|null>  $overrides */
function restoreTargetPatchGuard(string $scratch, array $overrides): void
{
    $guard = array_filter(
        array_merge(restoreTargetGuard($scratch), $overrides),
        static fn ($value): bool => $value !== null,
    );

    file_put_contents(restoreGuardFile($scratch), json_encode($guard, JSON_PRETTY_PRINT));
}

function restoreTargetStatePath(string $scratch, string $operation): string
{
    return $scratch.'/run/restores/parity-target/'.$operation.'/state.json';
}

/** @param  array<string, string|null>  $overrides */
function restoreTargetPatchState(string $scratch, string $operation, array $overrides): void
{
    $path = restoreTargetStatePath($scratch, $operation);

    $state = array_filter(
        array_merge(json_decode(File::get($path), true), $overrides),
        static fn ($value): bool => $value !== null,
    );

    file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT));
}

function restoreTargetInspect(string $scratch, string $operation, array $envOverrides = []): array
{
    return restoreTargetRun(
        $scratch,
        ['--inspect', '--target', 'parity-target', '--operation', $operation],
        $envOverrides,
    );
}

/**
 * A held target's complete observable state, so a read-only mode can be proven
 * to have changed none of it rather than merely asserted to be read-only.
 *
 * @return array<string, mixed>
 */
function restoreTargetHeldSnapshot(string $scratch, string $operation): array
{
    return [
        'maintenance' => restoreTargetMaintenanceActive($scratch),
        'queue' => restoreTargetQueueState($scratch),
        'scheduler' => restoreTargetSchedulerPresent($scratch),
        'current' => readlink($scratch.'/target/current'),
        'databases' => fakePostgresDatabases($scratch),
        'guard' => File::get(restoreGuardFile($scratch)),
        'state' => File::get(restoreTargetStatePath($scratch, $operation)),
        'storage' => is_file(restoreTargetStorage($scratch).'/app/restored-marker.txt'),
        'history' => restoreTargetHistory($scratch),
        'health_checks' => File::get($scratch.'/health-check.log'),
    ];
}

it('prints exactly one machine-readable result for an aligned restore', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        expect(restoreTargetResult($result['output']))->toBe([
            'status' => 'completed',
            'operation_id' => operationIdsIn($result['output'])[0],
            'target' => 'parity-target',
            'backup' => '20260115-120000',
            'backup_release' => FIXTURE_RELEASE,
            'backup_source_sha' => FIXTURE_SOURCE_SHA,
            'required_source_sha' => FIXTURE_SOURCE_SHA,
            'current_source_sha' => FIXTURE_SOURCE_SHA,
            'code_alignment' => 'ALIGNED',
            'runtime_resumed' => 'yes',
        ]);

        // The human summary is unchanged and still comes first: the machine
        // line is an addition, never a replacement.
        expect(mb_strpos($result['output'], 'RESTORE DATA COMPLETE: YES'))
            ->toBeLessThan(mb_strpos($result['output'], 'RATEGURU_RESTORE_RESULT='));
    } finally {
        removeScratchDir($scratch);
    }
});

it('prints exactly one machine-readable result for a held restore, naming the commit it needs', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        expect(restoreTargetResult($result['output']))->toBe([
            'status' => 'held',
            'operation_id' => operationIdsIn($result['output'])[0],
            'target' => 'parity-target',
            'backup' => '20260115-120000',
            'backup_release' => FIXTURE_RELEASE,
            'backup_source_sha' => FIXTURE_SOURCE_SHA,
            'required_source_sha' => FIXTURE_SOURCE_SHA,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
            'code_alignment' => 'REQUIRED',
            'runtime_resumed' => 'no',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('prints exactly one machine-readable result for a resume', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);
        restoreTargetAlignCode($scratch);

        $result = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);
        expect($result['exit'])->toBe(0, $result['output']);

        expect(restoreTargetResult($result['output']))->toBe([
            'status' => 'resumed',
            'operation_id' => $operation,
            'target' => 'parity-target',
            'backup' => '20260115-120000',
            'backup_release' => FIXTURE_RELEASE,
            'backup_source_sha' => FIXTURE_SOURCE_SHA,
            'required_source_sha' => FIXTURE_SOURCE_SHA,
            'current_source_sha' => FIXTURE_SOURCE_SHA,
            'code_alignment' => 'ALIGNED',
            'runtime_resumed' => 'yes',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('never puts a secret in the machine-readable result', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $result = restoreTargetApply($scratch);
        $decoded = restoreTargetResult($result['output']);
        $encoded = json_encode($decoded);

        // The fixture .env carries this password; nothing derived from it may
        // reach a line a workflow log will publish.
        foreach (['s3cr3t-not-logged', 'DB_PASSWORD', 'PGPASSWORD', 'rclone', 'authorized_keys', $scratch] as $secret) {
            expect($encoded)->not->toContain($secret);
        }

        // And the field set is closed: nothing may be added to it by accident.
        expect(array_keys($decoded))->toBe([
            'status', 'operation_id', 'target', 'backup', 'backup_release',
            'backup_source_sha', 'required_source_sha', 'current_source_sha',
            'code_alignment', 'runtime_resumed',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// the controlled code alignment: --inspect
// =============================================================================

it('reports a held operation and changes absolutely nothing', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);
        $before = restoreTargetHeldSnapshot($scratch, $operation);

        $result = restoreTargetInspect($scratch, $operation);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('STATUS: HELD')
            ->toContain('REQUIRED SOURCE SHA: '.FIXTURE_SOURCE_SHA)
            ->toContain('CURRENT SOURCE SHA: '.FIXTURE_OTHER_SOURCE_SHA)
            ->toContain('nothing was changed');

        expect(restoreTargetResult($result['output']))->toBe([
            'status' => 'held',
            'operation_id' => $operation,
            'target' => 'parity-target',
            'backup' => '20260115-120000',
            'backup_release' => FIXTURE_RELEASE,
            'backup_source_sha' => FIXTURE_SOURCE_SHA,
            'required_source_sha' => FIXTURE_SOURCE_SHA,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
            'code_alignment' => 'REQUIRED',
            'runtime_resumed' => 'no',
        ]);

        // Read-only means read-only: not one observable fact about the target,
        // the operation or the journal moved.
        expect(restoreTargetHeldSnapshot($scratch, $operation))->toBe($before);

        // And specifically none of the resume actions ran.
        expect(File::get($scratch.'/php.log'))->not->toContain('artisan up');
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('start');
    } finally {
        removeScratchDir($scratch);
    }
});

it('inspects the same operation repeatedly without ever resuming it', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);

        foreach (range(1, 3) as $attempt) {
            $result = restoreTargetInspect($scratch, $operation);
            expect($result['exit'])->toBe(0, "inspect attempt {$attempt}:\n".$result['output']);
        }

        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();
        expect(is_file(restoreGuardFile($scratch)))->toBeTrue();
        expect(restoreTargetHistory($scratch))->toHaveCount(1);
    } finally {
        removeScratchDir($scratch);
    }
});

it('takes no backup and no source, and requires an operation', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $noOperation = restoreTargetRun($scratch, ['--inspect', '--target', 'parity-target']);
        expect($noOperation['exit'])->not->toBe(0);
        expect($noOperation['output'])->toContain('--inspect requires --operation');

        $withBackup = restoreTargetRun($scratch, [
            '--inspect', '--target', 'parity-target',
            '--operation', '20260115-120000-abcdef', '--backup', '20260115-120000',
        ]);
        expect($withBackup['exit'])->not->toBe(0);
        expect($withBackup['output'])->toContain('--backup is only valid with --apply');

        $withSource = restoreTargetRun($scratch, [
            '--inspect', '--target', 'parity-target',
            '--operation', '20260115-120000-abcdef', '--source', 'offsite',
        ]);
        expect($withSource['exit'])->not->toBe(0);
        expect($withSource['output'])->toContain('--source is only valid with --apply');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to inspect an operation that is not this target own held code alignment', function (
    string $case,
    string $expected,
) {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);
        $before = restoreTargetHeldSnapshot($scratch, $operation);
        $requested = $operation;

        switch ($case) {
            case 'unknown-operation':
                $requested = '20200101-000000-abcdef';
                break;

            case 'guard-names-another-operation':
                restoreTargetPatchGuard($scratch, ['operation' => '20200101-000000-abcdef']);
                break;

            case 'guard-names-another-target':
                restoreTargetPatchGuard($scratch, ['target' => 'other-target']);
                break;

            case 'guard-missing':
                unlink(restoreGuardFile($scratch));
                break;

            case 'guard-is-in-progress':
                restoreTargetPatchGuard($scratch, ['status' => 'in-progress']);
                break;

            case 'guard-is-failed-held':
                restoreTargetPatchGuard($scratch, ['status' => 'failed-held']);
                break;

            case 'guard-and-state-disagree-about-the-commit':
                restoreTargetPatchGuard($scratch, ['required_source_sha' => FIXTURE_OTHER_SOURCE_SHA]);
                break;

            case 'abbreviated-required-commit':
                restoreTargetPatchGuard($scratch, ['required_source_sha' => 'a81d7f2']);
                restoreTargetPatchState($scratch, $operation, ['backup_source_sha' => 'a81d7f2']);
                break;

            case 'state-status-is-not-held':
                restoreTargetPatchState($scratch, $operation, ['status' => 'running']);
                break;

            case 'state-is-not-committed':
                restoreTargetPatchState($scratch, $operation, ['phase' => 'quiesced']);
                break;

            case 'state-alignment-is-not-required':
                restoreTargetPatchState($scratch, $operation, ['code_alignment' => 'aligned']);
                break;

            case 'state-names-another-target':
                restoreTargetPatchState($scratch, $operation, ['target' => 'other-target']);
                break;

            case 'state-names-no-backup':
                restoreTargetPatchState($scratch, $operation, ['backup' => null]);
                break;

            case 'state-is-not-an-object':
                file_put_contents(restoreTargetStatePath($scratch, $operation), "not json\n");
                break;
        }

        $result = restoreTargetInspect($scratch, $requested);

        expect($result['exit'])->not->toBe(0, $result['output']);
        expect($result['output'])->toContain($expected);
        expect($result['output'])->not->toContain('RATEGURU_RESTORE_RESULT=');

        // Every refusal leaves the target exactly as held as it was, except
        // for whatever this case deliberately edited itself.
        expect(restoreTargetMaintenanceActive($scratch))->toBe($before['maintenance']);
        expect(restoreTargetQueueState($scratch))->toBe($before['queue']);
        expect(restoreTargetSchedulerPresent($scratch))->toBe($before['scheduler']);
        expect(readlink($scratch.'/target/current'))->toBe($before['current']);
        expect(fakePostgresDatabases($scratch))->toBe($before['databases']);
        expect(restoreTargetHistory($scratch))->toBe($before['history']);
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    ['unknown-operation', 'is held by restore operation'],
    ['guard-names-another-operation', 'is held by restore operation'],
    ['guard-names-another-target', 'belongs to target other-target'],
    ['guard-missing', 'is not held: no restore guard'],
    // The two statuses that are NOT a code-alignment hold get a diagnosis of
    // their own rather than a generic refusal: they need manual recovery,
    // never a build and never a deployment.
    ['guard-is-in-progress', 'It is NOT a code-alignment hold'],
    ['guard-is-failed-held', 'Repair Target is not a way out either'],
    ['guard-and-state-disagree-about-the-commit', 'refusing to align a target whose own restore documents disagree'],
    ['abbreviated-required-commit', 'no full 40-character commit'],
    ['state-status-is-not-held', "has status 'running'"],
    ['state-is-not-committed', "is in phase 'quiesced'"],
    ['state-alignment-is-not-required', "records code_alignment 'aligned'"],
    ['state-names-another-target', 'belongs to target other-target'],
    ['state-names-no-backup', 'records no backup identity'],
    ['state-is-not-an-object', 'is not a JSON object'],
]);

it('refuses to report a target as held once the hold itself is gone', function (
    string $case,
    array $env,
    string $expected,
) {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);

        // Something outside this operation undid part of the hold: someone ran
        // `artisan up`, started the worker, or put the cron entry back. The
        // restore documents still say "held", but the target is no longer
        // safe to deploy an alignment into, and inspect must say so rather
        // than report a hold that is not there.
        switch ($case) {
            case 'maintenance-off':
                unlink(restoreTargetStorage($scratch).'/framework/down');
                break;

            case 'queue-running':
                file_put_contents($scratch.'/supervisor-state', "RUNNING\n");
                break;

            case 'queue-partly-running':
                file_put_contents($scratch.'/supervisor-second-state', "RUNNING\n");
                break;

            case 'scheduler-present':
                file_put_contents($scratch.'/cron.d/parity-scheduler', "* * * * * runtime true\n");
                break;
        }

        $result = restoreTargetInspect($scratch, $operation, $env);

        expect($result['exit'])->not->toBe(0, $result['output']);
        expect($result['output'])->toContain($expected);
        expect($result['output'])->not->toContain('RATEGURU_RESTORE_RESULT=');
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    ['maintenance-off', [], 'is NOT in maintenance mode'],
    ['queue-running', [], 'is not fully STOPPED'],
    ['queue-partly-running', [], 'is not fully STOPPED'],
    ['scheduler-present', [], 'a scheduled writer can fire against this target'],
    ['observation-fails', ['RGTEST_SUPERVISOR_STATUS_FAILURE' => 'supervisord is unreachable'], 'cannot observe the target queue program'],
]);

it('refuses to inspect a planned target before reading anything', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetRun($scratch, [
            '--inspect', '--target', 'planned-target', '--operation', '20260115-120000-abcdef',
        ]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('lifecycle=planned');
    } finally {
        removeScratchDir($scratch);
    }
});
