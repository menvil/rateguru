<?php

use Illuminate\Support\Facades\File;

/**
 * : infrastructure/scripts/install-bootstrap-services —
 * services and committed host configuration for a prepared RateGuru host.
 *
 * Every test executes the real, shipped script as a subprocess — never a
 * reimplementation — against a fully simulated host: a fixture filesystem
 * root the script maps every canonical path onto
 * (RATEGURU_BOOTSTRAPSVC_FS_ROOT), a layered stat stub (real types/modes,
 * fixture ownership), logging install/chown/chmod stubs that perform the
 * real filesystem work inside the scratch, a stateful systemctl stub
 * (enabled/active per unit as fixture files), toggle-driven
 * nginx/sshd/php-fpm/supervisorctl stubs, and one stub per child installer
 * that logs its invocation and answers its verify from a toggle file. All
 * injected through RATEGURU_BOOTSTRAPSVC_* overrides the script honors only
 * alongside RATEGURU_ALLOW_TEST_OVERRIDES=true. Nothing here needs root and
 * nothing touches the CI runner's real services or /etc.
 *
 * The profiles that matter mirror the real situations: a genuinely clean
 * PRE_DEPLOY host straight after install-bootstrap-host-layout (everything to install, queue
 * activation deferred), the current DEPLOYED staging host (mostly PASS,
 * second apply mutates nothing), and every broken/drifted/conflicting shape
 * in between. tits-guru stays lifecycle=planned and must receive zero
 * service configuration.
 */

// =============================================================================
// Harness
// =============================================================================

function bsvcScript(): string
{
    return base_path('infrastructure/scripts/install-bootstrap-services');
}

function bsvcSource(): string
{
    return File::get(bsvcScript());
}

function bsvcScratchDir(): string
{
    $dir = sys_get_temp_dir().'/bootstrap-services-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin', '/fs', '/log', '/svc', '/toggles'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function bsvcCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function bsvcRun(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(
        array_merge(['bash', bsvcScript()], $arguments),
        $descriptors,
        $pipes,
        null,
        $env,
    );

    expect($process)->not->toBeFalse('could not start install-bootstrap-services subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

function bsvcWriteStub(string $path, string $content): void
{
    file_put_contents($path, $content);
    chmod($path, 0o755);
}

function bsvcLog(string $scratch, string $name): string
{
    $path = $scratch.'/log/'.$name;

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/**
 * Content + structure snapshot for mutation-free proofs.
 *
 * @return array<string, string>
 */
function bsvcTreeSnapshot(string $dir): array
{
    $snapshot = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $entry) {
        $path = $entry->getPathname();

        if (is_link($path)) {
            $snapshot[$path] = 'link:'.readlink($path);
        } elseif ($entry->isFile()) {
            $snapshot[$path] = md5_file($path).':'.substr(sprintf('%o', fileperms($path)), -4);
        } else {
            $snapshot[$path] = 'dir:'.substr(sprintf('%o', fileperms($path)), -4);
        }
    }

    ksort($snapshot);

    return $snapshot;
}

// =============================================================================
// Stubs
// =============================================================================

function bsvcWriteStubs(string $scratch): void
{
    // stat: layered — type from the real scratch filesystem (with a
    // type-table override so a plain fixture file can present as a socket),
    // owner/group from the fixture ownership table, mode real.
    bsvcWriteStub($scratch.'/bin/stat', <<<'STUB'
        #!/bin/bash
        path="${!#}"
        if [[ -L "${path}" ]]; then ftype="symbolic link"
        elif [[ -d "${path}" ]]; then ftype="directory"
        elif [[ -S "${path}" ]]; then ftype="socket"
        elif [[ -f "${path}" ]]; then
            ftype="regular file"
            row_t="$(PATH="${STUB_REAL_PATH}" awk -F'|' -v p="${path}" '$1 == p && $2 == "TYPE" { print $3; exit }' "${STUB_TYPE_TABLE}" 2>/dev/null)"
            [[ -n "${row_t}" ]] && ftype="${row_t}"
        elif [[ -e "${path}" ]]; then ftype="other"
        else exit 1; fi
        mode="$(PATH="${STUB_REAL_PATH}" stat -c '%a' -- "${path}" 2>/dev/null)" \
            || mode="$(PATH="${STUB_REAL_PATH}" stat -f '%Mp%Lp' "${path}" 2>/dev/null)" || exit 1
        mode="$(printf '%o' $(( 8#${mode} )))"
        row="$(PATH="${STUB_REAL_PATH}" awk -F'|' -v p="${path}" '$1 == p && $2 != "TYPE" { print $2 "|" $3; found = 1; exit } END { exit !found }' "${STUB_OWNER_TABLE}" 2>/dev/null)" || row=""
        if [[ -z "${row}" ]]; then
            row="$(PATH="${STUB_REAL_PATH}" stat -c '%U|%G' -- "${path}" 2>/dev/null)" \
                || row="$(PATH="${STUB_REAL_PATH}" stat -f '%Su|%Sg' "${path}" 2>/dev/null)" || exit 1
        fi
        printf '%s|%s|%s\n' "${ftype}" "${row}" "${mode}"
        STUB);

    // chown: records the invocation and upserts the ownership row for the
    // exact path given — never recursive.
    bsvcWriteStub($scratch.'/bin/chown', <<<'STUB'
        #!/bin/bash
        printf 'chown %s\n' "$*" >> "${STUB_LOG}/chown.log"
        owner_group=""; path=""
        for arg in "$@"; do
            case "${arg}" in
                --) ;;
                -*) ;;
                *) if [[ -z "${owner_group}" ]]; then owner_group="${arg}"; else path="${arg}"; fi ;;
            esac
        done
        owner="${owner_group%%:*}"; group="${owner_group##*:}"
        tmp="${STUB_OWNER_TABLE}.tmp"
        PATH="${STUB_REAL_PATH}" awk -F'|' -v p="${path}" '$1 != p' "${STUB_OWNER_TABLE}" > "${tmp}" 2>/dev/null || : > "${tmp}"
        printf '%s|%s|%s\n' "${path}" "${owner}" "${group}" >> "${tmp}"
        PATH="${STUB_REAL_PATH}" mv "${tmp}" "${STUB_OWNER_TABLE}"
        exit 0
        STUB);

    bsvcWriteStub($scratch.'/bin/chmod', <<<'STUB'
        #!/bin/bash
        printf 'chmod %s\n' "$*" >> "${STUB_LOG}/chmod.log"
        PATH="${STUB_REAL_PATH}" chmod "$@"
        STUB);

    bsvcWriteStub($scratch.'/bin/install', <<<'STUB'
        #!/bin/bash
        printf 'install %s\n' "$*" >> "${STUB_LOG}/install.log"
        PATH="${STUB_REAL_PATH}" install "$@"
        STUB);

    // systemctl: stateful — enabled/active per unit as files under the
    // service-state directory; every invocation is logged.
    bsvcWriteStub($scratch.'/bin/systemctl', <<<'STUB'
        #!/bin/bash
        printf 'systemctl %s\n' "$*" >> "${STUB_LOG}/systemctl.log"
        # A real reload replaces workers, so the replacements are created
        # with whatever supplementary groups the account now has. Unless the
        # reload-keeps-workers-stale toggle models a host where that failed.
        respawn_nginx_workers() {
            [[ -f "${STUB_FS}/nginx-fresh-worker-gids.txt" ]] || return 0
            if [[ -e "${STUB_TOGGLES}/nginx-reload-keeps-stale-workers" ]]; then return 0; fi
            gids="$(PATH="${STUB_REAL_PATH}" cat "${STUB_FS}/nginx-fresh-worker-gids.txt")"
            PATH="${STUB_REAL_PATH}" rm -rf "${STUB_FS}/proc"
            : > "${STUB_FS}/nginx-worker-pids.txt"
            for pid in 9001 9002; do
                PATH="${STUB_REAL_PATH}" mkdir -p "${STUB_FS}/proc/${pid}"
                printf 'Name:\tnginx\nGroups:\t%s \n' "${gids}" > "${STUB_FS}/proc/${pid}/status"
                printf '%s\n' "${pid}" >> "${STUB_FS}/nginx-worker-pids.txt"
            done
        }
        cmd=""; unit=""
        for arg in "$@"; do
            case "${arg}" in
                --quiet) ;;
                *) if [[ -z "${cmd}" ]]; then cmd="${arg}"; else unit="${arg}"; fi ;;
            esac
        done
        unit="${unit%.service}"
        case "${cmd}" in
            is-enabled) [[ -e "${STUB_SVC_STATE}/${unit}.enabled" ]] ;;
            is-active)  [[ -e "${STUB_SVC_STATE}/${unit}.active" ]] ;;
            enable)  touch "${STUB_SVC_STATE}/${unit}.enabled" ;;
            disable) rm -f "${STUB_SVC_STATE}/${unit}.enabled" ;;
            start)
                [[ -e "${STUB_TOGGLES}/${unit}-start-fail" ]] && exit 1
                touch "${STUB_SVC_STATE}/${unit}.active"
                if [[ "${unit}" == nginx ]]; then respawn_nginx_workers; fi
                ;;
            stop)    rm -f "${STUB_SVC_STATE}/${unit}.active" ;;
            reload|restart)
                [[ -e "${STUB_SVC_STATE}/${unit}.active" ]] || exit 1
                if [[ "${unit}" == nginx ]]; then respawn_nginx_workers; fi
                ;;
            *) exit 0 ;;
        esac
        STUB);

    // pgrep: the PIDs of the simulated running www-data nginx workers.
    bsvcWriteStub($scratch.'/bin/pgrep', <<<'STUB'
        #!/bin/bash
        printf 'pgrep %s\n' "$*" >> "${STUB_LOG}/pgrep.log"
        [[ -s "${STUB_FS}/nginx-worker-pids.txt" ]] || exit 1
        PATH="${STUB_REAL_PATH}" cat "${STUB_FS}/nginx-worker-pids.txt"
        STUB);

    // nginx / sshd / php-fpm: config tests whose verdict a toggle controls.
    foreach (['nginx' => 'nginx', 'sshd' => 'sshd', 'php-fpm8.5' => 'php-fpm'] as $bin => $log) {
        bsvcWriteStub($scratch.'/bin/'.$bin, <<<STUB
            #!/bin/bash
            printf '{$log} %s\\n' "\$*" >> "\${STUB_LOG}/{$log}.log"
            [[ -e "\${STUB_TOGGLES}/{$log}-t-fail" ]] && exit 1
            exit 0
            STUB);
    }

    // supervisorctl: reread validates (toggle-driven), status answers from
    // the queue-running toggle, update/start flip it on.
    bsvcWriteStub($scratch.'/bin/supervisorctl', <<<'STUB'
        #!/bin/bash
        printf 'supervisorctl %s\n' "$*" >> "${STUB_LOG}/supervisorctl.log"
        case "${1:-}" in
            reread)
                [[ -e "${STUB_TOGGLES}/supervisor-reread-fail" ]] && { echo "ERROR: CANT_REREAD bad config"; exit 0; }
                echo "No config updates to processes"
                ;;
            status)
                if [[ -e "${STUB_TOGGLES}/queue-running" ]]; then
                    echo "rateguru-staging-queue:rateguru-staging-queue_00   RUNNING   pid 123, uptime 0:05:00"
                else
                    echo "rateguru-staging-queue:*: ERROR (no such process)"
                    exit 1
                fi
                ;;
            update|start)
                touch "${STUB_TOGGLES}/queue-running"
                ;;
        esac
        exit 0
        STUB);

    // One stub per child installer: logs the invocation, answers verify
    // from its own "<name>-compliant" toggle, and lets apply either fail
    // (via "<name>-apply-fail") or converge (creating the toggle). The
    // mail-capture apply also satisfies verify-mail-capture, mirroring the
    // real ownership relation between the two.
    foreach ([
        'runtime-installer', 'hostlayout-installer', 'operations-installer',
        'perimeter-installer', 'public-storage-installer', 'mail-capture-installer',
        'verify-mail-capture',
    ] as $child) {
        bsvcWriteStub($scratch.'/bin/'.$child, <<<'STUB'
            #!/bin/bash
            me="$(basename "$0")"
            printf '%s %s\n' "${me}" "$*" >> "${STUB_LOG}/children.log"
            case "$*" in
                *--apply*)
                    [[ -e "${STUB_TOGGLES}/${me}-apply-fail" ]] && exit 1
                    touch "${STUB_TOGGLES}/${me}-compliant"
                    if [[ "${me}" == mail-capture-installer ]]; then
                        touch "${STUB_TOGGLES}/verify-mail-capture-compliant"
                        touch "${STUB_SVC_STATE}/staging-mailpit.enabled" "${STUB_SVC_STATE}/staging-mailpit.active"
                        touch "${STUB_SVC_STATE}/staging-mailtrap-local.enabled" "${STUB_SVC_STATE}/staging-mailtrap-local.active"
                    fi
                    exit 0
                    ;;
                *)
                    [[ -e "${STUB_TOGGLES}/${me}-compliant" ]] && exit 0
                    exit 1
                    ;;
            esac
            STUB);
    }
}

