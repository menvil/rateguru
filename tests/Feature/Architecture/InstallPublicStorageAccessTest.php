<?php

use Illuminate\Support\Facades\File;

/**
 * the target-aware migration (staging infrastructure defect fix): grants Nginx (www-data)
 * narrowly scoped POSIX ACL traversal into a target's shared storage, fixing
 * the real staging incident where uploaded images returned HTTP 403 because
 * TARGET_ROOT/shared and TARGET_ROOT/shared/storage (mode 2770, group
 * rateguru-<target>) blocked www-data from ever reaching the otherwise
 * correctly-permissioned public files beneath them.
 *
 * These tests run the real shipped install-public-storage-access — never a
 * reimplementation of its logic — against a synthetic registry target (whose
 * application_root is a scratch directory, since the real committed registry
 * requires application_root to live under /home/www/rateguru) and command
 * stubs for setfacl/getfacl/runuser/curl. No test touches the real host
 * filesystem, real ACLs, or the network.
 */
function psaScript(): string
{
    return base_path('infrastructure/scripts/install-public-storage-access');
}

function psaSource(): string
{
    return file_get_contents(psaScript());
}

/**
 * The whole constants+functions section: everything from `set -Eeuo
 * pipefail` up to (not including) the final `parse_args "$@"` dispatch line.
 * Inert when sourced, which is what lets a test reassign RATEGURU_* env
 * (picked up while sourcing common) and then call perform_apply()/
 * perform_verify() directly, bypassing require_root.
 */
function psaFunctionsSection(): string
{
    $source = psaSource();
    $start = strpos($source, "set -Eeuo pipefail\n");
    $end = strpos($source, "\nparse_args \"\$@\"");

    expect($start)->not->toBeFalse('could not locate the functions-section start');
    expect($end)->not->toBeFalse('could not locate the functions-section end');

    return substr($source, $start, $end - $start);
}

function psaScratchDir(): string
{
    $dir = sys_get_temp_dir().'/psa-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin', '/target', '/backups', '/conf'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function psaCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

function psaWriteExecutable(string $path, string $content): void
{
    file_put_contents($path, $content);
    chmod($path, 0o755);
}

/**
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function psaExec(string $scriptPath, array $env): array
{
    // fd 2 redirected onto fd 1 at the descriptor level: reading stdout then
    // stderr sequentially can deadlock if a child fills one pipe's OS buffer
    // before the other is drained.
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(['bash', $scriptPath], $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start harness process');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

/**
 * Run the real script as a subprocess with the gated RATEGURU_* override
 * contract (CLI-level tests: argument parsing, --help, require-root gate,
 * tits-guru rejection before any filesystem access).
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $envOverrides
 * @return array{0: int, 1: string}
 */
function psaRunScript(array $arguments, array $envOverrides = [], ?string $scratchBin = null): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'psa-cli-');
    file_put_contents($tmp, 'exec '.escapeshellarg(psaScript())
        .' '.implode(' ', array_map('escapeshellarg', $arguments))."\n");

    $path = ($scratchBin !== null ? $scratchBin.':' : '').(getenv('PATH') ?: '/usr/bin:/bin');

    $env = array_merge(['PATH' => $path, 'HOME' => getenv('HOME') ?: '/tmp'], $envOverrides);

    try {
        return psaExec($tmp, $env);
    } finally {
        @unlink($tmp);
    }
}

/**
 * Build and run a harness that sources the whole functions section, applies
 * bash variable overrides, then runs $body — for calling perform_apply()/
 * perform_verify() directly, bypassing require_root.
 *
 * @param  array<string, string>  $vars
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function psaRunHarness(string $scratch, array $vars, string $body, array $env = []): array
{
    file_put_contents($scratch.'/functions-section.sh', psaFunctionsSection());

    $script = 'source '.escapeshellarg($scratch.'/functions-section.sh')."\n";

    // SELF_PATH defaults to the real script (${BASH_SOURCE[0]} would
    // otherwise resolve to functions-section.sh, the sourced scratch file,
    // once this section is extracted) — overridable like any other $vars
    // entry, e.g. to test validate_self_executable's own failure path.
    //
    // BACKUP_ROOT is deliberately *not* accepted via any CLI flag or
    // environment variable in the real script (see the task's own
    // requirement) — reassigning the bash variable directly here is neither
    // of those; it is the same test-only technique
    // InstallTargetOperationsTest.php already established for its own
    // BACKUP_ROOT/DST_*/SRC_* constants.
    $vars = array_merge([
        'SELF_PATH' => psaScript(),
        'BACKUP_ROOT' => $scratch.'/backups',
    ], $vars);

    foreach ($vars as $name => $value) {
        $script .= $name.'='.escapeshellarg($value)."\n";
    }

    $script .= $body."\n";

    $harnessPath = $scratch.'/harness.sh';
    file_put_contents($harnessPath, $script);

    $defaultEnv = [
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
    ];

    return psaExec($harnessPath, array_merge($defaultEnv, $env));
}

/**
 * A minimal, always-succeeding `targets` CLI stub. install-public-storage-
 * access never calls `targets list`, only (indirectly, via
 * target_registry_assert_valid) `targets validate --file PATH` — the real
 * validator enforces application_root must live under /home/www/rateguru,
 * which a scratch test target cannot satisfy, so tests use this stub via
 * RATEGURU_TARGETS_CLI instead. The real validator itself is already
 * covered by DeploymentTargetRegistryTest.php.
 */
function psaFakeTargetsCli(): string
{
    return <<<'SH'
#!/usr/bin/env bash
case "${1:-}" in
    validate) exit 0 ;;
    *) printf 'fake targets stub: unsupported command: %s\n' "${1:-}" >&2; exit 1 ;;
esac
SH;
}

/**
 * A synthetic registry with one active target (application_root a scratch
 * directory) and tits-guru unchanged (lifecycle=planned), matching the real
 * committed registry's own rejection contract.
 */
function psaRegistry(string $targetRoot, string $runtimeUser, string $healthHostHeader = 'test-target.internal'): string
{
    return json_encode([
        'schema_version' => 1,
        'targets' => [
            'test-target' => [
                'id' => 'test-target',
                'lifecycle' => 'active',
                'environment_class' => 'staging',
                'application_root' => $targetRoot,
                'runtime_user' => $runtimeUser,
                'runtime_group' => $runtimeUser,
                'health' => ['url' => 'http://127.0.0.1/', 'host_header' => $healthHostHeader],
            ],
            'tits-guru' => [
                'id' => 'tits-guru',
                'lifecycle' => 'planned',
                'environment_class' => 'production',
                'application_root' => '/home/www/rateguru/production/tits-guru',
                'runtime_user' => 'rateguru-tits-guru',
            ],
        ],
    ]);
}

