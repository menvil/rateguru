<?php

use Illuminate\Support\Facades\File;

/**
 * Restore Target Data: `fetch-backup` — staging ONE exact backup into a root-only
 * restore operation workspace.
 *
 * These tests execute the real shipped infrastructure/scripts/fetch-backup —
 * never a reimplementation — with every host dependency supplied through the
 * gated RATEGURU_* override contract, mirroring BackupTest/RestoreTest
 * exactly. The script's own root-only `install -o root -g root` calls need
 * real root, so the full-pipeline runs use a byte-for-byte patched copy with
 * only those two flag values rewritten (patchedInfraScript).
 */
function fetchBackupScript(): string
{
    return base_path('infrastructure/scripts/fetch-backup');
}

/**
 * @param  array<string, string>  $envOverrides
 * @return array{exit: int, output: string, scratch: string, backupBase: string, namespaceRoot: string, runRoot: string}
 */
function fetchBackupRun(string $scratch, array $arguments, array $envOverrides = [], array $registryOptions = []): array
{
    [$registryPath, $targetsPath] = parityRegistryFixture($scratch, $registryOptions);

    $backupBase = $scratch.'/backups';
    $namespaceRoot = $backupBase.'/'.($registryOptions['namespace'] ?? 'parity');

    $env = infraScriptEnv($scratch, $registryPath, $targetsPath, $envOverrides);

    [$exit, $output] = runInfraScript(patchedInfraScript($scratch, 'fetch-backup'), $arguments, $env);

    return [
        'exit' => $exit,
        'output' => $output,
        'scratch' => $scratch,
        'backupBase' => $backupBase,
        'namespaceRoot' => $namespaceRoot,
        'runRoot' => $scratch.'/run',
    ];
}

function fetchBackupWorkspace(string $scratch, string $operationId): string
{
    return $scratch.'/run/restores/parity-target/'.$operationId;
}

/**
 * An rclone stub that serves exactly one fixed remote directory tree from
 * disk, and records every argument vector it was given — so a test can prove
 * the remote path was composed from the registry and the fixed bucket rather
 * than from anything a caller supplied.
 */
function fetchBackupRcloneStub(string $scratch): string
{
    return writeExecutable($scratch.'/bin/rclone', <<<'BASH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "rclone $*" >> "${RGTEST_RCLONE_LOG}"

# rclone --config X copy SOURCE DEST [flags...]
source_path=""
dest_path=""
seen_copy=false
positional=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        copy) seen_copy=true; shift ;;
        --config) shift 2 ;;
        # `--stats 10s` takes a value; every other flag rclone is given here
        # is a bare switch. Counting positionals rather than taking "the last
        # bare token" is what keeps a flag value out of the destination path.
        --stats) shift 2 ;;
        --*) shift ;;
        *)
            if [[ "${seen_copy}" == true ]] && (( positional < 2 )); then
                if (( positional == 0 )); then source_path="$1"; else dest_path="$1"; fi
                positional=$(( positional + 1 ))
            fi
            shift
            ;;
    esac
done

[[ "${seen_copy}" == true ]] || exit 1

