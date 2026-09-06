<?php

use Illuminate\Support\Facades\File;

/**
 * the target-aware migration: target-aware deployment.
 *
 * These tests execute the real shipped `infrastructure/scripts/deploy` —
 * never a reimplementation of its logic — with every host dependency
 * (common's deployment.conf, the target registry, the targets validator,
 * health-check, verify-required-clis, systemctl, runuser, PHP/artisan)
 * supplied through the RATEGURU_* test-override contract already
 * established for health-check/status/cleanup, plus two new ones specific
 * to deploy (RATEGURU_HEALTH_CHECK_BIN, RATEGURU_VERIFY_REQUIRED_CLIS_BIN).
 *
 * deploy's own source guard (`if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
 * main "$@"; fi`) means sourcing the file never auto-runs main() — so, unlike
 * cleanup/install-target-operations, no separate "functions section"
 * extraction is needed here: tests simply `source` the real file directly,
 * then call parse_deploy_args/resolve_target/perform_deploy (bypassing
 * require_root, for parsing/resolution/pipeline coverage that doesn't need
 * real root) or invoke the script as a genuine subprocess (which does run
 * main(), requiring root first, exactly as production).
 *
 * Ownership/mode commands (chown, install -o/-g) run for real using this
 * test process's own uid/gid (getmyuid()/getmygid(), as raw numeric IDs —
 * chown/install both accept numeric owner:group directly) as
 * RUNTIME_USER/DEPLOY_ACCOUNT/CODE_GROUP in every fixture — the same
 * precedent installOpsBaseVars() already established in
 * InstallTargetOperationsTest.php — so these genuinely succeed without root
 * and without needing fake system accounts. systemctl, runuser, health-check
 * and verify-required-clis are PATH-shadowed or RATEGURU_*-overridden stubs;
 * `www-data`-dependent assertions (Laravel prep's shared/app ownership) are
 * skipped unless this test process is itself a member of a real www-data
 * group on the host — group *existence* alone is not sufficient, since
 * `install -g www-data` as a non-root process requires membership, not just
 * presence (e.g. absent entirely on macOS dev machines; present but this
 * account not a member of it on GitHub Actions' ubuntu-latest runner).
 */
function deployOpsScript(): string
{
    return base_path('infrastructure/scripts/deploy');
}

function deployOpsSource(): string
{
    return File::get(deployOpsScript());
}

function deployOpsCommonFile(): string
{
    return base_path('infrastructure/scripts/common');
}

function deployOpsTargetsCli(): string
{
    return base_path('infrastructure/scripts/targets');
}

function deployOpsRegistryPath(): string
{
    return base_path('infrastructure/config/deployment-targets.json');
}

function deployOpsDeploymentConfPath(): string
{
    return base_path('infrastructure/templates/deployment.conf.example');
}

function deployOpsScratchDir(): string
{
    $dir = sys_get_temp_dir().'/deploy-ops-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function deployOpsCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * Run a bash script as a real subprocess with an explicit environment (never
 * inherited shell exports). fd 2 is redirected onto fd 1 at the descriptor
 * level so there is only one stream to drain.
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function deployOpsExec(string $scriptPath, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(['bash', $scriptPath], $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start harness process');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

/**
 * Run the real deploy script as a genuine subprocess (never sourced) — this
 * is what makes BASH_SOURCE[0] == $0 true inside it, so its own source guard
 * calls main(), which calls require_root first, exactly like production.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function deployOpsRunScript(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(array_merge(['bash', deployOpsScript()], $arguments), $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start deploy subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

/**
 * Sources the real deploy script (BASH_SOURCE[0] != $0 here, so main() never
 * auto-runs — see the file-level docblock above) then runs $body, which can
 * call parse_deploy_args/resolve_target/perform_deploy/main directly.
 *
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function deployOpsRunHarness(string $scratch, string $body, array $env = []): array
{
    $script = "set -Eeuo pipefail\n".'source '.escapeshellarg(deployOpsScript())."\n".$body."\n";
    $harnessPath = $scratch.'/harness.sh';
    file_put_contents($harnessPath, $script);

    $defaultEnv = [
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
    ];

    return deployOpsExec($harnessPath, array_merge($defaultEnv, $env));
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function deployOpsBaseEnv(string $scratch, array $overrides = []): array
{
    return array_merge([
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => deployOpsCommonFile(),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => deployOpsDeploymentConfPath(),
        'RATEGURU_TARGET_REGISTRY_FILE' => deployOpsRegistryPath(),
        'RATEGURU_TARGETS_CLI' => deployOpsTargetsCli(),
        // Slice 5.5 supervisor-activation wait tuning, shrunk so an
        // activation that will never reach RUNNING fails immediately
        // instead of sleeping through the production retry budget.
        'RATEGURU_DEPLOY_QUEUE_WAIT_ATTEMPTS' => '1',
        'RATEGURU_DEPLOY_QUEUE_RETRY_DELAY' => '0',
    ], $overrides);
}

/**
 * A stub matching health-check's real --target CLI shape closely enough for
 * deploy's own purposes: logs its argv, exits 0 (or 1 to simulate a failed
 * post-switch health check).
 */
function deployOpsHealthCheckStub(string $scratch, string $logFile, bool $fail = false): string
{
    $path = $scratch.'/bin/health-check-stub-'.uniqid('', true);
    $exitCode = $fail ? 1 : 0;
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "$*" >> '.escapeshellarg($logFile)."\n"
        ."exit {$exitCode}\n");
    chmod($path, 0o755);

    return $path;
}

function deployOpsVerifyRequiredClisStub(string $scratch, string $logFile): string
{
    $path = $scratch.'/bin/verify-required-clis-stub';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "$*" >> '.escapeshellarg($logFile)."\n"
        ."exit 0\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * PATH-shadowed stubs for systemctl, runuser and supervisorctl — real
 * fixtures use numeric uid/gid strings as RUNTIME_USER, which real runuser
 * cannot resolve to a real account and does not need to: this stub drops
 * "-u VALUE --" and execs the remaining command directly as the current
 * (test) user.
 *
 * supervisorctl is stateful, mirroring the technique
 * InstallBootstrapServicesTest already established: `status` answers from a
 * queue-running state file, `update`/`start` create it (unless the
 * activation-fail toggle simulates a worker that never reaches RUNNING),
 * `stop` removes it, and every invocation is logged so tests can assert
 * exactly which supervisor commands a deployment ran — or that it ran none.
 */
function deployOpsInstallCoreStubs(string $scratch): void
{
    file_put_contents($scratch.'/bin/systemctl', "#!/usr/bin/env bash\n"
        .'echo "systemctl $*" >> '.escapeshellarg($scratch.'/systemctl.log')."\n"
        ."exit 0\n");
    chmod($scratch.'/bin/systemctl', 0o755);

    file_put_contents($scratch.'/bin/runuser', "#!/usr/bin/env bash\n"
        .'echo "runuser $*" >> '.escapeshellarg($scratch.'/runuser.log')."\n"
        .'shift 2; shift'."\n"
        .'exec "$@"'."\n");
    chmod($scratch.'/bin/runuser', 0o755);

    $stateDir = $scratch.'/supervisor-state';

    if (! is_dir($stateDir)) {
        expect(@mkdir($stateDir, 0o755, true))->toBeTrue("could not create supervisor state directory: {$stateDir}");
    }

    file_put_contents($scratch.'/bin/supervisorctl', "#!/usr/bin/env bash\n"
        .'echo "supervisorctl $*" >> '.escapeshellarg($scratch.'/supervisorctl.log')."\n"
        .'state='.escapeshellarg($stateDir)."\n"
        .<<<'STUB'
        case "${1:-}" in
            status)
                # Group-aware on purpose: answering for any requested group
                # would let deploy query the wrong program (e.g. a
                # hard-coded name) and still look healthy.
                if [[ "${2:-}" != "parity-queue:*" ]]; then
                    echo "${2:-}: ERROR (no such process)"
                    exit 1
                fi
                # status-sequence models real Supervisor state transitions:
                # one line consumed per status call, the final line sticky.
                # This is what reproduces the STARTING race that a single
                # immediate check would fail on.
                if [[ -s "${state}/status-sequence" ]]; then
                    next="$(head -n 1 "${state}/status-sequence")"
                    if (( $(wc -l < "${state}/status-sequence") > 1 )); then
                        tail -n +2 "${state}/status-sequence" > "${state}/seq.tmp"
                        mv "${state}/seq.tmp" "${state}/status-sequence"
                    fi
                    # Exit 0 even for non-RUNNING states, so the caller's own
                    # state classification is what decides — not our exit code.
                    echo "parity-queue:parity-queue_00   ${next}   pid 321, uptime 0:00:01"
                    exit 0
                fi
                if [[ -e "${state}/queue-running" ]]; then
                    # drop-after-status simulates a worker that is RUNNING when
                    # the pre-deploy snapshot reads it and gone by the time the
                    # transition re-checks it.
                    [[ -e "${state}/drop-after-status" ]] && rm -f "${state}/queue-running"
                    echo "parity-queue:parity-queue_00   RUNNING   pid 123, uptime 0:05:00"
                    exit 0
                fi
                echo "parity-queue:*: ERROR (no such process)"
                exit 1
                ;;
            reread)
                echo "parity-queue: available"
                exit 0
                ;;
            update|start)
                if [[ ! -e "${state}/activation-fail" ]]; then
                    touch "${state}/queue-running"
                fi
                exit 0
                ;;
            stop)
                rm -f "${state}/queue-running"
                exit 0
                ;;
        esac
        exit 0
        STUB."\n");
    chmod($scratch.'/bin/supervisorctl', 0o755);
}

/**
 * The supervisorctl invocations a run performed (empty when the stub was
 * never reached).
 *
 * @return list<string>
 */
function deployOpsSupervisorctlLog(string $scratch): array
{
    $path = $scratch.'/supervisorctl.log';

    if (! is_file($path)) {
        return [];
    }

    return array_values(array_filter(explode("\n", trim((string) file_get_contents($path)))));
}

function deployOpsFakePhpBin(string $scratch): string
{
    $path = $scratch.'/bin/fake-php';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "php $*" >> '.escapeshellarg($scratch.'/artisan.log')."\n"
        ."exit 0\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * Write a scripted Supervisor status sequence: one state per call, the last
 * line sticky. Models the real STARTING -> RUNNING promotion the committed
 * program's startsecs=3 produces.
 *
 * @param  list<string>  $states
 */
function deployOpsSupervisorStatusSequence(string $scratch, array $states): void
{
    file_put_contents($scratch.'/supervisor-state/status-sequence', implode("\n", $states)."\n");
}

/**
 * PATH-shadowed `install` that rewrites every `-g <group>` of deploy's
 * Laravel preparation to this test process's own primary group, then
 * delegates to the real binary.
 *
 * Deploy's Laravel prep uses two group names a scratch fixture cannot
 * satisfy: the literal `www-data` (which requires membership the CI runner
 * does not have) and `"${RUNTIME_USER}"` as a group name (valid where the
 * account has an eponymous primary group, as on the CI runner, but not on a
 * macOS dev machine where it is `staff`). Without this shim the entire
 * normal-deploy queue path would stay untested on every machine. Only the
 * group argument is substituted — ownership and mode semantics are otherwise
 * untouched, and Laravel storage group semantics are covered elsewhere.
 */
function deployOpsInstallGroupShimStub(string $scratch): void
{
    $realInstall = trim((string) shell_exec('command -v install'));
    expect($realInstall)->not->toBe('', 'no real install binary found');

    $group = deployOpsCurrentGroup();

    file_put_contents($scratch.'/bin/install', "#!/usr/bin/env bash\n"
        ."args=()\n"
        ."next_is_group=false\n"
        ."for arg in \"\$@\"; do\n"
        ."    if [[ \"\${next_is_group}\" == true ]]; then\n"
        .'        args+=('.escapeshellarg($group)."); next_is_group=false; continue\n"
        ."    fi\n"
        ."    if [[ \"\${arg}\" == '-g' ]]; then next_is_group=true; fi\n"
        ."    args+=(\"\${arg}\")\n"
        ."done\n"
        .'exec '.escapeshellarg($realInstall).' "${args[@]}'."\"\n");
    chmod($scratch.'/bin/install', 0o755);

    // Same substitution for chown's owner:group operand — deploy's Laravel
    // prep also runs `chown -R "${RUNTIME_USER}:${RUNTIME_USER}"`.
    $realChown = trim((string) shell_exec('command -v chown'));
    expect($realChown)->not->toBe('', 'no real chown binary found');

    file_put_contents($scratch.'/bin/chown', "#!/usr/bin/env bash\n"
        ."args=()\n"
        ."spec_seen=false\n"
        ."for arg in \"\$@\"; do\n"
        ."    if [[ \"\${spec_seen}\" == false ]] && [[ \"\${arg}\" != -* ]] && [[ \"\${arg}\" == *:* ]]; then\n"
        .'        args+=("${arg%%:*}:"'.escapeshellarg($group)."); spec_seen=true; continue\n"
        ."    fi\n"
        ."    [[ \"\${arg}\" == -* ]] || spec_seen=true\n"
        ."    args+=(\"\${arg}\")\n"
        ."done\n"
        .'exec '.escapeshellarg($realChown).' "${args[@]}'."\"\n");
    chmod($scratch.'/bin/chown', 0o755);
}

/**
 * A php stub whose `artisan queue:restart` fails while every other artisan
 * command succeeds — the "restart signal cannot be written" case.
 */
function deployOpsFailingQueueRestartPhpBin(string $scratch): string
{
    $path = $scratch.'/bin/fake-php-queue-fail';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "php $*" >> '.escapeshellarg($scratch.'/artisan.log')."\n"
        ."for arg in \"\$@\"; do\n"
        ."    if [[ \"\${arg}\" == 'queue:restart' ]]; then exit 1; fi\n"
        ."done\n"
        ."exit 0\n");
    chmod($path, 0o755);

    return $path;
}

/**
 * A minimal release directory containing artisan, for exercising the queue
 * transition directly without running deploy's full Laravel preparation
 * (whose `install -g www-data` needs a membership CI does not have).
 */
function deployOpsReleaseDirWithArtisan(string $scratch): string
{
    $dir = $scratch.'/release-'.uniqid('', true);
    expect(@mkdir($dir, 0o755, true))->toBeTrue("could not create release directory: {$dir}");
    file_put_contents($dir.'/artisan', "#!/usr/bin/env php\n<?php // fixture artisan\n");

    return $dir;
}

/**
 * Sources deploy and calls perform_queue_transition with an explicitly
 * controlled pre-deploy worker state, which is what selects CASE A
 * (activate, no restart) versus CASE B (no churn, mandatory restart).
 *
 * @return array{0: int, 1: string}
 */
function deployOpsRunQueueTransition(string $scratch, bool $originalRunning, array $env = []): array
{
    $releaseDir = deployOpsReleaseDirWithArtisan($scratch);
    $phpBin = $env['__php'] ?? $scratch.'/bin/fake-php';
    unset($env['__php']);
    // PHP heredocs interpolate variables, never function calls — the literal
    // has to be computed before the heredoc.
    $originalRunningLiteral = $originalRunning ? 'true' : 'false';

    $body = <<<BASH
        SUPERVISOR_PROGRAM=parity-queue
        RELEASE_ROOT={$releaseDir}
        RUNTIME_USER="\$(id -un)"
        PHP_BIN={$phpBin}
        ORIGINAL_QUEUE_RUNNING={$originalRunningLiteral}
        SUPERVISOR_ACTIVATED_NOW=false
        QUEUE_RESTART_ISSUED=false
        FAILURE_STATUS=failed
        perform_queue_transition
        printf 'TRANSITION_OK activated=%s restarted=%s\\n' "\${SUPERVISOR_ACTIVATED_NOW}" "\${QUEUE_RESTART_ISSUED}"
        BASH;

    return deployOpsRunHarness($scratch, $body, deployOpsBaseEnv($scratch, $env));
}

/**
 * A scratch target root + a separate incoming-artifacts directory + a real
 * .tar.gz built with real tar/sha256sum (portable, no stubbing needed). Pass
 * $laravel=true to additionally include artisan and the required-CLI
 * manifest verify-required-clis (stubbed elsewhere) would otherwise expect.
 *
 * @return array{root: string, incoming: string, artifact: string, checksum: string}
 */