/**
 * The gated RATEGURU_* override env every harness needs to reach
 * test-target without touching the real installed common/registry/targets.
 *
 * @return array<string, string>
 */
function psaBaseEnv(string $scratch, array $overrides = []): array
{
    return array_merge([
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => base_path('infrastructure/scripts/common'),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => base_path('infrastructure/templates/deployment.conf.example'),
        'RATEGURU_TARGET_REGISTRY_FILE' => $scratch.'/registry.json',
        'RATEGURU_TARGETS_CLI' => $scratch.'/bin/targets',
    ], $overrides);
}

/**
 * Builds the scratch target's directory tree (shared/storage/app/public,
 * current/public/storage symlink) plus the ACL-simulation state files
 * ("<dir>.simstate", one "user:perms" line per simulated grantee) and the
 * command stubs that consult them:
 *
 *   - runuser -u www-data -- ...: for SHARED_ROOT/STORAGE_ROOT specifically,
 *     consults the matching .simstate file and denies whatever permission
 *     isn't recorded there — everywhere else (files, other directories,
 *     the runtime user, root) runs for real, since this sandbox has no real
 *     www-data user to switch to and the runtime user always owns these
 *     paths outright.
 *   - setfacl -m u:www-data:PERMS DIR: records PERMS into DIR.simstate.
 *   - setfacl --restore=FILE: parses a getfacl -p backup and restores each
 *     mentioned directory's .simstate to exactly what it recorded.
 *   - getfacl -p DIR: prints synthetic ACL text including any recorded
 *     www-data entry, in the same shape install-public-storage-access greps.
 *
 * @return array{root: string, shared: string, storage: string, app: string,
 *               public: string, link: string, runtimeUser: string}
 */
function psaBuildTarget(string $scratch, ?string $customHealthCheck = null): array
{
    $runtimeUser = trim((string) shell_exec('id -un'));

    $root = $scratch.'/target';
    $shared = $root.'/shared';
    $storage = $shared.'/storage';
    $app = $storage.'/app';
    $public = $app.'/public';
    $current = $root.'/current/public';
    $link = $current.'/storage';

    mkdir($public, 0o755, true);
    mkdir($current, 0o755, true);
    symlink($public, $link);
    chmod($shared, 0o770);
    chmod($storage, 0o770);
    chmod($app, 0o710);
    chmod($public, 0o750);

    file_put_contents($scratch.'/registry.json', psaRegistry($root, $runtimeUser));
    psaWriteExecutable($scratch.'/bin/targets', psaFakeTargetsCli());

    psaWriteExecutable($scratch.'/bin/setfacl', <<<'SH'
#!/usr/bin/env bash
set -uo pipefail
if [[ "${1:-}" == --restore=* ]]; then
    file="${1#--restore=}"
    declare -A seen=()
    current_dir=""
    while IFS= read -r line; do
        if [[ "${line}" == "# file: "* ]]; then
            current_dir="/${line#\# file: }"
            : > "${current_dir}.simstate"
            seen["${current_dir}"]=1
        elif [[ "${line}" == user:*:* ]] && [[ -n "${current_dir}" ]]; then
            rest="${line#user:}"
            name="${rest%%:*}"
            perms="${rest#*:}"
            if [[ -n "${name}" ]]; then
                printf '%s:%s\n' "${name}" "${perms}" >> "${current_dir}.simstate"
            fi
        fi
    done < "${file}"
    exit 0
fi

if [[ "${1:-}" == "-m" ]]; then
    entry="$2"
    dir="$3"
    rest="${entry#u:}"
    name="${rest%%:*}"
    perms="${rest#*:}"
    tmp="${dir}.simstate.tmp"
    grep -v "^${name}:" "${dir}.simstate" 2>/dev/null > "${tmp}" || true
    printf '%s:%s\n' "${name}" "${perms}" >> "${tmp}"
    mv "${tmp}" "${dir}.simstate"
    exit 0
fi

printf 'setfacl stub: unsupported args: %s\n' "$*" >&2
exit 1
SH);

    psaWriteExecutable($scratch.'/bin/getfacl', <<<'SH'
#!/usr/bin/env bash
set -uo pipefail
for arg in "$@"; do
    case "${arg}" in
        -p) continue ;;
        *)
            dir="${arg}"
            printf '# file: %s\n' "${dir#/}"
            printf '# owner: sim\n# group: sim\n'
            printf 'user::rwx\n'
            if [[ -f "${dir}.simstate" ]]; then
                while IFS=: read -r name perms; do
                    [[ -n "${name}" ]] || continue
                    printf 'user:%s:%s\n' "${name}" "${perms}"
                done < "${dir}.simstate"
            fi
            printf 'group::rwx\nmask::rwx\nother::---\n\n'
            ;;
    esac