// =============================================================================
// Filesystem fixture
// =============================================================================

/**
 * The managed service files: logical destination => committed source.
 *
 * @return array<string, string>
 */
function bsvcManagedFiles(): array
{
    return [
        '/etc/ssh/sshd_config.d/70-rateguru-deploy.conf' => base_path('infrastructure/config/ssh/70-rateguru-deploy.conf'),
        '/etc/nginx/sites-available/rateguru-staging' => base_path('infrastructure/config/nginx/rateguru-staging'),
        '/etc/php/8.5/fpm/pool.d/rateguru-staging.conf' => base_path('infrastructure/config/php-fpm/rateguru-staging.conf'),
        '/etc/supervisor/conf.d/rateguru-staging-queue.conf' => base_path('infrastructure/config/supervisor/rateguru-staging-queue.conf'),
        '/etc/cron.d/rateguru-staging-scheduler' => base_path('infrastructure/config/cron/rateguru-staging-scheduler'),
    ];
}

/** @return list<string> */
function bsvcExternalPrerequisitePaths(): array
{
    return [
        '/etc/nginx/rateguru-staging.htpasswd',
        '/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/fullchain.pem',
        '/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem',
        '/etc/letsencrypt/live/staging-mail-capture/fullchain.pem',
        '/etc/letsencrypt/live/staging-mail-capture/privkey.pem',
        '/etc/letsencrypt/options-ssl-nginx.conf',
        '/etc/letsencrypt/ssl-dhparams.pem',
    ];
}

function bsvcOwnerTableAdd(string $scratch, string $physical, string $owner, string $group): void
{
    $table = $scratch.'/fs/owner-table.txt';
    $rows = array_filter(
        explode("\n", (string) @file_get_contents($table)),
        fn (string $row): bool => $row !== '' && ! str_starts_with($row, $physical.'|'),
    );
    $rows[] = "{$physical}|{$owner}|{$group}";
    file_put_contents($table, implode("\n", $rows)."\n");
}

/**
 * Build a fully simulated host and return the environment to run the
 * script against it.
 *
 * Options:
 *   profile:          'clean' (PRE_DEPLOY, nothing 5.4-installed, base
 *                     services stopped) | 'compliant' (DEPLOYED, everything
 *                     installed and running)
 *   current:          'absent' | 'valid' | 'dangling' | 'outside' | 'wrongtype'
 *                     (defaults: clean => absent, compliant => valid)
 *   omitExternal:     list<string> external-prerequisite paths NOT created
 *   euid:             string (default '0')
 *
 * @param  array<string, mixed>  $options
 * @return array<string, string>
 */
function bsvcFixture(string $scratch, array $options = []): array
{
    $fs = $scratch.'/fs';
    $profile = $options['profile'] ?? 'clean';

    foreach ([
        '/etc/nginx/sites-available', '/etc/nginx/sites-enabled',
        '/etc/php/8.5/fpm/pool.d', '/etc/supervisor/conf.d',
        '/etc/cron.d', '/etc/ssh/sshd_config.d',
        '/home/www/rateguru/staging/shared/storage',
        '/home/www/rateguru/staging/releases',
        '/usr/bin', '/run/php',
    ] as $sub) {
        @mkdir($fs.$sub, 0o755, true);
    }

    touch($fs.'/usr/bin/php8.5');

    // External prerequisites (presence only — sentinel content proves the
    // installer never prints or copies it anywhere).
    $omitted = $options['omitExternal'] ?? [];

    foreach (bsvcExternalPrerequisitePaths() as $path) {
        if (in_array($path, $omitted, true)) {
            continue;
        }

        @mkdir(dirname($fs.$path), 0o755, true);
        file_put_contents($fs.$path, 'SECRET-SENTINEL-'.md5($path)."\n");
    }

    // Fixture passwd: the install-bootstrap-host-layout accounts exist.
    // Group database: the code group's GID is what an Nginx worker must
    // carry in its supplementary groups.
    file_put_contents($fs.'/etc-group', implode("\n", [
        'root:x:0:',
        'www-data:x:33:',
        'rateguru-staging:x:5001:',
        'rateguru-staging-code:x:5010:rateguru-staging,deploy-rateguru-staging,www-data',
    ])."\n");

    // Simulated running Nginx workers. `nginxWorkers` maps PID => list of
    // supplementary GIDs, so a test can model workers that predate the
    // code-group membership (the clean-VPS state) as easily as current ones.
    $workers = $options['nginxWorkers'] ?? ['4101' => ['33', '5010'], '4102' => ['33', '5010']];

    foreach ($workers as $pid => $gids) {
        @mkdir($fs.'/proc/'.$pid, 0o755, true);
        file_put_contents(
            $fs.'/proc/'.$pid.'/status',
            "Name:\tnginx\nUid:\t33\t33\t33\t33\nGroups:\t".implode(' ', $gids)." \n",
        );
    }

    file_put_contents($fs.'/nginx-worker-pids.txt', implode("\n", array_keys($workers))."\n");
    file_put_contents($fs.'/nginx-fresh-worker-gids.txt', ($options['nginxFreshWorkerGids'] ?? '33 5010')."\n");

    file_put_contents($fs.'/etc-passwd', implode("\n", [
        'root:x:0:0:root:/root:/bin/bash',
        'rateguru-staging:x:5001:5001::/home/www/rateguru/staging:/usr/sbin/nologin',
        'deploy-rateguru-staging:x:5002:5002::/home/deploy-rateguru-staging:/bin/bash',
    ])."\n");

    file_put_contents($fs.'/owner-table.txt', '');
    file_put_contents($fs.'/type-table.txt', '');

    // The PHP-FPM pool socket exists as soon as the pool runs; presenting a
    // plain fixture file as a socket via the type table.
    touch($fs.'/run/php/rateguru-staging.sock');
    chmod($fs.'/run/php/rateguru-staging.sock', 0o660);
    file_put_contents($fs.'/type-table.txt', $fs."/run/php/rateguru-staging.sock|TYPE|socket\n", FILE_APPEND);
    bsvcOwnerTableAdd($scratch, $fs.'/run/php/rateguru-staging.sock', 'www-data', 'www-data');

    // Deployment state.
    $current = $options['current'] ?? ($profile === 'compliant' ? 'valid' : 'absent');
    $staging = $fs.'/home/www/rateguru/staging';

    switch ($current) {
        case 'valid':
            @mkdir($staging.'/releases/20240101120000', 0o755, true);
            symlink($staging.'/releases/20240101120000', $staging.'/current');
            break;
        case 'dangling':
            symlink($staging.'/releases/never-deployed', $staging.'/current');
            break;
        case 'outside':
            @mkdir($staging.'/rogue-release', 0o755, true);
            symlink($staging.'/rogue-release', $staging.'/current');
            break;
        case 'wrongtype':
            @mkdir($staging.'/current', 0o755, true);
            break;
    }

    bsvcWriteStubs($scratch);

    if ($profile === 'compliant') {
        // Managed files installed byte-identical, root:root 0644; enabled
        // link present; logs dir present with the runtime ownership.
        foreach (bsvcManagedFiles() as $logical => $src) {
            copy($src, $fs.$logical);
            chmod($fs.$logical, 0o644);
            bsvcOwnerTableAdd($scratch, $fs.$logical, 'root', 'root');
        }

        symlink('/etc/nginx/sites-available/rateguru-staging', $fs.'/etc/nginx/sites-enabled/rateguru-staging');

        @mkdir($staging.'/shared/storage/logs', 0o755, true);
        chmod($staging.'/shared/storage/logs', 0o2770);
        bsvcOwnerTableAdd($scratch, $staging.'/shared/storage/logs', 'rateguru-staging', 'rateguru-staging');

        foreach ([
            'ssh', 'nginx', 'postgresql', 'redis-server', 'supervisor',
            'php8.5-fpm', 'staging-mailpit', 'staging-mailtrap-local',
        ] as $unit) {
            touch($scratch.'/svc/'.$unit.'.enabled');
            touch($scratch.'/svc/'.$unit.'.active');
        }

        foreach ([
            'runtime-installer', 'hostlayout-installer', 'operations-installer',
            'perimeter-installer', 'public-storage-installer', 'verify-mail-capture',
        ] as $child) {
            touch($scratch.'/toggles/'.$child.'-compliant');
        }

        touch($scratch.'/toggles/queue-running');
    } else {
        // Clean host: only ssh runs (a VPS always has it), and only the
        // 5.2/5.3 prerequisite verifies pass.
        touch($scratch.'/svc/ssh.enabled');
        touch($scratch.'/svc/ssh.active');
        touch($scratch.'/toggles/runtime-installer-compliant');
        touch($scratch.'/toggles/hostlayout-installer-compliant');
    }

    return [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_BOOTSTRAPSVC_EUID' => $options['euid'] ?? '0',
        'RATEGURU_BOOTSTRAPSVC_FS_ROOT' => $fs,
        'RATEGURU_BOOTSTRAPSVC_PASSWD_FILE' => $fs.'/etc-passwd',
        'RATEGURU_BOOTSTRAPSVC_GROUP_FILE' => $fs.'/etc-group',
        'RATEGURU_BOOTSTRAPSVC_PGREP_BIN' => $scratch.'/bin/pgrep',
        'RATEGURU_BOOTSTRAPSVC_NGINX_WORKER_WAIT_ATTEMPTS' => '2',
        'RATEGURU_BOOTSTRAPSVC_RUNTIME_INSTALLER_BIN' => $scratch.'/bin/runtime-installer',
        'RATEGURU_BOOTSTRAPSVC_HOSTLAYOUT_INSTALLER_BIN' => $scratch.'/bin/hostlayout-installer',
        'RATEGURU_BOOTSTRAPSVC_OPERATIONS_INSTALLER_BIN' => $scratch.'/bin/operations-installer',
        'RATEGURU_BOOTSTRAPSVC_PERIMETER_INSTALLER_BIN' => $scratch.'/bin/perimeter-installer',
        'RATEGURU_BOOTSTRAPSVC_PUBLIC_STORAGE_INSTALLER_BIN' => $scratch.'/bin/public-storage-installer',
        'RATEGURU_BOOTSTRAPSVC_MAIL_CAPTURE_INSTALLER_BIN' => $scratch.'/bin/mail-capture-installer',
        'RATEGURU_BOOTSTRAPSVC_VERIFY_MAIL_CAPTURE_BIN' => $scratch.'/bin/verify-mail-capture',
        'RATEGURU_BOOTSTRAPSVC_SYSTEMCTL_BIN' => $scratch.'/bin/systemctl',
        'RATEGURU_BOOTSTRAPSVC_NGINX_BIN' => $scratch.'/bin/nginx',
        'RATEGURU_BOOTSTRAPSVC_SSHD_BIN' => $scratch.'/bin/sshd',
        'RATEGURU_BOOTSTRAPSVC_PHP_FPM_BIN' => $scratch.'/bin/php-fpm8.5',
        'RATEGURU_BOOTSTRAPSVC_SUPERVISORCTL_BIN' => $scratch.'/bin/supervisorctl',
        'RATEGURU_BOOTSTRAPSVC_STAT_BIN' => $scratch.'/bin/stat',
        'RATEGURU_BOOTSTRAPSVC_INSTALL_BIN' => $scratch.'/bin/install',
        'RATEGURU_BOOTSTRAPSVC_CHOWN_BIN' => $scratch.'/bin/chown',
        'RATEGURU_BOOTSTRAPSVC_CHMOD_BIN' => $scratch.'/bin/chmod',
        'RATEGURU_BOOTSTRAPSVC_SOCKET_WAIT_ATTEMPTS' => '1',
        'RATEGURU_BOOTSTRAPSVC_QUEUE_WAIT_ATTEMPTS' => '1',
        'RATEGURU_BOOTSTRAPSVC_STABILITY_WAIT' => '0',
        'RATEGURU_BOOTSTRAPSVC_RETRY_DELAY' => '0',
        'STUB_LOG' => $scratch.'/log',
        'STUB_REAL_PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'STUB_OWNER_TABLE' => $fs.'/owner-table.txt',
        'STUB_TYPE_TABLE' => $fs.'/type-table.txt',
        'STUB_SVC_STATE' => $scratch.'/svc',
        'STUB_TOGGLES' => $scratch.'/toggles',
        'STUB_FS' => $fs,
    ];
}