function deployOpsBuildFixture(string $scratch, bool $laravel = false): array
{
    $id = uniqid('', true);
    $root = $scratch.'/target-'.$id;
    $incoming = $scratch.'/incoming-'.$id;

    foreach ([
        $root.'/releases',
        $root.'/deployments',
        $root.'/locks',
        $root.'/shared/storage',
        $incoming,
    ] as $dir) {
        expect(@mkdir($dir, 0o755, true))->toBeTrue("could not create fixture directory: {$dir}");
    }
    touch($root.'/shared/.env');

    $artifactSrc = $scratch.'/artifact-src-'.$id;
    mkdir($artifactSrc.'/public', 0o755, true);
    file_put_contents($artifactSrc.'/public/index.php', "<?php // fixture\n");

    $tarEntries = 'public';

    if ($laravel) {
        file_put_contents($artifactSrc.'/artisan', "#!/usr/bin/env php\n<?php // fixture artisan\n");
        mkdir($artifactSrc.'/infrastructure/config', 0o755, true);
        mkdir($artifactSrc.'/infrastructure/scripts', 0o755, true);
        file_put_contents($artifactSrc.'/infrastructure/config/required-clis.txt', "targets\n");
        file_put_contents($artifactSrc.'/infrastructure/scripts/targets', "#!/usr/bin/env bash\nexit 0\n");
        chmod($artifactSrc.'/infrastructure/scripts/targets', 0o755);
        file_put_contents($artifactSrc.'/infrastructure/scripts/common', "#!/usr/bin/env bash\n");
        chmod($artifactSrc.'/infrastructure/scripts/common', 0o644);
        $tarEntries = 'public artisan infrastructure';
    }

    $artifact = $incoming.'/release.tar.gz';
    exec('tar -C '.escapeshellarg($artifactSrc)." -czf {$artifact} {$tarEntries} 2>&1", $tarOutput, $tarExit);
    expect($tarExit)->toBe(0, "failed to build fixture artifact:\n".implode("\n", $tarOutput));

    exec('cd '.escapeshellarg($incoming).' && sha256sum '.escapeshellarg(basename($artifact)).' > '.escapeshellarg(basename($artifact).'.sha256').' 2>&1', $shaOutput, $shaExit);
    expect($shaExit)->toBe(0, "failed to build fixture checksum:\n".implode("\n", $shaOutput));

    return ['root' => $root, 'incoming' => $incoming, 'artifact' => $artifact, 'checksum' => $artifact.'.sha256'];
}

/**
 * A scratch, writable copy of the real committed deployment.conf.example,
 * verbatim. Formerly rewrote STAGING_ROOT/STAGING_RUNTIME_USER/
 * STAGING_CODE_GROUP/STAGING_DEPLOY_USER/STAGING_INCOMING_ARTIFACTS to point
 * at each fixture's own paths — but the template no longer carries any
 * target-specific field at all (see
 * infrastructure/templates/deployment.conf.example's own header comment):
 * every one of those values now comes exclusively from the target registry,
 * via deployOpsParityRegistry(), which already derives them from the
 * fixture's own root/incoming plus the current account/group. Still returns
 * a fresh scratch copy (rather than the template path itself) so callers
 * that mutate it afterward — e.g. the Laravel-prep test's own PHP_BIN
 * override — never touch the repository's own source file.
 */
function deployOpsDeploymentConfForFixture(string $scratch): string
{
    $path = $scratch.'/deployment-'.uniqid('', true).'.conf';
    file_put_contents($path, File::get(deployOpsDeploymentConfPath()));

    return $path;
}

/**
 * A registry + patched `targets` validator declaring a single, fully valid
 * `parity-target` with lifecycle=active pointing at the fixture's own
 * application root — the same technique CleanupTest.php established,
 * re-derived here rather than shared, per this codebase's convention of each
 * test file owning its own helper namespace. runtime_user/deploy_user/
 * runtime_group/code_group are the current test process's own account/
 * primary group name (deployOpsCurrentAccount()/deployOpsCurrentGroup()) —
 * the registry is the only source of these values in target mode now;
 * deployment.conf no longer carries any target-specific field at all (see
 * deployOpsDeploymentConfForFixture()).
 *
 * @return array{0: string, 1: string} [registryPath, targetsCliPath]
 */
function deployOpsParityRegistry(string $scratch, array $fixture): array
{
    $account = deployOpsCurrentAccount();
    $group = deployOpsCurrentGroup();

    // Four constraints the committed `targets` validator enforces as
    // production safety rails, relaxed only in this throwaway, test-only
    // copy — the same technique CleanupTest.php already established for
    // application_root/ACTIVE_ALLOWLIST: (1) application_root and
    // (2) incoming_artifacts must otherwise live under /home/www/rateguru
    // and /home respectively, which a scratch fixture can't satisfy;
    // (3) code_group must otherwise differ from runtime_group, and
    // (4) code_group must otherwise differ from the runtime user's own
    // name — both real registry-modeling rules this fixture doesn't need to
    // honor. It just needs one group name the test process can chown to
    // without root, and reusing the test's own account/primary-group for
    // both runtime_user and code_group is fine here (that modeling rule is
    // already covered elsewhere, e.g. DeploymentTargetRegistryTest.php).
    // Constraint (4) only surfaces where a host's own account/primary-group
    // pair happen to share a name — e.g. GitHub Actions' `runner` user,
    // whose primary group is also named `runner` — so this was missed
    // locally (this machine's account and primary group differ) until CI
    // caught it.
    $patchedTargets = str_replace(
        'ACTIVE_ALLOWLIST="staging-main"',
        'ACTIVE_ALLOWLIST="parity-target"',
        File::get(deployOpsTargetsCli()),
    );
    $patchedTargets = str_replace(
        'elif [[ "${application_root}" != /home/www/rateguru/* ]]; then',
        'elif false; then',
        $patchedTargets,
    );
    $patchedTargets = str_replace(
        'elif [[ "${incoming}" != /home/* ]]; then',
        'elif false; then',
        $patchedTargets,
    );
    $patchedTargets = str_replace(
        'if [[ "${code_group}" == "${runtime_group}" ]]; then',
        'if false; then',
        $patchedTargets,
    );
    $patchedTargets = str_replace(
        'if [[ "${code_group}" == "${runtime_user}" ]]; then',
        'if false; then',
        $patchedTargets,
    );

    $targetsPath = $scratch.'/parity-targets';
    file_put_contents($targetsPath, $patchedTargets);
    chmod($targetsPath, 0o755);

    $registry = [
        'schema_version' => 1,
        'targets' => [
            'parity-target' => [
                'id' => 'parity-target',
                'lifecycle' => 'active',
                'environment_class' => 'staging',
                'application_root' => $fixture['root'],
                'runtime_user' => $account,
                'runtime_group' => $group,
                'deploy_user' => $account,
                'code_group' => $group,
                'incoming_artifacts' => $fixture['incoming'],
                'release_retention' => 5,
                'database' => ['name' => 'parity_db', 'application_role' => 'parity_app'],
                'health' => ['url' => 'http://127.0.0.1/', 'host_header' => 'parity.internal'],
                'public_hostnames' => ['parity.example', 'parity-secondary.example'],
                'backup' => ['namespace' => 'parity', 'local_retention_days' => 1, 'offsite_retention_days' => 1, 'minimum_retained_backups' => 2],
                'php_fpm' => ['pool' => 'parity', 'socket' => '/run/php/parity.sock'],
                'supervisor' => ['program' => 'parity-queue', 'queue' => 'parity'],
                'scheduler' => ['name' => 'parity-scheduler'],
                'nginx' => ['site_name' => 'parity', 'internal_hostname' => 'parity.internal'],
                'environment_template' => 'infrastructure/templates/environment/staging.env.example',
            ],
        ],
    ];

    $registryPath = $scratch.'/parity-registry.json';
    file_put_contents($registryPath, json_encode($registry, JSON_PRETTY_PRINT));

    exec(escapeshellarg($targetsPath).' validate --file '.escapeshellarg($registryPath).' 2>&1', $validateOutput, $validateExit);
    expect($validateExit)->toBe(0, "parity-target fixture failed validation:\n".implode("\n", $validateOutput));

    return [$registryPath, $targetsPath];
}

/**
 * Group *existence* alone is not enough: deploy's Laravel-prep step runs
 * `install -g www-data` as this test process, and a non-root process can
 * only chgrp to a group it is itself a member of. The www-data system group
 * exists on GitHub Actions' ubuntu-latest runner, but the `runner` account
 * is not a member of it, which `getent group www-data` alone can't see —
 * confirmed via a real CI failure ("Operation not permitted") that only a
 * membership check catches.
 */
function deployOpsWwwDataAvailable(): bool
{
    exec('getent group www-data >/dev/null 2>&1', $output, $exit);
    if ($exit !== 0) {
        return false;
    }

    if (getmyuid() === 0) {
        return true;
    }

    exec('id -nG', $groups);
    $memberships = preg_split('/\s+/', trim($groups[0] ?? ''));

    return in_array('www-data', $memberships, true);
}

/**
 * The current test process's real username/primary group name — via `id`,
 * not PHP's posix_* extension (not guaranteed enabled everywhere). Used as
 * RUNTIME_USER/DEPLOY_ACCOUNT/CODE_GROUP so chown/install succeed without
 * root, and because the target registry validator requires account-name-
 * shaped strings (a raw numeric uid fails is_safe_account_name).
 */
function deployOpsCurrentAccount(): string
{
    exec('id -un', $output);

    return trim($output[0] ?? '');
}

function deployOpsCurrentGroup(): string
{
    exec('id -gn', $output);

    return trim($output[0] ?? '');
}

// =============================================================================
// Selector contract
// =============================================================================

it('supports the --target selector', function () {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsBuildFixture($scratch);
        [$registryPath, $targetsPath] = deployOpsParityRegistry($scratch, $fixture);

        [$exit, $output] = deployOpsRunHarness($scratch, <<<'BASH'
            parse_deploy_args --target parity-target --release v1.0.0-20260101-000000-abc1234 --artifact /tmp/whatever.tar.gz
            resolve_target
            printf 'LABEL=%s\n' "${LABEL}"
            BASH, deployOpsBaseEnv($scratch, [
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('LABEL=parity-target');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('requires --target', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --release v1 --artifact /tmp/x.tar.gz',
            deployOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target is required');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects duplicate --target', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target a --target b --release v1 --artifact /tmp/x.tar.gz',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target given more than once');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects a removed --environment flag exactly like any other unknown argument', function () {
    // --environment is no longer a recognized flag at all — there is no
    // special deprecation message, just the same generic rejection any
    // other bogus flag gets.
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness($scratch, 'parse_deploy_args --environment staging', deployOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --environment');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects --target given without a value', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness($scratch, 'parse_deploy_args --target', deployOpsBaseEnv($scratch));
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects --release, --artifact or --checksum given without a value, instead of failing on an uncontrolled shift', function () {
    // Regression test: --release/--artifact/--checksum used to read "${2:-}"
    // (always safe under set -u) and then unconditionally `shift 2`, even
    // when no second argument existed. `shift 2` with only one positional
    // parameter left fails under `set -e`, aborting with no message at all
    // instead of a clear "requires a value" error — exactly the failure mode
    // --target was already guarded against.
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target staging-main --release',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release requires a value');

        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target staging-main --artifact',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--artifact requires a value');

        // --checksum stays optional when omitted entirely: deploy falls back
        // to "${ARTIFACT}.sha256" — see the end-to-end parity tests below.
        // This only proves that *when given*, --checksum still requires a
        // value, exactly like --release/--artifact.
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target staging-main --release RELEASE --artifact ARTIFACT --checksum',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--checksum requires a value');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects an explicitly empty value for --release, --artifact or --checksum', function () {
    // Regression test: --release/--artifact/--checksum used to accept an
    // explicit empty value silently — --release/--artifact only surfaced it
    // later, as the generic "--release is required"/"--artifact is
    // required", and --checksum '' silently fell back to the default
    // "${ARTIFACT}.sha256" as if the flag had never been given at all.
    // require_flag_value now rejects all three immediately, by name.
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            "parse_deploy_args --target staging-main --release '' --artifact /tmp/x.tar.gz",
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release requires a non-empty value');

        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            "parse_deploy_args --target staging-main --release v1 --artifact ''",
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--artifact requires a non-empty value');

        // --checksum stays optional only when omitted entirely — an
        // explicit empty value is a caller mistake (e.g. an unset shell
        // variable interpolated into the command line) and must fail
        // loudly, not silently substitute a default the caller never asked
        // for.
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            "parse_deploy_args --target staging-main --release v1 --artifact /tmp/x.tar.gz --checksum ''",
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--checksum requires a non-empty value');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects --release, --artifact, --checksum or --target swallowing the next flag as their value', function () {
    // Regression test: every value-taking flag used to blindly take
    // whatever token followed it, including another flag — e.g.
    // `--release --migrate` set RELEASE_ID to the literal string
    // "--migrate" and silently shifted past --migrate itself, leaving
    // RUN_MIGRATIONS false with no error at all. require_flag_value now
    // rejects any next-token starting with "-" by name, for every
    // value-taking flag deploy parses.
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target staging-main --release --migrate --artifact /tmp/x.tar.gz',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release requires a value, not another option: --migrate');

        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target staging-main --release v1 --artifact --migrate',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--artifact requires a value, not another option: --migrate');

        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target staging-main --release v1 --artifact /tmp/x.tar.gz --checksum --migrate',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--checksum requires a value, not another option: --migrate');

        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target --migrate',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a value, not another option: --migrate');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('still parses valid values that happen to follow --migrate or precede it', function () {
    // Preserves the ordinary case: a real value immediately followed by the
    // standalone --migrate flag still parses both correctly — this is what
    // the stricter require_flag_value checks above must not break.
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target staging-main --release v1.0.0-20260101-000000-abc1234 --artifact /tmp/x.tar.gz --migrate'
            .'; printf "RELEASE_ID=[%s]\nARTIFACT=[%s]\nRUN_MIGRATIONS=[%s]\n" "${RELEASE_ID}" "${ARTIFACT}" "${RUN_MIGRATIONS}"',
            deployOpsBaseEnv($scratch),
        );

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('RELEASE_ID=[v1.0.0-20260101-000000-abc1234]')
            ->toContain('ARTIFACT=[/tmp/x.tar.gz]')
            ->toContain('RUN_MIGRATIONS=[true]');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects an explicitly empty selector value', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            "parse_deploy_args --target '' --release v1 --artifact /tmp/x.tar.gz",
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--target requires a non-empty value');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('shows only the --target form on --help and exits 0', function () {
    // A real subprocess (`bash deploy --help`) still requires root first,
    // unchanged from before this slice — see "requires root before anything
    // else" below. This proves --help's own content via parse_deploy_args
    // directly (sourced, bypassing require_root), i.e. what a real
    // invocation reaches only once root authorization has already passed.
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness($scratch, 'parse_deploy_args --help', deployOpsBaseEnv($scratch));

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('--target TARGET_ID')
            ->not->toContain('--environment');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects unknown arguments', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness($scratch, 'parse_deploy_args --bogus', deployOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown argument: --bogus');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('preserves the existing --release/--artifact required-value behavior unchanged', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target staging-main --artifact /tmp/x.tar.gz',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--release is required');

        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target staging-main --release v1',
            deployOpsBaseEnv($scratch),
        );
        expect($exit)->not->toBe(0);
        expect($output)->toContain('--artifact is required');
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// Gated test overrides: RATEGURU_HEALTH_CHECK_BIN / RATEGURU_VERIFY_REQUIRED_CLIS_BIN
// =============================================================================