done
SH);

    psaWriteExecutable($scratch.'/bin/namei', "#!/usr/bin/env bash\nprintf 'namei stub: %s\\n' \"\$*\"\nexit 0\n");

    psaWriteExecutable($scratch.'/bin/runuser', <<<'SH'
#!/usr/bin/env bash
set -uo pipefail
# runuser -u USER -- CMD ARGS...
user="$2"
shift 3

acl_grants() {
    local dir="$1" needed="$2"
    local state="${dir}.simstate"
    [[ -f "${state}" ]] || return 1
    local line
    line="$(grep '^www-data:' "${state}" 2>/dev/null || true)"
    [[ -n "${line}" ]] || return 1
    local perms="${line#www-data:}"
    [[ "${perms}" == *"${needed}"* ]]
}

# For anything *not* one of the two ACL-simulated directories (a plain file
# or an ordinary subdirectory), this sandbox has no real www-data
# user/group to switch to — the test-runner process owns everything it
# creates, so a real `test -r`/`ls` as "the same user" would trivially
# succeed regardless of what www-data could actually do. Falling back to the
# real "other" permission bits instead is the accurate simulation here: every
# fixture in this suite deliberately is *not* group-owned by www-data
# (matching the real bug — secrets are group rateguru-<target>, not
# www-data), so whether www-data could reach it is exactly whether "other"
# grants the bit.
other_bit_set() {
    local path="$1" bit="$2"
    local mode
    mode="$(stat -c '%a' "${path}" 2>/dev/null || printf '000')"
    (( (8#${mode} & bit) != 0 ))
}

if [[ "${user}" == "www-data" ]]; then
    cmd="${1:-}"
    if [[ "${cmd}" == "test" ]]; then
        flag="$2"
        path="$3"
        if [[ -f "${path}.simstate" ]]; then
            case "${flag}" in
                -x) acl_grants "${path}" x; exit $? ;;
                -r) acl_grants "${path}" r; exit $? ;;
                -w) acl_grants "${path}" w; exit $? ;;
            esac
        else
            case "${flag}" in
                -r) other_bit_set "${path}" 4; exit $? ;;
                -w) other_bit_set "${path}" 2; exit $? ;;
                -x) other_bit_set "${path}" 1; exit $? ;;
            esac
        fi
    elif [[ "${cmd}" == "ls" ]]; then
        path="$2"
        if [[ -f "${path}.simstate" ]]; then
            if acl_grants "${path}" r && acl_grants "${path}" x; then
                :
            else
                exit 2
            fi
        else
            if other_bit_set "${path}" 4 && other_bit_set "${path}" 1; then
                :
            else
                exit 2
            fi
        fi
    fi
fi

exec "$@"
SH);

    $curl = $customHealthCheck ?? psaFakeCurlServingPublicStorage($public);
    psaWriteExecutable($scratch.'/bin/curl', $curl);

    return [
        'root' => $root,
        'shared' => $shared,
        'storage' => $storage,
        'app' => $app,
        'public' => $public,
        'link' => $link,
        'runtimeUser' => $runtimeUser,
    ];
}

/**
 * A fake curl that serves files directly out of $publicRoot for any
 * /storage/<path> request, mirroring what Nginx would actually do once
 * traversal works — reads the file for real (this stub always "can", since
 * there is no real www-data/Nginx process here; the *filesystem* traversal
 * enforcement is what runuser's stub simulates, which is what the script's
 * own runuser-based checks exercise before ever reaching curl).
 */