/**
 * The systemctl mutations (everything except is-enabled/is-active probes).
 *
 * @return list<string>
 */
function bsvcSystemctlMutations(string $scratch): array
{
    $lines = array_filter(explode("\n", bsvcLog($scratch, 'systemctl.log')));

    return array_values(array_filter(
        $lines,
        fn (string $line): bool => ! str_contains($line, 'is-enabled') && ! str_contains($line, 'is-active'),
    ));
}

// =============================================================================
// Shipping and CLI contract
// =============================================================================

it('ships the installer executable, syntax-clean and listed in the required-CLI manifest', function () {
    expect(is_file(bsvcScript()))->toBeTrue();
    expect(is_executable(bsvcScript()))->toBeTrue();

    exec('bash -n '.escapeshellarg(bsvcScript()).' 2>&1', $output, $exit);
    expect($exit)->toBe(0, implode("\n", $output));

    expect(File::get(base_path('infrastructure/config/required-clis.txt')))
        ->toContain("install-bootstrap-services\n");
});

it('prints usage on --help and rejects unknown, missing or duplicated modes', function () {
    $env = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'];

    [$exit, $output] = bsvcRun(['--help'], $env);
    expect($exit)->toBe(0);
    expect($output)->toContain('--check')->toContain('--apply')->toContain('--verify')->toContain('root');

    [$exit, $output] = bsvcRun([], $env);
    expect($exit)->toBe(1);
    expect($output)->toContain('one of --check, --apply or --verify is required');

    [$exit, $output] = bsvcRun(['--check', '--verify'], $env);
    expect($exit)->toBe(1);
    expect($output)->toContain('mode given more than once');

    [$exit, $output] = bsvcRun(['--frobnicate'], $env);
    expect($exit)->toBe(1);
    expect($output)->toContain('unknown argument: --frobnicate');
});

it('requires root for every mode and mutates nothing without it', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['euid' => '1000']);
        $before = bsvcTreeSnapshot($scratch);

        foreach (['--check', '--apply', '--verify'] as $mode) {
            [$exit, $output] = bsvcRun([$mode], $env);

            expect($exit)->toBe(1);
            expect($output)->toContain(substr($mode, 2).' must run as root');
        }

        expect(bsvcTreeSnapshot($scratch))->toBe($before, 'a non-root invocation mutated the fixture');
    } finally {
        bsvcCleanup($scratch);
    }
});

// =============================================================================
// Prerequisite gates (5.2/5.3 authoritative verifies)
// =============================================================================

it('stops --apply before any mutation when the install-bootstrap-runtime or 5.3 verify fails', function () {
    foreach (['runtime-installer' => '5.2', 'hostlayout-installer' => '5.3'] as $child => $slice) {
        $scratch = bsvcScratchDir();

        try {
            $env = bsvcFixture($scratch);
            unlink($scratch.'/toggles/'.$child.'-compliant');

            $before = bsvcTreeSnapshot($scratch.'/fs');
            [$exit, $output] = bsvcRun(['--apply'], $env);

            expect($exit)->toBe(1, $output);
            expect($output)->toContain("converge slice {$slice} first (no service/config mutation was performed)");
            expect(bsvcTreeSnapshot($scratch.'/fs'))->toBe($before, "a failing {$slice} gate must not mutate anything");
            expect(bsvcSystemctlMutations($scratch))->toBe([]);
            expect(bsvcLog($scratch, 'children.log'))->not->toContain('--apply');
        } finally {
            bsvcCleanup($scratch);
        }
    }
});

it('fails closed on an invalid source registry before any mutation', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch);
        file_put_contents($scratch.'/fs/bad-registry.json', "{ not json\n");
        $env['RATEGURU_BOOTSTRAPSVC_SOURCE_REGISTRY'] = $scratch.'/fs/bad-registry.json';

        $before = bsvcTreeSnapshot($scratch.'/fs');
        [$exit, $output] = bsvcRun(['--apply'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('fails targets validate');
        expect(bsvcTreeSnapshot($scratch.'/fs'))->toBe($before);
    } finally {
        bsvcCleanup($scratch);
    }
});

// =============================================================================
// --check: clean PRE_DEPLOY host
// =============================================================================

it('--check on a clean PRE_DEPLOY host reports the full plan, defers the queue, and gives tits-guru zero service configuration', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch);
        $before = bsvcTreeSnapshot($scratch.'/fs');

        [$exit, $output] = bsvcRun(['--check'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('SLICE 5.4 CONTRACT: NOT SATISFIED');
        expect($output)->toContain('PASS     state:staging-main — PRE_DEPLOY');
        expect($output)->toContain('PASS     target:tits-guru — lifecycle=planned — zero service configuration');
        expect($output)->toContain('MISSING  path:/home/www/rateguru/staging/shared/storage/logs');
        expect($output)->toContain('MISSING  file:/etc/nginx/sites-available/rateguru-staging');
        expect($output)->toContain('MISSING  link:/etc/nginx/sites-enabled/rateguru-staging');
        expect($output)->toContain('MISSING  file:/etc/php/8.5/fpm/pool.d/rateguru-staging.conf');
        expect($output)->toContain('MISSING  file:/etc/supervisor/conf.d/rateguru-staging-queue.conf');
        expect($output)->toContain('MISSING  file:/etc/cron.d/rateguru-staging-scheduler');
        expect($output)->toContain('MISSING  file:/etc/ssh/sshd_config.d/70-rateguru-deploy.conf');
        expect($output)->toContain('DEFERRED queue:rateguru-staging-queue — target queue activation DEFERRED until the first release exists');
        expect($output)->toContain('MISSING  service:nginx');
        expect($output)->toContain('-> apply:');

        // Every external prerequisite is present in this fixture.
        expect($output)->toContain('PASS     external-prerequisite:tls-certificate');
        expect($output)->toContain('PASS     external-prerequisite:basic-auth');

        // Planned tits-guru: no production or tits-guru service file is ever
        // planned, mentioned or demanded.
        expect($output)->not->toContain('rateguru-production');
        expect($output)->not->toContain('rateguru-tits-guru');

        // Strictly read-only: no mutation, no reload, no service change.
        expect(bsvcTreeSnapshot($scratch.'/fs'))->toBe($before, '--check mutated the fixture');
        expect(bsvcSystemctlMutations($scratch))->toBe([]);
        expect(bsvcLog($scratch, 'children.log'))->not->toContain('--apply');
    } finally {
        bsvcCleanup($scratch);
    }
});

// =============================================================================
// --apply: clean PRE_DEPLOY host end to end
// =============================================================================

it('converges a clean PRE_DEPLOY host end to end: files, link, log directory, children in order, services, deferred queue', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch);
        $fs = $scratch.'/fs';

        [$exit, $output] = bsvcRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('SLICE 5.4 CONTRACT: SATISFIED');

        // Directly-owned files: installed byte-identical, contract mode.
        foreach (bsvcManagedFiles() as $logical => $src) {
            expect(is_file($fs.$logical))->toBeTrue("{$logical} must be installed");
            expect(file_get_contents($fs.$logical))->toBe(file_get_contents($src));
            expect(substr(sprintf('%o', fileperms($fs.$logical)), -3))->toBe('644');
            expect(bsvcLog($scratch, 'chown.log'))->toContain("root:root {$fs}{$logical}");
        }

        // Enabled symlink points at the sites-available entry.
        expect(is_link($fs.'/etc/nginx/sites-enabled/rateguru-staging'))->toBeTrue();
        expect(readlink($fs.'/etc/nginx/sites-enabled/rateguru-staging'))
            ->toBe('/etc/nginx/sites-available/rateguru-staging');

        // The exact service-support log directory, setgid, runtime-owned.
        $logs = $fs.'/home/www/rateguru/staging/shared/storage/logs';
        expect(is_dir($logs))->toBeTrue();
        expect(fileperms($logs) & 0o7777)->toBe(0o2770);
        expect(bsvcLog($scratch, 'chown.log'))->toContain("rateguru-staging:rateguru-staging {$logs}");

        // Children invoked in dependency order, via their own applies.
        $children = bsvcLog($scratch, 'children.log');
        $order = [
            'runtime-installer --verify',
            'hostlayout-installer --verify',
            'operations-installer --verify',
            'operations-installer --apply',
            'perimeter-installer --verify',
            'perimeter-installer --apply',
            'public-storage-installer --verify --target staging-main',
            'public-storage-installer --apply --target staging-main',
            'verify-mail-capture',
            'mail-capture-installer --apply',
        ];
        $position = -1;
        foreach ($order as $entry) {
            $next = strpos($children, $entry, $position + 1);
            expect($next)->not->toBeFalse("children.log lost '{$entry}' or its ordering:\n{$children}");
            $position = $next;
        }

        // Configuration validated before service activation.
        expect(bsvcLog($scratch, 'nginx.log'))->toContain('nginx -t');
        expect(bsvcLog($scratch, 'sshd.log'))->toContain('sshd -t');
        expect(bsvcLog($scratch, 'php-fpm.log'))->toContain('php-fpm -t');
        expect(bsvcLog($scratch, 'supervisorctl.log'))->toContain('supervisorctl reread');

        // Base services enabled and started.
        $systemctl = bsvcLog($scratch, 'systemctl.log');
        foreach (['nginx', 'php8.5-fpm', 'supervisor', 'postgresql', 'redis-server'] as $unit) {
            expect($systemctl)->toContain("systemctl enable {$unit}");
            expect($systemctl)->toContain("systemctl start {$unit}");
        }

        // PRE_DEPLOY: queue activation deferred — no update/start of the
        // program, and supervisor was started BEFORE the program config was
        // installed (no autostart crash loop on a clean host).
        expect($output)->toContain('activation DEFERRED until the first release exists');
        expect(bsvcLog($scratch, 'supervisorctl.log'))->not->toContain('update');
        expect(bsvcLog($scratch, 'supervisorctl.log'))->not->toContain('start');

        $supervisorStart = strpos($systemctl, 'systemctl start supervisor');
        $confInstall = strpos(bsvcLog($scratch, 'install.log'), 'rateguru-staging-queue.conf');
        expect($supervisorStart)->not->toBeFalse();
        expect($confInstall)->not->toBeFalse();

        // No fake release, current or production/tits-guru resource exists.
        expect(file_exists($fs.'/home/www/rateguru/staging/current'))->toBeFalse('apply must never fabricate current');
        expect(glob($fs.'/home/www/rateguru/staging/releases/*'))->toBe([]);
        expect(file_exists($fs.'/etc/nginx/sites-available/rateguru-production'))->toBeFalse();
        expect(file_exists($fs.'/etc/php/8.5/fpm/pool.d/rateguru-production.conf'))->toBeFalse();
        expect(glob($fs.'/etc/nginx/sites-available/*'))->toBe([$fs.'/etc/nginx/sites-available/rateguru-staging']);

        // Secrets were inspected by presence only: never rewritten, never
        // printed.
        foreach (bsvcExternalPrerequisitePaths() as $path) {
            expect(file_get_contents($fs.$path))->toBe('SECRET-SENTINEL-'.md5($path)."\n", "{$path} was rewritten");
            expect($output)->not->toContain('SECRET-SENTINEL-'.md5($path));
        }
    } finally {
        bsvcCleanup($scratch);
    }
});

