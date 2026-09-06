<?php

use Illuminate\Support\Facades\File;

/**
 * Restore Target Data: `restore-database` — the staged PostgreSQL swap.
 *
 * Executes the real shipped infrastructure/scripts/restore-database against a
 * file-backed fake PostgreSQL (one file per database, holding owner and
 * allow-connections state). That fake is what makes these tests real rather
 * than rigged: a rename, a connection barrier and a drop are all genuinely
 * observable across separate script invocations, so "the live database was
 * never touched" and "the swap was reversed" are checked against catalog
 * state, not against log lines.
 */
function restoreDatabaseScript(): string
{
    return base_path('infrastructure/scripts/restore-database');
}

/**
 * @return array{exit: int, output: string}
 */
function restoreDatabaseRun(string $scratch, string $operationId, string $step, array $envOverrides = []): array
{
    [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

    $env = infraScriptEnv($scratch, $registryPath, $targetsPath, array_merge(fakePostgresEnv($scratch), $envOverrides));

    [$exit, $output] = runInfraScript(patchedInfraScript($scratch, 'restore-database'), [
        '--target', 'parity-target', '--operation', $operationId, '--'.$step,
    ], $env);

    return ['exit' => $exit, 'output' => $output];
}

/**
 * The standard fixture: a target tree, a fake catalog holding the live
 * database, and a workspace with a verified staged backup.
 */
function restoreDatabaseFixture(string $scratch, array $options = []): string
{
    targetTreeFixture($scratch);
    installFakePostgres($scratch, $options);

    $operationId = $options['operation'] ?? '20260115-120000-abc123';
    restoreWorkspaceFixture($scratch, $operationId, $options['state'] ?? []);

    return $operationId;
}

// =============================================================================
// Stage: never touches the live database
// =============================================================================

it('stages the backup into a new temporary database and leaves the live one untouched', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreDatabaseFixture($scratch);

        $result = restoreDatabaseRun($scratch, $operation, 'stage');

        expect($result['exit'])->toBe(0, $result['output']);

        $staged = stagedDatabaseName($operation);

        expect(fakePostgresDatabases($scratch))->toBe(['parity_db', $staged]);

        // Created from template0, owned by the application role.
        expect(File::get($scratch.'/createdb.log'))
            ->toContain('--template=template0')
            ->toContain('--owner=parity_app')
            ->toContain($staged);

        // Restored AS the application role, over TCP, with the credential in
        // the environment rather than the argument vector.
        $pgRestore = File::get($scratch.'/pg_restore.log');
        expect($pgRestore)
            ->toContain('--exit-on-error')
            ->toContain('--single-transaction')
            ->toContain('--no-owner')
            ->toContain('--no-privileges')
            ->toContain('--username=parity_app')
            ->toContain('--dbname='.$staged)
            ->toContain('pgpassword-present')
            ->not->toContain('s3cr3t-not-logged');

        // The live database was never renamed, dropped or blocked.
        expect(File::get($scratch.'/psql.log'))
            ->not->toContain('RENAME TO')
            ->not->toContain('ALLOW_CONNECTIONS');
        expect(File::get($scratch.'/dropdb.log'))->toBe('');
        expect(trim(File::get($scratch.'/pg/db/parity_db')))->toBe('parity_app t');

        $state = restoreOperationState($scratch.'/run/restores/parity-target/'.$operation);
        expect($state['phase'])->toBe('database-staged');
        expect($state['staged_database'])->toBe($staged);
        expect($state['pre_restore_database'])->toBe(preRestoreDatabaseName($operation));
        expect($state['staged_tables'])->toBe('42');
        expect($state['staged_migrations'])->toBe('17');
    } finally {
        removeScratchDir($scratch);
    }
});

it('leaves the live database untouched and drops the temporary one when pg_restore fails', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreDatabaseFixture($scratch);

        $result = restoreDatabaseRun($scratch, $operation, 'stage', ['RGTEST_PG_RESTORE_EXIT' => '3']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('the live database parity_db was never touched');

        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(File::get($scratch.'/dropdb.log'))->toContain(stagedDatabaseName($operation));
    } finally {
        removeScratchDir($scratch);
    }
});