function psaFakeCurlServingPublicStorage(string $publicRoot): string
{
    $publicRootLiteral = escapeshellarg($publicRoot);

    return <<<SH
#!/usr/bin/env bash
set -uo pipefail
output=""
url=""
args=("\$@")
for ((i = 0; i < \${#args[@]}; i++)); do
    case "\${args[i]}" in
        --output) output="\${args[i+1]}" ;;
        http://*|https://*) url="\${args[i]}" ;;
    esac
done

path="\${url#*://*/}"

if [[ "\${path}" == "up" ]]; then
    # The health-check endpoint: always healthy in this stub.
    if [[ -n "\${output}" ]]; then
        printf 'OK' > "\${output}"
    fi
    printf '200'
    exit 0
fi

# The real /storage/<relative> URL is served through the "public/storage"
# symlink, which resolves to \$publicRoot itself — so the "storage/" URL
# segment must be stripped before joining with \$publicRoot, not kept.
relative="\${path#storage/}"
file={$publicRootLiteral}"/\${relative}"

if [[ -f "\${file}" ]]; then
    if [[ -n "\${output}" ]]; then
        cp "\${file}" "\${output}"
    fi
    printf '200'
else
    if [[ -n "\${output}" ]]; then
        printf 'Not Found' > "\${output}"
    fi
    printf '404'
fi
SH;
}

it('--check succeeds against a healthy scratch target and performs no writes (as root would)', function () {
    $scratch = psaScratchDir();

    try {
        $target = psaBuildTarget($scratch);

        $before = [
            'shared' => fileperms($target['shared']),
            'storage' => fileperms($target['storage']),
        ];

        // perform_check is exactly what run_check calls once require_root has
        // passed — calling it directly here exercises the real no-root-
        // required-to-be-*correct* logic without needing an actual root
        // process; --check's own require_root gate is covered separately.
        [$exit, $output] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_check', psaBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('structural validation passed')
            ->toContain('check passed');

        clearstatcache(true, $target['shared']);
        clearstatcache(true, $target['storage']);
        expect(fileperms($target['shared']))->toBe($before['shared']);
        expect(fileperms($target['storage']))->toBe($before['storage']);
        expect(file_exists($target['shared'].'.simstate'))->toBeFalse('--check must not create any ACL state');
    } finally {
        psaCleanup($scratch);
    }
});

// =============================================================================
// CLI-level: require-root gate, tits-guru rejection before any access
// =============================================================================

it('--check requires root', function () {
    if (getmyuid() === 0) {
        test()->markTestSkipped('this test process is running as root — the require-root gate cannot be exercised');
    }

    $scratch = psaScratchDir();

    try {
        psaBuildTarget($scratch);
        [$exit, $output] = psaRunScript(['--check', '--target', 'test-target'], psaBaseEnv($scratch), $scratch.'/bin');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('this command must be executed as root');
    } finally {
        psaCleanup($scratch);
    }
});

it('--apply requires root', function () {
    if (getmyuid() === 0) {
        test()->markTestSkipped('this test process is running as root — the require-root gate cannot be exercised');
    }

    $scratch = psaScratchDir();

    try {
        psaBuildTarget($scratch);
        [$exit, $output] = psaRunScript(['--apply', '--target', 'test-target'], psaBaseEnv($scratch), $scratch.'/bin');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('this command must be executed as root');
    } finally {
        psaCleanup($scratch);
    }
});

it('--verify requires root', function () {
    if (getmyuid() === 0) {
        test()->markTestSkipped('this test process is running as root — the require-root gate cannot be exercised');
    }

    $scratch = psaScratchDir();

    try {
        psaBuildTarget($scratch);
        [$exit, $output] = psaRunScript(['--verify', '--target', 'test-target'], psaBaseEnv($scratch), $scratch.'/bin');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('this command must be executed as root');
    } finally {
        psaCleanup($scratch);
    }
});

it('rejects a planned target before any filesystem or ACL access, calling perform_apply/perform_verify directly', function () {
    $scratch = psaScratchDir();

    try {
        $target = psaBuildTarget($scratch);

        foreach (['perform_apply', 'perform_verify'] as $entryPoint) {
            // TARGET_ID is a plain shell variable (reset to "" when the
            // functions section is sourced, normally set by parse_args) —
            // overridden here via $vars, not the environment, so it survives
            // that reset the same way DST_*/SRC_* overrides do in
            // InstallTargetOperationsTest.php.
            [$exit, $output] = psaRunHarness(
                $scratch,
                ['TARGET_ID' => 'tits-guru'],
                $entryPoint,
                psaBaseEnv($scratch),
            );

            expect($exit)->not->toBe(0, "{$entryPoint}: {$output}");
            expect($output)->toContain('lifecycle=planned');
        }

        expect(file_exists($target['shared'].'.simstate'))->toBeFalse('tits-guru must never reach ACL code');
    } finally {
        psaCleanup($scratch);
    }
});

it('produces a clear failure when required ACL tools are missing', function () {
    $scratch = psaScratchDir();

    try {
        psaBuildTarget($scratch);
        unlink($scratch.'/bin/setfacl');

        // Isolate PATH to exactly this scratch bin: on a host that genuinely
        // has the acl package installed, an ambient-PATH fallback would
        // still find a real setfacl elsewhere and this test would pass for
        // the wrong reason. bash and jq are the only real tools reached
        // before check_required_tools hits the deleted setfacl entry (it is
        // first in REQUIRED_TOOLS), so those two are the only ones symlinked
        // in from the host.
        foreach (['bash', 'jq'] as $realTool) {
            $resolved = trim((string) shell_exec('command -v '.escapeshellarg($realTool)));
            expect($resolved)->not->toBe('', "host is missing {$realTool}, needed to isolate this test's PATH");
            symlink($resolved, $scratch.'/bin/'.$realTool);
        }

        // perform_check bypasses require_root (see the dedicated "--check
        // requires root" test above); PATH is passed via $env, which wins
        // over psaRunHarness's own ambient-PATH-including default, isolating
        // it to exactly this scratch bin for the same reason as before.
        [$exit, $output] = psaRunHarness(
            $scratch,
            ['TARGET_ID' => 'test-target'],
            'perform_check',
            array_merge(psaBaseEnv($scratch), ['PATH' => $scratch.'/bin']),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('required tool not found: setfacl')
            ->toContain("install the 'acl'");
    } finally {
        psaCleanup($scratch);
    }
});

// =============================================================================
// Structural validation: symlinks rejected, PUBLIC_LINK must resolve exactly
// =============================================================================

it('rejects a symlinked shared directory', function () {
    $scratch = psaScratchDir();

    try {
        $target = psaBuildTarget($scratch);
        $real = $scratch.'/real-shared-elsewhere';
        rename($target['shared'], $real);
        symlink($real, $target['shared']);

        [$exit, $output] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_check', psaBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('must not be a symlink');
    } finally {
        psaCleanup($scratch);
    }
});

it('rejects a symlinked storage directory', function () {
    $scratch = psaScratchDir();

    try {
        $target = psaBuildTarget($scratch);
        $real = $scratch.'/real-storage-elsewhere';
        rename($target['storage'], $real);
        symlink($real, $target['storage']);

        [$exit, $output] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_check', psaBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('must not be a symlink');
    } finally {
        psaCleanup($scratch);
    }
});

it('rejects a public storage link that does not resolve exactly to public storage', function () {
    $scratch = psaScratchDir();

    try {
        $target = psaBuildTarget($scratch);
        unlink($target['link']);
        $decoy = $scratch.'/decoy-public';
        mkdir($decoy, 0o755);
        symlink($decoy, $target['link']);

        [$exit, $output] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_check', psaBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('does not resolve to public storage');
    } finally {
        psaCleanup($scratch);
    }
});

// =============================================================================
// Static architecture guards: the fix must never widen scope beyond the two
// exact ACL entries, and must never touch anything out of scope.
// =============================================================================

it('never chmods, chowns or chgrps a directory — the sole chmod call stays scoped to the canary file', function () {
    $source = psaSource();

    // Exactly one chmod call exists in the whole script: restoring the
    // canary's mode to the same world-readable 0644 a real Laravel upload
    // gets, since mktemp always creates at 0600 regardless of umask. The
    // canary is a dynamically-named regular *file* under PUBLIC_STORAGE,
    // never one of the two ACL-controlled directories or anything else —
    // anything beyond this one exact line, or any chown/chgrp at all, is
    // out of scope for this fix.
    $allowedChmodLine = 'chmod 644 "${path}"';
    $chmodLinesSeen = 0;

    foreach (preg_split('/\R/', $source) as $line) {
        $trimmed = ltrim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }

        expect($trimmed)
            ->not->toMatch('/(^|[;&|]\s*)chown\b/')
            ->not->toMatch('/(^|[;&|]\s*)chgrp\b/');

        if (preg_match('/(^|[;&|]\s*)chmod\b/', $trimmed) === 1) {
            expect($trimmed)->toBe($allowedChmodLine, "unexpected chmod call: {$trimmed}");
            $chmodLinesSeen++;
        }
    }

    expect($chmodLinesSeen)->toBe(1, 'exactly one chmod call is allowed — the canary file');
});

it('never modifies user or group membership', function () {
    $source = psaSource();

    expect($source)
        ->not->toContain('usermod')
        ->not->toContain('gpasswd')
        ->not->toContain('adduser')
        ->not->toContain('groupadd')
        ->not->toMatch('/\badduser\b.*\bgroup\b/');
});

it('never references or modifies Nginx configuration', function () {
    // The header comment legitimately explains *why* this fix helps Nginx
    // reach public storage — what must never appear is a command touching
    // Nginx's configuration, service state, or config test/reload.
    $source = psaSource();

    expect($source)
        ->not->toContain('/etc/nginx')
        ->not->toContain('sites-available')
        ->not->toContain('sites-enabled')
        ->not->toMatch('/\bnginx\s+-[st]\b/')
        ->not->toMatch('/systemctl\s+\S*\s*nginx/')
        ->not->toMatch('/service\s+nginx/');
});

it('applies exactly setfacl -m u:www-data:--x, never a recursive setfacl', function () {
    $source = psaSource();

    expect($source)->toContain('setfacl -m "u:${WEB_USER}:--x" "${SHARED_ROOT}"')
        ->toContain('setfacl -m "u:${WEB_USER}:--x" "${STORAGE_ROOT}"')
        ->not->toMatch('/setfacl\s+(-\S+\s+)*-R\b/')
        ->not->toMatch('/getfacl\s+(-\S+\s+)*-R\b/');
});

it('never creates the canary via the unsafe mktemp -u name-then-write pattern', function () {
    $source = psaSource();

    expect($source)->not->toContain('mktemp -u');

    // The safe replacement: a real (non -u) mktemp call templated *inside*
    // PUBLIC_STORAGE — proving the correct API is used, not just that the
    // dangerous one is absent.
    expect($source)->toMatch('/mktemp\s+"\$1\/canary-X+"/');
});

it('a pre-existing symlink at the canary path is never selected or written through', function () {
    $scratch = psaScratchDir();

    try {
        $target = psaBuildTarget($scratch);

        // Plant a symlink at a fixed name pointing at a sentinel file
        // *outside* public storage, then fake `mktemp` to deterministically
        // try exactly that name (real mktemp's suffix is random — this
        // stub trades that for determinism, using `set -C`/noclobber to
        // reproduce mktemp's core O_EXCL guarantee: refuse, never follow, an
        // existing path — verified separately to behave exactly this way
        // against a real symlink). If the old mktemp -u name-then-write
        // pattern were still in place, this sentinel would end up
        // overwritten with the canary token; the fix must instead fail
        // cleanly, leaving the symlink and its target completely untouched.
        $sentinelTarget = $scratch.'/attacker-sentinel.txt';
        file_put_contents($sentinelTarget, "UNCHANGED\n");
        symlink($sentinelTarget, $target['public'].'/canary-COLLISION');

        $realMktemp = trim((string) shell_exec('command -v mktemp'));
        expect($realMktemp)->not->toBe('', 'host is missing mktemp');

        psaWriteExecutable($scratch.'/bin/mktemp', <<<SH
#!/usr/bin/env bash
set -uo pipefail

if [[ \$# -eq 0 ]]; then
    exec {$realMktemp}
fi

template="\$1"
dir="\${template%/*}"
candidate="\${dir}/canary-COLLISION"

if { set -C; : > "\${candidate}"; } 2>/dev/null; then
    printf '%s\n' "\${candidate}"
    exit 0
else
    echo "mktemp: failed to create file via template '\${template}': File exists" >&2
    exit 1
fi
SH);

        [$exit, $output] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_apply', psaBaseEnv($scratch));

        expect($exit)->not->toBe(0, $output);
        expect($output)->toContain('could not create the public-storage canary');

        // The symlink itself is untouched: still a symlink, still pointing
        // at the same sentinel, whose content was never written to.
        expect(is_link($target['public'].'/canary-COLLISION'))->toBeTrue('the pre-existing symlink must not be replaced');
        expect(readlink($target['public'].'/canary-COLLISION'))->toBe($sentinelTarget);
        expect(file_get_contents($sentinelTarget))->toBe("UNCHANGED\n");
    } finally {
        psaCleanup($scratch);
    }
});

it('creates the canary as the target runtime user', function () {
    $scratch = psaScratchDir();

    try {
        $target = psaBuildTarget($scratch);

        // The canary is removed (cleanup_canary) the moment this same HTTP
        // request completes inside the real script, so the internal-HTTP
        // request the script itself makes is the only window in which the
        // file can still be inspected — recording owner+mode there, as a
        // side channel, rather than trying to catch it from outside the
        // running process.
        $ownerLog = $scratch.'/canary-owner.txt';
        $publicRootLiteral = escapeshellarg($target['public']);
        $ownerLogLiteral = escapeshellarg($ownerLog);

        psaWriteExecutable($scratch.'/bin/curl', <<<SH
#!/usr/bin/env bash
set -uo pipefail
output=""
url=""
args=("\$@")
for ((i = 0; i < \${#args[@]}; i++)); do
    case "\${args[i]}" in
        --output) output="\${args[i+1]}" ;;
        http://*|https://*) url="\${args[i]}" ;;
    esac
done

path="\${url#*://*/}"

if [[ "\${path}" == "up" ]]; then
    if [[ -n "\${output}" ]]; then
        printf 'OK' > "\${output}"
    fi
    printf '200'
    exit 0
fi

relative="\${path#storage/}"
file={$publicRootLiteral}"/\${relative}"

if [[ -f "\${file}" ]]; then
    stat -c '%U:%a' "\${file}" > {$ownerLogLiteral} 2>/dev/null || true
    if [[ -n "\${output}" ]]; then
        cp "\${file}" "\${output}"
    fi
    printf '200'
else
    if [[ -n "\${output}" ]]; then
        printf 'Not Found' > "\${output}"
    fi
    printf '404'
fi
SH);

        [$exit, $output] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_apply', psaBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect(file_exists($ownerLog))->toBeTrue('the curl stub never observed the canary being served');

        [$owner, $mode] = explode(':', trim(file_get_contents($ownerLog)));
        expect($owner)->toBe($target['runtimeUser']);
        expect($mode)->toBe('644');
    } finally {
        psaCleanup($scratch);
    }
});

// =============================================================================
// Full perform_apply / perform_verify integration
// =============================================================================

it('a successful apply grants exactly user:www-data:--x, changes no ownership/mode, and passes every verification', function () {
    $scratch = psaScratchDir();

    try {
        $target = psaBuildTarget($scratch);

        $before = [
            'shared' => psaStatSummary($target['shared']),
            'storage' => psaStatSummary($target['storage']),
        ];

        [$exit, $output] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_apply', psaBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('ACL applied')
            ->toContain('www-data execute-only traversal: OK')
            ->toContain('www-data directory listing: correctly denied')
            ->toContain('ACL precision: OK')
            ->toContain('direct www-data read of canary: OK')
            ->toContain('internal HTTP public-media test: OK')
            ->toContain('tits-guru: still correctly rejected')
            ->toContain('apply complete');

        // Exactly one ACL entry, exactly u:www-data:--x, on each directory.
        expect(file_get_contents($target['shared'].'.simstate'))->toBe("www-data:--x\n");
        expect(file_get_contents($target['storage'].'.simstate'))->toBe("www-data:--x\n");

        // Ownership, mode, setgid — everything except the new ACL entry —
        // stayed exactly as it was.
        clearstatcache(true, $target['shared']);
        clearstatcache(true, $target['storage']);
        expect(psaStatSummary($target['shared']))->toBe($before['shared']);
        expect(psaStatSummary($target['storage']))->toBe($before['storage']);

        // The canary is gone after a successful run.
        $leftoverCanaries = glob($target['public'].'/canary-*');
        expect($leftoverCanaries)->toBeEmpty('canary file must be removed after a successful apply');

        // A backup directory was created and kept.
        $backups = glob($scratch.'/backups/*', GLOB_ONLYDIR);
        expect($backups)->not->toBeEmpty('apply must create a backup directory');
        expect(file_exists($backups[0].'/acl.restore'))->toBeTrue();
    } finally {
        psaCleanup($scratch);
    }
});

it('cleanup removes only the canary, never a pre-existing regular upload', function () {
    $scratch = psaScratchDir();

    try {
        $target = psaBuildTarget($scratch);

        $existingUpload = $target['public'].'/existing-photo.jpg';
        file_put_contents($existingUpload, "REAL UPLOAD CONTENT\n");

        [$exit, $output] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_apply', psaBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('existing upload regression check: OK');

        // cleanup_canary only ever does `rm -f "${CANARY_PATH}"` — a single,
        // non-recursive, non-glob removal of exactly the one path this run
        // itself created — so a pre-existing, unrelated file must survive
        // completely untouched.
        expect(file_exists($existingUpload))->toBeTrue('cleanup must never remove a pre-existing upload');
        expect(file_get_contents($existingUpload))->toBe("REAL UPLOAD CONTENT\n");

        $leftoverCanaries = glob($target['public'].'/canary-*');
        expect($leftoverCanaries)->toBeEmpty('the canary itself must still be removed');
    } finally {
        psaCleanup($scratch);
    }
});

it('apply is idempotent: running it again succeeds and the ACL state is unchanged', function () {
    $scratch = psaScratchDir();

    try {
        $target = psaBuildTarget($scratch);

        [$exit1, $out1] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_apply', psaBaseEnv($scratch));
        expect($exit1)->toBe(0, $out1);

        [$exit2, $out2] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_apply', psaBaseEnv($scratch));
        expect($exit2)->toBe(0, $out2);

        expect(file_get_contents($target['shared'].'.simstate'))->toBe("www-data:--x\n");
        expect(file_get_contents($target['storage'].'.simstate'))->toBe("www-data:--x\n");

        $backups = glob($scratch.'/backups/*', GLOB_ONLYDIR);
        expect(count($backups))->toBeGreaterThanOrEqual(2, 'each apply run should record its own backup');
    } finally {
        psaCleanup($scratch);
    }
});

it('verify passes against a successfully applied target and makes no changes', function () {
    $scratch = psaScratchDir();

    try {
        $target = psaBuildTarget($scratch);

        [$applyExit, $applyOut] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_apply', psaBaseEnv($scratch));
        expect($applyExit)->toBe(0, $applyOut);

        $before = [
            'shared' => file_get_contents($target['shared'].'.simstate'),
            'storage' => file_get_contents($target['storage'].'.simstate'),
        ];
        $backupsBefore = glob($scratch.'/backups/*', GLOB_ONLYDIR);

        [$exit, $output] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_verify', psaBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('--- target validation ---')
            ->toContain('--- filesystem structure ---')
            ->toContain('--- ACL traversal ---')
            ->toContain('--- private-data isolation ---')
            ->toContain('--- direct www-data read ---')
            ->toContain('--- internal HTTP public-media test ---')
            ->toContain('--- final result ---')
            ->toContain('PASS: public storage access verified');

        expect(file_get_contents($target['shared'].'.simstate'))->toBe($before['shared']);
        expect(file_get_contents($target['storage'].'.simstate'))->toBe($before['storage']);

        $backupsAfter = glob($scratch.'/backups/*', GLOB_ONLYDIR);
        expect($backupsAfter)->toBe($backupsBefore, '--verify must never create a backup');

        $leftoverCanaries = glob($target['public'].'/canary-*');
        expect($leftoverCanaries)->toBeEmpty('canary file must be removed after a successful verify');
    } finally {
        psaCleanup($scratch);
    }
});

it('www-data cannot read shared/.env, logs, framework or app/private even after a successful apply', function () {
    $scratch = psaScratchDir();

    try {
        $target = psaBuildTarget($scratch);
        file_put_contents($target['shared'].'/.env', "APP_KEY=secret\n");
        chmod($target['shared'].'/.env', 0o640);

        // Real deploys give these directories group=runtime_user (2770),
        // never www-data — "other" bits withheld is what actually protects
        // them, matching the fake runuser stub's other_bit_set fallback.
        foreach (['logs', 'framework', 'app/private'] as $secretDir) {
            $path = $target['storage'].'/'.$secretDir;
            mkdir($path, 0o750, true);
            file_put_contents($path.'/secret.log', "sensitive\n");
            chmod($path, 0o750);
        }

        [$exit, $output] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_apply', psaBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('www-data cannot read '.$target['shared'].'/.env: OK');
        expect($output)->toContain('www-data cannot read '.$target['storage'].'/logs: OK');
        expect($output)->toContain('www-data cannot read '.$target['storage'].'/framework: OK');
        expect($output)->toContain('www-data cannot read '.$target['storage'].'/app/private: OK');
    } finally {
        psaCleanup($scratch);
    }
});

it('a genuine post-change verification failure rolls back to the original ACL-absent state exactly once and preserves the exit code', function () {
    $scratch = psaScratchDir();

    try {
        $target = psaBuildTarget($scratch);

        // /up (the pre-apply health check) still succeeds; every /storage/...
        // request 404s — the internal HTTP canary check fails genuinely,
        // *after* the ACL has already been applied, not before.
        psaWriteExecutable($scratch.'/bin/curl', <<<'SH'
#!/usr/bin/env bash
set -uo pipefail
for arg in "$@"; do
    case "${arg}" in
        http://*|https://*) url="${arg}" ;;
    esac
done
if [[ "${url#*://*/}" == "up" ]]; then
    printf '200'
else
    printf '404'
fi
SH);

        $logFile = $scratch.'/log-invocations.txt';
        $driver = "log() {\n"
            ."    printf '[%s] %s\\n' \"\$(date -u '+%Y-%m-%dT%H:%M:%SZ')\" \"\$*\"\n"
            .'    printf \'%s\n\' "$*" >> '.escapeshellarg($logFile)."\n"
            ."}\n"
            .'perform_apply';

        [$exit, $output] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], $driver, psaBaseEnv($scratch));

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('apply failed');

        // The ACL was genuinely applied, then rolled back to *exactly* the
        // original state — which, since this target had never been applied
        // before, means no www-data entry remains on either directory.
        $sharedAcl = file_exists($target['shared'].'.simstate') ? file_get_contents($target['shared'].'.simstate') : '';
        $storageAcl = file_exists($target['storage'].'.simstate') ? file_get_contents($target['storage'].'.simstate') : '';
        expect($sharedAcl)->not->toContain('www-data');
        expect($storageAcl)->not->toContain('www-data');

        // The canary was cleaned up even though the run failed.
        $leftoverCanaries = glob($target['public'].'/canary-*');
        expect($leftoverCanaries)->toBeEmpty('canary file must be removed even when apply fails');

        // The real, file-based invocation count (immune to the same
        // subshell-stdout-swallowing InstallTargetOperationsTest.php
        // discovered) proves the handler's real body ran exactly once.
        $logInvocations = file_exists($logFile) ? file_get_contents($logFile) : '';
        expect(substr_count($logInvocations, 'apply failed'))->toBe(1, 'on_apply_error\'s real body must run exactly once');
        expect(substr_count($logInvocations, 'rollback complete: exact original ACL state restored'))->toBe(1);
    } finally {
        psaCleanup($scratch);
    }
});

it('rollback after an idempotent re-apply restores the pre-existing permissive ACL, not an assumed-blocked baseline', function () {
    $scratch = psaScratchDir();

    try {
        $target = psaBuildTarget($scratch);

        // Establish the ACL for real first — this run must succeed cleanly.
        [$firstExit, $firstOut] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_apply', psaBaseEnv($scratch));
        expect($firstExit)->toBe(0, $firstOut);
        expect(file_get_contents($target['shared'].'.simstate'))->toBe("www-data:--x\n");
        expect(file_get_contents($target['storage'].'.simstate'))->toBe("www-data:--x\n");

        // Now force the *second*, idempotent apply's post-change
        // verification to fail genuinely, exactly like the ACL-absent
        // rollback test above, but starting from a target whose backed-up
        // "original" state already grants www-data:--x.
        psaWriteExecutable($scratch.'/bin/curl', <<<'SH'
#!/usr/bin/env bash
set -uo pipefail
for arg in "$@"; do
    case "${arg}" in
        http://*|https://*) url="${arg}" ;;
    esac
done
if [[ "${url#*://*/}" == "up" ]]; then
    printf '200'
else
    printf '404'
fi
SH);

        $logFile = $scratch.'/log-invocations.txt';
        $driver = "log() {\n"
            ."    printf '[%s] %s\\n' \"\$(date -u '+%Y-%m-%dT%H:%M:%SZ')\" \"\$*\"\n"
            .'    printf \'%s\n\' "$*" >> '.escapeshellarg($logFile)."\n"
            ."}\n"
            .'perform_apply';

        [$exit, $output] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], $driver, psaBaseEnv($scratch));

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('apply failed');

        // Rollback must restore the *pre-existing permissive* ACL — the one
        // this run's own backup actually captured — not remove it. Reporting
        // "rollback complete" here while www-data can still traverse would
        // have been exactly the old bug: assuming the original state always
        // blocked access.
        expect(file_get_contents($target['shared'].'.simstate'))->toBe("www-data:--x\n");
        expect(file_get_contents($target['storage'].'.simstate'))->toBe("www-data:--x\n");

        $logInvocations = file_exists($logFile) ? file_get_contents($logFile) : '';
        expect(substr_count($logInvocations, 'rollback complete: exact original ACL state restored'))->toBe(1);
        expect($logInvocations)->not->toContain('ACL rollback verification failed');
    } finally {
        psaCleanup($scratch);
    }
});

it('verify performs no changes and reports FAIL exactly once when verification genuinely fails', function () {
    $scratch = psaScratchDir();

    try {
        $target = psaBuildTarget($scratch);

        [$applyExit, $applyOut] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_apply', psaBaseEnv($scratch));
        expect($applyExit)->toBe(0, $applyOut);

        // Break reachability *after* a successful apply, purely for --verify.
        psaWriteExecutable($scratch.'/bin/curl', "#!/usr/bin/env bash\nprintf '404'\nexit 0\n");

        $before = [
            'shared' => file_get_contents($target['shared'].'.simstate'),
            'storage' => file_get_contents($target['storage'].'.simstate'),
        ];

        $logFile = $scratch.'/log-invocations.txt';
        $driver = "log() {\n"
            ."    printf '[%s] %s\\n' \"\$(date -u '+%Y-%m-%dT%H:%M:%SZ')\" \"\$*\"\n"
            .'    printf \'%s\n\' "$*" >> '.escapeshellarg($logFile)."\n"
            ."}\n"
            .'perform_verify';

        [$exit, $output] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], $driver, psaBaseEnv($scratch));

        expect($exit)->not->toBe(0);

        // verify must never touch the ACL state, even when it fails.
        expect(file_get_contents($target['shared'].'.simstate'))->toBe($before['shared']);
        expect(file_get_contents($target['storage'].'.simstate'))->toBe($before['storage']);

        $logInvocations = file_exists($logFile) ? file_get_contents($logFile) : '';
        expect(substr_count($logInvocations, '--- final result ---'))->toBe(1);
        expect(substr_count($logInvocations, 'FAIL: verification did not pass'))->toBe(1);

        // The canary is cleaned up even when --verify itself is what fails,
        // not just when --apply fails (covered separately).
        $leftoverCanaries = glob($target['public'].'/canary-*');
        expect($leftoverCanaries)->toBeEmpty('canary file must be removed even when verify fails');
    } finally {
        psaCleanup($scratch);
    }
});