it('a second --apply on the converged host performs zero meaningful mutation', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch);

        [$exit1, $out1] = bsvcRun(['--apply'], $env);
        expect($exit1)->toBe(0, $out1);

        // Reset every log, then re-apply.
        foreach (glob($scratch.'/log/*') ?: [] as $log) {
            file_put_contents($log, '');
        }

        [$exit2, $out2] = bsvcRun(['--apply'], $env);

        expect($exit2)->toBe(0, $out2);
        expect($out2)->toContain('SLICE 5.4 CONTRACT: SATISFIED');

        // No managed file was rewritten (the only install calls are the
        // per-run backup directories).
        $installs = bsvcLog($scratch, 'install.log');
        expect($installs)->not->toContain('/etc/');
        expect($installs)->not->toContain('storage/logs');

        // No service was enabled, started, stopped, reloaded or restarted.
        expect(bsvcSystemctlMutations($scratch))->toBe([]);

        // No child installer re-applied.
        expect(bsvcLog($scratch, 'children.log'))->not->toContain('--apply');
        expect($out2)->toContain('already compliant');
    } finally {
        bsvcCleanup($scratch);
    }
});

// =============================================================================
// DEPLOYED host
// =============================================================================

it('recognizes the compliant DEPLOYED staging host: --check and --verify pass, --apply mutates nothing', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);

        [$exit, $output] = bsvcRun(['--check'], $env);
        expect($exit)->toBe(0, $output);
        expect($output)->toContain('SLICE 5.4 CONTRACT: SATISFIED');
        expect($output)->toContain('PASS     state:staging-main — DEPLOYED');
        expect($output)->toContain('PASS     queue:rateguru-staging-queue — RUNNING under supervisor');
        expect($output)->toContain('PASS     socket:/run/php/rateguru-staging.sock');
        expect($output)->not->toContain('DEFERRED queue:');

        [$exit, $output] = bsvcRun(['--verify'], $env);
        expect($exit)->toBe(0, $output);

        foreach (glob($scratch.'/log/*') ?: [] as $log) {
            file_put_contents($log, '');
        }

        [$exit, $output] = bsvcRun(['--apply'], $env);
        expect($exit)->toBe(0, $output);
        expect(bsvcLog($scratch, 'install.log'))->not->toContain('/etc/');
        expect(bsvcSystemctlMutations($scratch))->toBe([]);
        expect(bsvcLog($scratch, 'children.log'))->not->toContain('--apply');
    } finally {
        bsvcCleanup($scratch);
    }
});

it('--verify fails read-only on a non-compliant host, mutating nothing', function () {
    // A clean PRE_DEPLOY host: nothing installed yet -> contract not
    // satisfied, and strictly read-only.
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch);
        $before = bsvcTreeSnapshot($scratch.'/fs');

        [$exit, $output] = bsvcRun(['--verify'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('SLICE 5.4 CONTRACT: NOT SATISFIED');
        expect(bsvcTreeSnapshot($scratch.'/fs'))->toBe($before, 'a failing --verify mutated the clean fixture');
        expect(bsvcSystemctlMutations($scratch))->toBe([]);
        expect(bsvcLog($scratch, 'children.log'))->not->toContain('--apply');
    } finally {
        bsvcCleanup($scratch);
    }

    // A compliant DEPLOYED host with one drifted managed file: --verify
    // reports the drift, fails, and never converges it (that is --apply's
    // job).
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);
        file_put_contents($scratch.'/fs/etc/nginx/sites-available/rateguru-staging', "# drifted nginx config\n");

        $before = bsvcTreeSnapshot($scratch.'/fs');
        [$exit, $output] = bsvcRun(['--verify'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('DRIFT    file:/etc/nginx/sites-available/rateguru-staging');
        expect($output)->toContain('SLICE 5.4 CONTRACT: NOT SATISFIED');
        expect(bsvcTreeSnapshot($scratch.'/fs'))->toBe($before, 'a failing --verify mutated the drifted fixture');
        expect(bsvcSystemctlMutations($scratch))->toBe([]);
    } finally {
        bsvcCleanup($scratch);
    }
});

it('starts a stopped queue program on a DEPLOYED host and verifies it is stably RUNNING', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);
        unlink($scratch.'/toggles/queue-running');

        [$exit, $output] = bsvcRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('queue:rateguru-staging-queue RUNNING (stable)');
        expect(bsvcLog($scratch, 'supervisorctl.log'))->toContain('update rateguru-staging-queue');
    } finally {
        bsvcCleanup($scratch);
    }
});

// =============================================================================
// Broken release state
// =============================================================================

it('fails closed in every mode for broken current shapes: dangling, outside releases, wrong type', function () {
    foreach ([
        'dangling' => 'dangling symlink',
        'outside' => 'resolves outside the releases directory',
        'wrongtype' => 'exists but is not a symlink',
    ] as $shape => $message) {
        $scratch = bsvcScratchDir();

        try {
            $env = bsvcFixture($scratch, ['current' => $shape]);

            [$exit, $output] = bsvcRun(['--check'], $env);
            expect($exit)->toBe(1, "broken current ({$shape}) must fail --check");
            expect($output)->toContain('CONFLICT state:staging-main');
            expect($output)->toContain($message);

            $before = bsvcTreeSnapshot($scratch.'/fs');
            [$exit, $output] = bsvcRun(['--apply'], $env);

            expect($exit)->toBe(1, "broken current ({$shape}) must fail --apply:\n{$output}");
            expect($output)->toContain('broken release state is never treated as PRE_DEPLOY');
            expect(bsvcTreeSnapshot($scratch.'/fs'))->toBe($before, "broken current ({$shape}) apply must not mutate");
            expect(bsvcLog($scratch, 'children.log'))->not->toContain('--apply');
        } finally {
            bsvcCleanup($scratch);
        }
    }
});

// =============================================================================
// Drift, conflicts and symlinked destinations
// =============================================================================

it('reports drift distinctly from missing/conflict and converges only the drifted file on apply', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);
        $fs = $scratch.'/fs';

        // Drift exactly one file.
        file_put_contents($fs.'/etc/nginx/sites-available/rateguru-staging', "# drifted nginx config\n");

        [$exit, $output] = bsvcRun(['--check'], $env);
        expect($exit)->toBe(1, $output);
        expect($output)->toContain('DRIFT    file:/etc/nginx/sites-available/rateguru-staging — content differs from the committed source');
        expect($output)->toContain("DRIFT: 1\n");

        foreach (glob($scratch.'/log/*') ?: [] as $log) {
            file_put_contents($log, '');
        }

        [$exit, $output] = bsvcRun(['--apply'], $env);
        expect($exit)->toBe(0, $output);

        // Only the drifted file was reinstalled; only nginx was reloaded.
        $installs = array_values(array_filter(
            explode("\n", bsvcLog($scratch, 'install.log')),
            fn (string $line): bool => str_contains($line, '/etc/'),
        ));
        expect($installs)->toHaveCount(1);
        expect($installs[0])->toContain('sites-available/rateguru-staging');

        expect(bsvcSystemctlMutations($scratch))->toBe(['systemctl reload nginx']);

        expect(file_get_contents($fs.'/etc/nginx/sites-available/rateguru-staging'))
            ->toBe(file_get_contents(base_path('infrastructure/config/nginx/rateguru-staging')));
    } finally {
        bsvcCleanup($scratch);
    }
});

it('reports wrong owner or mode on an installed file as DRIFT and reinstalls it', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);
        $fs = $scratch.'/fs';

        // Wrong owner on the pool; wrong mode on the cron.
        bsvcOwnerTableAdd($scratch, $fs.'/etc/php/8.5/fpm/pool.d/rateguru-staging.conf', 'www-data', 'www-data');
        chmod($fs.'/etc/cron.d/rateguru-staging-scheduler', 0o600);

        [$exit, $output] = bsvcRun(['--check'], $env);
        expect($exit)->toBe(1, $output);
        expect($output)->toContain('DRIFT    file:/etc/php/8.5/fpm/pool.d/rateguru-staging.conf — owned www-data:www-data, required root:root');
        expect($output)->toContain('DRIFT    file:/etc/cron.d/rateguru-staging-scheduler — mode 600, required 0644');

        [$exit, $output] = bsvcRun(['--apply'], $env);
        expect($exit)->toBe(0, $output);
        expect(substr(sprintf('%o', fileperms($fs.'/etc/cron.d/rateguru-staging-scheduler')), -3))->toBe('644');
    } finally {
        bsvcCleanup($scratch);
    }
});

it('repoints a wrong enabled symlink and refuses a non-symlink at the enabled path', function () {
    // Wrong target: DRIFT, converged by apply.
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);
        $fs = $scratch.'/fs';

        unlink($fs.'/etc/nginx/sites-enabled/rateguru-staging');
        symlink('/etc/nginx/sites-available/default', $fs.'/etc/nginx/sites-enabled/rateguru-staging');

        [$exit, $output] = bsvcRun(['--check'], $env);
        expect($exit)->toBe(1, $output);
        expect($output)->toContain('DRIFT    link:/etc/nginx/sites-enabled/rateguru-staging — symlink points at /etc/nginx/sites-available/default');

        [$exit, $output] = bsvcRun(['--apply'], $env);
        expect($exit)->toBe(0, $output);
        expect(readlink($fs.'/etc/nginx/sites-enabled/rateguru-staging'))
            ->toBe('/etc/nginx/sites-available/rateguru-staging');
    } finally {
        bsvcCleanup($scratch);
    }

    // Non-symlink at the enabled path: CONFLICT, apply fails before mutation.
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);
        $fs = $scratch.'/fs';

        unlink($fs.'/etc/nginx/sites-enabled/rateguru-staging');
        file_put_contents($fs.'/etc/nginx/sites-enabled/rateguru-staging', "not a symlink\n");

        [$exit, $output] = bsvcRun(['--check'], $env);
        expect($exit)->toBe(1, $output);
        expect($output)->toContain('CONFLICT link:/etc/nginx/sites-enabled/rateguru-staging — exists but is not a symlink');

        [$exit, $output] = bsvcRun(['--apply'], $env);
        expect($exit)->toBe(1, $output);
        expect($output)->toContain('enabled site path exists but is not a symlink');
    } finally {
        bsvcCleanup($scratch);
    }
});

it('refuses wrong-type and symlinked destinations without touching them', function () {
    // A directory where the pool file belongs.
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);
        $fs = $scratch.'/fs';

        unlink($fs.'/etc/php/8.5/fpm/pool.d/rateguru-staging.conf');
        mkdir($fs.'/etc/php/8.5/fpm/pool.d/rateguru-staging.conf');

        [$exit, $output] = bsvcRun(['--check'], $env);
        expect($exit)->toBe(1, $output);
        expect($output)->toContain('CONFLICT file:/etc/php/8.5/fpm/pool.d/rateguru-staging.conf — is a directory');

        [$exit, $output] = bsvcRun(['--apply'], $env);
        expect($exit)->toBe(1, $output);
        expect($output)->toContain('destination exists but is not a regular file');
        expect(is_dir($fs.'/etc/php/8.5/fpm/pool.d/rateguru-staging.conf'))->toBeTrue('the conflicting directory must not be deleted or replaced');
    } finally {
        bsvcCleanup($scratch);
    }

    // A symlink planted at a managed destination (symlink attack): never
    // followed, never written through.
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);
        $fs = $scratch.'/fs';

        $victim = $scratch.'/victim.txt';
        file_put_contents($victim, "VICTIM CONTENT\n");
        unlink($fs.'/etc/ssh/sshd_config.d/70-rateguru-deploy.conf');
        symlink($victim, $fs.'/etc/ssh/sshd_config.d/70-rateguru-deploy.conf');

        [$exit, $output] = bsvcRun(['--apply'], $env);
        expect($exit)->toBe(1, $output);
        expect($output)->toContain('destination is a symlink');
        expect(file_get_contents($victim))->toBe("VICTIM CONTENT\n", 'the symlink target must never be written through');
        expect(is_link($fs.'/etc/ssh/sshd_config.d/70-rateguru-deploy.conf'))->toBeTrue();
    } finally {
        bsvcCleanup($scratch);
    }
});

// =============================================================================
// External prerequisites
// =============================================================================

