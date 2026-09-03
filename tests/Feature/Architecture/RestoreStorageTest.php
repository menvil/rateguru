<?php

use Illuminate\Support\Facades\File;

/**
 * Restore Target Data: `restore-storage` — the staged filesystem swap.
 *
 * Executes the real shipped infrastructure/scripts/restore-storage against a
 * real scratch target tree: real extraction, real renames, real ownership and
 * modes. Nothing about the filesystem behaviour is stubbed, which is the only
 * way "the live tree was untouched" and "the swap was reversed" mean anything.
 */
function restoreStorageScript(): string
{
    return base_path('infrastructure/scripts/restore-storage');
}

/**
 * @return array{exit: int, output: string}
 */
function restoreStorageRun(string $scratch, string $operationId, string $step, array $envOverrides = []): array
{
    [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

    $env = infraScriptEnv($scratch, $registryPath, $targetsPath, $envOverrides);

    [$exit, $output] = runInfraScript(patchedInfraScript($scratch, 'restore-storage'), [
        '--target', 'parity-target', '--operation', $operationId, '--'.$step,
    ], $env);

    return ['exit' => $exit, 'output' => $output];
}

function restoreStorageFixture(string $scratch, array $options = []): string
{
    targetTreeFixture($scratch);

    $operationId = $options['operation'] ?? '20260115-120000-abc123';
    restoreWorkspaceFixture(
        $scratch,
        $operationId,
        array_merge(['phase' => 'database-staged'], $options['state'] ?? []),
        $options['backup'] ?? [],
    );

    return $operationId;
}

function restoreStorageRoot(string $scratch): string
{
    return $scratch.'/target/shared/storage';
}

/** Every direct child of the target's shared/storage, sorted. */
function restoreStorageEntries(string $scratch): array
{
    $entries = array_values(array_diff(scandir(restoreStorageRoot($scratch)), ['.', '..']));
    sort($entries);

    return $entries;
}

// =============================================================================
// Stage: outside the live tree
// =============================================================================

it('extracts the archive into a sibling of the live tree and never touches the live tree', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreStorageFixture($scratch);

        $result = restoreStorageRun($scratch, $operation, 'stage');
        expect($result['exit'])->toBe(0, $result['output']);

        $storage = restoreStorageRoot($scratch);
        $stageParent = $storage.'/.restore-'.$operation;

        expect(is_dir($stageParent.'/app'))->toBeTrue();
        expect(is_file($stageParent.'/app/restored-marker.txt'))->toBeTrue();

        // The live tree is byte-identical to what it was.
        expect(is_file($storage.'/app/live-marker.txt'))->toBeTrue();
        expect(is_file($storage.'/app/restored-marker.txt'))->toBeFalse();

        // Both siblings live inside the target's own shared/storage, which is
        // what makes the activation renames same-filesystem.
        expect(restoreStorageEntries($scratch))->toBe(['.restore-'.$operation, 'app', 'framework']);

        $state = restoreOperationState($scratch.'/run/restores/parity-target/'.$operation);
        expect($state['phase'])->toBe('storage-staged');
        expect($state['storage_stage_path'])->toBe($stageParent.'/app');
        expect($state['pre_restore_storage_path'])->toBe($storage.'/.pre-restore-app-'.$operation);
    } finally {
        removeScratchDir($scratch);
    }
});

/**
 * A group this process belongs to that is NOT its primary group, or null.
 * Only such a group can prove the public/private split behaviourally: with
 * one group everywhere, "the web group" and "the runtime group" are the same
 * value and the assertion would pass either way.
 */