# A relative destination means the argument parsing above mistook a flag
# VALUE for a path — which would silently copy a backup into whatever
# directory the test runner happened to be in. Fail loudly instead.
[[ "${dest_path}" == /* ]] || {
    printf 'ERROR: stub refuses a relative destination: %s\n' "${dest_path}" >&2
    exit 1
}

local_source="${RGTEST_REMOTE_ROOT}/${source_path}"

if [[ ! -d "${local_source}" ]]; then
    printf 'ERROR: remote directory not found: %s\n' "${source_path}" >&2
    exit 1
fi

cp -a "${local_source}/." "${dest_path}/"
BASH);
}

// =============================================================================
// Selection contract: exact backup only, never "latest"
// =============================================================================

it('requires --target, --source and --backup, and offers no latest', function () {
    $scratch = restoreScratchDir();

    try {
        $missingBackup = fetchBackupRun($scratch, ['--target', 'parity-target', '--source', 'local']);
        expect($missingBackup['exit'])->not->toBe(0);
        expect($missingBackup['output'])->toContain('--backup is required');
        expect($missingBackup['output'])->toContain("there is no 'latest'");

        $missingSource = fetchBackupRun($scratch, ['--target', 'parity-target', '--backup', '20260115-120000']);
        expect($missingSource['exit'])->not->toBe(0);
        expect($missingSource['output'])->toContain('--source is required');

        $missingTarget = fetchBackupRun($scratch, ['--source', 'local', '--backup', '20260115-120000']);
        expect($missingTarget['exit'])->not->toBe(0);
        expect($missingTarget['output'])->toContain('--target is required');
    } finally {
        removeScratchDir($scratch);
    }
});

it('never accepts a filesystem path, an rclone remote, a bucket or a database name from the command line', function () {
    $source = File::get(fetchBackupScript());

    foreach (['--path', '--backup-root', '--remote', '--bucket', '--database', '--dir', '--latest'] as $rejected) {
        expect($source)->not->toContain("{$rejected})", "fetch-backup must not accept {$rejected}");
    }

    // The remote is composed from fixed configuration plus the registry
    // namespace and the validated backup ID — nothing else.
    expect($source)->toContain('${RCLONE_REMOTE}:${RCLONE_BUCKET}/rateguru/${BACKUP_NAMESPACE}/${BACKUP_ID}');
});

it('rejects a malformed backup timestamp before touching the filesystem', function (string $backupId) {
    $scratch = restoreScratchDir();

    try {
        $result = fetchBackupRun($scratch, ['--target', 'parity-target', '--source', 'local', '--backup', $backupId]);

        expect($result['exit'])->not->toBe(0, "expected {$backupId} to be rejected");
        expect($result['output'])->toContain('backup ID');
        expect(is_dir($result['runRoot'].'/restores'))->toBeFalse('no workspace may be created for a malformed backup ID');
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'not a timestamp' => 'latest',
    'wrong shape' => '2026-01-15-120000',
    'path traversal' => '../../etc',
    'absolute path' => '/etc/passwd',
    'trailing slash' => '20260115-120000/',
    'impossible month' => '20261315-120000',
    'impossible day' => '20260199-120000',
    'impossible hour' => '20260115-250000',
    'command substitution' => '$(id)',
    'shell metacharacters' => '20260115-120000; id',
]);

// =============================================================================
// Local staging
// =============================================================================

it('stages the exact named local backup, and only that one', function () {
    $scratch = restoreScratchDir();

    try {
        $namespaceRoot = $scratch.'/backups/parity';
        buildBackupFixture($namespaceRoot, '20260115-120000');
        buildBackupFixture($namespaceRoot, '20260120-120000');

        $result = fetchBackupRun($scratch, ['--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000']);

        expect($result['exit'])->toBe(0, $result['output']);

        $operations = operationIdsIn($result['output']);
        expect($operations)->toHaveCount(1);

        $staged = fetchBackupWorkspace($scratch, $operations[0]).'/selected-backup';

        expect(is_dir($staged))->toBeTrue();
        expect(File::get($staged.'/manifest.json'))->toContain('"backup_namespace": "parity"');

        // The older backup's own dump content, proving the named backup was
        // staged rather than the newest one.
        expect(sha1_file($staged.'/database.dump'))
            ->toBe(sha1_file($namespaceRoot.'/20260115-120000/database.dump'));

        foreach (['database.dump', 'storage-app.tar.gz', 'environment.env', 'release.json', 'server-configuration.tar.gz', 'manifest.json', 'SHA256SUMS'] as $file) {
            expect(is_file($staged.'/'.$file))->toBeTrue("missing staged file: {$file}");
            expect(is_link($staged.'/'.$file))->toBeFalse("staged file must not be a symlink: {$file}");
        }

        expect(count(array_diff(scandir($staged), ['.', '..'])))->toBe(7);
    } finally {
        removeScratchDir($scratch);
    }
});

it('keeps the staged backup usable after the original backup directory disappears', function () {
    $scratch = restoreScratchDir();

    try {
        $namespaceRoot = $scratch.'/backups/parity';
        buildBackupFixture($namespaceRoot, '20260115-120000');
        $originalDump = File::get($namespaceRoot.'/20260115-120000/database.dump');

        $result = fetchBackupRun($scratch, ['--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000']);
        expect($result['exit'])->toBe(0, $result['output']);

        $operations = operationIdsIn($result['output']);
        $staged = fetchBackupWorkspace($scratch, $operations[0]).'/selected-backup';

        // Exactly what backup's own local retention does after the emergency
        // pre-restore backup is created.
        exec('rm -rf '.escapeshellarg($namespaceRoot.'/20260115-120000'));
        expect(is_dir($namespaceRoot.'/20260115-120000'))->toBeFalse();

        foreach (['database.dump', 'storage-app.tar.gz', 'manifest.json', 'SHA256SUMS'] as $file) {
            expect(is_file($staged.'/'.$file))->toBeTrue("staged {$file} must survive the source disappearing");
        }

        expect(File::get($staged.'/database.dump'))->toBe($originalDump);

        // And the staged copy still checksums cleanly on its own.
        exec('cd '.escapeshellarg($staged).' && sha256sum --check SHA256SUMS 2>&1', $out, $exit);
        expect($exit)->toBe(0, implode("\n", $out));
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a backup that does not exist in the target namespace', function () {
    $scratch = restoreScratchDir();

    try {
        buildBackupFixture($scratch.'/backups/parity', '20260115-120000');
        buildBackupFixture($scratch.'/backups/other', '20260101-000000');

        $result = fetchBackupRun($scratch, ['--target', 'parity-target', '--source', 'local', '--backup', '20260101-000000']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('does not exist in namespace parity');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a symlinked backup namespace root and a symlinked backup directory', function () {
    $scratch = restoreScratchDir();

    try {
        // A symlinked timestamp directory pointing at a real backup elsewhere.
        $namespaceRoot = $scratch.'/backups/parity';
        mkdir($namespaceRoot, 0o755, true);
        buildBackupFixture($scratch.'/elsewhere', '20260115-120000');
        symlink($scratch.'/elsewhere/20260115-120000', $namespaceRoot.'/20260115-120000');

        $result = fetchBackupRun($scratch, ['--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('backup directory must not be a symlink');
    } finally {
        removeScratchDir($scratch);
    }

    $scratch = restoreScratchDir();

    try {
        // A symlinked namespace root pointing at a directory of real backups.
        mkdir($scratch.'/backups', 0o755, true);
        buildBackupFixture($scratch.'/elsewhere-ns', '20260115-120000');
        symlink($scratch.'/elsewhere-ns', $scratch.'/backups/parity');

        $result = fetchBackupRun($scratch, ['--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('backup namespace root must not be a symlink');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a backup whose required entry is a symlink rather than a regular file', function () {
    $scratch = restoreScratchDir();

    try {
        $dir = buildBackupFixture($scratch.'/backups/parity', '20260115-120000');
        unlink($dir.'/database.dump');
        symlink('/etc/hosts', $dir.'/database.dump');

        $result = fetchBackupRun($scratch, ['--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('backup file must not be a symlink: database.dump');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a backup directory missing one of the seven required files', function () {
    $scratch = restoreScratchDir();

    try {
        $dir = buildBackupFixture($scratch.'/backups/parity', '20260115-120000');
        unlink($dir.'/server-configuration.tar.gz');

        $result = fetchBackupRun($scratch, ['--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('backup is missing a required file: server-configuration.tar.gz');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Offsite staging
// =============================================================================

it('downloads exactly one backup from the fixed remote and bucket for the registry namespace', function () {
    $scratch = restoreScratchDir();

    try {
        fetchBackupRcloneStub($scratch);

        // Two remote backups; only the named one may be downloaded.
        buildBackupFixture($scratch.'/remote/rateguru-b2:rateguru-database-backups/rateguru/parity', '20260115-120000');
        buildBackupFixture($scratch.'/remote/rateguru-b2:rateguru-database-backups/rateguru/parity', '20260120-120000');

        $result = fetchBackupRun($scratch, ['--target', 'parity-target', '--source', 'offsite', '--backup', '20260115-120000'], [
            'RATEGURU_RCLONE_BIN' => $scratch.'/bin/rclone',
            'RATEGURU_RCLONE_CONFIG' => writeExecutable($scratch.'/rclone.conf', "[rateguru-b2]\ntype = b2\n"),
            'RGTEST_RCLONE_LOG' => $scratch.'/rclone.log',
            'RGTEST_REMOTE_ROOT' => $scratch.'/remote',
        ]);

        expect($result['exit'])->toBe(0, $result['output']);

        $log = File::get($scratch.'/rclone.log');
        expect($log)->toContain('rateguru-b2:rateguru-database-backups/rateguru/parity/20260115-120000');
        expect($log)->not->toContain('20260120-120000');
        expect(substr_count($log, 'copy'))->toBe(1);

        $operations = operationIdsIn($result['output']);
        $staged = fetchBackupWorkspace($scratch, $operations[0]).'/selected-backup';
        expect(is_file($staged.'/manifest.json'))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

it('fails closed when the offsite backup is incomplete', function () {
    $scratch = restoreScratchDir();

    try {
        fetchBackupRcloneStub($scratch);

        $remote = $scratch.'/remote/rateguru-b2:rateguru-database-backups/rateguru/parity';
        $dir = buildBackupFixture($remote, '20260115-120000');
        unlink($dir.'/release.json');

        $result = fetchBackupRun($scratch, ['--target', 'parity-target', '--source', 'offsite', '--backup', '20260115-120000'], [
            'RATEGURU_RCLONE_BIN' => $scratch.'/bin/rclone',
            'RATEGURU_RCLONE_CONFIG' => writeExecutable($scratch.'/rclone.conf', "[rateguru-b2]\n"),
            'RGTEST_RCLONE_LOG' => $scratch.'/rclone.log',
            'RGTEST_REMOTE_ROOT' => $scratch.'/remote',
        ]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('release.json');
    } finally {
        removeScratchDir($scratch);
    }
});

it('never logs a credential, an rclone config body or an environment value', function () {
    $scratch = restoreScratchDir();

    try {
        fetchBackupRcloneStub($scratch);

        buildBackupFixture($scratch.'/remote/rateguru-b2:rateguru-database-backups/rateguru/parity', '20260115-120000');

        $config = $scratch.'/rclone.conf';
        file_put_contents($config, "[rateguru-b2]\ntype = b2\naccount = SUPER-SECRET-ACCOUNT\nkey = SUPER-SECRET-KEY\n");

        $result = fetchBackupRun($scratch, ['--target', 'parity-target', '--source', 'offsite', '--backup', '20260115-120000'], [
            'RATEGURU_RCLONE_BIN' => $scratch.'/bin/rclone',
            'RATEGURU_RCLONE_CONFIG' => $config,
            'RGTEST_RCLONE_LOG' => $scratch.'/rclone.log',
            'RGTEST_REMOTE_ROOT' => $scratch.'/remote',
        ]);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->not->toContain('SUPER-SECRET-ACCOUNT')
            ->not->toContain('SUPER-SECRET-KEY')
            ->not->toContain('from-backup-never-applied');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Workspace containment
// =============================================================================

it('creates a root-only workspace under the fixed restore run root and nowhere else', function () {
    $scratch = restoreScratchDir();

    try {
        buildBackupFixture($scratch.'/backups/parity', '20260115-120000');

        $result = fetchBackupRun($scratch, ['--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000']);
        expect($result['exit'])->toBe(0, $result['output']);

        $operations = operationIdsIn($result['output']);
        $workspace = fetchBackupWorkspace($scratch, $operations[0]);

        expect(is_dir($workspace))->toBeTrue();
        expect(substr(sprintf('%o', fileperms($workspace)), -4))->toBe('0700');
        expect(substr(sprintf('%o', fileperms($workspace.'/selected-backup')), -4))->toBe('0700');

        // Directly under <run root>/restores/<target>/<operation>: no other
        // directory was created anywhere in the scratch tree.
        expect($workspace)->toBe($scratch.'/run/restores/parity-target/'.$operations[0]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('rejects an operation ID that is not the closed generated format', function (string $operationId) {
    $scratch = restoreScratchDir();

    try {
        buildBackupFixture($scratch.'/backups/parity', '20260115-120000');

        $result = fetchBackupRun($scratch, [
            '--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000',
            '--operation', $operationId,
        ]);

        expect($result['exit'])->not->toBe(0, "expected {$operationId} to be rejected");
        expect($result['output'])->toContain('invalid restore operation ID');
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'path traversal' => '../../etc',
    'contains a slash' => '20260115-120000/abcdef',
    'contains whitespace' => '20260115-120000 abcdef',
    'shell metacharacters' => '20260115-120000-abcdef; id',
    'command substitution' => '$(id)',
    'uppercase hex' => '20260115-120000-ABCDEF',
    'too short' => '20260115-120000-abc',
]);

it('refuses to stage into a workspace that already holds a staged backup', function () {
    $scratch = restoreScratchDir();

    try {
        buildBackupFixture($scratch.'/backups/parity', '20260115-120000');

        $first = fetchBackupRun($scratch, ['--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000']);
        expect($first['exit'])->toBe(0, $first['output']);

        $operation = operationIdsIn($first['output'])[0];

        $second = fetchBackupRun($scratch, [
            '--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000',
            '--operation', $operation,
        ]);

        expect($second['exit'])->not->toBe(0);
        expect($second['output'])->toContain('a backup is already staged in this operation workspace');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Discard
// =============================================================================

it('discards a fetch-only workspace, and refuses to discard a restore-target operation', function () {
    $scratch = restoreScratchDir();

    try {
        buildBackupFixture($scratch.'/backups/parity', '20260115-120000');

        $fetch = fetchBackupRun($scratch, ['--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000']);
        expect($fetch['exit'])->toBe(0, $fetch['output']);

        $operation = operationIdsIn($fetch['output'])[0];
        $workspace = fetchBackupWorkspace($scratch, $operation);
        expect(is_dir($workspace))->toBeTrue();

        // A workspace carrying state.json belongs to restore-target and is
        // never removed by this flag.
        file_put_contents($workspace.'/state.json', '{"target":"parity-target"}');

        $refused = fetchBackupRun($scratch, ['--discard', '--target', 'parity-target', '--operation', $operation]);
        expect($refused['exit'])->not->toBe(0);
        expect($refused['output'])->toContain('refusing to discard a restore-target operation workspace');
        expect(is_dir($workspace))->toBeTrue();

        unlink($workspace.'/state.json');

        $discarded = fetchBackupRun($scratch, ['--discard', '--target', 'parity-target', '--operation', $operation]);
        expect($discarded['exit'])->toBe(0, $discarded['output']);
        expect(is_dir($workspace))->toBeFalse();

        // The backup itself is untouched.
        expect(is_dir($scratch.'/backups/parity/20260115-120000'))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Lifecycle
// =============================================================================

it('rejects a planned target before creating a workspace, reading a backup root or downloading anything', function () {
    $scratch = restoreScratchDir();

    try {
        fetchBackupRcloneStub($scratch);
        buildBackupFixture($scratch.'/backups/planned', '20260115-120000');

        $result = fetchBackupRun($scratch, ['--target', 'planned-target', '--source', 'offsite', '--backup', '20260115-120000'], [
            'RATEGURU_RCLONE_BIN' => $scratch.'/bin/rclone',
            'RATEGURU_RCLONE_CONFIG' => writeExecutable($scratch.'/rclone.conf', "[rateguru-b2]\n"),
            'RGTEST_RCLONE_LOG' => $scratch.'/rclone.log',
            'RGTEST_REMOTE_ROOT' => $scratch.'/remote',
        ]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('lifecycle=planned');
        expect(is_dir($scratch.'/run/restores'))->toBeFalse('no workspace may exist for a planned target');
        expect(file_exists($scratch.'/rclone.log'))->toBeFalse('a planned target must never reach rclone');
    } finally {
        removeScratchDir($scratch);
    }
});

it('requires root for a real invocation', function () {
    $source = File::get(fetchBackupScript());

    expect($source)->toContain("main() {\n    require_root\n    parse_fetch_args");
});