it('fails --apply before any mutation when TLS, private key or Basic Auth material is missing — naming category and path only', function () {
    foreach ([
        '/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/fullchain.pem' => 'tls-certificate',
        '/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/privkey.pem' => 'tls-private-key',
        '/etc/nginx/rateguru-staging.htpasswd' => 'basic-auth',
    ] as $path => $category) {
        $scratch = bsvcScratchDir();

        try {
            $env = bsvcFixture($scratch, ['omitExternal' => [$path]]);

            [$exit, $output] = bsvcRun(['--check'], $env);
            expect($exit)->toBe(1);
            expect($output)->toContain("MISSING  external-prerequisite:{$category} — absent: {$path}");

            $before = bsvcTreeSnapshot($scratch.'/fs');
            [$exit, $output] = bsvcRun(['--apply'], $env);

            expect($exit)->toBe(1, $output);
            expect($output)->toContain("EXTERNAL PREREQUISITE MISSING: {$category} ({$path})");
            expect(bsvcTreeSnapshot($scratch.'/fs'))->toBe($before, "a missing {$category} must block every mutation");
            expect(bsvcSystemctlMutations($scratch))->toBe([]);
            expect(bsvcLog($scratch, 'children.log'))->not->toContain('--apply');

            // Never created, never faked.
            expect(file_exists($scratch.'/fs'.$path))->toBeFalse('the missing external prerequisite must never be fabricated');
        } finally {
            bsvcCleanup($scratch);
        }
    }
});

// =============================================================================
// Config validation failure -> rollback
// =============================================================================

it('rolls back the candidate and preserves the previous running state when a config test fails', function () {
    foreach ([
        'nginx-t-fail' => ['nginx -t failed', '/etc/nginx/sites-available/rateguru-staging'],
        'sshd-t-fail' => ['sshd configuration test failed', '/etc/ssh/sshd_config.d/70-rateguru-deploy.conf'],
        'php-fpm-t-fail' => ['PHP-FPM configuration test failed', '/etc/php/8.5/fpm/pool.d/rateguru-staging.conf'],
        'supervisor-reread-fail' => ['supervisorctl reread reported a configuration error', '/etc/supervisor/conf.d/rateguru-staging-queue.conf'],
    ] as $toggle => [$message, $logical]) {
        $scratch = bsvcScratchDir();

        try {
            $env = bsvcFixture($scratch, ['profile' => 'compliant']);
            $fs = $scratch.'/fs';

            // Drift the family's file so apply must install a candidate, and
            // make its validation fail.
            $previous = "# previous installed configuration\n";
            file_put_contents($fs.$logical, $previous);
            touch($scratch.'/toggles/'.$toggle);

            [$exit, $output] = bsvcRun(['--apply'], $env);

            expect($exit)->toBe(1, $output);
            expect($output)->toContain($message);
            expect($output)->toContain('rollback complete');

            // The previous file is back, byte-identical.
            expect(file_get_contents($fs.$logical))->toBe($previous, "{$logical} must be restored after a failed {$toggle}");

            // The service was never reloaded with the invalid candidate, and
            // nothing that was running was stopped.
            $mutations = bsvcSystemctlMutations($scratch);
            foreach ($mutations as $mutation) {
                expect($mutation)->not->toContain('reload nginx');
                expect($mutation)->not->toContain('reload php8.5-fpm');
            }

            foreach (['nginx', 'php8.5-fpm', 'supervisor', 'ssh'] as $unit) {
                expect(file_exists($scratch.'/svc/'.$unit.'.active'))->toBeTrue("{$unit} must still be running after the failed apply");
            }
        } finally {
            bsvcCleanup($scratch);
        }
    }
});

it('reverts service enable/start state changes when a later step fails', function () {
    $scratch = bsvcScratchDir();

    try {
        // Clean host, but mail capture apply fails — everything earlier
        // (files, service starts) must be rolled back.
        $env = bsvcFixture($scratch);
        touch($scratch.'/toggles/mail-capture-installer-apply-fail');

        [$exit, $output] = bsvcRun(['--apply'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('install-mail-capture --apply failed');
        expect($output)->toContain('rollback complete');

        // Every directly-owned file this run installed was removed again.
        foreach (array_keys(bsvcManagedFiles()) as $logical) {
            expect(file_exists($scratch.'/fs'.$logical))->toBeFalse("{$logical} must be removed by rollback");
        }
        expect(is_link($scratch.'/fs/etc/nginx/sites-enabled/rateguru-staging'))->toBeFalse('the enabled link must be removed by rollback');

        // Services this run started/enabled were stopped/disabled again.
        foreach (['nginx', 'php8.5-fpm', 'supervisor'] as $unit) {
            expect(file_exists($scratch.'/svc/'.$unit.'.active'))->toBeFalse("{$unit} started by this run must be stopped by rollback");
            expect(file_exists($scratch.'/svc/'.$unit.'.enabled'))->toBeFalse("{$unit} enabled by this run must be disabled by rollback");
        }

        // ssh was running before this run and stays running.
        expect(file_exists($scratch.'/svc/ssh.active'))->toBeTrue('ssh must never be stopped by rollback');
    } finally {
        bsvcCleanup($scratch);
    }
});

it('re-applies the restored supervisor program configuration when a later step fails after supervisorctl update ran', function () {
    $scratch = bsvcScratchDir();

    try {
        // DEPLOYED host with a drifted supervisor conf (so apply reinstalls
        // it and pushes it into the running supervisor via update), then a
        // later component (mail capture) fails.
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);
        $conf = $scratch.'/fs/etc/supervisor/conf.d/rateguru-staging-queue.conf';
        $previous = "# previous installed supervisor configuration\n";
        file_put_contents($conf, $previous);

        unlink($scratch.'/toggles/verify-mail-capture-compliant');
        touch($scratch.'/toggles/mail-capture-installer-apply-fail');

        [$exit, $output] = bsvcRun(['--apply'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('install-mail-capture --apply failed');
        expect($output)->toContain('rollback complete');

        // The file is back to its exact previous content, and the running
        // supervisor was re-pointed at the restored configuration — never
        // left on the rolled-back candidate.
        expect(file_get_contents($conf))->toBe($previous);
        expect($output)->toContain('rollback: supervisor program rateguru-staging-queue re-applied from the restored configuration');

        // Validation preceded the re-apply: a reread ran before the final
        // update in the supervisorctl log.
        $supervisorctl = bsvcLog($scratch, 'supervisorctl.log');
        expect(strrpos($supervisorctl, 'supervisorctl reread'))
            ->toBeLessThan(strrpos($supervisorctl, 'supervisorctl update rateguru-staging-queue'));
    } finally {
        bsvcCleanup($scratch);
    }
});

it('aborts immediately when a child installer fails, without invoking later components', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch);
        touch($scratch.'/toggles/operations-installer-apply-fail');

        [$exit, $output] = bsvcRun(['--apply'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('install-target-operations --apply failed');

        $children = bsvcLog($scratch, 'children.log');
        expect($children)->not->toContain('perimeter-installer --apply');
        expect($children)->not->toContain('public-storage-installer');
        expect($children)->not->toContain('mail-capture-installer');

        // No later directly-owned file was installed either.
        expect(file_exists($scratch.'/fs/etc/ssh/sshd_config.d/70-rateguru-deploy.conf'))->toBeFalse();
        expect(file_exists($scratch.'/fs/etc/nginx/sites-available/rateguru-staging'))->toBeFalse();
    } finally {
        bsvcCleanup($scratch);
    }
});

// =============================================================================
// Services
// =============================================================================

it('enables and starts stopped base services, and leaves compliant ones untouched', function () {
    $scratch = bsvcScratchDir();

    try {
        // Compliant deployed host except redis is stopped and postgresql is
        // active but disabled.
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);
        unlink($scratch.'/svc/redis-server.active');
        unlink($scratch.'/svc/postgresql.enabled');

        [$exit, $output] = bsvcRun(['--check'], $env);
        expect($exit)->toBe(1, $output);
        expect($output)->toContain('MISSING  service:redis-server — enabled but not active');
        expect($output)->toContain('MISSING  service:postgresql — active but not enabled');

        foreach (glob($scratch.'/log/*') ?: [] as $log) {
            file_put_contents($log, '');
        }

        [$exit, $output] = bsvcRun(['--apply'], $env);
        expect($exit)->toBe(0, $output);

        // Exactly the two needed mutations happened — nothing else was
        // enabled, started, stopped, reloaded or restarted.
        expect(bsvcSystemctlMutations($scratch))->toBe([
            'systemctl enable postgresql',
            'systemctl start redis-server',
        ]);
    } finally {
        bsvcCleanup($scratch);
    }
});

// =============================================================================
// Slice 5.5: mail-capture verification is genuinely read-only
// =============================================================================

it('only ever invokes verify-mail-capture in --read-only mode, in every mode including apply', function () {
    // verify-mail-capture's default (--e2e) sends SMTP, deletes messages and
    // stops/starts the mirror service. If this installer used it, its
    // --check/--verify would be mutating — and bootstrap-host, which delegates
    // to them, would inherit that. The full acceptance run stays an explicit
    // operator command.
    foreach (['clean', 'compliant'] as $profile) {
        foreach (['--check', '--verify', '--apply'] as $mode) {
            $scratch = bsvcScratchDir();

            try {
                $env = bsvcFixture($scratch, ['profile' => $profile]);
                bsvcRun([$mode], $env);

                $invocations = array_values(array_filter(
                    explode("\n", bsvcLog($scratch, 'children.log')),
                    fn (string $line): bool => str_starts_with($line, 'verify-mail-capture'),
                ));

                expect($invocations)->not->toBe(
                    [],
                    "{$profile} {$mode} never reached verify-mail-capture — the assertion would be vacuous",
                );

                foreach ($invocations as $invocation) {
                    // toContain() is variadic in Pest — a second argument
                    // would be another needle, not a failure message.
                    expect(str_contains($invocation, '--read-only'))
                        ->toBeTrue("{$profile} {$mode} invoked the mutating mail-capture verifier: {$invocation}");
                }
            } finally {
                bsvcCleanup($scratch);
            }
        }
    }
});

// =============================================================================
// Secrets never enter output
// =============================================================================

it('never prints secret content in any mode', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);
        $fs = $scratch.'/fs';

        // Also give the informational secret-readiness files sentinel
        // content.
        @mkdir($fs.'/home/www/rateguru/staging/shared', 0o755, true);
        @mkdir($fs.'/home/deploy-rateguru-staging/.ssh', 0o755, true);
        @mkdir($fs.'/root/.config/rclone', 0o755, true);
        file_put_contents($fs.'/home/www/rateguru/staging/shared/.env', "APP_KEY=base64:ENV-SECRET-SENTINEL\n");
        file_put_contents($fs.'/home/deploy-rateguru-staging/.ssh/authorized_keys', "ssh-ed25519 DEPLOY-KEY-SENTINEL\n");
        file_put_contents($fs.'/root/.config/rclone/rclone.conf', "b2_account_key = RCLONE-SECRET-SENTINEL\n");

        foreach (['--check', '--verify', '--apply'] as $mode) {
            [, $output] = bsvcRun([$mode], $env);

            foreach (['ENV-SECRET-SENTINEL', 'DEPLOY-KEY-SENTINEL', 'RCLONE-SECRET-SENTINEL', 'SECRET-SENTINEL-'] as $sentinel) {
                expect(str_contains($output, $sentinel))->toBeFalse("{$mode} output leaked secret content ({$sentinel})");
            }
        }

        // Present secret-readiness files are PASS by presence only, and were
        // never rewritten.
        [, $output] = bsvcRun(['--check'], $env);
        expect($output)->toContain('PASS     secret:laravel-env:staging-main');
        expect(file_get_contents($fs.'/home/www/rateguru/staging/shared/.env'))->toBe("APP_KEY=base64:ENV-SECRET-SENTINEL\n");
    } finally {
        bsvcCleanup($scratch);
    }
});

// =============================================================================
// Documentation and roadmap
// =============================================================================

it('documents the ownership boundaries and the PRE_DEPLOY/DEPLOYED distinction in the runbook', function () {
    $runbook = File::get(base_path('infrastructure/runbooks/bootstrap-services.md'));

    expect($runbook)
        ->toContain('install-bootstrap-services')
        ->toContain('install-target-operations')
        ->toContain('install-target-perimeter')
        ->toContain('install-public-storage-access')
        ->toContain('install-mail-capture')
        ->toContain('PRE_DEPLOY')
        ->toContain('DEPLOYED')
        ->toContain('EXTERNAL PREREQUISITE MISSING');

    // The clean-VPS architecture note: bootstrap readiness and application
    // runtime readiness are distinct; no release is ever fabricated.
    expect($runbook)->toContain('never fabricate');

    // The known first-deploy queue-activation gap is documented as a
    // 5.5/5.6 integration fix, not silently ignored.
    expect($runbook)->toContain('5.5/5.6');
});