function restoreStorageSecondaryGroup(): ?string
{
    $groups = preg_split('/\s+/', trim((string) shell_exec('id -Gn')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $primary = trim((string) shell_exec('id -gn'));

    foreach ($groups as $group) {
        if ($group !== $primary) {
            return $group;
        }
    }

    return null;
}

it('gives the web group the two directories Nginx needs, and nothing else', function () {
    $webGroup = restoreStorageSecondaryGroup();

    if ($webGroup === null) {
        test()->markTestSkipped('needs a secondary group to distinguish the web group from the runtime group');
    }

    $scratch = restoreScratchDir();

    try {
        $operation = restoreStorageFixture($scratch, [
            'backup' => [
                'archive_builder' => function (string $stage): void {
                    mkdir($stage.'/app/private', 0o755, true);
                    file_put_contents($stage.'/app/private/secret-report.pdf', "private\n");
                },
            ],
        ]);

        $runtimeGroup = trim((string) shell_exec('id -gn'));
        $user = trim((string) shell_exec('id -un'));

        expect(restoreStorageRun($scratch, $operation, 'stage', [
            'RATEGURU_RESTORE_WEB_GROUP' => $webGroup,
        ])['exit'])->toBe(0);

        $app = restoreStorageRoot($scratch).'/.restore-'.$operation.'/app';

        // app itself: the traverse-only doorway, in the web group.
        expect(trim((string) shell_exec('stat -c "%U:%G" '.escapeshellarg($app))))->toBe($user.':'.$webGroup);
        expect(trim((string) shell_exec('stat -c "%a" '.escapeshellarg($app))))->toBe('2710');

        // app/public: Nginx lists and reads published media, so it and its
        // contents are in the web group.
        expect(trim((string) shell_exec('stat -c "%U:%G" '.escapeshellarg($app.'/public'))))->toBe($user.':'.$webGroup);
        expect(trim((string) shell_exec('stat -c "%a" '.escapeshellarg($app.'/public'))))->toBe('2750');
        expect(trim((string) shell_exec('stat -c "%G" '.escapeshellarg($app.'/public/restored-public.txt'))))->toBe($webGroup);
        expect(trim((string) shell_exec('stat -c "%a" '.escapeshellarg($app.'/public/restored-public.txt'))))->toBe('640');

        // Private application storage: same modes, but the target's OWN
        // runtime group — www-data can traverse app, so leaving private
        // content in the web group would hand it to Nginx.
        expect(trim((string) shell_exec('stat -c "%U:%G" '.escapeshellarg($app.'/private'))))->toBe($user.':'.$runtimeGroup);
        expect(trim((string) shell_exec('stat -c "%a" '.escapeshellarg($app.'/private'))))->toBe('2750');
        expect(trim((string) shell_exec('stat -c "%G" '.escapeshellarg($app.'/private/secret-report.pdf'))))->toBe($runtimeGroup);
        expect(trim((string) shell_exec('stat -c "%a" '.escapeshellarg($app.'/private/secret-report.pdf'))))->toBe('640');
    } finally {
        removeScratchDir($scratch);
    }
});

it('assigns the whole tree the runtime identity first, and the web group to exactly two paths', function () {
    // The structural half of the split above, so the contract holds on a
    // host where the test process has only one group.
    expect(File::get(restoreStorageScript()))
        ->toContain('chown -R "${RUNTIME_USER}:${RUNTIME_GROUP}" "${app_root}"')
        ->toContain('chgrp "${RESTORE_WEB_GROUP}" "${app_root}"')
        ->toContain('chgrp -R "${RESTORE_WEB_GROUP}" "${app_root}/public"');

    // And exactly those two, never a blanket web-group chown.
    expect(substr_count(File::get(restoreStorageScript()), 'RESTORE_WEB_GROUP}" "${app_root}'))->toBe(2);
});

it('removes its own partially staged tree when staging fails after extraction', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreStorageFixture($scratch);
        $staged = $scratch.'/run/restores/parity-target/'.$operation.'/selected-backup';

        // An archive whose single entry is named `app` but is a regular FILE:
        // it passes the pre-extraction safety pass (a regular file under the
        // app root is legitimate), gets extracted into the staging parent,
        // and only then fails the "did the archive produce app/?" check —
        // which is exactly the window in which a root-owned partial tree
        // would otherwise be left behind.
        buildArchiveFixture($staged.'/storage-app.tar.gz', [
            ['name' => 'app', 'type' => 'file'],
        ]);

        $result = restoreStorageRun($scratch, $operation, 'stage');

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('did not produce an app/ directory');
        expect($result['output'])->toContain('removed the partially staged storage tree');

        // Nothing root-owned is left inside the target's shared storage, and
        // the live tree is untouched.
        expect(restoreStorageEntries($scratch))->toBe(['app', 'framework']);
        expect(is_file(restoreStorageRoot($scratch).'/app/live-marker.txt'))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

it('keeps the staged tree when staging succeeds', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreStorageFixture($scratch);

        // The mirror image of the test above: the cleanup handler must be
        // disarmed on success, or a successful stage would delete the very
        // tree activation is about to swap in.
        expect(restoreStorageRun($scratch, $operation, 'stage')['exit'])->toBe(0);

        expect(is_dir(restoreStorageRoot($scratch).'/.restore-'.$operation.'/app'))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to stage over an existing staging directory or pre-restore tree', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreStorageFixture($scratch);
        mkdir(restoreStorageRoot($scratch).'/.restore-'.$operation, 0o700, true);

        $result = restoreStorageRun($scratch, $operation, 'stage');

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('storage staging directory already exists');
    } finally {
        removeScratchDir($scratch);
    }

    $scratch = restoreScratchDir();

    try {
        $operation = restoreStorageFixture($scratch);
        mkdir(restoreStorageRoot($scratch).'/.pre-restore-app-'.$operation, 0o700, true);

        $result = restoreStorageRun($scratch, $operation, 'stage');

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('pre-restore storage tree already exists');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a symlinked live storage tree rather than following it', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreStorageFixture($scratch);

        $storage = restoreStorageRoot($scratch);
        mkdir($scratch.'/elsewhere-app', 0o755, true);
        exec('rm -rf '.escapeshellarg($storage.'/app'));
        symlink($scratch.'/elsewhere-app', $storage.'/app');

        $result = restoreStorageRun($scratch, $operation, 'stage');

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('live storage tree must be a real directory, not a symlink');
        expect(is_dir($storage.'/.restore-'.$operation))->toBeFalse('nothing may be extracted for an unsafe live tree');
    } finally {
        removeScratchDir($scratch);
    }
});

it('re-validates the archive immediately before extracting it as root', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreStorageFixture($scratch);
        $staged = $scratch.'/run/restores/parity-target/'.$operation.'/selected-backup';

        // verify-backup already passed earlier in the operation; this proves
        // the archive is checked again at the last moment before extraction.
        buildArchiveFixture($staged.'/storage-app.tar.gz', [
            ['name' => 'app', 'type' => 'dir'],
            ['name' => 'app/escape', 'type' => 'symlink', 'link' => '/etc/passwd'],
        ]);

        $result = restoreStorageRun($scratch, $operation, 'stage');

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('contains a symbolic link');
        expect(is_dir(restoreStorageRoot($scratch).'/.restore-'.$operation))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('rejects an archive whose members are not all under app/, before extracting', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreStorageFixture($scratch, [
            'backup' => [
                'archive_builder' => function (string $stage): void {
                    mkdir($stage.'/framework', 0o755, true);
                    file_put_contents($stage.'/framework/x', "x\n");
                },
                'archive_member' => 'app framework',
            ],
        ]);

        $result = restoreStorageRun($scratch, $operation, 'stage');

        expect($result['exit'])->not->toBe(0);
        // Rejected by the pre-extraction archive-safety pass, so nothing was
        // written at all. The staged-entries check inside --stage is the
        // defence-in-depth backstop behind it and is unreachable while that
        // pass holds.
        expect($result['output'])->toContain('storage archive contains an entry outside app/');
        expect(is_dir(restoreStorageRoot($scratch).'/.restore-'.$operation))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Activation
// =============================================================================

it('swaps the staged tree in with two renames and retains the previous tree', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreStorageFixture($scratch);

        expect(restoreStorageRun($scratch, $operation, 'stage')['exit'])->toBe(0);

        $workspace = $scratch.'/run/restores/parity-target/'.$operation;
        setRestoreOperationPhase($workspace, 'database-activated');

        $result = restoreStorageRun($scratch, $operation, 'activate');
        expect($result['exit'])->toBe(0, $result['output']);

        $storage = restoreStorageRoot($scratch);

        expect(is_file($storage.'/app/restored-marker.txt'))->toBeTrue('the restored tree is now live');
        expect(is_file($storage.'/app/live-marker.txt'))->toBeFalse();
        expect(is_link($storage.'/app'))->toBeFalse('the live tree must remain a real directory');

        // The previous tree is retained under its operation-scoped name.
        expect(is_file($storage.'/.pre-restore-app-'.$operation.'/live-marker.txt'))->toBeTrue();

        expect(restoreOperationState($workspace)['phase'])->toBe('storage-activated');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Compensation
// =============================================================================

it('reverses a completed swap and puts the original tree back', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreStorageFixture($scratch);
        $workspace = $scratch.'/run/restores/parity-target/'.$operation;

        expect(restoreStorageRun($scratch, $operation, 'stage')['exit'])->toBe(0);
        setRestoreOperationPhase($workspace, 'database-activated');
        expect(restoreStorageRun($scratch, $operation, 'activate')['exit'])->toBe(0);

        setRestoreOperationPhase($workspace, 'storage-activated');
        $result = restoreStorageRun($scratch, $operation, 'compensate');

        expect($result['exit'])->toBe(0, $result['output']);

        $storage = restoreStorageRoot($scratch);
        expect(is_file($storage.'/app/live-marker.txt'))->toBeTrue('the original tree is live again');
        expect(is_file($storage.'/app/restored-marker.txt'))->toBeFalse();
        expect(is_file($storage.'/.restore-'.$operation.'/app/restored-marker.txt'))->toBeTrue();
        expect(file_exists($storage.'/.pre-restore-app-'.$operation))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('restores the original tree when activation stopped between the two renames', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreStorageFixture($scratch);
        $workspace = $scratch.'/run/restores/parity-target/'.$operation;
        $storage = restoreStorageRoot($scratch);

        expect(restoreStorageRun($scratch, $operation, 'stage')['exit'])->toBe(0);

        // Simulate the exact interrupted state: the live tree is parked under
        // the pre-restore name and nothing took its place.
        exec('mv '.escapeshellarg($storage.'/app').' '.escapeshellarg($storage.'/.pre-restore-app-'.$operation));
        expect(file_exists($storage.'/app'))->toBeFalse();

        setRestoreOperationPhase($workspace, 'database-activated');
        $result = restoreStorageRun($scratch, $operation, 'compensate');

        expect($result['exit'])->toBe(0, $result['output']);
        expect(is_file($storage.'/app/live-marker.txt'))->toBeTrue();
        expect($result['output'])->toContain('original tree restored');
    } finally {
        removeScratchDir($scratch);
    }
});

it('does nothing when the live tree was never swapped', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreStorageFixture($scratch, ['state' => ['phase' => 'database-activated']]);

        $result = restoreStorageRun($scratch, $operation, 'compensate');

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])->toContain('nothing to undo');
        expect(is_file(restoreStorageRoot($scratch).'/app/live-marker.txt'))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Commit
// =============================================================================

it('removes the pre-restore tree and the staging directory only at commit', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreStorageFixture($scratch);
        $workspace = $scratch.'/run/restores/parity-target/'.$operation;
        $storage = restoreStorageRoot($scratch);

        expect(restoreStorageRun($scratch, $operation, 'stage')['exit'])->toBe(0);
        setRestoreOperationPhase($workspace, 'database-activated');
        expect(restoreStorageRun($scratch, $operation, 'activate')['exit'])->toBe(0);

        expect(is_dir($storage.'/.pre-restore-app-'.$operation))->toBeTrue('the previous tree survives activation');

        setRestoreOperationPhase($workspace, 'verified');
        expect(restoreStorageRun($scratch, $operation, 'commit')['exit'])->toBe(0);

        expect(restoreStorageEntries($scratch))->toBe(['app', 'framework']);
        expect(is_file($storage.'/app/restored-marker.txt'))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Isolation, state gating and rm -rf safety
// =============================================================================

it('never touches another target tree or anything outside this target shared storage', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreStorageFixture($scratch);
        $workspace = $scratch.'/run/restores/parity-target/'.$operation;

        mkdir($scratch.'/other-project/shared/storage/app', 0o755, true);
        file_put_contents($scratch.'/other-project/shared/storage/app/keep.txt', "keep\n");

        expect(restoreStorageRun($scratch, $operation, 'stage')['exit'])->toBe(0);
        setRestoreOperationPhase($workspace, 'database-activated');
        expect(restoreStorageRun($scratch, $operation, 'activate')['exit'])->toBe(0);
        setRestoreOperationPhase($workspace, 'verified');
        expect(restoreStorageRun($scratch, $operation, 'commit')['exit'])->toBe(0);

        expect(is_file($scratch.'/other-project/shared/storage/app/keep.txt'))->toBeTrue();

        // Neither the release tree nor shared/.env was read or written.
        expect(is_file($scratch.'/target/shared/.env'))->toBeTrue();
        expect(is_link($scratch.'/target/current'))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

it('removes only operation-scoped direct children of this target shared storage', function () {
    $source = File::get(base_path('infrastructure/scripts/restore-common'));

    // The single guarded remover, and every guard it applies before rm runs.
    expect($source)
        ->toContain('refusing to remove an empty path')
        ->toContain('refusing to remove a relative path')
        ->toContain('refusing to remove a path with a relative component')
        ->toContain('refusing to remove a non-direct child')
        ->toContain('refusing to remove a storage entry this tooling did not create')
        ->toContain('refusing to recursively remove a symlink');

    // restore-storage itself never runs a bare rm -rf: every recursive
    // removal goes through the guarded helper.
    $storage = File::get(base_path('infrastructure/scripts/restore-storage'));

    foreach (preg_split('/\R/', $storage) as $line) {
        $trimmed = ltrim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        expect($trimmed)->not->toMatch('/(^|[;&|]\s*)rm\s+-rf?\b/');
    }
});

it('refuses every step when the operation is in the wrong phase or belongs to another target', function (string $step, string $phase) {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreStorageFixture($scratch, ['state' => ['phase' => $phase]]);

        $result = restoreStorageRun($scratch, $operation, $step);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain("is in phase '{$phase}'");
    } finally {
        removeScratchDir($scratch);
    }

    $scratch = restoreScratchDir();

    try {
        $operation = restoreStorageFixture($scratch, ['state' => ['target' => 'someone-else']]);

        $result = restoreStorageRun($scratch, $operation, $step);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('belongs to target someone-else');
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    ['stage', 'backup-verified'],
    ['activate', 'storage-staged'],
    ['compensate', 'backup-verified'],
    ['commit', 'storage-activated'],
]);

it('extracts with --no-same-owner and --no-same-permissions, trusting nothing from inside the archive', function () {
    $source = File::get(restoreStorageScript());

    expect($source)
        ->toContain('--no-same-owner')
        ->toContain('--no-same-permissions');
});

it('rejects a planned target before computing any storage path', function () {
    $scratch = restoreScratchDir();

    try {
        [$registryPath, $targetsPath] = parityRegistryFixture($scratch);
        $env = infraScriptEnv($scratch, $registryPath, $targetsPath);

        [$exit, $output] = runInfraScript(patchedInfraScript($scratch, 'restore-storage'), [
            '--target', 'planned-target', '--operation', '20260115-120000-abc123', '--stage',
        ], $env);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('lifecycle=planned');
    } finally {
        removeScratchDir($scratch);
    }
});

it('requires root for a real invocation', function () {
    $source = File::get(restoreStorageScript());

    expect($source)->toContain("main() {\n    require_root\n    parse_restore_storage_args");
});