it('never creates, contacts or provisions anything for tits-guru during a successful apply', function () {
    $scratch = psaScratchDir();

    try {
        $target = psaBuildTarget($scratch);

        [$exit, $output] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_apply', psaBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('tits-guru: still correctly rejected');

        $scratchFiles = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $scratchFiles[] = $file->getPathname();
        }

        foreach ($scratchFiles as $path) {
            if ($path === $scratch.'/registry.json') {
                continue; // the registry legitimately mentions tits-guru as planned
            }
            expect($path)->not->toContain('tits-guru', "no tits-guru path should exist under the scratch target tree: {$path}");
        }
    } finally {
        psaCleanup($scratch);
    }
});

it('documents the real failure mode, the ACL rationale and the acl package prerequisite in the runbook', function () {
    $runbook = File::get(base_path('infrastructure/runbooks/public-storage-access.md'));

    expect($runbook)
        ->toContain('stat() failed (13: Permission')
        ->toContain('execute-only traversal')
        ->toContain('Why an ACL, not `chmod` or group membership')
        ->toContain('user:www-data:--x')
        ->toContain('sudo apt-get install acl')
        ->toContain('Security invariants');
});

it('documents backup location, rollback behaviour and manual restore in the public-storage-access runbook', function () {
    $runbook = File::get(base_path('infrastructure/runbooks/public-storage-access.md'));

    expect($runbook)
        ->toContain('/var/backups/rateguru-public-storage-access/')
        ->toContain('setfacl --restore=')
        ->toContain('Manually restoring a backup, if automatic rollback itself fails');
});