it('rejects a staged database with no public tables, or an unreadable migrations table', function (array $env, string $expected) {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreDatabaseFixture($scratch);

        $result = restoreDatabaseRun($scratch, $operation, 'stage', $env);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain($expected);

        expect(fakePostgresDatabases($scratch))->toBe(['parity_db'], 'the staged database must be dropped');
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'no tables' => [['RGTEST_TABLE_COUNT' => '0'], 'staged database contains no public tables'],
    'unreadable migration count' => [['RGTEST_MIGRATION_COUNT' => 'not-a-number'], 'unable to determine the staged migrations table row count'],
]);

it('refuses to restore as an application role holding elevated privileges', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreDatabaseFixture($scratch);

        $result = restoreDatabaseRun($scratch, $operation, 'stage', ['RGTEST_ROLE_ELEVATED' => 'SUPERUSER']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('holds elevated privileges (SUPERUSER)');

        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(File::get($scratch.'/createdb.log'))->toBe('', 'nothing may be created for an unsafe role');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to stage when the application role cannot log in or does not exist', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreDatabaseFixture($scratch, ['roles' => ['someone_else']]);

        $result = restoreDatabaseRun($scratch, $operation, 'stage');

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('application role parity_app does not exist');
    } finally {
        removeScratchDir($scratch);
    }

    $scratch = restoreScratchDir();

    try {
        $operation = restoreDatabaseFixture($scratch);

        $result = restoreDatabaseRun($scratch, $operation, 'stage', ['RGTEST_ROLE_CANLOGIN' => 'f']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('cannot log in');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to stage when the registry and shared/.env disagree about the database or the role', function () {
    $scratch = restoreScratchDir();

    try {
        targetTreeFixture($scratch, ['database' => 'a_different_db']);
        installFakePostgres($scratch);
        $operation = '20260115-120000-abc123';
        restoreWorkspaceFixture($scratch, $operation);

        $result = restoreDatabaseRun($scratch, $operation, 'stage');

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('drift: the registry declares database parity_db');
        expect($result['output'])->toContain('resolve the mismatch manually; nothing was changed');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to stage when the target database does not exist — a restore is not a provisioning step', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreDatabaseFixture($scratch, ['databases' => []]);

        $result = restoreDatabaseRun($scratch, $operation, 'stage');

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('this is a data restore, not a target provisioning step');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Activation
// =============================================================================

it('blocks connections, renames the live database aside and renames the staged one in', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreDatabaseFixture($scratch);

        expect(restoreDatabaseRun($scratch, $operation, 'stage')['exit'])->toBe(0);

        $workspace = $scratch.'/run/restores/parity-target/'.$operation;
        setRestoreOperationPhase($workspace, 'emergency-backup-verified');

        $result = restoreDatabaseRun($scratch, $operation, 'activate');
        expect($result['exit'])->toBe(0, $result['output']);

        $pre = preRestoreDatabaseName($operation);

        // The staged database now carries the canonical name; the previous
        // one is retained, still blocked to connections.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db', $pre]);
        expect(trim(File::get($scratch.'/pg/db/parity_db')))->toBe('parity_app t');
        expect(trim(File::get($scratch.'/pg/db/'.$pre)))->toBe('parity_app f');

        // Nothing was dropped: the pre-restore database is the way back until
        // the whole operation commits.
        expect(File::get($scratch.'/dropdb.log'))->toBe('');

        $psql = File::get($scratch.'/psql.log');
        $barrierPosition = mb_strpos($psql, 'ALLOW_CONNECTIONS false');
        $terminatePosition = mb_strpos($psql, 'pg_terminate_backend');
        $renamePosition = mb_strpos($psql, 'RENAME TO');

        expect($barrierPosition)->toBeLessThan($terminatePosition);
        expect($terminatePosition)->toBeLessThan($renamePosition);

        // Scoped to this database only — never a host-wide disconnect.
        expect($psql)->toContain("datname = 'parity_db'");

        expect(restoreOperationState($workspace)['phase'])->toBe('database-activated');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to activate a staged database that does not exist', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreDatabaseFixture($scratch, ['state' => ['phase' => 'emergency-backup-verified']]);

        $result = restoreDatabaseRun($scratch, $operation, 'activate');

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('staged restore database is missing');
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Compensation
// =============================================================================

it('reverses a completed swap, putting the pre-restore database back under the canonical name', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreDatabaseFixture($scratch);
        $workspace = $scratch.'/run/restores/parity-target/'.$operation;

        expect(restoreDatabaseRun($scratch, $operation, 'stage')['exit'])->toBe(0);
        setRestoreOperationPhase($workspace, 'emergency-backup-verified');
        expect(restoreDatabaseRun($scratch, $operation, 'activate')['exit'])->toBe(0);

        // A genuinely distinguishing marker on the ORIGINAL database, so
        // "the original is back" is proven by identity rather than by name.
        // The fake catalog stores "<owner> <allowconn>", and a compensation
        // that merely renamed the RESTORED database back would leave the
        // restored owner value in place instead of this one.
        file_put_contents($scratch.'/pg/db/'.preRestoreDatabaseName($operation), "the_original_database f\n");

        $result = restoreDatabaseRun($scratch, $operation, 'compensate');
        expect($result['exit'])->toBe(0, $result['output']);

        expect(fakePostgresDatabases($scratch))->toBe(['parity_db', stagedDatabaseName($operation)]);
        expect(trim(File::get($scratch.'/pg/db/parity_db')))->toBe('the_original_database t');
        expect(trim(File::get($scratch.'/pg/db/'.stagedDatabaseName($operation))))->toBe('parity_app f');
        expect($result['output'])->toContain('swap reversed');
    } finally {
        removeScratchDir($scratch);
    }
});

it('restores the original database when activation stopped between the two renames', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreDatabaseFixture($scratch);
        $workspace = $scratch.'/run/restores/parity-target/'.$operation;
        $staged = stagedDatabaseName($operation);
        $pre = preRestoreDatabaseName($operation);

        expect(restoreDatabaseRun($scratch, $operation, 'stage')['exit'])->toBe(0);
        setRestoreOperationPhase($workspace, 'emergency-backup-verified');

        // The second rename fails: the live name is gone, the original is
        // parked under the pre-restore name, the staged one still exists.
        $activation = restoreDatabaseRun($scratch, $operation, 'activate', [
            'RGTEST_RENAME_FAIL' => $staged.'->parity_db',
        ]);

        expect($activation['exit'])->not->toBe(0);
        expect(fakePostgresDatabases($scratch))->toBe([$pre, $staged]);

        setRestoreOperationPhase($workspace, 'database-activated');

        $result = restoreDatabaseRun($scratch, $operation, 'compensate');
        expect($result['exit'])->toBe(0, $result['output']);

        expect(fakePostgresDatabases($scratch))->toBe(['parity_db', $staged]);
        expect(trim(File::get($scratch.'/pg/db/parity_db')))->toBe('parity_app t');
        expect($result['output'])->toContain('original database restored');
    } finally {
        removeScratchDir($scratch);
    }
});

it('does nothing, and re-enables connections, when the swap never happened', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreDatabaseFixture($scratch, ['state' => ['phase' => 'database-activated']]);

        // The barrier was applied but the rename never ran.
        file_put_contents($scratch.'/pg/db/parity_db', "parity_app f\n");

        $result = restoreDatabaseRun($scratch, $operation, 'compensate');

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])->toContain('nothing to undo');
        expect(trim(File::get($scratch.'/pg/db/parity_db')))->toBe('parity_app t');
    } finally {
        removeScratchDir($scratch);
    }
});