// =============================================================================
// Nginx worker supplementary groups (the clean-VPS blocker #2).
//
// Adding www-data to a code group in /etc/group does not change the
// supplementary groups of Nginx workers that are already running. A host can
// therefore have a perfectly correct account database and still 404 every
// request, because the live workers predate the membership. Only a reload
// (never a restart) replaces them.
// =============================================================================

it('does not pass when the account is a code-group member but the running workers are stale', function () {
    foreach (['--check', '--verify'] as $mode) {
        $scratch = bsvcScratchDir();

        try {
            // Workers carry only www-data's own GID — exactly the clean-VPS
            // state after 5.3 appended the membership but before any reload.
            $env = bsvcFixture($scratch, [
                'profile' => 'compliant',
                'nginxWorkers' => ['4101' => ['33'], '4102' => ['33']],
            ]);

            $before = bsvcTreeSnapshot($scratch.'/fs');
            [$exit, $output] = bsvcRun([$mode], $env);

            expect($exit)->toBe(1, "{$mode} must not pass with stale workers:\n{$output}");
            expect($output)->toContain('DRIFT    nginx-workers:rateguru-staging-code');
            expect($output)->toContain('running workers predate the rateguru-staging-code membership');

            // Read-only means read-only: no reload, no restart, no mutation.
            expect(bsvcSystemctlMutations($scratch))->toBe([], "{$mode} must not touch any service");
            expect(bsvcTreeSnapshot($scratch.'/fs'))->toBe($before, "{$mode} mutated the fixture");
        } finally {
            bsvcCleanup($scratch);
        }
    }
});

it('--apply reloads nginx once for stale workers and then verifies the replacements carry the group', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, [
            'profile' => 'compliant',
            'nginxWorkers' => ['4101' => ['33'], '4102' => ['33']],
        ]);

        [$exit, $output] = bsvcRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('service:nginx workers are stale — reloading once');
        expect($output)->toContain('stale: rateguru-staging-code:');
        expect($output)->toContain('nginx-workers:rateguru-staging-code every running worker carries the code-group GID');
        expect($output)->toContain('SLICE 5.4 CONTRACT: SATISFIED');

        // Reload, never restart — and exactly once.
        $mutations = bsvcSystemctlMutations($scratch);
        expect($mutations)->toBe(['systemctl reload nginx']);

        // The configuration was validated before the reload.
        expect(bsvcLog($scratch, 'nginx.log'))->toContain('nginx -t');
    } finally {
        bsvcCleanup($scratch);
    }
});

it('--apply performs no nginx reload when the running workers already carry the group', function () {
    $scratch = bsvcScratchDir();

    try {
        // The default compliant fixture already has current workers.
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);

        [$exit, $output] = bsvcRun(['--apply'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('workers already carry every active code group — no reload needed');
        expect(bsvcSystemctlMutations($scratch))->toBe([], 'a converged host must not be reloaded');

        // And a second apply stays mutation-free too.
        foreach (glob($scratch.'/log/*') ?: [] as $log) {
            file_put_contents($log, '');
        }

        [$exit2, $out2] = bsvcRun(['--apply'], $env);
        expect($exit2)->toBe(0, $out2);
        expect(bsvcSystemctlMutations($scratch))->toBe([]);
    } finally {
        bsvcCleanup($scratch);
    }
});

it('fails closed when a reload does not produce workers carrying the required group', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, [
            'profile' => 'compliant',
            'nginxWorkers' => ['4101' => ['33']],
        ]);
        // The reload happens but the replacements are still stale — the
        // installer must not declare the contract satisfied.
        touch($scratch.'/toggles/nginx-reload-keeps-stale-workers');

        [$exit, $output] = bsvcRun(['--apply'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('nginx workers still do not carry the rateguru-staging-code GID after activation');
        expect($output)->toContain('cannot read /home/www/rateguru/staging/current/public');
    } finally {
        bsvcCleanup($scratch);
    }
});

/**
 * A genuine multi-active-target repository: the real infrastructure/ tree
 * copied to a scratch root, its registry replaced by one declaring TWO
 * lifecycle=active targets with different code groups (plus the planned
 * tits-guru), the targets validator's active allowlist widened to match, and
 * a full set of committed service sources authored for the second target.
 *
 * The installer derives REPO_ROOT from its own location, so running the copy
 * exercises the real script against a real second target — no fabricated
 * GIDs and no new override surface.
 *
 * @return array{0: string, 1: array<string, string>} [scriptPath, env]
 */
function bsvcMultiTargetRepo(string $scratch, array $options = []): array
{
    $repo = $scratch.'/repo';
    exec('cp -R '.escapeshellarg(base_path('infrastructure')).' '.escapeshellarg($repo.'-infra-tmp').' 2>&1', $o, $c);
    expect($c)->toBe(0, 'could not copy the infrastructure tree');
    @mkdir($repo, 0o755, true);
    rename($repo.'-infra-tmp', $repo.'/infrastructure');

    // Two active targets, different code groups; tits-guru stays planned.
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true, 512, JSON_THROW_ON_ERROR);
    $second = $registry['targets']['staging-main'];
    $second['id'] = 'staging-second';
    $second['application_root'] = '/home/www/rateguru/second';
    $second['runtime_user'] = 'rateguru-second';
    $second['runtime_group'] = 'rateguru-second';
    $second['deploy_user'] = 'deploy-rateguru-second';
    $second['code_group'] = 'rateguru-second-code';
    $second['incoming_artifacts'] = '/home/deploy-rateguru-second/incoming';
    $second['database'] = ['name' => 'rateguru_second', 'application_role' => 'rateguru_second_app'];
    $second['health'] = ['url' => 'http://127.0.0.1/', 'host_header' => 'rateguru-second.internal'];
    $second['public_hostnames'] = ['second.staging.myprojects.pp.ua'];
    $second['backup'] = ['namespace' => 'second', 'local_retention_days' => 5, 'offsite_retention_days' => 14, 'minimum_retained_backups' => 2];
    $second['php_fpm'] = ['pool' => 'rateguru-second', 'socket' => '/run/php/rateguru-second.sock'];
    $second['supervisor'] = ['program' => 'rateguru-second-queue', 'queue' => 'rateguru-second'];
    $second['scheduler'] = ['name' => 'rateguru-second-scheduler'];
    $second['nginx'] = ['site_name' => 'rateguru-second', 'internal_hostname' => 'rateguru-second.internal'];
    $registry['targets']['staging-second'] = $second;

    file_put_contents(
        $repo.'/infrastructure/config/deployment-targets.json',
        json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n",
    );

    // The validator allows exactly one active target by name; widen it for
    // this fixture only.
    $targets = $repo.'/infrastructure/scripts/targets';
    file_put_contents($targets, str_replace(
        'ACTIVE_ALLOWLIST="staging-main"',
        'ACTIVE_ALLOWLIST_MULTI=(staging-main staging-second)',
        File::get($targets),
    ));
    file_put_contents($targets, str_replace(
        '[[ "${target_id}" != "${ACTIVE_ALLOWLIST}" ]]',
        '! printf \'%s\\n\' "${ACTIVE_ALLOWLIST_MULTI[@]}" | grep -Fxq "${target_id}"',
        File::get($targets),
    ));
    file_put_contents($targets, str_replace(
        'lifecycle=active is currently allowed only for ${ACTIVE_ALLOWLIST}',
        'lifecycle=active is currently allowed only for ${ACTIVE_ALLOWLIST_MULTI[*]}',
        File::get($targets),
    ));

    // Committed service sources for the second target, derived from the
    // first so every registry/config consistency check still applies.
    foreach ([
        'config/nginx/rateguru-staging' => 'config/nginx/rateguru-second',
        'config/php-fpm/rateguru-staging.conf' => 'config/php-fpm/rateguru-second.conf',
        'config/supervisor/rateguru-staging-queue.conf' => 'config/supervisor/rateguru-second-queue.conf',
        'config/cron/rateguru-staging-scheduler' => 'config/cron/rateguru-second-scheduler',
    ] as $from => $to) {
        $body = File::get($repo.'/infrastructure/'.$from);
        $body = str_replace(
            ['/home/www/rateguru/staging', 'rateguru-staging-queue', 'rateguru-staging', 'rateguru.staging.myprojects.pp.ua'],
            ['/home/www/rateguru/second', 'rateguru-second-queue', 'rateguru-second', 'second.staging.myprojects.pp.ua'],
            $body,
        );
        file_put_contents($repo.'/infrastructure/'.$to, $body);
    }

    return [$repo.'/infrastructure/scripts/install-bootstrap-services', $options];
}

it('requires www-data in every active target code group, on a real two-active-target registry', function () {
    $scratch = bsvcScratchDir();

    try {
        // Workers carry only the FIRST target's code group (5010). The
        // second target's group (5020) is genuinely declared by the registry
        // now, not fabricated.
        $env = bsvcFixture($scratch, [
            'profile' => 'compliant',
            'nginxWorkers' => ['4101' => ['33', '5010']],
        ]);
        [$script] = bsvcMultiTargetRepo($scratch);

        // The second target's group exists in the group database.
        file_put_contents($scratch.'/fs/etc-group', file_get_contents($scratch.'/fs/etc-group')
            ."rateguru-second:x:5002:\nrateguru-second-code:x:5020:rateguru-second,www-data\n");

        $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
        $process = proc_open(array_merge(['bash', $script, '--check'], []), $descriptors, $pipes, null, $env);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);

        // Both targets are active and both code groups are asserted.
        expect($output)->toContain('2 active target(s): staging-main staging-second');
        expect($output)->toContain('PASS     nginx-workers:rateguru-staging-code');
        expect($output)->toContain('DRIFT    nginx-workers:rateguru-second-code');
        expect($output)->toContain('worker(s) without gid 5020');
        expect($exit)->toBe(1, "a worker missing one active code group must not pass:\n{$output}");

        // The planned target never appears in the worker contract.
        expect($output)->not->toContain('nginx-workers:tits-guru');
        expect($output)->toContain('target:tits-guru — lifecycle=planned');
    } finally {
        bsvcCleanup($scratch);
    }
});

it('passes on the two-active-target registry once workers carry both code groups', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, [
            'profile' => 'compliant',
            'nginxWorkers' => ['4101' => ['33', '5010', '5020'], '4102' => ['33', '5010', '5020']],
        ]);
        [$script] = bsvcMultiTargetRepo($scratch);

        file_put_contents($scratch.'/fs/etc-group', file_get_contents($scratch.'/fs/etc-group')
            ."rateguru-second:x:5002:\nrateguru-second-code:x:5020:rateguru-second,www-data\n");

        $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
        $process = proc_open(['bash', $script, '--check'], $descriptors, $pipes, null, $env);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        expect($output)->toContain('PASS     nginx-workers:rateguru-staging-code');
        expect($output)->toContain('PASS     nginx-workers:rateguru-second-code');
        expect(bsvcSystemctlMutations($scratch))->toBe([], '--check must never touch a service');
    } finally {
        bsvcCleanup($scratch);
    }
});

/**
 * Extend a compliant fixture with everything the SECOND active target needs
 * for a full --apply: identities, filesystem roots and its PHP-FPM socket.
 */