it('documents that all three modes require root, atomic canary creation and exact-match rollback in the runbook', function () {
    $runbook = File::get(base_path('infrastructure/runbooks/public-storage-access.md'));

    expect($runbook)
        ->toContain('All three modes require root')
        ->toContain('requires root, no changes')
        ->toContain('never the unsafe "pick a name, then write to it"')
        ->toContain('confirmed byte-for-byte identical to the pre-apply backup');
});

function psaStatSummary(string $path): array
{
    clearstatcache(true, $path);
    $stat = stat($path);

    return [$stat['uid'], $stat['gid'], $stat['mode'] & 0o7777];
}

// =============================================================================
// the service bootstrap: PRE_DEPLOY vs DEPLOYED split. The ACL itself must be grantable
// and verifiable before any application release exists (install-bootstrap-host-layout already
// creates shared and shared/storage on a clean host); the application/HTTP
// proofs (storage/app/public, the current/public/storage link, the canary,
// the existing-upload regression) are deferred until a release is deployed —
// never faked, never weakened on a deployed host.
// =============================================================================

/**
 * Reduces a full scratch target to the genuine pre-deploy shape install-bootstrap-host-layout
 * leaves behind: shared and shared/storage exist; storage/app, its public
 * descendant and the whole current tree do not.
 *
 * @param  array{root: string, app: string}  $target
 */