it('ignores RATEGURU_HEALTH_CHECK_BIN/RATEGURU_VERIFY_REQUIRED_CLIS_BIN without the opt-in flag, and honors them with it', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness($scratch, <<<'BASH'
            printf 'DENIED HEALTH_CHECK_BIN=%s\n' "${HEALTH_CHECK_BIN}"
            printf 'DENIED VERIFY_REQUIRED_CLIS_BIN=%s\n' "${VERIFY_REQUIRED_CLIS_BIN}"
            BASH, [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_COMMON_FILE' => deployOpsCommonFile(),
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            'RATEGURU_DEPLOYMENT_CONF_FILE' => deployOpsDeploymentConfPath(),
            // Deliberately no RATEGURU_ALLOW_TEST_OVERRIDES set for the two new
            // overrides themselves is impossible to isolate from the flag
            // above (one flag gates both) — so this proves the "denied" half
            // differently: the values default to the hardcoded production
            // paths even though poisoned override vars are present, because
            // this harness passes them under names the script never reads
            // unless it explicitly opts in per-value below.
        ]);

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('DENIED HEALTH_CHECK_BIN=/home/www/rateguru/bin/health-check')
            ->toContain('DENIED VERIFY_REQUIRED_CLIS_BIN=/home/www/rateguru/bin/verify-required-clis');

        [$deniedExit, $deniedOutput] = deployOpsRunHarness($scratch, <<<'BASH'
            printf 'HEALTH_CHECK_BIN=%s\n' "${HEALTH_CHECK_BIN}"
            printf 'VERIFY_REQUIRED_CLIS_BIN=%s\n' "${VERIFY_REQUIRED_CLIS_BIN}"
            BASH, [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_COMMON_FILE' => deployOpsCommonFile(),
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            'RATEGURU_DEPLOYMENT_CONF_FILE' => deployOpsDeploymentConfPath(),
            'RATEGURU_HEALTH_CHECK_BIN' => '/tmp/poisoned-health-check',
            'RATEGURU_VERIFY_REQUIRED_CLIS_BIN' => '/tmp/poisoned-verify',
        ]);
        // With RATEGURU_ALLOW_TEST_OVERRIDES already true (required just to
        // reach a sourceable common/deployment.conf at all in this harness),
        // the two new overrides ARE honored — this is the "granted" half.
        expect($deniedExit)->toBe(0, $deniedOutput);
        expect($deniedOutput)
            ->toContain('HEALTH_CHECK_BIN=/tmp/poisoned-health-check')
            ->toContain('VERIFY_REQUIRED_CLIS_BIN=/tmp/poisoned-verify');

        // The genuinely gate-off case: no RATEGURU_ALLOW_TEST_OVERRIDES at
        // all, real subprocess, deploy must fail at its hardcoded default
        // COMMON_FILE path (proving no override of any kind took effect) —
        // the same technique TargetAwareOperationsTest.php already
        // established for this exact class of assertion.
        [$fullyDeniedExit, $fullyDeniedOutput] = deployOpsRunScript(['--help'], [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_COMMON_FILE' => deployOpsCommonFile(),
            'RATEGURU_HEALTH_CHECK_BIN' => '/tmp/poisoned-health-check',
            'RATEGURU_VERIFY_REQUIRED_CLIS_BIN' => '/tmp/poisoned-verify',
        ]);
        expect($fullyDeniedExit)->not->toBe(0);
        expect($fullyDeniedOutput)->toContain('/home/www/rateguru/bin/common');
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// Root-first contract + lifecycle ordering
// =============================================================================

it('requires root before anything else, even for --target with an obviously invalid deployment', function () {
    if (getmyuid() === 0) {
        test()->markTestSkipped('this test process is running as root — the require-root gate cannot be exercised');
    }

    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunScript(
            ['--target', 'tits-guru', '--release', 'v0.0.0-20260101-000000-abc0000', '--artifact', '/definitely/missing.tar.gz'],
            deployOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('this command must be executed as root');
        expect($output)->not->toContain('lifecycle=planned');
        expect($output)->not->toContain('artifact does not exist');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects a planned target before any artifact, checksum, incoming-directory or filesystem validation', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness($scratch, <<<'BASH'
            parse_deploy_args --target tits-guru --release v0.0.0-20260101-000000-abc0000 --artifact /definitely/missing.tar.gz
            resolve_target
            echo "SHOULD NOT REACH HERE"
            BASH, deployOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('tits-guru')
            ->toContain('lifecycle=planned')
            ->toContain('not active');
        expect($output)->not->toContain('SHOULD NOT REACH HERE');
        expect($output)->not->toContain('artifact does not exist');
        expect($output)->not->toContain('incoming');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects an unknown target clearly', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness($scratch, <<<'BASH'
            parse_deploy_args --target ghost-target --release v1.0.0-20260101-000000-abc1234 --artifact /tmp/x.tar.gz
            resolve_target
            BASH, deployOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('unknown target: ghost-target');
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// Artifact isolation
// =============================================================================

it('rejects an artifact located outside the target incoming-artifacts directory', function () {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsBuildFixture($scratch);
        [$registryPath, $targetsPath] = deployOpsParityRegistry($scratch, $fixture);
        $confPath = deployOpsDeploymentConfForFixture($scratch);

        $outsideArtifact = $scratch.'/outside.tar.gz';
        copy($fixture['artifact'], $outsideArtifact);
        copy($fixture['checksum'], $outsideArtifact.'.sha256');

        [$exit, $output] = deployOpsRunHarness($scratch, <<<BASH
            parse_deploy_args --target parity-target --release v1.0.0-20260101-000000-abc1234 --artifact {$outsideArtifact}
            resolve_target
            perform_deploy
            BASH, deployOpsBaseEnv($scratch, [
            'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('artifact must be located inside');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects a checksum located outside the target incoming-artifacts directory', function () {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsBuildFixture($scratch);
        [$registryPath, $targetsPath] = deployOpsParityRegistry($scratch, $fixture);
        $confPath = deployOpsDeploymentConfForFixture($scratch);

        $outsideChecksum = $scratch.'/outside.tar.gz.sha256';
        copy($fixture['checksum'], $outsideChecksum);

        [$exit, $output] = deployOpsRunHarness($scratch, <<<BASH
            parse_deploy_args --target parity-target --release v1.0.0-20260101-000000-abc1234 --artifact {$fixture['artifact']} --checksum {$outsideChecksum}
            resolve_target
            perform_deploy
            BASH, deployOpsBaseEnv($scratch, [
            'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
        ]));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('checksum must be located inside');
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// End-to-end scratch: a full deployment through --target
// =============================================================================

/**
 * Runs one full, real deployment (extraction, symlinks, ownership/mode
 * normalization, verify-required-clis, atomic switch, health-check, history)
 * against a fresh fixture, via --target parity-target.
 *
 * @return array{exit: int, output: string, fixture: array, healthCheckLog: string, verifyCliLog: string, releaseId: string}
 */
function deployOpsRunFullDeployment(string $scratch, ?bool $failHealthCheck = false): array
{
    $fixture = deployOpsBuildFixture($scratch);
    $confPath = deployOpsDeploymentConfForFixture($scratch);
    deployOpsInstallCoreStubs($scratch);
    [$registryPath, $targetsPath] = deployOpsParityRegistry($scratch, $fixture);

    $healthCheckLog = $scratch.'/health-check-'.uniqid('', true).'.log';
    touch($healthCheckLog);
    $healthCheckStub = deployOpsHealthCheckStub($scratch, $healthCheckLog, $failHealthCheck ?? false);

    $verifyCliLog = $scratch.'/verify-cli-'.uniqid('', true).'.log';
    touch($verifyCliLog);
    $verifyCliStub = deployOpsVerifyRequiredClisStub($scratch, $verifyCliLog);

    $releaseId = 'v1.0.0-2026010'.random_int(1, 9).'-000000-abc'.random_int(1000, 9999);

    $env = deployOpsBaseEnv($scratch, [
        'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
        'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
        'RATEGURU_TARGETS_CLI' => $targetsPath,
        'RATEGURU_HEALTH_CHECK_BIN' => $healthCheckStub,
        'RATEGURU_VERIFY_REQUIRED_CLIS_BIN' => $verifyCliStub,
    ]);

    [$exit, $output] = deployOpsRunHarness(
        $scratch,
        "parse_deploy_args --target parity-target --release {$releaseId} --artifact {$fixture['artifact']}\nresolve_target\nperform_deploy",
        $env,
    );

    return [
        'exit' => $exit,
        'output' => $output,
        'fixture' => $fixture,
        'healthCheckLog' => $healthCheckLog,
        'verifyCliLog' => $verifyCliLog,
        'releaseId' => $releaseId,
    ];
}

it('deploys via --target: content, ownership, symlinks, links, history, health selector', function () {
    $scratch = deployOpsScratchDir();

    try {
        $result = deployOpsRunFullDeployment($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        $root = $result['fixture']['root'];
        $releaseRoot = $root.'/releases/'.$result['releaseId'];

        expect(is_dir($releaseRoot))->toBeTrue('release directory must exist');
        expect(file_get_contents($releaseRoot.'/public/index.php'))->toBe("<?php // fixture\n", 'release content');

        clearstatcache(true, $releaseRoot);
        $stat = stat($releaseRoot);
        expect($stat['uid'])->toBe(getmyuid(), 'release owned by DEPLOY_ACCOUNT');
        expect($stat['gid'])->toBe(getmygid(), 'release group is CODE_GROUP');

        expect(readlink($releaseRoot.'/.env'))->toBe($root.'/shared/.env', '.env symlink');
        expect(readlink($releaseRoot.'/storage'))->toBe($root.'/shared/storage', 'storage symlink');
        expect(readlink($releaseRoot.'/public/storage'))->toBe($root.'/shared/storage/app/public', 'public/storage symlink');

        expect(readlink($root.'/current'))->toBe($releaseRoot, 'current symlink');
        expect(is_link($root.'/previous'))->toBeFalse('previous must be absent (first deployment)');

        $history = array_values(array_filter(explode("\n", trim(file_get_contents($root.'/deployments/history.jsonl')))));
        expect($history)->toHaveCount(2, 'deployment-started + deployment-finished');
        $started = json_decode($history[0], true, 512, JSON_THROW_ON_ERROR);
        $finished = json_decode($history[1], true, 512, JSON_THROW_ON_ERROR);
        expect($started['event'])->toBe('deployment-started');
        expect($started['status'])->toBe('running');
        expect($finished['event'])->toBe('deployment-finished');
        expect($finished['status'])->toBe('success');
        expect($finished['release'])->toBe($result['releaseId']);

        expect(trim(file_get_contents($scratch.'/systemctl.log')))->toContain('reload');

        expect(trim(File::get($result['healthCheckLog'])))->toBe('--target parity-target');
        expect($result['output'])->toContain('parity-target deployed successfully');

        // No artisan in this fixture, so queue:restart must never run.
        expect(File::exists($scratch.'/runuser.log'))->toBeFalse();
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// Laravel-prep command sequence (guarded: this process needs to itself be a
// member of a real www-data group, not merely have one exist on the host)
// =============================================================================

it('runs the Laravel artisan command sequence with the correct --expected-host', function () {
    if (! deployOpsWwwDataAvailable()) {
        test()->markTestSkipped('no www-data group on this host, or this account is not a member of it — Laravel prep\'s shared/app ownership (install -g www-data, as this non-root test process) needs both');
    }

    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsBuildFixture($scratch, laravel: true);
        $confPath = deployOpsDeploymentConfForFixture($scratch);
        deployOpsInstallCoreStubs($scratch);
        deployOpsFakePhpBin($scratch);
        $conf = preg_replace('/^PHP_BIN=.*$/m', 'PHP_BIN='.$scratch.'/bin/fake-php', File::get($confPath));
        file_put_contents($confPath, $conf);
        [$registryPath, $targetsPath] = deployOpsParityRegistry($scratch, $fixture);

        $healthCheckLog = $scratch.'/hc.log';
        touch($healthCheckLog);
        $healthCheckStub = deployOpsHealthCheckStub($scratch, $healthCheckLog);
        $verifyCliLog = $scratch.'/vc.log';
        touch($verifyCliLog);
        $verifyCliStub = deployOpsVerifyRequiredClisStub($scratch, $verifyCliLog);
        $artisanLog = $scratch.'/artisan.log';
        @unlink($artisanLog);

        $env = deployOpsBaseEnv($scratch, [
            'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
            'RATEGURU_HEALTH_CHECK_BIN' => $healthCheckStub,
            'RATEGURU_VERIFY_REQUIRED_CLIS_BIN' => $verifyCliStub,
        ]);

        $releaseId = 'v1.0.0-2026010'.random_int(1, 9).'-000000-abc'.random_int(1000, 9999);
        $expectedHost = 'parity.example';

        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            "parse_deploy_args --target parity-target --migrate --release {$releaseId} --artifact {$fixture['artifact']}\nresolve_target\nperform_deploy",
            $env,
        );

        expect($exit)->toBe(0, $output);

        $artisanCalls = array_values(array_filter(explode("\n", trim(File::get($artisanLog)))));
        expect($artisanCalls)->toHaveCount(5, 'config:cache, sharing:verify, view:cache, migrate, queue:restart');
        expect($artisanCalls[0])->toContain('artisan config:cache');
        expect($artisanCalls[1])->toContain('artisan rateguru:sharing:verify')->toContain("--expected-host={$expectedHost}");
        expect($artisanCalls[2])->toContain('artisan view:cache');
        expect($artisanCalls[3])->toContain('artisan migrate --force');
        expect($artisanCalls[4])->toContain('artisan queue:restart');

        $verifyLog = trim(File::get($verifyCliLog));

        expect($verifyLog)
            ->toContain('--release-root')
            ->toContain($fixture['root'].'/releases/.'.$releaseId.'.tmp-');
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// Failure recovery
// =============================================================================

it('recovers correctly from a failed post-switch health check', function () {
    $scratch = deployOpsScratchDir();

    try {
        $first = deployOpsRunFullDeployment($scratch);
        expect($first['exit'])->toBe(0, $first['output']);

        $root = $first['fixture']['root'];
        // realpath(), not readlink(): deploy itself canonicalizes via
        // readlink -f when capturing/restoring ORIGINAL_CURRENT_PATH (resolving
        // e.g. /var -> /private/var on macOS), so comparing against a raw,
        // uncanonicalized readlink() here would be a false mismatch, not a real one.
        $originalCurrent = realpath($root.'/current');

        $confPath = deployOpsDeploymentConfForFixture($scratch);
        [$registryPath, $targetsPath] = deployOpsParityRegistry($scratch, $first['fixture']);
        deployOpsInstallCoreStubs($scratch);
        $failingHealthCheckLog = $scratch.'/failing-hc-target.log';
        touch($failingHealthCheckLog);
        $failingHealthCheckStub = deployOpsHealthCheckStub($scratch, $failingHealthCheckLog, fail: true);
        $verifyCliLog = $scratch.'/verify-cli-3.log';
        touch($verifyCliLog);
        $verifyCliStub = deployOpsVerifyRequiredClisStub($scratch, $verifyCliLog);

        $secondReleaseId = 'v1.0.1-20260102-000000-def5679';

        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            "parse_deploy_args --target parity-target --release {$secondReleaseId} --artifact {$first['fixture']['artifact']}\nresolve_target\nperform_deploy",
            deployOpsBaseEnv($scratch, [
                'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
                'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                'RATEGURU_TARGETS_CLI' => $targetsPath,
                'RATEGURU_HEALTH_CHECK_BIN' => $failingHealthCheckStub,
                'RATEGURU_VERIFY_REQUIRED_CLIS_BIN' => $verifyCliStub,
            ]),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('deployment health check failed');

        expect(realpath($root.'/current'))->toBe($originalCurrent, 'current must be restored to the prior release');
        expect(is_link($root.'/previous'))->toBeFalse('previous must not have been created by the failed deployment');
        expect(is_dir($root.'/releases/'.$secondReleaseId))->toBeFalse('the failed release directory must be removed');

        $history = array_values(array_filter(explode("\n", trim(file_get_contents($root.'/deployments/history.jsonl')))));
        $last = json_decode(end($history), true, 512, JSON_THROW_ON_ERROR);
        expect($last['event'])->toBe('deployment-finished');
        expect($last['status'])->toBe('failed-health-check');
        expect($last['release'])->toBe($secondReleaseId);

        // Recovery uses the same target deploy was invoked with — proven by
        // the fact the failing stub itself was only ever reached via
        // HEALTH_CHECK_ARGS=(--target parity-target); the recovery path here
        // does not invoke health-check a second time (deploy's own recovery,
        // unlike rollback's, never re-checks health — see
        // restore_deployment_links).
        expect(trim(File::get($failingHealthCheckLog)))->toBe('--target parity-target');
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// Slice 5.5: first-deploy Supervisor activation
// =============================================================================

it('activates the deferred supervisor worker on the first deployment: reread, update, RUNNING — only the target program', function () {
    $scratch = deployOpsScratchDir();

    try {
        // deployOpsRunFullDeployment builds a first deployment: current is
        // absent and the stateful supervisorctl stub starts with no
        // queue-running state — exactly the PRE_DEPLOY host shape after
        // install-bootstrap-services deferred the program.
        $result = deployOpsRunFullDeployment($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        expect($result['output'])->toContain('activating supervisor worker parity-queue');
        expect($result['output'])->toContain('supervisor worker parity-queue RUNNING');

        $calls = deployOpsSupervisorctlLog($scratch);
        expect($calls)->not->toBe([], 'the activation path must actually run supervisorctl');

        // reread precedes update; update targets exactly the registry
        // program; the worker reached RUNNING without needing a start.
        $joined = implode("\n", $calls);
        expect(strpos($joined, 'supervisorctl reread'))
            ->toBeLessThan(strpos($joined, 'supervisorctl update parity-queue'));
        expect($joined)->toContain('supervisorctl update parity-queue');
        expect($joined)->not->toContain('restart');
        expect($joined)->not->toContain('stop');

        // Only the target program group is ever touched: every supervisorctl
        // invocation is a bare reread or names parity-queue.
        foreach ($calls as $call) {
            expect(
                $call === 'supervisorctl reread'
                    || str_starts_with($call, 'supervisorctl status parity-queue:')
                    || str_starts_with($call, 'supervisorctl update parity-queue')
                    || str_starts_with($call, 'supervisorctl start parity-queue:'),
            )->toBeTrue("unexpected supervisorctl invocation: {$call}");
        }

        // The worker state the stub tracks ends RUNNING.
        expect(file_exists($scratch.'/supervisor-state/queue-running'))->toBeTrue();

        // The deployment success record only exists because both health and
        // the queue requirement held.
        $history = array_values(array_filter(explode("\n", trim(file_get_contents($result['fixture']['root'].'/deployments/history.jsonl')))));
        $finished = json_decode(end($history), true, 512, JSON_THROW_ON_ERROR);
        expect($finished['event'])->toBe('deployment-finished');
        expect($finished['status'])->toBe('success');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('fails the first deployment when supervisor activation cannot reach RUNNING, and recovery stops the partially-activated worker', function () {
    $scratch = deployOpsScratchDir();

    try {
        // The activation-fail toggle makes update/start succeed as commands
        // while the worker never reaches RUNNING — the crash-looping shape.
        deployOpsInstallCoreStubs($scratch);
        touch($scratch.'/supervisor-state/activation-fail');

        $result = deployOpsRunFullDeployment($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('supervisor worker parity-queue did not reach RUNNING');

        $root = $result['fixture']['root'];

        // First-deploy failure recovery: original current did not exist, so
        // current is removed again, the failed release is cleaned, and the
        // newly-activated worker is stopped — never left running against a
        // missing current.
        expect(is_link($root.'/current'))->toBeFalse('current must be removed by first-deploy failure recovery');
        expect(glob($root.'/releases/*'))->toBe([], 'the failed release must be cleaned');
        expect(implode("\n", deployOpsSupervisorctlLog($scratch)))->toContain('supervisorctl stop parity-queue:*');
        expect(file_exists($scratch.'/supervisor-state/queue-running'))->toBeFalse();

        // The failed deployment is recorded with the activation status.
        $history = array_values(array_filter(explode("\n", trim(file_get_contents($root.'/deployments/history.jsonl')))));
        $finished = json_decode(end($history), true, 512, JSON_THROW_ON_ERROR);
        expect($finished['event'])->toBe('deployment-finished');
        expect($finished['status'])->toBe('failed-supervisor-activation');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('performs zero supervisor registration/start churn on a normal deployment whose worker is already RUNNING', function () {
    $scratch = deployOpsScratchDir();

    try {
        // First deployment activates the worker (proven above); this test is
        // about the second, normal deployment on the now-DEPLOYED target.
        $first = deployOpsRunFullDeployment($scratch);
        expect($first['exit'])->toBe(0, $first['output']);
        expect(file_exists($scratch.'/supervisor-state/queue-running'))->toBeTrue();

        file_put_contents($scratch.'/supervisorctl.log', '');

        $confPath = deployOpsDeploymentConfForFixture($scratch);
        [$registryPath, $targetsPath] = deployOpsParityRegistry($scratch, $first['fixture']);
        $healthCheckLog = $scratch.'/second-hc.log';
        touch($healthCheckLog);
        $healthCheckStub = deployOpsHealthCheckStub($scratch, $healthCheckLog);
        $verifyCliLog = $scratch.'/second-vc.log';
        touch($verifyCliLog);
        $verifyCliStub = deployOpsVerifyRequiredClisStub($scratch, $verifyCliLog);

        $secondReleaseId = 'v1.0.1-20260102-000000-def9999';

        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            "parse_deploy_args --target parity-target --release {$secondReleaseId} --artifact {$first['fixture']['artifact']}\nresolve_target\nperform_deploy",
            deployOpsBaseEnv($scratch, [
                'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
                'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                'RATEGURU_TARGETS_CLI' => $targetsPath,
                'RATEGURU_HEALTH_CHECK_BIN' => $healthCheckStub,
                'RATEGURU_VERIFY_REQUIRED_CLIS_BIN' => $verifyCliStub,
            ]),
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('supervisor worker parity-queue already RUNNING — no supervisor registration/start churn');

        // Only read-only status queries ran — no reread, update, start,
        // stop or restart on a healthy worker.
        $calls = deployOpsSupervisorctlLog($scratch);
        expect($calls)->not->toBe([]);

        foreach ($calls as $call) {
            expect(str_starts_with($call, 'supervisorctl status parity-queue:'))
                ->toBeTrue("normal deployment must not run supervisor mutations: {$call}");
        }

        expect(file_exists($scratch.'/supervisor-state/queue-running'))->toBeTrue('the worker must remain RUNNING');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('leaves a previously-RUNNING worker coherent when a later deployment step fails and the old current is restored', function () {
    $scratch = deployOpsScratchDir();

    try {
        $first = deployOpsRunFullDeployment($scratch);
        expect($first['exit'])->toBe(0, $first['output']);

        $root = $first['fixture']['root'];
        $originalCurrent = realpath($root.'/current');

        file_put_contents($scratch.'/supervisorctl.log', '');

        $confPath = deployOpsDeploymentConfForFixture($scratch);
        [$registryPath, $targetsPath] = deployOpsParityRegistry($scratch, $first['fixture']);
        $failingHealthCheckLog = $scratch.'/failing-hc-supervisor.log';
        touch($failingHealthCheckLog);
        $failingHealthCheckStub = deployOpsHealthCheckStub($scratch, $failingHealthCheckLog, fail: true);
        $verifyCliLog = $scratch.'/vc-supervisor.log';
        touch($verifyCliLog);
        $verifyCliStub = deployOpsVerifyRequiredClisStub($scratch, $verifyCliLog);

        $secondReleaseId = 'v1.0.2-20260103-000000-abc7777';

        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            "parse_deploy_args --target parity-target --release {$secondReleaseId} --artifact {$first['fixture']['artifact']}\nresolve_target\nperform_deploy",
            deployOpsBaseEnv($scratch, [
                'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
                'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                'RATEGURU_TARGETS_CLI' => $targetsPath,
                'RATEGURU_HEALTH_CHECK_BIN' => $failingHealthCheckStub,
                'RATEGURU_VERIFY_REQUIRED_CLIS_BIN' => $verifyCliStub,
            ]),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('deployment health check failed');

        // The old current is restored, and the worker that was RUNNING
        // before the deploy — and was never touched by it (the failure came
        // before the activation step) — is still RUNNING against it.
        expect(realpath($root.'/current'))->toBe($originalCurrent);
        expect(file_exists($scratch.'/supervisor-state/queue-running'))->toBeTrue('the pre-existing worker must remain RUNNING');

        $calls = deployOpsSupervisorctlLog($scratch);

        foreach ($calls as $call) {
            expect(str_starts_with($call, 'supervisorctl status parity-queue:'))
                ->toBeTrue("recovery must not mutate the previously-RUNNING worker: {$call}");
        }
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects a planned target before any supervisorctl invocation', function () {
    $scratch = deployOpsScratchDir();

    try {
        deployOpsInstallCoreStubs($scratch);

        [$exit, $output] = deployOpsRunHarness($scratch, <<<'BASH'
            parse_deploy_args --target tits-guru --release v0.0.0-20260101-000000-abc0000 --artifact /definitely/missing.tar.gz
            resolve_target
            perform_deploy
            BASH, deployOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);
        expect($output)->toContain('lifecycle=planned');
        expect(deployOpsSupervisorctlLog($scratch))->toBe([], 'a planned target must never cause a supervisorctl invocation');
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// Slice 5.5: queue transition semantics (CASE A activation vs CASE B restart)
// =============================================================================

it('CASE A — a worker that was not running is activated and is NOT then sent a queue:restart', function () {
    $scratch = deployOpsScratchDir();

    try {
        deployOpsInstallCoreStubs($scratch);
        deployOpsFakePhpBin($scratch);
        @unlink($scratch.'/artisan.log');

        [$exit, $output] = deployOpsRunQueueTransition($scratch, originalRunning: false);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('activating supervisor worker parity-queue');
        expect($output)->toContain('freshly started against the new release — no queue:restart signal needed');
        expect($output)->toContain('TRANSITION_OK activated=true restarted=false');

        // The worker was started by supervisor against the new current, so it
        // already booted the new release — signalling a restart would ask a
        // brand-new worker to exit for nothing.
        expect(file_exists($scratch.'/artisan.log'))->toBeFalse('a freshly started worker must not be sent queue:restart');

        $calls = implode("\n", deployOpsSupervisorctlLog($scratch));
        expect($calls)->toContain('supervisorctl reread');
        expect($calls)->toContain('supervisorctl update parity-queue');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('CASE B — an already-running worker is sent queue:restart with zero supervisor registration churn', function () {
    $scratch = deployOpsScratchDir();

    try {
        deployOpsInstallCoreStubs($scratch);
        deployOpsFakePhpBin($scratch);
        touch($scratch.'/supervisor-state/queue-running');
        @unlink($scratch.'/artisan.log');

        [$exit, $output] = deployOpsRunQueueTransition($scratch, originalRunning: true);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('already RUNNING — no supervisor registration/start churn');
        expect($output)->toContain('queue restart signal issued; Supervisor performs the eventual worker process replacement');
        expect($output)->toContain('TRANSITION_OK activated=false restarted=true');

        // The mandatory signal was written.
        expect(file_get_contents($scratch.'/artisan.log'))->toContain('artisan queue:restart');

        // And no registration/start churn happened — only read-only status.
        foreach (deployOpsSupervisorctlLog($scratch) as $call) {
            expect(str_starts_with($call, 'supervisorctl status parity-queue:'))
                ->toBeTrue("a normal deployment must not reread/update/start: {$call}");
        }
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('CASE B — a queue:restart that cannot be written fails the deployment instead of warning', function () {
    $scratch = deployOpsScratchDir();

    try {
        deployOpsInstallCoreStubs($scratch);
        $failingPhp = deployOpsFailingQueueRestartPhpBin($scratch);
        touch($scratch.'/supervisor-state/queue-running');

        [$exit, $output] = deployOpsRunQueueTransition(
            $scratch,
            originalRunning: true,
            env: ['__php' => $failingPhp],
        );

        expect($exit)->not->toBe(0, 'a failed restart signal must be fatal');
        expect($output)->toContain('artisan queue:restart failed');
        expect($output)->toContain('would keep serving the previous release');
        expect($output)->not->toContain('TRANSITION_OK');

        // Explicitly not the old warning-only behavior.
        expect($output)->not->toContain('WARNING: queue restart command failed');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('does not require immediate PID turnover: a graceful restart with the worker still RUNNING succeeds', function () {
    // Laravel's restart is graceful — the worker finishes its current job and
    // exits afterwards, and Supervisor replaces the process. Requiring
    // turnover here would fail deployments for a worker that is mid-job.
    $scratch = deployOpsScratchDir();

    try {
        deployOpsInstallCoreStubs($scratch);
        deployOpsFakePhpBin($scratch);
        touch($scratch.'/supervisor-state/queue-running');

        [$exit, $output] = deployOpsRunQueueTransition($scratch, originalRunning: true);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('TRANSITION_OK activated=false restarted=true');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('fails the deployment and writes no success history when the worker is not RUNNING after the queue transition', function () {
    $scratch = deployOpsScratchDir();

    try {
        // The worker is RUNNING at snapshot time and gone by the time the
        // transition re-checks it — the "queue did not survive" case.
        deployOpsInstallCoreStubs($scratch);
        touch($scratch.'/supervisor-state/queue-running');
        touch($scratch.'/supervisor-state/drop-after-status');

        $first = deployOpsRunFullDeployment($scratch);

        expect($first['exit'])->not->toBe(0, $first['output']);
        expect($first['output'])->toContain('did not reach RUNNING within the wait budget after the queue restart signal');

        $root = $first['fixture']['root'];

        // No success record; the failure is recorded instead.
        $history = array_values(array_filter(explode("\n", trim(file_get_contents($root.'/deployments/history.jsonl')))));
        $finished = json_decode(end($history), true, 512, JSON_THROW_ON_ERROR);
        expect($finished['event'])->toBe('deployment-finished');
        expect($finished['status'])->toBe('failed-queue-restart');

        foreach ($history as $entry) {
            $row = json_decode($entry, true, 512, JSON_THROW_ON_ERROR);
            expect($row['status'])->not->toBe('success', 'no success history may be written');
        }

        // First deployment: current is removed again by recovery.
        expect(is_link($root.'/current'))->toBeFalse();
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('re-signals the queue against the restored release when a deployment fails after a successful restart signal', function () {
    $scratch = deployOpsScratchDir();

    try {
        deployOpsInstallCoreStubs($scratch);
        deployOpsFakePhpBin($scratch);

        $oldRelease = deployOpsReleaseDirWithArtisan($scratch);
        $newRelease = deployOpsReleaseDirWithArtisan($scratch);
        $target = $scratch.'/recovery-target';
        expect(@mkdir($target.'/deployments', 0o755, true))->toBeTrue();
        symlink($newRelease, $target.'/current');
        touch($scratch.'/supervisor-state/queue-running');
        @unlink($scratch.'/artisan.log');

        // Drive deploy's real recovery handler with the exact state a
        // post-restart failure leaves behind.
        $body = <<<BASH
            SUPERVISOR_PROGRAM=parity-queue
            TARGET_ROOT={$target}
            RELEASE_ID=v9.9.9-20260101-000000-abc0000
            RELEASE_ROOT={$newRelease}
            TEMP_RELEASE_ROOT={$scratch}/nonexistent-temp
            CURRENT_LINK={$target}/current
            PREVIOUS_LINK={$target}/previous
            ORIGINAL_CURRENT_PRESENT=true
            ORIGINAL_CURRENT_PATH={$oldRelease}
            ORIGINAL_PREVIOUS_PRESENT=false
            ORIGINAL_PREVIOUS_PATH=""
            ORIGINAL_QUEUE_RUNNING=true
            SUPERVISOR_ACTIVATED_NOW=false
            QUEUE_RESTART_ISSUED=true
            DEPLOYMENT_STARTED=true
            CURRENT_SWITCHED=true
            TERMINAL_HISTORY_WRITTEN=false
            FAILURE_STATUS=failed-queue-restart
            RUNTIME_USER="\$(id -un)"
            PHP_BIN={$scratch}/bin/fake-php
            # set +e so the deliberate failure status reaches the handler
            # instead of aborting the harness under set -e.
            set +e
            false
            handle_deployment_exit
            BASH;

        [$exit, $output] = deployOpsRunHarness($scratch, $body, deployOpsBaseEnv($scratch));

        expect($exit)->not->toBe(0);

        // The old current is restored...
        expect(realpath($target.'/current'))->toBe(realpath($oldRelease));

        // ...and the queue was re-signalled from the RESTORED release, so the
        // workers end up coherent with the release actually being served.
        expect($output)->toContain('re-issuing the queue restart signal from the restored release');
        expect(file_get_contents($scratch.'/artisan.log'))->toContain('artisan queue:restart');

        // Only the target program was involved — no unrelated supervisor
        // program and no stop/start of anything.
        foreach (deployOpsSupervisorctlLog($scratch) as $call) {
            expect(str_starts_with($call, 'supervisorctl status parity-queue:'))
                ->toBeTrue("recovery must not mutate supervisor state here: {$call}");
        }
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// Slice 5.5: the transient STARTING race after a normal queue:restart
// =============================================================================

/**
 * A full, real Laravel deployment onto a target whose worker is already
 * RUNNING — the CASE B path end to end, including artisan queue:restart and
 * the deployment history record.
 *
 * @param  list<string>  $statusSequence  Supervisor states, one per status call.
 * @return array{exit:int, output:string, root:string, releaseId:string}
 */
function deployOpsRunNormalLaravelDeployment(string $scratch, array $statusSequence, string $phpBin = ''): array
{
    $fixture = deployOpsBuildFixture($scratch, laravel: true);
    $confPath = deployOpsDeploymentConfForFixture($scratch);
    deployOpsInstallCoreStubs($scratch);
    deployOpsInstallGroupShimStub($scratch);
    $phpBin = $phpBin !== '' ? $phpBin : deployOpsFakePhpBin($scratch);
    file_put_contents(
        $confPath,
        preg_replace('/^PHP_BIN=.*$/m', 'PHP_BIN='.$phpBin, File::get($confPath)),
    );
    [$registryPath, $targetsPath] = deployOpsParityRegistry($scratch, $fixture);

    $healthCheckLog = $scratch.'/hc-normal.log';
    touch($healthCheckLog);
    $verifyCliLog = $scratch.'/vc-normal.log';
    touch($verifyCliLog);

    // The target is already DEPLOYED with a RUNNING worker: `current` exists
    // and the first scripted status (the pre-deploy snapshot) is RUNNING.
    mkdir($fixture['root'].'/releases/previous-release', 0o755, true);
    symlink($fixture['root'].'/releases/previous-release', $fixture['root'].'/current');
    deployOpsSupervisorStatusSequence($scratch, $statusSequence);

    $releaseId = 'v2.0.0-20260201-000000-abc'.random_int(1000, 9999);

    [$exit, $output] = deployOpsRunHarness(
        $scratch,
        "parse_deploy_args --target parity-target --release {$releaseId} --artifact {$fixture['artifact']}\nresolve_target\nperform_deploy",
        deployOpsBaseEnv($scratch, [
            'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
            'RATEGURU_HEALTH_CHECK_BIN' => deployOpsHealthCheckStub($scratch, $healthCheckLog),
            'RATEGURU_VERIFY_REQUIRED_CLIS_BIN' => deployOpsVerifyRequiredClisStub($scratch, $verifyCliLog),
            // Enough attempts to outlast a transient STARTING, with no delay.
            'RATEGURU_DEPLOY_QUEUE_WAIT_ATTEMPTS' => '5',
            'RATEGURU_DEPLOY_QUEUE_RETRY_DELAY' => '0',
        ]),
    );

    return ['exit' => $exit, 'output' => $output, 'root' => $fixture['root'], 'releaseId' => $releaseId];
}

/** @return list<array<string, mixed>> */
function deployOpsHistory(string $root): array
{
    $raw = trim((string) @file_get_contents($root.'/deployments/history.jsonl'));

    if ($raw === '') {
        return [];
    }

    return array_map(
        fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", $raw))),
    );
}

it('succeeds when the replacement worker is transiently STARTING after a normal queue:restart', function () {
    // The committed program has autorestart=true and startsecs=3: an idle
    // worker exits the moment Laravel signals it, and Supervisor's replacement
    // legitimately sits in STARTING until it survives startsecs. A single
    // immediate status check would see STARTING and roll back a healthy
    // deployment.
    $scratch = deployOpsScratchDir();

    try {
        $result = deployOpsRunNormalLaravelDeployment($scratch, [
            'RUNNING',   // pre-deploy snapshot: worker was already running
            'STARTING',  // replacement spawned, not yet past startsecs
            'STARTING',
            'RUNNING',   // promoted
        ]);

        expect($result['exit'])->toBe(0, $result['output']);

        // CASE B: the mandatory restart signal was issued...
        expect(file_get_contents($scratch.'/artisan.log'))->toContain('artisan queue:restart');

        // ...with zero supervisor registration churn.
        foreach (deployOpsSupervisorctlLog($scratch) as $call) {
            expect(str_starts_with($call, 'supervisorctl status parity-queue:'))
                ->toBeTrue("normal deploy must not reread/update/start: {$call}");
        }

        // Success was recorded, and nothing was rolled back.
        $history = deployOpsHistory($result['root']);
        $finished = end($history);
        expect($finished['event'])->toBe('deployment-finished');
        expect($finished['status'])->toBe('success');
        expect($finished['release'])->toBe($result['releaseId']);

        expect(realpath($result['root'].'/current'))
            ->toBe(realpath($result['root'].'/releases/'.$result['releaseId']), 'current must point at the new release');
        expect(is_dir($result['root'].'/releases/'.$result['releaseId']))->toBeTrue();
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('fails the deployment when the worker never reaches RUNNING within the wait budget', function () {
    // An endlessly STARTING (or backing-off) process is not success — the
    // bounded wait must expire and the deployment must fail.
    foreach ([
        ['RUNNING', 'STARTING'],
        ['RUNNING', 'BACKOFF'],
        ['RUNNING', 'STOPPED'],
    ] as $sequence) {
        $scratch = deployOpsScratchDir();

        try {
            $result = deployOpsRunNormalLaravelDeployment($scratch, $sequence);

            expect($result['exit'])->not->toBe(0, "sequence {$sequence[1]} must fail:\n{$result['output']}");
            expect($result['output'])->toContain('did not reach RUNNING within the wait budget after the queue restart signal');

            // The restart signal was issued before the failure — this is the
            // post-signal operational check failing, not the signal itself.
            expect(file_get_contents($scratch.'/artisan.log'))->toContain('artisan queue:restart');

            $history = deployOpsHistory($result['root']);
            $finished = end($history);
            expect($finished['status'])->toBe('failed-queue-restart');

            foreach ($history as $entry) {
                expect($entry['status'])->not->toBe('success', 'no success history may be written');
            }

            // Normal recovery ran: the previous release is current again.
            expect(realpath($result['root'].'/current'))
                ->toBe(realpath($result['root'].'/releases/previous-release'));

            // The worker never confirmed healthy, so recovery is degraded and
            // deliberately does NOT delete the failed release — a worker may
            // still be executing inside it. The degradation is surfaced, not
            // absorbed.
            expect($result['output'])->toContain('is not RUNNING after recovery — investigate manually');
            expect(is_dir($result['root'].'/releases/'.$result['releaseId']))
                ->toBeTrue('a release must never be deleted while a worker may still be running in it');
        } finally {
            deployOpsCleanup($scratch);
        }
    }
});

it('does not report degraded recovery when the worker is transiently STARTING after a recovery restart', function () {
    $scratch = deployOpsScratchDir();

    try {
        deployOpsInstallCoreStubs($scratch);
        deployOpsFakePhpBin($scratch);

        $oldRelease = deployOpsReleaseDirWithArtisan($scratch);
        $newRelease = deployOpsReleaseDirWithArtisan($scratch);
        $target = $scratch.'/recovery-transient';
        expect(@mkdir($target.'/deployments', 0o755, true))->toBeTrue();
        symlink($newRelease, $target.'/current');

        // Recovery re-signals, then observes STARTING twice before RUNNING.
        deployOpsSupervisorStatusSequence($scratch, ['STARTING', 'STARTING', 'RUNNING']);

        $body = <<<BASH
            SUPERVISOR_PROGRAM=parity-queue
            TARGET_ROOT={$target}
            RELEASE_ID=v9.9.9-20260101-000000-abc0000
            RELEASE_ROOT={$newRelease}
            TEMP_RELEASE_ROOT={$scratch}/nonexistent-temp
            CURRENT_LINK={$target}/current
            PREVIOUS_LINK={$target}/previous
            ORIGINAL_CURRENT_PRESENT=true
            ORIGINAL_CURRENT_PATH={$oldRelease}
            ORIGINAL_PREVIOUS_PRESENT=false
            ORIGINAL_PREVIOUS_PATH=""
            ORIGINAL_QUEUE_RUNNING=true
            SUPERVISOR_ACTIVATED_NOW=false
            QUEUE_RESTART_ISSUED=true
            DEPLOYMENT_STARTED=true
            CURRENT_SWITCHED=true
            TERMINAL_HISTORY_WRITTEN=false
            FAILURE_STATUS=failed-queue-restart
            RUNTIME_USER="\$(id -un)"
            PHP_BIN={$scratch}/bin/fake-php
            set +e
            false
            handle_deployment_exit
            BASH;

        [, $output] = deployOpsRunHarness($scratch, $body, deployOpsBaseEnv($scratch, [
            'RATEGURU_DEPLOY_QUEUE_WAIT_ATTEMPTS' => '5',
            'RATEGURU_DEPLOY_QUEUE_RETRY_DELAY' => '0',
        ]));

        // Recovery re-signalled from the restored release and then waited out
        // the transient STARTING instead of declaring itself degraded.
        expect($output)->toContain('re-issuing the queue restart signal from the restored release');
        expect($output)->not->toContain('is not RUNNING after recovery');
        expect($output)->not->toContain('unable to fully restore deployment symlinks');

        expect(realpath($target.'/current'))->toBe(realpath($oldRelease));

        // Still no force-start and no unrelated program.
        foreach (deployOpsSupervisorctlLog($scratch) as $call) {
            expect(str_starts_with($call, 'supervisorctl status parity-queue:'))
                ->toBeTrue("recovery must not mutate supervisor state: {$call}");
        }
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('still warns when the worker never returns to RUNNING after recovery', function () {
    $scratch = deployOpsScratchDir();

    try {
        deployOpsInstallCoreStubs($scratch);
        deployOpsFakePhpBin($scratch);

        $oldRelease = deployOpsReleaseDirWithArtisan($scratch);
        $newRelease = deployOpsReleaseDirWithArtisan($scratch);
        $target = $scratch.'/recovery-degraded';
        expect(@mkdir($target.'/deployments', 0o755, true))->toBeTrue();
        symlink($newRelease, $target.'/current');

        // Never promoted.
        deployOpsSupervisorStatusSequence($scratch, ['STARTING']);

        $body = <<<BASH
            SUPERVISOR_PROGRAM=parity-queue
            TARGET_ROOT={$target}
            RELEASE_ID=v9.9.9-20260101-000000-abc0000
            RELEASE_ROOT={$newRelease}
            TEMP_RELEASE_ROOT={$scratch}/nonexistent-temp
            CURRENT_LINK={$target}/current
            PREVIOUS_LINK={$target}/previous
            ORIGINAL_CURRENT_PRESENT=true
            ORIGINAL_CURRENT_PATH={$oldRelease}
            ORIGINAL_PREVIOUS_PRESENT=false
            ORIGINAL_PREVIOUS_PATH=""
            ORIGINAL_QUEUE_RUNNING=true
            SUPERVISOR_ACTIVATED_NOW=false
            QUEUE_RESTART_ISSUED=true
            DEPLOYMENT_STARTED=true
            CURRENT_SWITCHED=true
            TERMINAL_HISTORY_WRITTEN=false
            FAILURE_STATUS=failed-queue-restart
            RUNTIME_USER="\$(id -un)"
            PHP_BIN={$scratch}/bin/fake-php
            set +e
            false
            handle_deployment_exit
            BASH;

        [, $output] = deployOpsRunHarness($scratch, $body, deployOpsBaseEnv($scratch, [
            'RATEGURU_DEPLOY_QUEUE_WAIT_ATTEMPTS' => '2',
            'RATEGURU_DEPLOY_QUEUE_RETRY_DELAY' => '0',
        ]));

        expect($output)->toContain('is not RUNNING after recovery — investigate manually');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('waits out a transient STARTING during first-deploy activation too, without ever signalling a restart', function () {
    $scratch = deployOpsScratchDir();

    try {
        deployOpsInstallCoreStubs($scratch);
        deployOpsFakePhpBin($scratch);
        @unlink($scratch.'/artisan.log');

        // Not running at snapshot, then STARTING after update, then RUNNING.
        deployOpsSupervisorStatusSequence($scratch, ['STOPPED', 'STARTING', 'STARTING', 'RUNNING']);

        [$exit, $output] = deployOpsRunQueueTransition($scratch, originalRunning: false, env: [
            'RATEGURU_DEPLOY_QUEUE_WAIT_ATTEMPTS' => '5',
            'RATEGURU_DEPLOY_QUEUE_RETRY_DELAY' => '0',
        ]);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('TRANSITION_OK activated=true restarted=false');

        // Activation happened, and a freshly started worker is never signalled.
        $calls = implode("\n", deployOpsSupervisorctlLog($scratch));
        expect($calls)->toContain('supervisorctl update parity-queue');
        expect(file_exists($scratch.'/artisan.log'))->toBeFalse('a freshly started worker must not be sent queue:restart');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('resolves the supervisor program from the target registry, never a hard-coded name', function () {
    // The generic pipeline references the registry accessor and the resolved
    // variable — the literal staging program name appears nowhere in deploy.
    $source = deployOpsSource();

    expect($source)->toContain('target_supervisor_program');
    expect($source)->toContain('${SUPERVISOR_PROGRAM}');
    expect($source)->not->toContain('rateguru-staging-queue');
    expect($source)->not->toContain('restart all');
});

it('gates the supervisor wait tuning behind the shared test-override flag', function () {
    $scratch = deployOpsScratchDir();

    try {
        // Granted: with the shared flag on, the tuning values are honored.
        [$exit, $output] = deployOpsRunHarness($scratch, <<<'BASH'
            printf 'ATTEMPTS=%s DELAY=%s\n' "${QUEUE_WAIT_ATTEMPTS}" "${QUEUE_RETRY_DELAY_SECONDS}"
            BASH, deployOpsBaseEnv($scratch, [
            'RATEGURU_DEPLOY_QUEUE_WAIT_ATTEMPTS' => '7',
            'RATEGURU_DEPLOY_QUEUE_RETRY_DELAY' => '2',
        ]));

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('ATTEMPTS=7 DELAY=2');

        // Denied: without the flag a real subprocess never honors any
        // override — proven by failing at the hardcoded COMMON_FILE default
        // (the same technique the existing override-gate test uses).
        [$deniedExit, $deniedOutput] = deployOpsRunScript(['--help'], [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_COMMON_FILE' => deployOpsCommonFile(),
            'RATEGURU_DEPLOY_QUEUE_WAIT_ATTEMPTS' => '999',
            'RATEGURU_DEPLOY_QUEUE_RETRY_DELAY' => '999',
        ]);
        expect($deniedExit)->not->toBe(0);
        expect($deniedOutput)->toContain('/home/www/rateguru/bin/common');
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// Out of scope: nothing else changed
//
// A prior version of this file had its own "leaves sudoers and workflows
// untouched" test here, checking infrastructure/config/sudoers/rateguru-deploy
// and .github/workflows/deploy-staging.yml against origin/develop. Both
// legitimately graduated to the generic wrappers/--target staging-main in
// the target-aware migration (see TargetPerimeterTest.php), leaving that test with an
// empty $unchanged list — it was deleted outright rather than left as a
// vacuous loop, matching the precedent already set elsewhere in this file's
// own history for scripts that graduate out of "still legacy-only".
// =============================================================================

// =============================================================================
// The first-deploy permission contract (the clean-VPS blocker #2).
//
// A clean VPS bootstrapped through 5.2/5.3/5.4 reached PRE_DEPLOY READY, the
// first deployment switched current, and then all 10 health checks returned
// HTTP 404. The Nginx error log showed the real cause:
//
//   stat() ".../current/public/index.php" failed (13: Permission denied)
//
// The release modes were correct. The identity model was not: www-data was
// in its own group only, so Nginx workers could not traverse the 0750 tree.
// Both halves of that contract are asserted together here, because either
// half alone is what made the failure so hard to see.
// =============================================================================

it('produces a release tree exactly two identities must be able to read, and requires both of them', function () {
    $scratch = deployOpsScratchDir();

    try {
        $result = deployOpsRunFullDeployment($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        $root = $result['fixture']['root'];
        $releaseRoot = $root.'/releases/'.$result['releaseId'];

        // --- half one: what deploy actually writes -----------------------
        clearstatcache();

        expect(substr(sprintf('%o', fileperms($releaseRoot)), -4))
            ->toBe('0750', 'release directory must stay group-readable/traversable only');
        expect(substr(sprintf('%o', fileperms($releaseRoot.'/public')), -4))
            ->toBe('0750', 'public directory must stay group-traversable only');
        expect(substr(sprintf('%o', fileperms($releaseRoot.'/public/index.php')), -4))
            ->toBe('0640', 'index.php must stay group-readable only — never world-readable');

        // Owned by the deploy identity, grouped to the code group. In this
        // fixture both map to the test account/group (see
        // deployOpsParityRegistry), which is what makes the chown assertions
        // runnable without root.
        $stat = stat($releaseRoot);
        expect($stat['uid'])->toBe(getmyuid(), 'releases are owned by the deploy user');
        expect($stat['gid'])->toBe(getmygid(), 'releases are grouped to the code group');

        // Nothing was widened to world access to make serving work.
        foreach ([$releaseRoot, $releaseRoot.'/public', $releaseRoot.'/public/index.php'] as $path) {
            expect(fileperms($path) & 0o007)->toBe(0, "world access must never be granted: {$path}");
        }

        // --- half two: who must be able to read it -----------------------
        // Because the tree is 0750/0640 and owned by the deploy user, every
        // consumer reaches it through the code group — and there are exactly
        // two: PHP-FPM/queue as the runtime user, and Nginx as www-data.
        $hostLayout = File::get(base_path('infrastructure/scripts/install-bootstrap-host-layout'));

        expect($hostLayout)->toContain('required_code_group_memberships');
        expect($hostLayout)->toContain('${WWW_DATA_USER}');
        expect($hostLayout)->toContain('WWW_DATA_USER="www-data"');

        // www-data reads immutable code only — it never joins a runtime
        // group, which would hand it the shared mutable state as well.
        expect($hostLayout)->not->toMatch('/--groups\s+"\$\{TGT_RUNTIME_GROUP/');

        // The preflight asserts the same two relations, so the installer and
        // the read-only inspection can never disagree about them.
        $preflight = File::get(base_path('infrastructure/scripts/bootstrap-host-preflight'));
        expect($preflight)->toContain('report_membership "www-data" "${TGT_CODE_GROUP[${target_id}]}"');

        // And the shared mutable boundary stays independent: a narrow ACL,
        // never a group membership and never a chmod.
        expect($preflight)->toContain('user:www-data:--x');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('keeps the post-switch health check fatal, so a release Nginx cannot serve still fails the deployment', function () {
    // The clean-VPS deployment correctly failed and rolled back. That is the
    // behaviour to preserve: a 404 after the current switch is never an
    // accepted PRE_DEPLOY state.
    $scratch = deployOpsScratchDir();

    try {
        $first = deployOpsRunFullDeployment($scratch);
        expect($first['exit'])->toBe(0, $first['output']);

        $root = $first['fixture']['root'];
        $originalCurrent = realpath($root.'/current');

        $confPath = deployOpsDeploymentConfForFixture($scratch);
        [$registryPath, $targetsPath] = deployOpsParityRegistry($scratch, $first['fixture']);
        deployOpsInstallCoreStubs($scratch);
        $failingLog = $scratch.'/failing-hc-permissions.log';
        touch($failingLog);
        $verifyLog = $scratch.'/vc-permissions.log';
        touch($verifyLog);

        $releaseId = 'v3.0.0-20260301-000000-abc'.random_int(1000, 9999);

        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            "parse_deploy_args --target parity-target --release {$releaseId} --artifact {$first['fixture']['artifact']}\nresolve_target\nperform_deploy",
            deployOpsBaseEnv($scratch, [
                'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
                'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
                'RATEGURU_TARGETS_CLI' => $targetsPath,
                'RATEGURU_HEALTH_CHECK_BIN' => deployOpsHealthCheckStub($scratch, $failingLog, fail: true),
                'RATEGURU_VERIFY_REQUIRED_CLIS_BIN' => deployOpsVerifyRequiredClisStub($scratch, $verifyLog),
            ]),
        );

        expect($exit)->not->toBe(0, 'an unservable release must fail the deployment');
        expect($output)->toContain('deployment health check failed');

        // Rolled back to the previous release, failure recorded, no success.
        expect(realpath($root.'/current'))->toBe($originalCurrent);
        expect(is_dir($root.'/releases/'.$releaseId))->toBeFalse();

        $history = deployOpsHistory($root);
        $finished = end($history);
        expect($finished['status'])->toBe('failed-health-check');

        foreach ($history as $entry) {
            expect($entry['release'] === $releaseId && $entry['status'] === 'success')->toBeFalse();
        }
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// the controlled code alignment: the restore interlock and controlled code alignment
// =============================================================================
//
// Two behaviours, one gate. While a target is held after a restore its live
// data may belong to a different commit than `current` serves, so an ORDINARY
// deployment refuses outright — it would health-check the target, transition
// its queue and bring it back up, fighting the hold that exists precisely to
// stop anything running against data whose code has not arrived yet.
//
// The one deployment that proceeds is the controlled alignment, and it is the
// SAME script with the SAME mechanics: artifact verification, path safety,
// extraction, permissions, links, the CLI mode check, the immutable release,
// the atomic current switch and the PHP-FPM reload all run unchanged. What
// differs is everything after the switch — nothing that resumes the target.
//
// The commit is never an argument. deploy reads it out of the server's own
// restore guard and the operation's own state.json, and independently requires
// restore-target --inspect (the single implementation of "this target is
// genuinely still held") to name the same operation, target and commit. The
// runtime half of that proof — maintenance on, queue provably fully STOPPED,
// no scheduler entry — is exercised against the real observer in
// RestoreTargetTest's own --inspect coverage; what is proven here is that
// deploy demands it, believes nothing else, and refuses on any disagreement.

const DEPLOY_OPS_RESTORE_OPERATION = '20260901-101112-a1b2c3';
const DEPLOY_OPS_REQUIRED_SHA = 'a81d7f2c3b4a5968778899aabbccddeeff001122';
const DEPLOY_OPS_CURRENT_SHA = 'b92e8a3d4c5a6a79889900bbccddeeff11223344';
const DEPLOY_OPS_ALIGNMENT_RELEASE = 'v1.4.0-20260101-000000-a81d7f2';

function deployOpsRunRoot(string $scratch): string
{
    return $scratch.'/run';
}

function deployOpsGuardPath(string $scratch): string
{
    return deployOpsRunRoot($scratch).'/restores/parity-target/restore-guard';
}

/** @param  array<string, string|null>  $overrides */
function deployOpsWriteRestoreGuard(string $scratch, array $overrides = []): void
{
    $path = deployOpsGuardPath($scratch);

    if (! is_dir(dirname($path))) {
        expect(@mkdir(dirname($path), 0o700, true))->toBeTrue('could not create the restore run root');
    }

    $guard = array_filter(array_merge([
        'operation' => DEPLOY_OPS_RESTORE_OPERATION,
        'target' => 'parity-target',
        'required_source_sha' => DEPLOY_OPS_REQUIRED_SHA,
        'status' => 'held',
        'created_at' => '2026-09-01T10:11:12Z',
    ], $overrides), static fn ($value): bool => $value !== null);

    file_put_contents($path, json_encode($guard, JSON_PRETTY_PRINT));
    chmod($path, 0o600);
}

function deployOpsStatePath(string $scratch, string $operation = DEPLOY_OPS_RESTORE_OPERATION): string
{
    return deployOpsRunRoot($scratch).'/restores/parity-target/'.$operation.'/state.json';
}

/** @param  array<string, string|null>  $overrides */
function deployOpsWriteRestoreState(string $scratch, array $overrides = []): void
{
    $path = deployOpsStatePath($scratch);

    if (! is_dir(dirname($path))) {
        expect(@mkdir(dirname($path), 0o700, true))->toBeTrue('could not create the restore operation workspace');
    }

    $state = array_filter(array_merge([
        'operation_id' => DEPLOY_OPS_RESTORE_OPERATION,
        'target' => 'parity-target',
        'environment' => 'staging',
        'backup_namespace' => 'parity',
        'source' => 'offsite',
        'backup' => '20260115-120000',
        'backup_release' => DEPLOY_OPS_ALIGNMENT_RELEASE,
        'backup_source_sha' => DEPLOY_OPS_REQUIRED_SHA,
        'status' => 'held',
        'phase' => 'committed',
        'code_alignment' => 'required',
        'runtime_resumed' => 'no',
    ], $overrides), static fn ($value): bool => $value !== null);

    file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT));
    chmod($path, 0o600);
}

/** Both restore documents in the one state a controlled alignment may run in. */
function deployOpsWriteHeldRestore(string $scratch, array $guard = [], array $state = []): void
{
    deployOpsWriteRestoreGuard($scratch, $guard);
    deployOpsWriteRestoreState($scratch, $state);
}

/**
 * A stand-in for the installed restore-target, exercised through deploy's own
 * gated RATEGURU_RESTORE_TARGET_BIN seam — the identical technique this file
 * already uses for health-check and verify-required-clis. It records the exact
 * argv deploy invoked it with, so a test can prove deploy really asks the one
 * implementation of the hold proof rather than deciding for itself.
 */
function deployOpsRestoreTargetStub(string $scratch, string $logFile, array $options = []): string
{
    $path = $scratch.'/bin/restore-target-'.uniqid('', true);
    $exit = (int) ($options['exit'] ?? 0);

    $lines = $options['lines'] ?? ['RATEGURU_RESTORE_RESULT='.json_encode(array_merge([
        'status' => 'held',
        'operation_id' => DEPLOY_OPS_RESTORE_OPERATION,
        'target' => 'parity-target',
        'backup' => '20260115-120000',
        'backup_release' => DEPLOY_OPS_ALIGNMENT_RELEASE,
        'backup_source_sha' => DEPLOY_OPS_REQUIRED_SHA,
        'required_source_sha' => DEPLOY_OPS_REQUIRED_SHA,
        'current_source_sha' => DEPLOY_OPS_CURRENT_SHA,
        'code_alignment' => 'REQUIRED',
        'runtime_resumed' => 'no',
    ], $options['result'] ?? []))];

    $body = "#!/usr/bin/env bash\n"
        .'echo "restore-target $*" >> '.escapeshellarg($logFile)."\n";

    foreach ($lines as $line) {
        $body .= 'printf "%s\n" '.escapeshellarg($line)."\n";
    }

    $body .= "exit {$exit}\n";

    file_put_contents($path, $body);
    chmod($path, 0o755);

    return $path;
}

/**
 * A release artifact carrying release.json — what a real build produces, and
 * what a controlled alignment is checked against. Deliberately without
 * artisan, so deploy's Laravel preparation (whose `install -g www-data` needs
 * a membership CI does not have) stays out of these tests: everything they
 * assert happens before and after it.
 *
 * @return array{artifact: string, checksum: string}
 */
function deployOpsAlignmentArtifact(
    string $scratch,
    array $fixture,
    string $releaseId = DEPLOY_OPS_ALIGNMENT_RELEASE,
    ?string $sourceSha = DEPLOY_OPS_REQUIRED_SHA,
    bool $withReleaseJson = true,
): array {
    $id = uniqid('', true);
    $source = $scratch.'/alignment-src-'.$id;
    expect(@mkdir($source.'/public', 0o755, true))->toBeTrue('could not create the alignment artifact source');
    file_put_contents($source.'/public/index.php', "<?php // fixture\n");

    $entries = 'public';

    if ($withReleaseJson) {
        file_put_contents($source.'/release.json', json_encode(array_filter([
            'project' => 'rateguru',
            'release' => $releaseId,
            'source_sha' => $sourceSha,
        ], static fn ($value): bool => $value !== null)));
        $entries .= ' release.json';
    }

    $artifact = $fixture['incoming'].'/rateguru-alignment-'.$id.'.tar.gz';

    exec('tar -C '.escapeshellarg($source).' -czf '.escapeshellarg($artifact)." {$entries} 2>&1", $tarOutput, $tarExit);
    expect($tarExit)->toBe(0, "failed to build the alignment artifact:\n".implode("\n", $tarOutput));

    exec(
        'cd '.escapeshellarg($fixture['incoming'])
            .' && sha256sum '.escapeshellarg(basename($artifact))
            .' > '.escapeshellarg(basename($artifact).'.sha256').' 2>&1',
        $shaOutput,
        $shaExit,
    );
    expect($shaExit)->toBe(0, "failed to checksum the alignment artifact:\n".implode("\n", $shaOutput));

    return ['artifact' => $artifact, 'checksum' => $artifact.'.sha256'];
}

/**
 * One real deploy invocation against an existing fixture — the same
 * parse_deploy_args/resolve_target/perform_deploy pipeline every other test in
 * this file drives, with RUN_ROOT pointed at the scratch restore root.
 *
 * @return array{exit: int, output: string, healthCheckLog: string, restoreTargetLog: string}
 */
function deployOpsRunDeployOn(string $scratch, array $fixture, string $arguments, array $options = []): array
{
    $confPath = deployOpsDeploymentConfForFixture($scratch);
    deployOpsInstallCoreStubs($scratch);
    [$registryPath, $targetsPath] = deployOpsParityRegistry($scratch, $fixture);

    // Installed after the core stubs, which would otherwise overwrite it.
    if (($options['systemctl_fails'] ?? false) === true) {
        file_put_contents($scratch.'/bin/systemctl', "#!/usr/bin/env bash\n"
            .'echo "systemctl $*" >> '.escapeshellarg($scratch.'/systemctl.log')."\n"
            ."exit 1\n");
        chmod($scratch.'/bin/systemctl', 0o755);
    }

    $healthCheckLog = $scratch.'/health-check-'.uniqid('', true).'.log';
    touch($healthCheckLog);
    $verifyCliLog = $scratch.'/verify-cli-'.uniqid('', true).'.log';
    touch($verifyCliLog);
    $restoreTargetLog = $scratch.'/restore-target-'.uniqid('', true).'.log';
    touch($restoreTargetLog);

    $verifyStub = ($options['verify_clis_fail'] ?? false) === true
        ? deployOpsFailingVerifyRequiredClisStub($scratch, $verifyCliLog)
        : deployOpsVerifyRequiredClisStub($scratch, $verifyCliLog);

    $env = deployOpsBaseEnv($scratch, array_merge([
        'RATEGURU_DEPLOYMENT_CONF_FILE' => $confPath,
        'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
        'RATEGURU_TARGETS_CLI' => $targetsPath,
        'RATEGURU_HEALTH_CHECK_BIN' => deployOpsHealthCheckStub($scratch, $healthCheckLog),
        'RATEGURU_VERIFY_REQUIRED_CLIS_BIN' => $verifyStub,
        'RATEGURU_RUN_ROOT' => deployOpsRunRoot($scratch),
        'RATEGURU_RESTORE_TARGET_BIN' => deployOpsRestoreTargetStub(
            $scratch,
            $restoreTargetLog,
            $options['restore_target'] ?? [],
        ),
    ], $options['env'] ?? []));

    [$exit, $output] = deployOpsRunHarness(
        $scratch,
        "parse_deploy_args {$arguments}\nresolve_target\nperform_deploy",
        $env,
    );

    return [
        'exit' => $exit,
        'output' => $output,
        'healthCheckLog' => $healthCheckLog,
        'restoreTargetLog' => $restoreTargetLog,
    ];
}

function deployOpsFailingVerifyRequiredClisStub(string $scratch, string $logFile): string
{
    $path = $scratch.'/bin/verify-required-clis-failing';
    file_put_contents($path, "#!/usr/bin/env bash\n"
        .'echo "$*" >> '.escapeshellarg($logFile)."\n"
        ."echo 'a required CLI lost its executable bit' >&2\n"
        ."exit 1\n");
    chmod($path, 0o755);

    return $path;
}

/** A target already serving something, so an alignment has a real current to replace. */
function deployOpsServingFixture(string $scratch): array
{
    $fixture = deployOpsBuildFixture($scratch);

    $first = deployOpsRunDeployOn(
        $scratch,
        $fixture,
        '--target parity-target --release v2.0.0-20260201-000000-bbbb111 --artifact '.$fixture['artifact'],
    );
    expect($first['exit'])->toBe(0, $first['output']);

    return $fixture;
}

// --- normal mode: the interlock ---------------------------------------------

it('deploys normally when no target is held', function () {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsBuildFixture($scratch);

        // The restore run root exists and is empty — the ordinary state of a
        // host that has never had a restore held. Nothing about the normal
        // deployment path changes.
        expect(@mkdir(deployOpsRunRoot($scratch), 0o700, true))->toBeTrue();

        $result = deployOpsRunDeployOn(
            $scratch,
            $fixture,
            '--target parity-target --release v1.0.0-20260101-000000-aaa1111 --artifact '.$fixture['artifact'],
        );

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])->toContain('deployed successfully');

        // The normal contract in full: the health check ran, and the history
        // is the ordinary deployment pair.
        expect(File::get($result['healthCheckLog']))->toContain('--target parity-target');
        expect(File::get($result['restoreTargetLog']))->toBe('', 'a normal deployment never consults restore-target');

        $history = deployOpsHistory($fixture['root']);
        expect(array_column($history, 'event'))->toBe(['deployment-started', 'deployment-finished']);
        expect(end($history)['status'])->toBe('success');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('refuses an ordinary deployment while the target is held, before any mutation', function () {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsBuildFixture($scratch);
        deployOpsWriteHeldRestore($scratch);

        $releaseId = 'v1.0.0-20260101-000000-aaa2222';

        $result = deployOpsRunDeployOn(
            $scratch,
            $fixture,
            "--target parity-target --release {$releaseId} --artifact ".$fixture['artifact'],
        );

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('is held after restore operation '.DEPLOY_OPS_RESTORE_OPERATION)
            ->toContain('Controlled code alignment is required')
            ->toContain('mode=continue-held');

        // Before any mutation means exactly that: no release directory, no
        // staged temporary tree, no history record at all, and `current`
        // untouched.
        expect(is_dir($fixture['root'].'/releases/'.$releaseId))->toBeFalse();
        expect(glob($fixture['root'].'/releases/.*.tmp-*') ?: [])->toBe([]);
        expect(deployOpsHistory($fixture['root']))->toBe([]);
        expect(is_link($fixture['root'].'/current'))->toBeFalse();
        expect(File::get($result['healthCheckLog']))->toBe('');
    } finally {
        deployOpsCleanup($scratch);
    }
});

// --- alignment mode: authorization -------------------------------------------

it('refuses --restore-operation together with --migrate, before anything is resolved', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target parity-target --release v1.0.0-20260101-000000-aaa3333'
                .' --artifact /tmp/x.tar.gz --migrate --restore-operation '.DEPLOY_OPS_RESTORE_OPERATION,
            deployOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--migrate and --restore-operation are mutually exclusive');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('rejects a malformed restore operation ID during parsing', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target parity-target --release v1.0.0-20260101-000000-aaa4444'
                .' --artifact /tmp/x.tar.gz --restore-operation ../../etc/passwd',
            deployOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('invalid restore operation ID');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('refuses a controlled alignment whose restore documents do not authorize it', function (
    string $case,
    string $expected,
) {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsBuildFixture($scratch);
        $artifact = deployOpsAlignmentArtifact($scratch, $fixture);
        $operation = DEPLOY_OPS_RESTORE_OPERATION;

        deployOpsWriteHeldRestore($scratch);

        switch ($case) {
            case 'no-guard':
                unlink(deployOpsGuardPath($scratch));
                break;

            case 'guard-names-another-operation':
                deployOpsWriteRestoreGuard($scratch, ['operation' => '20200101-000000-ffffff']);
                break;

            case 'guard-names-another-target':
                deployOpsWriteRestoreGuard($scratch, ['target' => 'other-target']);
                break;

            case 'guard-is-in-progress':
                deployOpsWriteRestoreGuard($scratch, ['status' => 'in-progress']);
                break;

            case 'guard-is-failed-held':
                deployOpsWriteRestoreGuard($scratch, ['status' => 'failed-held']);
                break;

            case 'guard-sha-is-abbreviated':
                deployOpsWriteRestoreGuard($scratch, ['required_source_sha' => 'a81d7f2']);
                break;

            case 'guard-is-a-symlink':
                unlink(deployOpsGuardPath($scratch));
                symlink('/dev/null', deployOpsGuardPath($scratch));
                break;

            case 'state-missing':
                unlink(deployOpsStatePath($scratch));
                break;

            case 'state-names-another-target':
                deployOpsWriteRestoreState($scratch, ['target' => 'other-target']);
                break;

            case 'state-is-not-held':
                deployOpsWriteRestoreState($scratch, ['status' => 'running']);
                break;

            case 'state-is-not-committed':
                deployOpsWriteRestoreState($scratch, ['phase' => 'quiesced']);
                break;

            case 'state-alignment-is-not-required':
                deployOpsWriteRestoreState($scratch, ['code_alignment' => 'aligned']);
                break;

            case 'state-and-guard-disagree':
                deployOpsWriteRestoreState($scratch, ['backup_source_sha' => DEPLOY_OPS_CURRENT_SHA]);
                break;

            case 'state-names-no-backup':
                deployOpsWriteRestoreState($scratch, ['backup' => null]);
                break;

            case 'unknown-operation':
                $operation = '20200101-000000-ffffff';
                break;
        }

        $result = deployOpsRunDeployOn(
            $scratch,
            $fixture,
            '--target parity-target --release '.DEPLOY_OPS_ALIGNMENT_RELEASE
                .' --artifact '.$artifact['artifact']
                .' --checksum '.$artifact['checksum']
                ." --restore-operation {$operation}",
        );

        expect($result['exit'])->not->toBe(0, $result['output']);
        expect($result['output'])->toContain($expected);

        // Refused before mutation, in every single case: nothing extracted,
        // nothing recorded, no current, and the guard left exactly as it was
        // for the operator who has to resolve it.
        expect(is_dir($fixture['root'].'/releases/'.DEPLOY_OPS_ALIGNMENT_RELEASE))->toBeFalse();
        expect(deployOpsHistory($fixture['root']))->toBe([]);
        expect(is_link($fixture['root'].'/current'))->toBeFalse();
        expect(File::get($result['healthCheckLog']))->toBe('');
    } finally {
        deployOpsCleanup($scratch);
    }
})->with([
    ['no-guard', 'is not held: no restore guard'],
    ['guard-names-another-operation', 'is held by restore operation 20200101-000000-ffffff'],
    ['guard-names-another-target', 'belongs to target other-target'],
    ['guard-is-in-progress', "hold status 'in-progress'"],
    ['guard-is-failed-held', "hold status 'failed-held'"],
    ['guard-sha-is-abbreviated', 'no full 40-character commit'],
    ['guard-is-a-symlink', 'is a symlink'],
    ['state-missing', 'has no state file'],
    ['state-names-another-target', 'belongs to target other-target'],
    ['state-is-not-held', "has status 'running'"],
    ['state-is-not-committed', "is in phase 'quiesced'"],
    ['state-alignment-is-not-required', "records code_alignment 'aligned'"],
    ['state-and-guard-disagree', 'refusing to align a target whose own restore documents disagree'],
    ['state-names-no-backup', 'records no backup identity'],
    ['unknown-operation', 'is held by restore operation '.DEPLOY_OPS_RESTORE_OPERATION],
]);

it('asks restore-target to prove the target is still held, and refuses when it will not', function (
    string $case,
    array $restoreTarget,
    string $expected,
) {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsBuildFixture($scratch);
        $artifact = deployOpsAlignmentArtifact($scratch, $fixture);
        deployOpsWriteHeldRestore($scratch);

        $result = deployOpsRunDeployOn(
            $scratch,
            $fixture,
            '--target parity-target --release '.DEPLOY_OPS_ALIGNMENT_RELEASE
                .' --artifact '.$artifact['artifact']
                .' --checksum '.$artifact['checksum']
                .' --restore-operation '.DEPLOY_OPS_RESTORE_OPERATION,
            ['restore_target' => $restoreTarget],
        );

        expect($result['exit'])->not->toBe(0, $result['output']);
        expect($result['output'])->toContain($expected);

        // It really did ask, and it asked read-only.
        expect(File::get($result['restoreTargetLog']))
            ->toContain('--inspect --target parity-target --operation '.DEPLOY_OPS_RESTORE_OPERATION);

        expect(is_dir($fixture['root'].'/releases/'.DEPLOY_OPS_ALIGNMENT_RELEASE))->toBeFalse();
        expect(deployOpsHistory($fixture['root']))->toBe([]);
        expect(is_link($fixture['root'].'/current'))->toBeFalse();
    } finally {
        deployOpsCleanup($scratch);
    }
})->with([
    // The hold itself is gone — maintenance lifted, a worker running, a cron
    // entry back. restore-target is the one implementation of that proof and
    // it refuses; deploy believes it and stops.
    ['inspect-refuses', ['exit' => 1, 'lines' => ['ERROR: parity-target is NOT in maintenance mode']], 'refused restore operation'],
    ['no-result', ['lines' => []], 'produced 0 machine-readable results'],
    ['two-results', ['lines' => [
        'RATEGURU_RESTORE_RESULT={"status":"held"}',
        'RATEGURU_RESTORE_RESULT={"status":"held"}',
    ]], 'produced 2 machine-readable results'],
    ['malformed-result', ['lines' => ['RATEGURU_RESTORE_RESULT=not-json']], 'malformed machine-readable result'],
    ['another-operation', ['result' => ['operation_id' => '20200101-000000-ffffff']], 'reported operation 20200101-000000-ffffff'],
    ['another-target', ['result' => ['target' => 'other-target']], 'reported target other-target'],
    ['not-held', ['result' => ['status' => 'resumed']], 'reported status resumed'],
    ['already-aligned', ['result' => ['code_alignment' => 'ALIGNED']], 'reported code alignment ALIGNED'],
    ['already-resumed', ['result' => ['runtime_resumed' => 'yes']], 'reported the runtime as resumed'],
    ['another-commit', ['result' => ['required_source_sha' => DEPLOY_OPS_CURRENT_SHA]], 'refusing to deploy into a target whose own restore state is inconsistent'],
]);

it('refuses an artifact that was not built from the required commit, before switching current', function (
    string $case,
    array $artifactOptions,
    string $expected,
) {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsServingFixture($scratch);
        $originalCurrent = realpath($fixture['root'].'/current');
        // array_key_exists, not ??: 'sha' => null is a deliberate case (an
        // artifact whose release.json carries no source_sha at all), and ??
        // would silently substitute the correct commit for it.
        $artifact = deployOpsAlignmentArtifact(
            $scratch,
            $fixture,
            $artifactOptions['release'] ?? DEPLOY_OPS_ALIGNMENT_RELEASE,
            array_key_exists('sha', $artifactOptions) ? $artifactOptions['sha'] : DEPLOY_OPS_REQUIRED_SHA,
            $artifactOptions['release_json'] ?? true,
        );

        deployOpsWriteHeldRestore($scratch);

        $result = deployOpsRunDeployOn(
            $scratch,
            $fixture,
            '--target parity-target --release '.DEPLOY_OPS_ALIGNMENT_RELEASE
                .' --artifact '.$artifact['artifact']
                .' --checksum '.$artifact['checksum']
                .' --restore-operation '.DEPLOY_OPS_RESTORE_OPERATION,
        );

        expect($result['exit'])->not->toBe(0, $result['output']);
        expect($result['output'])->toContain($expected);

        // `current` never moved, and the release never became installable.
        expect(realpath($fixture['root'].'/current'))->toBe($originalCurrent);
        expect(is_dir($fixture['root'].'/releases/'.DEPLOY_OPS_ALIGNMENT_RELEASE))->toBeFalse();

        // The guard is still there, so the target is still refused by every
        // ordinary operation.
        expect(is_file(deployOpsGuardPath($scratch)))->toBeTrue();
    } finally {
        deployOpsCleanup($scratch);
    }
})->with([
    ['wrong-commit', ['sha' => DEPLOY_OPS_CURRENT_SHA], 'refusing to install code the data on this target does not belong to'],
    ['no-release-json', ['release_json' => false], 'artifact contains no release.json'],
    ['no-source-sha', ['sha' => null], 'refusing to install code the data on this target does not belong to'],
    ['release-mismatch', ['release' => 'v9.9.9-20260101-000000-a81d7f2'], 'names release v9.9.9-20260101-000000-a81d7f2'],
]);

// --- alignment mode: the happy path -----------------------------------------

it('installs the required commit and leaves the target exactly as held as it found it', function () {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsServingFixture($scratch);

        // A second ordinary deployment, so `previous` exists and the alignment
        // has something to clear.
        $second = deployOpsRunDeployOn(
            $scratch,
            $fixture,
            '--target parity-target --release v2.1.0-20260202-000000-bbbb222 --artifact '.$fixture['artifact'],
        );
        expect($second['exit'])->toBe(0, $second['output']);
        expect(is_link($fixture['root'].'/previous'))->toBeTrue();

        $artifact = deployOpsAlignmentArtifact($scratch, $fixture);
        deployOpsWriteHeldRestore($scratch);

        // The stub log accumulates across every deploy run in this scratch
        // tree, and the two ordinary deployments above legitimately activated
        // and restarted the worker. Truncating here is what makes the
        // assertions below statements about the ALIGNMENT deploy alone.
        file_put_contents($scratch.'/supervisorctl.log', '');
        file_put_contents($scratch.'/systemctl.log', '');

        $result = deployOpsRunDeployOn(
            $scratch,
            $fixture,
            '--target parity-target --release '.DEPLOY_OPS_ALIGNMENT_RELEASE
                .' --artifact '.$artifact['artifact']
                .' --checksum '.$artifact['checksum']
                .' --restore-operation '.DEPLOY_OPS_RESTORE_OPERATION,
        );

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('controlled alignment authorized')
            ->toContain('is STILL HELD')
            ->toContain('restore-target --resume --target parity-target --operation '.DEPLOY_OPS_RESTORE_OPERATION);

        // SAME deploy mechanics: the release is installed and current points
        // at it, PHP-FPM was reloaded, and the required CLI check ran.
        $releaseRoot = $fixture['root'].'/releases/'.DEPLOY_OPS_ALIGNMENT_RELEASE;
        expect(is_dir($releaseRoot))->toBeTrue();
        expect(is_file($releaseRoot.'/public/index.php'))->toBeTrue();
        expect(realpath($fixture['root'].'/current'))->toBe(realpath($releaseRoot));
        expect(File::get($scratch.'/systemctl.log'))->toContain('reload');

        // DIFFERENT runtime policy: nothing that resumes the target ran.
        expect(File::get($result['healthCheckLog']))->toBe('', 'a held target is never health checked as if it were serving');
        expect(deployOpsSupervisorctlLog($scratch))
            ->not->toContain('supervisorctl start parity-queue:*')
            ->not->toContain('supervisorctl update parity-queue')
            ->not->toContain('supervisorctl reread');
        expect(trim((string) @file_get_contents($scratch.'/artisan.log')))->toBe('');
        expect($result['output'])->not->toContain('running database migrations');

        // The guard is untouched — only restore-target --resume may remove it.
        expect(json_decode(File::get(deployOpsGuardPath($scratch)), true))
            ->toMatchArray(['status' => 'held', 'operation' => DEPLOY_OPS_RESTORE_OPERATION]);

        // `previous` is cleared rather than pointed at the release that was
        // current: that release is exactly the code the restored data does NOT
        // match, so an ordinary "roll back one release" must not silently undo
        // the alignment once the hold ends.
        expect(is_link($fixture['root'].'/previous'))->toBeFalse();
        expect(file_exists($fixture['root'].'/previous'))->toBeFalse();

        // Every release directory is still on disk: clearing `previous`
        // removes an implicit rollback target, never a release.
        expect(is_dir($fixture['root'].'/releases/v2.1.0-20260202-000000-bbbb222'))->toBeTrue();

        // The history says what actually happened, and never claims the target
        // is serving while it is still in maintenance.
        $history = deployOpsHistory($fixture['root']);
        $alignment = array_values(array_filter(
            $history,
            static fn (array $entry): bool => str_starts_with((string) $entry['event'], 'restore-alignment-'),
        ));

        expect(array_column($alignment, 'event'))->toBe(['restore-alignment-started', 'restore-alignment-finished']);
        expect(end($alignment)['status'])->toBe('held');

        foreach ($history as $entry) {
            expect($entry['event'] === 'deployment-finished' && $entry['release'] === DEPLOY_OPS_ALIGNMENT_RELEASE)
                ->toBeFalse('a controlled alignment must never record an ordinary successful deployment');
        }
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('keeps the target held when a controlled alignment fails before switching current', function () {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsServingFixture($scratch);
        $originalCurrent = realpath($fixture['root'].'/current');
        $artifact = deployOpsAlignmentArtifact($scratch, $fixture);
        deployOpsWriteHeldRestore($scratch);
        file_put_contents($scratch.'/supervisorctl.log', '');

        $result = deployOpsRunDeployOn(
            $scratch,
            $fixture,
            '--target parity-target --release '.DEPLOY_OPS_ALIGNMENT_RELEASE
                .' --artifact '.$artifact['artifact']
                .' --checksum '.$artifact['checksum']
                .' --restore-operation '.DEPLOY_OPS_RESTORE_OPERATION,
            ['verify_clis_fail' => true],
        );

        expect($result['exit'])->not->toBe(0, $result['output']);
        expect($result['output'])->toContain('controlled restore alignment failed');
        expect($result['output'])->toContain('remains HELD');

        // Nothing was switched, the staged release was discarded, and the
        // failure was recorded as an alignment rather than as a deployment.
        expect(realpath($fixture['root'].'/current'))->toBe($originalCurrent);
        expect(is_dir($fixture['root'].'/releases/'.DEPLOY_OPS_ALIGNMENT_RELEASE))->toBeFalse();
        expect(glob($fixture['root'].'/releases/.*.tmp-*') ?: [])->toBe([]);

        $history = deployOpsHistory($fixture['root']);
        $finished = end($history);
        expect($finished['event'])->toBe('restore-alignment-finished');
        expect($finished['status'])->toBe('failed');

        // The hold is intact in every respect deploy can be responsible for.
        expect(is_file(deployOpsGuardPath($scratch)))->toBeTrue();
        expect(File::get($result['healthCheckLog']))->toBe('');
        expect(deployOpsSupervisorctlLog($scratch))->not->toContain('supervisorctl start parity-queue:*');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('restores the prior release but still keeps the target held when an alignment fails after the switch', function () {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsServingFixture($scratch);
        $originalCurrent = realpath($fixture['root'].'/current');
        $artifact = deployOpsAlignmentArtifact($scratch, $fixture);
        deployOpsWriteHeldRestore($scratch);
        file_put_contents($scratch.'/supervisorctl.log', '');

        // PHP-FPM will not reload. That is the first thing after the atomic
        // current switch, so this is the "failed after the switch" case.
        $result = deployOpsRunDeployOn(
            $scratch,
            $fixture,
            '--target parity-target --release '.DEPLOY_OPS_ALIGNMENT_RELEASE
                .' --artifact '.$artifact['artifact']
                .' --checksum '.$artifact['checksum']
                .' --restore-operation '.DEPLOY_OPS_RESTORE_OPERATION,
            ['systemctl_fails' => true],
        );

        expect($result['exit'])->not->toBe(0, $result['output']);

        // Recovery put the prior release back — the normal deployment recovery,
        // unchanged.
        expect(realpath($fixture['root'].'/current'))->toBe($originalCurrent);

        // And stopped there. No queue recovery, no maintenance change, no
        // scheduler restoration, no guard removal.
        expect($result['output'])
            ->toContain('controlled restore alignment failed')
            ->toContain('remains HELD');
        expect($result['output'])->not->toContain('re-issuing the queue restart signal');
        expect(is_file(deployOpsGuardPath($scratch)))->toBeTrue();
        expect(File::get($result['healthCheckLog']))->toBe('');
        expect(deployOpsSupervisorctlLog($scratch))->not->toContain('supervisorctl start parity-queue:*');

        $history = deployOpsHistory($fixture['root']);
        $finished = end($history);
        expect($finished['event'])->toBe('restore-alignment-finished');
        expect($finished['status'])->not->toBe('success');
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// Controlled recovery deployment
// =============================================================================
//
// The other controlled mode: a replacement host rebuilt by recover-host holds
// the recovered data of a lost machine and no code at all. Exactly one
// deployment may give it code — this one, installing the exact commit that data
// belongs to — and it must leave the host every bit as held afterwards as it
// found it, with `previous` never invented.
//
// Everything below drives the SAME deploy the tests above drive. The
// authorization half is read from the two persisted recovery documents; unlike
// the restore alignment there is no runtime hold to re-prove, because a
// recovering host has no `current` at all.

const DEPLOY_OPS_RECOVERY_OPERATION = '20260902-131415-d4e5f6';

function deployOpsRecoveryGuardPath(string $scratch): string
{
    return deployOpsRunRoot($scratch).'/recoveries/parity-target/recovery-guard';
}

/** @param  array<string, string|null>  $overrides */
function deployOpsWriteRecoveryGuard(string $scratch, array $overrides = []): void
{
    $path = deployOpsRecoveryGuardPath($scratch);

    if (! is_dir(dirname($path))) {
        expect(@mkdir(dirname($path), 0o700, true))->toBeTrue('could not create the recovery run root');
    }

    $guard = array_filter(array_merge([
        'operation' => DEPLOY_OPS_RECOVERY_OPERATION,
        'target' => 'parity-target',
        'backup' => '20260115-023000',
        'required_source_sha' => DEPLOY_OPS_REQUIRED_SHA,
        'status' => 'awaiting-code',
        'created_at' => '2026-09-02T13:14:15Z',
    ], $overrides), static fn ($value): bool => $value !== null);

    file_put_contents($path, json_encode($guard, JSON_PRETTY_PRINT));
    chmod($path, 0o600);
}

/** @param  array<string, string|null>  $overrides */
function deployOpsWriteRecoveryState(string $scratch, array $overrides = []): void
{
    $path = deployOpsRunRoot($scratch).'/recoveries/parity-target/'
        .DEPLOY_OPS_RECOVERY_OPERATION.'/state.json';

    if (! is_dir(dirname($path))) {
        expect(@mkdir(dirname($path), 0o700, true))->toBeTrue('could not create the recovery operation workspace');
    }

    $state = array_filter(array_merge([
        'operation_kind' => 'host-recovery',
        'operation' => DEPLOY_OPS_RECOVERY_OPERATION,
        'target' => 'parity-target',
        'environment' => 'staging',
        'backup_namespace' => 'parity',
        'source' => 'offsite',
        'backup' => '20260115-023000',
        'backup_release' => DEPLOY_OPS_ALIGNMENT_RELEASE,
        'backup_source_sha' => DEPLOY_OPS_REQUIRED_SHA,
        'status' => 'awaiting-code',
        'phase' => 'awaiting-code',
        'data_restored' => 'true',
    ], $overrides), static fn ($value): bool => $value !== null);

    file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT));
    chmod($path, 0o600);
}

/** Both recovery documents in the one state a controlled recovery deployment may run in. */
function deployOpsWriteAwaitingCodeRecovery(string $scratch, array $guard = [], array $state = []): void
{
    deployOpsWriteRecoveryGuard($scratch, $guard);
    deployOpsWriteRecoveryState($scratch, $state);
}

// --- parsing ------------------------------------------------------------------

it('refuses --recovery-operation together with --migrate, before anything is resolved', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target parity-target --release v1.0.0-20260101-000000-aaa4444'
                .' --artifact /tmp/x.tar.gz --migrate --recovery-operation '.DEPLOY_OPS_RECOVERY_OPERATION,
            deployOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--migrate and --recovery-operation are mutually exclusive');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('refuses --restore-operation and --recovery-operation together', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target parity-target --release v1.0.0-20260101-000000-aaa5555'
                .' --artifact /tmp/x.tar.gz'
                .' --restore-operation '.DEPLOY_OPS_RESTORE_OPERATION
                .' --recovery-operation '.DEPLOY_OPS_RECOVERY_OPERATION,
            deployOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('--restore-operation and --recovery-operation are mutually exclusive');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('refuses a malformed recovery operation ID', function () {
    $scratch = deployOpsScratchDir();

    try {
        [$exit, $output] = deployOpsRunHarness(
            $scratch,
            'parse_deploy_args --target parity-target --release v1.0.0-20260101-000000-aaa6666'
                .' --artifact /tmp/x.tar.gz --recovery-operation ../../etc/passwd',
            deployOpsBaseEnv($scratch),
        );

        expect($exit)->not->toBe(0);
        expect($output)->toContain('invalid restore operation ID');
    } finally {
        deployOpsCleanup($scratch);
    }
});

// --- authorization -------------------------------------------------------------

it('refuses a recovery deployment when nothing is being recovered', function () {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsBuildFixture($scratch);
        $artifact = deployOpsAlignmentArtifact($scratch, $fixture);

        $result = deployOpsRunDeployOn(
            $scratch,
            $fixture,
            '--target parity-target --release '.DEPLOY_OPS_ALIGNMENT_RELEASE
                .' --artifact '.$artifact['artifact']
                .' --checksum '.$artifact['checksum']
                .' --recovery-operation '.DEPLOY_OPS_RECOVERY_OPERATION,
        );

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('is not being recovered: no recovery guard')
            ->toContain('an ordinary deployment needs no recovery operation');

        expect(deployOpsHistory($fixture['root']))->toBe([]);
        expect(is_link($fixture['root'].'/current'))->toBeFalse();
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('refuses a recovery deployment on every disagreement between the two documents', function (array $guard, array $state, string $expected) {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsBuildFixture($scratch);
        $artifact = deployOpsAlignmentArtifact($scratch, $fixture);
        deployOpsWriteAwaitingCodeRecovery($scratch, $guard, $state);

        $result = deployOpsRunDeployOn(
            $scratch,
            $fixture,
            '--target parity-target --release '.DEPLOY_OPS_ALIGNMENT_RELEASE
                .' --artifact '.$artifact['artifact']
                .' --checksum '.$artifact['checksum']
                .' --recovery-operation '.DEPLOY_OPS_RECOVERY_OPERATION,
        );

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain($expected);

        // Nothing moved: no history, no release directory, no current.
        expect(deployOpsHistory($fixture['root']))->toBe([]);
        expect(is_link($fixture['root'].'/current'))->toBeFalse();
    } finally {
        deployOpsCleanup($scratch);
    }
})->with([
    'a guard for another target' => [
        ['target' => 'somebody-else'], [], 'belongs to target somebody-else',
    ],
    'a guard for another operation' => [
        ['operation' => '20260101-000000-aaaaaa'], [],
        'is being recovered by operation 20260101-000000-aaaaaa',
    ],
    'a recovery still in progress' => [
        ['status' => 'in-progress'], [], "has status 'in-progress', not 'awaiting-code'",
    ],
    'a failed-held recovery' => [
        ['status' => 'failed-held'], [], "has status 'failed-held', not 'awaiting-code'",
    ],
    'an abbreviated commit' => [
        ['required_source_sha' => 'a81d7f2'], [], 'carries no full 40-character commit',
    ],
    'a state that is not a host recovery' => [
        [], ['operation_kind' => 'target-restore'], "records operation_kind 'target-restore'",
    ],
    'documents that disagree about the commit' => [
        [], ['backup_source_sha' => 'b92e8a3d4c5a6a79889900bbccddeeff11223344'],
        'refusing to deploy into a host whose own recovery documents disagree',
    ],
    'documents that disagree about the backup' => [
        [], ['backup' => '20260101-000000'],
        'refusing to deploy into a host whose own recovery documents disagree',
    ],
    'a state in the wrong phase' => [
        [], ['phase' => 'verified'], "is in phase 'verified', expected awaiting-code",
    ],
]);

it('refuses a recovery deployment onto a target that already has a current release', function () {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsServingFixture($scratch);
        $artifact = deployOpsAlignmentArtifact($scratch, $fixture);
        deployOpsWriteAwaitingCodeRecovery($scratch);

        $originalCurrent = realpath($fixture['root'].'/current');

        $result = deployOpsRunDeployOn(
            $scratch,
            $fixture,
            '--target parity-target --release '.DEPLOY_OPS_ALIGNMENT_RELEASE
                .' --artifact '.$artifact['artifact']
                .' --checksum '.$artifact['checksum']
                .' --recovery-operation '.DEPLOY_OPS_RECOVERY_OPERATION,
        );

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('a controlled recovery deployment installs the FIRST code a rebuilt host receives');

        expect(realpath($fixture['root'].'/current'))->toBe($originalCurrent);
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('refuses an artifact built from anything but the required commit, before current is switched', function () {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsBuildFixture($scratch);
        $artifact = deployOpsAlignmentArtifact($scratch, $fixture, DEPLOY_OPS_ALIGNMENT_RELEASE, DEPLOY_OPS_CURRENT_SHA);
        deployOpsWriteAwaitingCodeRecovery($scratch);

        $result = deployOpsRunDeployOn(
            $scratch,
            $fixture,
            '--target parity-target --release '.DEPLOY_OPS_ALIGNMENT_RELEASE
                .' --artifact '.$artifact['artifact']
                .' --checksum '.$artifact['checksum']
                .' --recovery-operation '.DEPLOY_OPS_RECOVERY_OPERATION,
        );

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('artifact was built from '.DEPLOY_OPS_CURRENT_SHA)
            ->toContain('recovery operation '.DEPLOY_OPS_RECOVERY_OPERATION.' requires '.DEPLOY_OPS_REQUIRED_SHA);

        // Rejected while the only thing on disk was a staged directory.
        expect(is_link($fixture['root'].'/current'))->toBeFalse();
        expect(is_dir($fixture['root'].'/releases/'.DEPLOY_OPS_ALIGNMENT_RELEASE))->toBeFalse();
        expect(glob($fixture['root'].'/releases/.*.tmp-*') ?: [])->toBe([]);

        // The guard is untouched.
        expect(is_file(deployOpsRecoveryGuardPath($scratch)))->toBeTrue();
    } finally {
        deployOpsCleanup($scratch);
    }
});

// --- the successful recovery deployment ----------------------------------------

it('installs the exact commit and leaves the rebuilt host held, with previous absent', function () {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsBuildFixture($scratch);
        $artifact = deployOpsAlignmentArtifact($scratch, $fixture);
        deployOpsWriteAwaitingCodeRecovery($scratch);
        file_put_contents($scratch.'/supervisorctl.log', '');

        $result = deployOpsRunDeployOn(
            $scratch,
            $fixture,
            '--target parity-target --release '.DEPLOY_OPS_ALIGNMENT_RELEASE
                .' --artifact '.$artifact['artifact']
                .' --checksum '.$artifact['checksum']
                .' --recovery-operation '.DEPLOY_OPS_RECOVERY_OPERATION,
        );

        expect($result['exit'])->toBe(0, $result['output']);

        // current points at the rebuilt release.
        expect(basename(realpath($fixture['root'].'/current')))->toBe(DEPLOY_OPS_ALIGNMENT_RELEASE);

        // previous stays ABSENT: a rebuilt host has no earlier release, and
        // synthesising one would arm a rollback to undo the recovery.
        expect(is_link($fixture['root'].'/previous'))->toBeFalse();
        expect(file_exists($fixture['root'].'/previous'))->toBeFalse();
        expect($result['output'])->toContain('previous is deliberately absent');

        // Nothing that resumes the target.
        expect(File::get($result['healthCheckLog']))->toBe('', 'a recovery deployment never health-checks a held host');
        expect(deployOpsSupervisorctlLog($scratch))->not->toContain('supervisorctl start parity-queue:*');
        expect(deployOpsSupervisorctlLog($scratch))->not->toContain('supervisorctl restart parity-queue:*');
        expect($result['output'])
            ->not->toContain('artisan up')
            ->not->toContain('deployed successfully');

        // No migration, ever.
        expect($result['output'])->not->toContain('running database migrations');

        // The guard is untouched, and recover-host --resume is what ends it.
        expect(is_file(deployOpsRecoveryGuardPath($scratch)))->toBeTrue();
        expect(json_decode(File::get(deployOpsRecoveryGuardPath($scratch)), true)['status'])->toBe('awaiting-code');
        expect($result['output'])->toContain('recover-host --resume --target parity-target');

        // And it never consulted restore-target: a recovering host has no
        // Supervisor-shaped hold to re-prove.
        expect(File::get($result['restoreTargetLog']))->toBe('');

        // The history says held, never success.
        $history = deployOpsHistory($fixture['root']);
        expect(array_column($history, 'event'))
            ->toBe(['recovery-alignment-started', 'recovery-alignment-finished']);
        expect(end($history)['status'])->toBe('held');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('leaves a rebuilt host held when a recovery deployment fails after the switch', function () {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsBuildFixture($scratch);
        $artifact = deployOpsAlignmentArtifact($scratch, $fixture);
        deployOpsWriteAwaitingCodeRecovery($scratch);
        file_put_contents($scratch.'/supervisorctl.log', '');

        $result = deployOpsRunDeployOn(
            $scratch,
            $fixture,
            '--target parity-target --release '.DEPLOY_OPS_ALIGNMENT_RELEASE
                .' --artifact '.$artifact['artifact']
                .' --checksum '.$artifact['checksum']
                .' --recovery-operation '.DEPLOY_OPS_RECOVERY_OPERATION,
            ['systemctl_fails' => true],
        );

        expect($result['exit'])->not->toBe(0, $result['output']);

        // Recovery put the links back where they were — which, on a rebuilt
        // host, is ABSENT.
        expect(is_link($fixture['root'].'/current'))->toBeFalse();
        expect(is_link($fixture['root'].'/previous'))->toBeFalse();

        expect($result['output'])
            ->toContain('controlled recovery deployment failed')
            ->toContain('remains a HELD replacement host');

        // No queue recovery, no guard removal, no health check.
        expect(is_file(deployOpsRecoveryGuardPath($scratch)))->toBeTrue();
        expect(File::get($result['healthCheckLog']))->toBe('');
        expect(deployOpsSupervisorctlLog($scratch))->not->toContain('supervisorctl start parity-queue:*');

        $history = deployOpsHistory($fixture['root']);
        $finished = end($history);
        expect($finished['event'])->toBe('recovery-alignment-finished');
        expect($finished['status'])->not->toBe('success');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('refuses an ordinary deployment while a recovery owns the target', function () {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsBuildFixture($scratch);
        deployOpsWriteAwaitingCodeRecovery($scratch);

        $releaseId = 'v1.0.0-20260101-000000-aaa7777';

        $result = deployOpsRunDeployOn(
            $scratch,
            $fixture,
            "--target parity-target --release {$releaseId} --artifact ".$fixture['artifact'],
        );

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('is being recovered onto a replacement host by recovery operation '.DEPLOY_OPS_RECOVERY_OPERATION)
            ->toContain('is not finished being rebuilt');

        expect(is_dir($fixture['root'].'/releases/'.$releaseId))->toBeFalse();
        expect(deployOpsHistory($fixture['root']))->toBe([]);
        expect(File::get($result['healthCheckLog']))->toBe('');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('treats both guards at once as a hard failure, in every deployment mode', function (string $arguments) {
    $scratch = deployOpsScratchDir();

    try {
        $fixture = deployOpsBuildFixture($scratch);
        $artifact = deployOpsAlignmentArtifact($scratch, $fixture);
        deployOpsWriteHeldRestore($scratch);
        deployOpsWriteAwaitingCodeRecovery($scratch);

        $result = deployOpsRunDeployOn(
            $scratch,
            $fixture,
            '--target parity-target --release '.DEPLOY_OPS_ALIGNMENT_RELEASE
                .' --artifact '.$artifact['artifact']
                .' --checksum '.$artifact['checksum']
                .' '.$arguments,
        );

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('carries BOTH a restore guard')
            ->toContain('resolve it by hand');

        expect(deployOpsHistory($fixture['root']))->toBe([]);
        expect(is_link($fixture['root'].'/current'))->toBeFalse();
    } finally {
        deployOpsCleanup($scratch);
    }
})->with([
    'ordinary' => [''],
    'restore alignment' => ['--restore-operation '.DEPLOY_OPS_RESTORE_OPERATION],
    'recovery deployment' => ['--recovery-operation '.DEPLOY_OPS_RECOVERY_OPERATION],
]);