function bsvcAddSecondTargetHostState(string $scratch): void
{
    $fs = $scratch.'/fs';

    file_put_contents($fs.'/etc-group', file_get_contents($fs.'/etc-group')
        ."rateguru-second:x:5002:\nrateguru-second-code:x:5020:rateguru-second,www-data\n");

    file_put_contents($fs.'/etc-passwd', file_get_contents($fs.'/etc-passwd')
        ."rateguru-second:x:5003:5002::/home/www/rateguru/second:/usr/sbin/nologin\n"
        ."deploy-rateguru-second:x:5004:5004::/home/deploy-rateguru-second:/bin/bash\n");

    foreach (['/home/www/rateguru/second/shared/storage', '/home/www/rateguru/second/releases'] as $dir) {
        @mkdir($fs.$dir, 0o755, true);
    }

    // External prerequisites the second target's committed vhost references.
    // The installer correctly refuses to apply without them, so the fixture
    // must supply them exactly as an operator would.
    foreach ([
        '/etc/nginx/rateguru-second.htpasswd',
        '/etc/letsencrypt/live/second.staging.myprojects.pp.ua/fullchain.pem',
        '/etc/letsencrypt/live/second.staging.myprojects.pp.ua/privkey.pem',
    ] as $path) {
        @mkdir(dirname($fs.$path), 0o755, true);
        file_put_contents($fs.$path, 'SECRET-SENTINEL-'.md5($path)."\n");
    }

    // The second pool's socket, presented as a socket via the type table.
    touch($fs.'/run/php/rateguru-second.sock');
    chmod($fs.'/run/php/rateguru-second.sock', 0o660);
    file_put_contents($fs.'/type-table.txt', $fs."/run/php/rateguru-second.sock|TYPE|socket\n", FILE_APPEND);
    bsvcOwnerTableAdd($scratch, $fs.'/run/php/rateguru-second.sock', 'www-data', 'www-data');
}

it('--apply on a real two-active-target host performs exactly one validated nginx reload', function () {
    $scratch = bsvcScratchDir();

    try {
        // Both targets stale: workers carry neither code group. A per-target
        // reload would produce two; the aggregated phase must produce one.
        $env = bsvcFixture($scratch, [
            'profile' => 'compliant',
            'nginxWorkers' => ['4101' => ['33']],
            'nginxFreshWorkerGids' => '33 5010 5020',
        ]);
        [$script] = bsvcMultiTargetRepo($scratch);
        bsvcAddSecondTargetHostState($scratch);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
        $process = proc_open(['bash', $script, '--apply'], $descriptors, $pipes, null, $env);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('2 active target(s): staging-main staging-second');

        // Exactly one nginx reload for the whole apply, not one per target.
        $reloads = array_values(array_filter(
            bsvcSystemctlMutations($scratch),
            fn (string $line): bool => $line === 'systemctl reload nginx',
        ));
        expect($reloads)->toHaveCount(1, "expected exactly one nginx reload:\n".implode("\n", bsvcSystemctlMutations($scratch)));

        // Never a restart for this condition.
        expect(implode("\n", bsvcSystemctlMutations($scratch)))->not->toContain('restart nginx');

        // Both targets' code groups were verified after the single reload.
        expect($output)->toContain('nginx-workers:rateguru-staging-code every running worker carries');
        expect($output)->toContain('nginx-workers:rateguru-second-code every running worker carries');
        expect($output)->toContain('SLICE 5.4 CONTRACT: SATISFIED');

        // The planned target got no service configuration at all.
        expect($output)->not->toContain('nginx-workers:tits-guru');
        expect(file_exists($scratch.'/fs/etc/nginx/sites-available/rateguru-production'))->toBeFalse();
    } finally {
        bsvcCleanup($scratch);
    }
});

it('--apply on a converged two-active-target host reloads nginx zero times', function () {
    $scratch = bsvcScratchDir();

    try {
        // Workers already carry both code groups and both sites are already
        // installed by the first apply.
        $env = bsvcFixture($scratch, [
            'profile' => 'compliant',
            'nginxWorkers' => ['4101' => ['33', '5010', '5020']],
            'nginxFreshWorkerGids' => '33 5010 5020',
        ]);
        [$script] = bsvcMultiTargetRepo($scratch);
        bsvcAddSecondTargetHostState($scratch);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
        $first = proc_open(['bash', $script, '--apply'], $descriptors, $pipes, null, $env);
        $firstOutput = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        expect(proc_close($first))->toBe(0, $firstOutput);

        foreach (glob($scratch.'/log/*') ?: [] as $log) {
            file_put_contents($log, '');
        }

        $second = proc_open(['bash', $script, '--apply'], $descriptors, $pipes, null, $env);
        $secondOutput = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        expect(proc_close($second))->toBe(0, $secondOutput);

        expect($secondOutput)->toContain('workers already carry every active code group — no reload needed');
        expect(bsvcSystemctlMutations($scratch))->toBe([], 'a converged multi-target host must be mutation-free');
    } finally {
        bsvcCleanup($scratch);
    }
});

it('reports stale workers rather than crashing when nginx is stopped or workers are unreadable', function () {
    // nginx stopped: the runtime state genuinely cannot be inspected.
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);
        unlink($scratch.'/svc/nginx.active');

        [$exit, $output] = bsvcRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  nginx-workers:rateguru-staging-code — nginx is not running');
    } finally {
        bsvcCleanup($scratch);
    }

    // No workers at all.
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant', 'nginxWorkers' => []]);

        [$exit, $output] = bsvcRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('no www-data nginx worker process found to inspect');
    } finally {
        bsvcCleanup($scratch);
    }
});

// =============================================================================
// Target-scoped mode: --target TARGET_ID
// =============================================================================
//
// The same contract, narrowed to one target. Everything host-wide — the SSH
// deploy restriction, the operations and perimeter families, mail capture, the
// base services themselves — stops being this run's to converge and becomes a
// prerequisite it only inspects. That is what keeps repairing one live target
// from quietly turning into re-bootstrapping the host underneath it.
//
// The first test is the one that matters most: WITHOUT --target nothing about
// this installer changed.

it('leaves host mode exactly as it was: no --target, no target vocabulary anywhere in the report', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);

        [$exit, $output] = bsvcRun(['--check'], $env);

        expect($exit)->toBe(0, "host mode must still verify the compliant fixture clean:\n{$output}");

        expect($output)->toContain('Bootstrap services installer (check):');

        // Every host-wide item is still this run's own.
        expect($output)->toContain('SSH deploy restriction');
        expect($output)->toContain('config-test:sshd');
        expect($output)->toContain('mail-capture:verify-mail-capture');

        // The two things that only exist in target mode must not leak into it.
        expect($output)->not->toContain('HOST-REQ');
        expect($output)->not->toContain('Scope: target');
    } finally {
        bsvcCleanup($scratch);
    }
});

it('narrows to one target and leaves every host-wide family out of scope', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);

        [$exit, $output] = bsvcRun(['--check', '--target', 'staging-main'], $env);

        expect($exit)->toBe(0, "target mode must verify the compliant fixture clean:\n{$output}");

        expect($output)->toContain('Scope: target staging-main only');
        expect($output)->toContain('TARGET SERVICES CONTRACT (staging-main): SATISFIED');

        // Host-wide: never this run's to own.
        expect($output)->not->toContain('SSH deploy restriction');
        expect($output)->not->toContain('config-test:sshd');
        expect($output)->not->toContain('mail-capture:verify-mail-capture');

        // The operations and perimeter families are not reported at all —
        // see the health-circularity test below for why verifying them from
        // inside a target repair is not merely out of scope but unsound.
        expect($output)->not->toContain('operations:install-target-operations');
        expect($output)->not->toContain('perimeter:install-target-perimeter');

        // The layout prerequisite IS asked, and at the same scope.
        expect($output)->toContain('prerequisite:install-bootstrap-host-layout');
        expect($output)->toContain('--verify --target staging-main');

        // Base services become prerequisites rather than things to enable.
        expect($output)->toContain('host-owned, never enabled or started by a target-scoped run');

        // The target's own service configuration is reported exactly as before.
        expect($output)->toContain('rateguru-staging-queue');
        expect($output)->toContain('public-storage-acl:staging-main');
    } finally {
        bsvcCleanup($scratch);
    }
});

it('gates a target apply on that target layout only, not on the whole host layout', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);

        [$exit, $output] = bsvcRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->toBe(0, $output);

        $children = bsvcLog($scratch, 'children.log');

        // The layout gate is asked at the SAME scope. Demanding the whole host
        // layout would make repairing one target fail because a different
        // active target had drifted.
        expect($children)->toContain('hostlayout-installer --verify --target staging-main');

        // The host-wide families are only ever verified, never applied.
        foreach (['operations-installer', 'perimeter-installer', 'mail-capture-installer'] as $child) {
            expect($children)->not->toContain($child.' --apply');
        }

        // The per-target ACL owner is still delegated to, at target scope.
        expect($children)->toContain('public-storage-installer');
        expect($children)->toContain('--target staging-main');
    } finally {
        bsvcCleanup($scratch);
    }
});

it('is not blocked by a host-wide family whose verify depends on the target being healthy', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);

        // install-target-operations --verify runs the application health check
        // on a DEPLOYED target. A broken vhost or pool is exactly what a target
        // repair fixes, so gating the repair on this verify would mean the site
        // has to be healthy before it can be made healthy.
        @unlink($scratch.'/toggles/operations-installer-compliant');
        @unlink($scratch.'/toggles/perimeter-installer-compliant');

        [$checkExit, $checkOutput] = bsvcRun(['--check', '--target', 'staging-main'], $env);

        expect($checkExit)->toBe(0, "a stale host-wide family must not fail a target-scoped check:\n{$checkOutput}");
        expect($checkOutput)->not->toContain('operations:install-target-operations');
        expect($checkOutput)->not->toContain('perimeter:install-target-perimeter');

        [$applyExit, $applyOutput] = bsvcRun(['--apply', '--target', 'staging-main'], $env);

        expect($applyExit)->toBe(0, "a stale host-wide family must not refuse a target-scoped apply:\n{$applyOutput}");

        // And it is never converged either — it stays the host bootstrap's.
        expect(bsvcLog($scratch, 'children.log'))->not->toContain('operations-installer --apply');
        expect(bsvcLog($scratch, 'children.log'))->not->toContain('perimeter-installer --apply');

        // Host mode still owns and converges both.
        foreach (glob($scratch.'/log/*') ?: [] as $log) {
            file_put_contents($log, '');
        }

        [$hostExit, $hostOutput] = bsvcRun(['--apply'], $env);

        expect($hostExit)->toBe(0, $hostOutput);
        expect(bsvcLog($scratch, 'children.log'))->toContain('operations-installer --apply');
        expect(bsvcLog($scratch, 'children.log'))->toContain('perimeter-installer --apply');
    } finally {
        bsvcCleanup($scratch);
    }
});

it('refuses a target-scoped apply when this target own layout is not converged', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);

        // The one prerequisite a target-scoped run really does depend on, and
        // it is asked at the same scope: demanding the WHOLE host layout would
        // make repairing one target fail because a different one had drifted.
        @unlink($scratch.'/toggles/hostlayout-installer-compliant');

        $before = bsvcTreeSnapshot($scratch.'/fs');

        [$applyExit, $applyOutput] = bsvcRun(['--apply', '--target', 'staging-main'], $env);

        expect($applyExit)->not->toBe(0, $applyOutput);
        expect($applyOutput)->toContain('install-bootstrap-host-layout --verify --target staging-main failed');
        expect($applyOutput)->toContain('no service/config mutation was performed');

        expect(bsvcSystemctlMutations($scratch))->toBe([]);
        expect(bsvcTreeSnapshot($scratch.'/fs'))->toBe($before);
    } finally {
        bsvcCleanup($scratch);
    }
});

it('never enables a base service, rewrites SSH or converges mail capture in target mode', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);

        [$exit, $output] = bsvcRun(['--apply', '--target', 'staging-main'], $env);

        expect($exit)->toBe(0, $output);

        $installed = bsvcLog($scratch, 'install.log');

        // The one file governing every deploy account on the machine.
        expect($installed)->not->toContain('70-rateguru-deploy.conf');

        // sshd is never even asked to validate, let alone reloaded.
        expect(bsvcLog($scratch, 'sshd.log'))->toBe('');

        foreach (bsvcSystemctlMutations($scratch) as $mutation) {
            foreach (['postgresql', 'redis', 'mailpit', 'mailtrap', 'ssh'] as $hostUnit) {
                expect($mutation)->not->toContain($hostUnit);
            }
        }
    } finally {
        bsvcCleanup($scratch);
    }
});

it('refuses an unknown target and a planned target before any work', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);

        [$unknownExit, $unknownOutput] = bsvcRun(['--check', '--target', 'not-a-target'], $env);

        expect($unknownExit)->not->toBe(0);
        expect($unknownOutput)->toContain('unknown target: not-a-target');

        [$plannedExit, $plannedOutput] = bsvcRun(['--check', '--target', 'tits-guru'], $env);

        expect($plannedExit)->not->toBe(0);
        expect($plannedOutput)->toContain('tits-guru is lifecycle=planned');

        expect(bsvcSystemctlMutations($scratch))->toBe([]);
        expect(bsvcLog($scratch, 'install.log'))->toBe('');
    } finally {
        bsvcCleanup($scratch);
    }
});