function psaMakePreDeploy(array $target): void
{
    exec('rm -rf '.escapeshellarg($target['app']).' '.escapeshellarg($target['root'].'/current'));
}

it('grants and verifies the ACL on a PRE_DEPLOY target, deferring every application/HTTP-level proof', function () {
    $scratch = psaScratchDir();

    try {
        $target = psaBuildTarget($scratch);
        psaMakePreDeploy($target);

        [$exit, $output] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_apply', psaBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('target state: PRE_DEPLOY')
            ->toContain('structural validation (pre-deploy)')
            ->toContain('target test-target health check: DEFERRED (pre-deploy: no current release exists yet)')
            ->toContain('ACL applied')
            ->toContain('www-data execute-only traversal: OK')
            ->toContain('www-data directory listing: correctly denied')
            ->toContain('ACL precision: OK')
            ->toContain('public-storage canary and existing-upload regression: DEFERRED')
            ->toContain('tits-guru: still correctly rejected')
            ->toContain('apply complete');

        // Deferred means deferred — the HTTP proofs are never reported as
        // having passed, and no canary was ever created anywhere.
        expect($output)->not->toContain('internal HTTP public-media test: OK');
        expect($output)->not->toContain('direct www-data read of canary: OK');

        // The exact narrow ACL landed on both directories.
        expect(file_get_contents($target['shared'].'.simstate'))->toBe("www-data:--x\n");
        expect(file_get_contents($target['storage'].'.simstate'))->toBe("www-data:--x\n");

        // Nothing application-shaped was fabricated to satisfy the checks.
        expect(file_exists($target['root'].'/current'))->toBeFalse('pre-deploy apply must never fabricate current');
        expect(file_exists($target['app']))->toBeFalse('pre-deploy apply must never fabricate storage/app');
    } finally {
        psaCleanup($scratch);
    }
});