it('fails loudly when neither the canonical nor the pre-restore database exists', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreDatabaseFixture($scratch, [
            'databases' => [],
            'state' => ['phase' => 'database-activated'],
        ]);

        $result = restoreDatabaseRun($scratch, $operation, 'compensate');

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('database compensation impossible');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Commit
// =============================================================================

it('drops the pre-restore database only at commit', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreDatabaseFixture($scratch);
        $workspace = $scratch.'/run/restores/parity-target/'.$operation;

        expect(restoreDatabaseRun($scratch, $operation, 'stage')['exit'])->toBe(0);
        setRestoreOperationPhase($workspace, 'emergency-backup-verified');
        expect(restoreDatabaseRun($scratch, $operation, 'activate')['exit'])->toBe(0);

        expect(File::get($scratch.'/dropdb.log'))->toBe('', 'the pre-restore database survives activation');

        setRestoreOperationPhase($workspace, 'verified');
        $result = restoreDatabaseRun($scratch, $operation, 'commit');

        expect($result['exit'])->toBe(0, $result['output']);
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(File::get($scratch.'/dropdb.log'))->toContain(preRestoreDatabaseName($operation));
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Isolation, state gating and safety
// =============================================================================

it('never touches an unrelated database on the same host', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreDatabaseFixture($scratch, [
            'databases' => ['parity_db' => 'parity_app', 'cataloghub_production' => 'cataloghub_app'],
        ]);
        $workspace = $scratch.'/run/restores/parity-target/'.$operation;

        expect(restoreDatabaseRun($scratch, $operation, 'stage')['exit'])->toBe(0);
        setRestoreOperationPhase($workspace, 'emergency-backup-verified');
        expect(restoreDatabaseRun($scratch, $operation, 'activate')['exit'])->toBe(0);
        setRestoreOperationPhase($workspace, 'verified');
        expect(restoreDatabaseRun($scratch, $operation, 'commit')['exit'])->toBe(0);

        expect(trim(File::get($scratch.'/pg/db/cataloghub_production')))->toBe('cataloghub_app t');
        expect(File::get($scratch.'/psql.log'))->not->toContain('cataloghub');
        expect(File::get($scratch.'/dropdb.log'))->not->toContain('cataloghub');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses every destructive step when the operation is in the wrong phase or belongs to another target', function (string $step, string $phase) {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreDatabaseFixture($scratch, ['state' => ['phase' => $phase]]);

        $result = restoreDatabaseRun($scratch, $operation, $step);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain("is in phase '{$phase}'");
    } finally {
        removeScratchDir($scratch);
    }

    $scratch = restoreScratchDir();

    try {
        $operation = restoreDatabaseFixture($scratch, ['state' => ['target' => 'someone-else']]);

        $result = restoreDatabaseRun($scratch, $operation, $step);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('belongs to target someone-else');
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    ['stage', 'quiesced'],
    ['activate', 'backup-verified'],
    ['compensate', 'backup-verified'],
    ['commit', 'database-activated'],
]);

it('refuses to run at all without a state document created by restore-target', function () {
    $scratch = restoreScratchDir();

    try {
        targetTreeFixture($scratch);
        installFakePostgres($scratch);

        $operation = '20260115-120000-abc123';
        mkdir($scratch.'/run/restores/parity-target/'.$operation, 0o700, true);

        $result = restoreDatabaseRun($scratch, $operation, 'activate');

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('this primitive is driven by restore-target, never invoked on its own');
    } finally {
        removeScratchDir($scratch);
    }
});

it('derives every database name internally and never accepts one from the command line', function () {
    $source = File::get(restoreDatabaseScript());

    foreach (['--database', '--dbname ', '--staged-database', '--sql'] as $rejected) {
        expect($source)->not->toContain("{$rejected})");
    }

    // Both names come from the registry namespace plus the generated
    // operation ID, and both are matched against the closed identifier class
    // before they can reach SQL text.
    expect($source)->toContain('restore_staged_database_name "${BACKUP_NAMESPACE}" "${OPERATION_ID}"');
    expect($source)->toContain('restore_pre_restore_database_name "${BACKUP_NAMESPACE}" "${OPERATION_ID}"');
    expect(File::get(base_path('infrastructure/scripts/restore-common')))
        ->toContain('restore_validate_identifier "${name}" "staged restore database name"');
});

it('never sources or evals the target environment file', function () {
    foreach (['restore-database', 'restore-common'] as $script) {
        $source = File::get(base_path('infrastructure/scripts/'.$script));

        foreach (preg_split('/\R/', $source) as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            expect(preg_match('/(^|[;&|]\s*)(source|\.|eval)\s+.*(\.env|SHARED_ENV|env_file)/', $trimmed))
                ->toBe(0, "a target .env must never be executed as shell: {$trimmed}");
        }
    }
});

it('runs no migration and issues no schema reset, ever', function () {
    $source = File::get(restoreDatabaseScript());

    foreach (['artisan migrate', 'migrate --force', 'migrate:fresh', 'db:wipe', 'DROP SCHEMA', 'TRUNCATE'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
});

it('requires root for a real invocation', function () {
    $source = File::get(restoreDatabaseScript());

    expect($source)->toContain("main() {\n    require_root\n    parse_restore_database_args");
});