it('mutates only the named target on a real two-active-target host', function () {
    $scratch = bsvcScratchDir();

    try {
        [$script] = bsvcMultiTargetRepo($scratch);

        $env = bsvcFixture($scratch, ['profile' => 'compliant']);
        bsvcAddSecondTargetHostState($scratch);

        // Drift scoped to the SELECTED target, so the run has something real
        // to converge. A --check alone would prove only that the report is
        // quiet about the other target; the property that matters is that a
        // mutation stays inside the one that was named.
        $enabled = $scratch.'/fs/etc/nginx/sites-enabled/rateguru-staging';
        @unlink($enabled);

        $secondBefore = bsvcTreeSnapshot($scratch.'/fs/etc/nginx');

        $run = static function (array $arguments) use ($script, $env): string {
            $process = proc_open(
                array_merge(['bash', $script], $arguments),
                [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
                $pipes,
                null,
                $env,
            );

            $output = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            proc_close($process);

            return $output;
        };

        $output = $run(['--apply', '--target', 'staging-main']);

        // The narrowed run describes and touches one target, even though both
        // are lifecycle=active in this registry.
        expect($output)->toContain('Scope: target staging-main only');
        expect($output)->not->toContain('staging-second');
        expect($output)->not->toContain('rateguru-second');

        expect(is_link($enabled))->toBeTrue("the selected target's site was not re-enabled:\n{$output}");

        // Everything the other target owns is byte-identical, and no mutation
        // tool was ever handed one of its paths.
        $secondAfter = bsvcTreeSnapshot($scratch.'/fs/etc/nginx');

        foreach ($secondBefore as $path => $state) {
            if (str_contains($path, 'second')) {
                expect($secondAfter[$path] ?? null)->toBe($state, "the other target's {$path} changed");
            }
        }

        foreach (['install.log', 'chown.log', 'chmod.log', 'systemctl.log', 'children.log'] as $log) {
            expect(bsvcLog($scratch, $log))->not->toContain('rateguru-second');
            expect(bsvcLog($scratch, $log))->not->toContain('staging-second');
        }
    } finally {
        bsvcCleanup($scratch);
    }
});

it('does not let shared mail-capture material block a healthy target', function () {
    $scratch = bsvcScratchDir();

    try {
        // The shared mail-capture vhosts have no per-target component, and the
        // TLS material they reference belongs to the host bootstrap. A target
        // that is itself perfectly fine must not be unrepairable because of it.
        $env = bsvcFixture($scratch, [
            'profile' => 'compliant',
            'omitExternal' => [
                '/etc/letsencrypt/live/staging-mail-capture/fullchain.pem',
                '/etc/letsencrypt/live/staging-mail-capture/privkey.pem',
            ],
        ]);

        [$targetExit, $targetOutput] = bsvcRun(['--check', '--target', 'staging-main'], $env);

        expect($targetExit)->toBe(0, "a missing mail certificate must not fail a target-scoped check:\n{$targetOutput}");
        expect($targetOutput)->not->toContain('staging-mail-capture');

        [$applyExit, $applyOutput] = bsvcRun(['--apply', '--target', 'staging-main'], $env);

        expect($applyExit)->toBe(0, "a missing mail certificate must not refuse a target-scoped apply:\n{$applyOutput}");

        // Host mode still demands it, because there it really is this run's
        // prerequisite.
        [$hostExit, $hostOutput] = bsvcRun(['--check'], $env);

        expect($hostExit)->toBe(1);
        expect($hostOutput)->toContain('staging-mail-capture');
    } finally {
        bsvcCleanup($scratch);
    }
});

it('reads a parser failure as this target own drift when its configuration is damaged', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);

        // Somebody damaged this target's vhost, so nginx -t fails BECAUSE of
        // it. Replacing that file with the committed one is the repair —
        // refusing here would decline the case this whole mode exists for.
        file_put_contents(
            $scratch.'/fs/etc/nginx/sites-available/rateguru-staging',
            "this is not valid nginx configuration\n",
        );
        touch($scratch.'/toggles/nginx-t-fail');

        [$exit, $output] = bsvcRun(['--check', '--target', 'staging-main'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('DRIFT    config-test:nginx');
        expect($output)->toContain("this target's own configuration is drifted");
        expect($output)->not->toContain('CONFLICT config-test:nginx');
    } finally {
        bsvcCleanup($scratch);
    }
});

it('keeps a parser failure a conflict when this target configuration is already correct', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);

        // Every file this target owns is byte-identical to what is committed,
        // so the parser is failing somewhere else on the host. Converging this
        // target would change nothing and fix nothing.
        touch($scratch.'/toggles/nginx-t-fail');

        [$targetExit, $targetOutput] = bsvcRun(['--check', '--target', 'staging-main'], $env);

        expect($targetExit)->toBe(1, $targetOutput);
        expect($targetOutput)->toContain('CONFLICT config-test:nginx');
        expect($targetOutput)->not->toContain('DRIFT    config-test:nginx');

        // Host mode is unchanged.
        [$hostExit, $hostOutput] = bsvcRun(['--check'], $env);

        expect($hostExit)->toBe(1);
        expect($hostOutput)->toContain('CONFLICT config-test:nginx');
    } finally {
        bsvcCleanup($scratch);
    }
});

it('reports an unconverged target layout as repairable drift, not as a conflict', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);

        // This target's layout being unconverged is a repairable condition
        // with a named owner. Calling it a conflict makes an orchestrator
        // refuse the case it exists for.
        @unlink($scratch.'/toggles/hostlayout-installer-compliant');

        [$exit, $output] = bsvcRun(['--check', '--target', 'staging-main'], $env);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('DRIFT    prerequisite:install-bootstrap-host-layout');
        expect($output)->toContain('hostlayout-installer --apply --target staging-main');
        expect($output)->not->toContain('CONFLICT prerequisite:install-bootstrap-host-layout');

        // Host mode keeps the hard gate it always had.
        [$hostExit, $hostOutput] = bsvcRun(['--check'], $env);

        expect($hostExit)->toBe(1);
        expect($hostOutput)->toContain('CONFLICT slice-5.3:install-bootstrap-host-layout');
    } finally {
        bsvcCleanup($scratch);
    }
});

it('refuses a target apply when a base service is active but not enabled, and never enables it', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);

        // The gap that made the boundary declarative rather than real: the
        // prerequisite checked only is-active, so an active-but-disabled unit
        // reported PASS and the convergence below quietly enabled it.
        unlink($scratch.'/svc/php8.5-fpm.enabled');

        [$checkExit, $checkOutput] = bsvcRun(['--check', '--target', 'staging-main'], $env);

        expect($checkExit)->toBe(1, $checkOutput);
        expect($checkOutput)->toContain('HOST-REQ service:php8.5-fpm');
        expect($checkOutput)->toContain('active but not enabled');

        $before = bsvcTreeSnapshot($scratch.'/fs');

        foreach (glob($scratch.'/log/*') ?: [] as $log) {
            file_put_contents($log, '');
        }

        [$applyExit, $applyOutput] = bsvcRun(['--apply', '--target', 'staging-main'], $env);

        expect($applyExit)->not->toBe(0, $applyOutput);
        expect($applyOutput)->toContain('base host service(s) are not enabled and active');
        expect($applyOutput)->toContain('No mutation was performed');

        expect(bsvcSystemctlMutations($scratch))->toBe([], 'the refused target apply touched a service');
        expect(file_exists($scratch.'/svc/php8.5-fpm.enabled'))->toBeFalse('the target apply enabled a base host service');
        expect(bsvcTreeSnapshot($scratch.'/fs'))->toBe($before);

        // Host mode still owns it and enables it, unchanged.
        [$hostExit, $hostOutput] = bsvcRun(['--apply'], $env);

        expect($hostExit)->toBe(0, $hostOutput);
        expect(file_exists($scratch.'/svc/php8.5-fpm.enabled'))->toBeTrue();
    } finally {
        bsvcCleanup($scratch);
    }
});

it('refuses a target apply when a base service is stopped, and never starts it', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);

        unlink($scratch.'/svc/supervisor.active');

        $before = bsvcTreeSnapshot($scratch.'/fs');

        [$applyExit, $applyOutput] = bsvcRun(['--apply', '--target', 'staging-main'], $env);

        expect($applyExit)->not->toBe(0, $applyOutput);
        expect($applyOutput)->toContain('supervisor is not active');
        expect($applyOutput)->toContain('No mutation was performed');

        expect(bsvcSystemctlMutations($scratch))->toBe([]);
        expect(file_exists($scratch.'/svc/supervisor.active'))->toBeFalse('the target apply started a base host service');
        expect(bsvcTreeSnapshot($scratch.'/fs'))->toBe($before);
    } finally {
        bsvcCleanup($scratch);
    }
});

it('repairs a target while the host SSH restriction is damaged, and never touches it', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);

        $restriction = $scratch.'/fs/etc/ssh/sshd_config.d/70-rateguru-deploy.conf';
        $decoy = $scratch.'/fs/etc/ssh/elsewhere.conf';

        // A host-global SSH problem that has nothing to do with this target:
        // the deploy restriction is a symlink where a regular file belongs.
        file_put_contents($decoy, "Match User deploy-rateguru-staging\n");
        unlink($restriction);
        symlink($decoy, $restriction);

        // ...and drift that IS this target's, so the run has something to do.
        $enabled = $scratch.'/fs/etc/nginx/sites-enabled/rateguru-staging';
        @unlink($enabled);

        [$checkExit, $checkOutput] = bsvcRun(['--check', '--target', 'staging-main'], $env);

        expect($checkExit)->toBe(1, $checkOutput);
        expect($checkOutput)->not->toContain('70-rateguru-deploy.conf');
        expect($checkOutput)->not->toContain('sshd_config.d');

        [$applyExit, $applyOutput] = bsvcRun(['--apply', '--target', 'staging-main'], $env);

        // The point of the whole mode: a target repair does not depend on host
        // configuration it never reads, reports or converges.
        expect($applyExit)->toBe(0, "a damaged host SSH restriction must not block a target repair:\n{$applyOutput}");
        expect(is_link($enabled))->toBeTrue('the target site was not re-enabled');

        // And the damaged file is exactly as it was found.
        expect(is_link($restriction))->toBeTrue('the target apply replaced the host SSH restriction');
        expect(readlink($restriction))->toBe($decoy);
        expect(bsvcLog($scratch, 'install.log'))->not->toContain('70-rateguru-deploy.conf');
        expect(bsvcLog($scratch, 'sshd.log'))->toBe('');

        // Host mode still owns it, and still refuses the same damage.
        [$hostExit, $hostOutput] = bsvcRun(['--apply'], $env);

        expect($hostExit)->not->toBe(0);
        expect($hostOutput)->toContain('70-rateguru-deploy.conf');
    } finally {
        bsvcCleanup($scratch);
    }
});

it('repairs a target on a host with no sshd_config.d at all', function () {
    $scratch = bsvcScratchDir();

    try {
        $env = bsvcFixture($scratch, ['profile' => 'compliant']);

        // The directory the SSH restriction lives in is gone entirely. In host
        // mode that is a hard prerequisite; a target-scoped run installs
        // nothing there and must not care.
        exec('rm -rf '.escapeshellarg($scratch.'/fs/etc/ssh/sshd_config.d'));

        $enabled = $scratch.'/fs/etc/nginx/sites-enabled/rateguru-staging';
        @unlink($enabled);

        [$applyExit, $applyOutput] = bsvcRun(['--apply', '--target', 'staging-main'], $env);

        expect($applyExit)->toBe(0, "a missing sshd_config.d must not block a target repair:\n{$applyOutput}");
        expect(is_link($enabled))->toBeTrue();
        expect(is_dir($scratch.'/fs/etc/ssh/sshd_config.d'))->toBeFalse('the target apply created a host SSH directory');

        // Host mode still requires it.
        [$hostExit, $hostOutput] = bsvcRun(['--apply'], $env);

        expect($hostExit)->not->toBe(0);
        expect($hostOutput)->toContain('sshd_config.d');
    } finally {
        bsvcCleanup($scratch);
    }
});