it('verify passes structurally on a PRE_DEPLOY target after apply, with the HTTP proofs deferred', function () {
    $scratch = psaScratchDir();

    try {
        $target = psaBuildTarget($scratch);
        psaMakePreDeploy($target);

        [$applyExit, $applyOutput] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_apply', psaBaseEnv($scratch));
        expect($applyExit)->toBe(0, $applyOutput);

        [$exit, $output] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_verify', psaBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('target state: PRE_DEPLOY')
            ->toContain('www-data execute-only traversal: OK')
            ->toContain('ACL precision: OK')
            ->toContain('public-storage canary and existing-upload regression: DEFERRED')
            ->toContain('PASS: public storage access verified for test-target');

        expect($output)->not->toContain('internal HTTP public-media test: OK');
    } finally {
        psaCleanup($scratch);
    }
});

it('a broken (dangling) current never enters the pre-deploy path: it fails inside the deployed-state validation', function () {
    $scratch = psaScratchDir();

    try {
        $target = psaBuildTarget($scratch);

        // current exists but its public/storage link dangles: remove the
        // real public storage tree the link resolves to.
        exec('rm -rf '.escapeshellarg($target['app']));

        [$exit, $output] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_apply', psaBaseEnv($scratch));

        expect($exit)->not->toBe(0, $output);
        expect($output)->toContain('target state: DEPLOYED');
        expect($output)->not->toContain('target state: PRE_DEPLOY');
        expect($output)->not->toContain('ACL applied');
    } finally {
        psaCleanup($scratch);
    }
});

it('keeps the full HTTP-level verification on a DEPLOYED target — the pre-deploy split never weakens it', function () {
    $scratch = psaScratchDir();

    try {
        psaBuildTarget($scratch);

        [$exit, $output] = psaRunHarness($scratch, ['TARGET_ID' => 'test-target'], 'perform_apply', psaBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('target state: DEPLOYED')
            ->toContain('direct www-data read of canary: OK')
            ->toContain('internal HTTP public-media test: OK');
        expect($output)->not->toContain('DEFERRED');
    } finally {
        psaCleanup($scratch);
    }
});
