<?php

use Illuminate\Support\Facades\File;

/**
 * : infrastructure/scripts/bootstrap-host-preflight — the
 * strictly read-only clean-VPS host contract inspection.
 *
 * Every test below executes the real, shipped script as a subprocess —
 * never a reimplementation — against a fully simulated host: fixture
 * os-release/meminfo/passwd/group/timezone files, a constrained tool PATH,
 * and stub systemctl/ss/df/ip/getent/stat binaries, all injected through
 * RATEGURU_PREFLIGHT_* overrides that the script only honors alongside
 * RATEGURU_ALLOW_TEST_OVERRIDES=true (the identical gate common, backup and
 * every installer already use). Nothing here requires the CI host to run
 * nginx, PostgreSQL or even systemd.
 *
 * The two profiles that matter most mirror the two real situations the
 * preflight must serve: a clean VPS (everything missing — the report tells
 * the clean-host bootstrap what to build) and the current staging host (everything present —
 * recognized as PASS, never misreported as a conflict).
 */

// =============================================================================
// Harness
// =============================================================================

function bootstrapPreflightScript(): string
{
    return base_path('infrastructure/scripts/bootstrap-host-preflight');
}

function bootstrapPreflightSource(): string
{
    return File::get(bootstrapPreflightScript());
}

function bootstrapPreflightScratchDir(): string
{
    $dir = sys_get_temp_dir().'/bootstrap-preflight-'.uniqid('', true).'-'.getmypid();

    foreach (['', '/bin', '/fs', '/tools'] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function bootstrapPreflightCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env
 * @return array{0: int, 1: string}
 */
function bootstrapPreflightRun(array $arguments, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(
        array_merge(['bash', bootstrapPreflightScript()], $arguments),
        $descriptors,
        $pipes,
        null,
        $env,
    );

    expect($process)->not->toBeFalse('could not start bootstrap-host-preflight subprocess');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

function bootstrapPreflightWriteStub(string $path, string $content): void
{
    file_put_contents($path, $content);
    chmod($path, 0o755);
}

/**
 * The canonical tool inventory the script probes, mirrored here so the
 * compliant fixture can satisfy every probe. apt-get/dpkg belong to the
 * HOST section rather than TOOLS but resolve through the same fixture PATH.
 *
 * @return list<string>
 */
function bootstrapPreflightAllTools(): array
{
    return [
        // required base
        'bash', 'jq', 'curl', 'tar', 'gzip', 'sha256sum', 'install', 'stat',
        'readlink', 'mktemp', 'sort', 'cut', 'env', 'tr', 'head', 'tail',
        'date', 'id', 'rm', 'mv', 'cp', 'ls', 'cat', 'chmod', 'chown', 'ln',
        'od', 'du', 'df', 'sleep', 'timeout', 'uname', 'find', 'grep', 'sed', 'awk',
        'cmp', 'diff', 'unzip', 'flock', 'namei', 'runuser', 'hostname', 'useradd',
        'getent', 'visudo', 'ss', 'ip', 'setfacl', 'getfacl', 'pgrep',
        // runtime/service (rclone is probed as a managed external runtime
        // binary, never as an Ubuntu package requirement)
        'nginx', 'systemctl', 'pg_dump', 'pg_restore', 'psql', 'createdb',
        'dropdb', 'rclone', 'php8.5',
        // optional development/validation
        'shellcheck', 'actionlint', 'wget', 'journalctl',
        // HOST section probes
        'apt-get', 'dpkg',
    ];
}

/**
 * The pinned rclone version from the committed external-runtimes contract —
 * the same file the preflight itself consumes, so the pin is never
 * duplicated as a test literal.
 */
function bootstrapPreflightRclonePin(): string
{
    preg_match(
        '/^RCLONE_VERSION=(.+)$/m',
        File::get(base_path('infrastructure/config/external-runtimes/versions.env')),
        $match,
    );

    expect($match[1] ?? null)->not->toBeNull('the committed external-runtimes contract no longer pins RCLONE_VERSION');

    return $match[1];
}

/**
 * The full compliant-host stat table: every path the FILESYSTEM and SECRETS
 * sections probe, in the shape the stub stat prints (%F|%U|%G|%a).
 *
 * @return list<string>
 */
function bootstrapPreflightCompliantStatTable(): array
{
    $rows = [
        '/home/www/rateguru|directory|root|root|755',
        '/home/www/rateguru/config|directory|root|root|755',
        '/home/www/rateguru/bin|directory|root|root|755',
        '/home/www/rateguru/backups|directory|root|root|700',
        '/home/www/rateguru/run|directory|root|root|700',
        '/home/www/rateguru/config/deployment-targets.json|regular file|root|root|640',
        '/home/www/rateguru/config/deployment.conf|regular file|root|root|640',
        '/home/www/rateguru/bin/common|regular file|root|root|644',
        // Per-target rows mirror the install-bootstrap-host-layout structural contract that
        // install-bootstrap-host-layout converges and the preflight now
        // asserts authoritatively (setgid 2750/2770 modes included).
        '/home/www/rateguru/staging|directory|root|root|755',
        '/home/www/rateguru/staging/releases|directory|deploy-rateguru-staging|rateguru-staging-code|2750',
        '/home/www/rateguru/staging/shared|directory|rateguru-staging|rateguru-staging|2770',
        '/home/www/rateguru/staging/shared/storage|directory|rateguru-staging|rateguru-staging|2770',
        '/home/www/rateguru/staging/shared/storage/logs|directory|rateguru-staging|rateguru-staging|2770',
        '/home/www/rateguru/staging/current|symbolic link|root|root|777',
        // The immutable release the compliant current resolves to — the
        // the queue activation target-state classification (PRE_DEPLOY/DEPLOYED/BROKEN)
        // stats the resolved path to prove the DEPLOYED shape.
        '/home/www/rateguru/staging/releases/20260101120000|directory|deploy-rateguru-staging|rateguru-staging-code|750',
        '/home/www/rateguru/staging/locks|directory|deploy-rateguru-staging|rateguru-staging-code|2750',
        '/home/www/rateguru/staging/deployments|directory|deploy-rateguru-staging|rateguru-staging-code|2750',
        '/home/deploy-rateguru-staging|directory|deploy-rateguru-staging|deploy-rateguru-staging|750',
        '/home/deploy-rateguru-staging/.ssh|directory|deploy-rateguru-staging|deploy-rateguru-staging|700',
        '/home/deploy-rateguru-staging/incoming|directory|deploy-rateguru-staging|deploy-rateguru-staging|750',
        '/var/log/rateguru|directory|root|root|750',
        '/usr/local/sbin/rateguru-deploy|regular file|root|root|755',
        '/usr/local/sbin/rateguru-rollback|regular file|root|root|755',
        '/usr/local/sbin/rateguru-cleanup|regular file|root|root|755',
        // the controlled code alignment: the restore perimeter is part of what a clean host is
        // expected to carry, exactly like the other three wrappers.
        '/usr/local/sbin/rateguru-restore|regular file|root|root|755',
        '/etc/sudoers.d/rateguru-deploy|regular file|root|root|440',
        '/etc/cron.d/rateguru-backups|regular file|root|root|644',
        '/etc/cron.d/rateguru-staging-scheduler|regular file|root|root|644',
        '/etc/ssh/sshd_config.d/70-rateguru-deploy.conf|regular file|root|root|644',
        '/etc/nginx/sites-available/rateguru-staging|regular file|root|root|644',
        '/etc/nginx/sites-enabled/rateguru-staging|symbolic link|root|root|777',
        '/etc/php/8.5/fpm/pool.d/rateguru-staging.conf|regular file|root|root|644',
        '/etc/supervisor/conf.d/rateguru-staging-queue.conf|regular file|root|root|644',
        '/etc/systemd/system/staging-mailpit.service|regular file|root|root|644',
        '/etc/systemd/system/staging-mailtrap-local.service|regular file|root|root|644',
        '/etc/staging-mail-capture|directory|root|root|755',
        '/var/lib/staging-mail-capture|directory|staging-mailpit|staging-mailpit|750',
        '/usr/local/bin/staging-mailpit|regular file|root|root|755',
        '/usr/local/bin/staging-mailtrap-local|regular file|root|root|755',
        // Secret material: presence rows only — no content exists anywhere.
        '/home/www/rateguru/staging/shared/.env|regular file|rateguru-staging|rateguru-staging|640',
        '/home/deploy-rateguru-staging/.ssh/authorized_keys|regular file|deploy-rateguru-staging|deploy-rateguru-staging|600',
        '/root/.config/rclone/rclone.conf|regular file|root|root|600',
        '/etc/nginx/rateguru-staging.htpasswd|regular file|root|root|640',
        '/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/fullchain.pem|regular file|root|root|644',
        '/etc/letsencrypt/live/staging-mail-capture/fullchain.pem|regular file|root|root|644',
    ];

    foreach ([
        'targets', 'health-check', 'status', 'cleanup', 'deploy', 'rollback',
        'backup', 'restore-test', 'offsite-backup', 'offsite-retention',
        'offsite-restore-test', 'backup-cycle', 'verify-required-clis',
        // The binary /usr/local/sbin/rateguru-restore execs into. A compliant
        // host carries both, so the "MISSING: 0" case below keeps proving what
        // it claims rather than passing on a shorter list.
        'restore-target',
    ] as $cli) {
        $rows[] = "/home/www/rateguru/bin/{$cli}|regular file|root|root|755";
    }

    return $rows;
}

function bootstrapPreflightCompliantPasswd(): string
{
    return implode("\n", [
        'root:x:0:0:root:/root:/bin/bash',
        'www-data:x:33:33::/var/www:/usr/sbin/nologin',
        'postgres:x:110:118::/var/lib/postgresql:/bin/bash',
        'rateguru-staging:x:1001:1001::/home/www/rateguru/staging:/usr/sbin/nologin',
        'deploy-rateguru-staging:x:1002:1002::/home/deploy-rateguru-staging:/bin/bash',
        'staging-mailpit:x:990:990::/var/lib/staging-mail-capture:/usr/sbin/nologin',
        'staging-mailtrap-local:x:991:991::/var/lib/staging-mail-capture:/usr/sbin/nologin',
    ])."\n";
}

function bootstrapPreflightCompliantGroup(): string
{
    return implode("\n", [
        'root:x:0:',
        'www-data:x:33:',
        'postgres:x:118:',
        'rateguru-staging:x:1001:',
        'deploy-rateguru-staging:x:1002:',
        'rateguru-staging-code:x:1010:rateguru-staging,deploy-rateguru-staging,www-data',
        'staging-mailpit:x:990:',
        'staging-mailtrap-local:x:991:',
    ])."\n";
}

/**
 * Host identity/state files: os-release, meminfo, passwd, group, timezone,
 * and the systemd runtime directory.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapPreflightWriteHostFiles(string $fs, array $options): void
{
    $os = $options['os'] ?? 'ubuntu-22.04';
    $osRelease = match ($os) {
        'ubuntu-22.04' => "ID=ubuntu\nVERSION_ID=\"22.04\"\nPRETTY_NAME=\"Ubuntu 22.04.4 LTS\"\n",
        'ubuntu-24.04' => "ID=ubuntu\nVERSION_ID=\"24.04\"\nPRETTY_NAME=\"Ubuntu 24.04 LTS\"\n",
        'debian' => "ID=debian\nVERSION_ID=\"12\"\nVERSION=\"12 (sentinel-bookworm)\"\n",
        'absent' => null,
    };

    if ($osRelease !== null) {
        file_put_contents($fs.'/os-release', $osRelease);
    }

    $swapKib = ($options['swap'] ?? true) ? 2097152 : 0;
    file_put_contents($fs.'/meminfo', "MemTotal:        4046844 kB\nMemAvailable:    3000000 kB\nSwapTotal:       {$swapKib} kB\n");

    file_put_contents($fs.'/passwd', $options['passwd'] ?? bootstrapPreflightCompliantPasswd());
    file_put_contents($fs.'/group', $options['group'] ?? bootstrapPreflightCompliantGroup());
    file_put_contents($fs.'/timezone', ($options['timezone'] ?? 'Etc/UTC')."\n");

    if ($options['systemd'] ?? true) {
        @mkdir($fs.'/run-systemd', 0o755, true);
    }
}

/**
 * Stub executables satisfying the script's constrained tool-PATH lookups.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapPreflightWriteToolStubs(string $scratch, array $options): void
{
    $tools = $options['tools'] ?? 'all';
    $toolNames = $tools === 'all' ? bootstrapPreflightAllTools() : $tools;

    foreach ($toolNames as $tool) {
        bootstrapPreflightWriteStub($scratch.'/tools/'.$tool, "#!/bin/sh\nexit 0\n");
    }
}

/**
 * The systemctl state table (unit=running|stopped) the systemctl stub reads.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapPreflightWriteServiceFixture(string $scratch, array $options): void
{
    $services = $options['services'] ?? 'all-running';

    if ($services === 'all-running') {
        $services = [
            'nginx.service' => 'running',
            'php8.5-fpm.service' => 'running',
            'postgresql.service' => 'running',
            'redis-server.service' => 'running',
            'supervisor.service' => 'running',
            'staging-mailpit.service' => 'running',
            'staging-mailtrap-local.service' => 'running',
        ];
    }

    $serviceRows = '';

    foreach ($services as $unit => $state) {
        $serviceRows .= "{$unit}={$state}\n";
    }

    file_put_contents($scratch.'/services.txt', $serviceRows);
}

/**
 * Runtime registry and deployment.conf fixtures (parity/drift/absent), plus
 * the stat-table rows announcing whichever of the two exist.
 *
 * @param  array<string, mixed>  $options
 * @return array{0: string, 1: string, 2: list<string>} [registryPath, confPath, extraStatRows]
 */
function bootstrapPreflightWriteRegistryFixtures(string $scratch, array $options): array
{
    $fs = $scratch.'/fs';
    $extraStatRows = [];

    $runtimeRegistry = $options['runtimeRegistry'] ?? 'parity';
    $runtimeRegistryPath = $fs.'/deployment-targets.json';

    if ($runtimeRegistry === 'parity') {
        copy(base_path('infrastructure/config/deployment-targets.json'), $runtimeRegistryPath);
    } elseif ($runtimeRegistry === 'drift') {
        $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);
        $registry['x-test-sentinel'] = 'DRIFT-SECRET-SENTINEL-hunter2';
        file_put_contents($runtimeRegistryPath, json_encode($registry, JSON_PRETTY_PRINT)."\n");
    }

    if ($runtimeRegistry !== 'absent') {
        $extraStatRows[] = "{$runtimeRegistryPath}|regular file|root|root|640";
    }

    $runtimeConf = $options['runtimeConf'] ?? 'parity';
    $runtimeConfPath = $fs.'/deployment.conf';

    if ($runtimeConf === 'parity') {
        copy(base_path('infrastructure/templates/deployment.conf.example'), $runtimeConfPath);
    } elseif ($runtimeConf === 'drift') {
        file_put_contents(
            $runtimeConfPath,
            File::get(base_path('infrastructure/templates/deployment.conf.example'))."# drifted\n",
        );
    }

    if ($runtimeConf !== 'absent') {
        $extraStatRows[] = "{$runtimeConfPath}|regular file|root|root|640";
    }

    return [$runtimeRegistryPath, $runtimeConfPath, $extraStatRows];
}

/**
 * ss listener tables for the TCP and unix probes.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapPreflightWriteListenerFixtures(string $scratch, array $options): void
{
    $tcpPorts = $options['tcpPorts'] ?? [80, 443, 5432, 6379, 1025, 8025, 3535, 3550];
    $tcpRows = '';

    foreach ($tcpPorts as $port) {
        $tcpRows .= "LISTEN 0 511 0.0.0.0:{$port} 0.0.0.0:*\n";
    }

    file_put_contents($scratch.'/ss-tcp.txt', $tcpRows);

    $unixSockets = $options['unixSockets'] ?? ['/run/php/rateguru-staging.sock', '/var/run/supervisor.sock'];
    $unixRows = '';

    foreach ($unixSockets as $socket) {
        $unixRows .= "u_str LISTEN 0 4096 {$socket} 12345\n";
    }

    file_put_contents($scratch.'/ss-unix.txt', $unixRows);
}

/**
 * The probe binaries the script's gated *_BIN overrides point at: stat,
 * systemctl, ss, df, ip, getent.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapPreflightWriteProbeStubs(string $scratch, array $options): void
{
    $statTablePath = $scratch.'/stat-table.txt';
    bootstrapPreflightWriteStub($scratch.'/bin/stat', <<<SH
#!/usr/bin/env bash
# Test stub: honors only `stat -c '%F|%U|%G|%a' -- PATH`.
path="\${!#}"
awk -F'|' -v p="\${path}" '\$1 == p { print \$2 "|" \$3 "|" \$4 "|" \$5; found = 1; exit } END { exit !found }' '{$statTablePath}'
SH);

    $servicesPath = $scratch.'/services.txt';
    bootstrapPreflightWriteStub($scratch.'/bin/systemctl', <<<SH
#!/usr/bin/env bash
unit="\${!#}"
case "\$1" in
    list-unit-files)
        if grep -q "^\${unit}=" '{$servicesPath}' 2>/dev/null; then
            printf '%s enabled enabled\\n' "\${unit}"
        fi
        exit 0
        ;;
    is-active)
        if grep -q "^\${unit}=running\$" '{$servicesPath}' 2>/dev/null; then
            exit 0
        fi
        exit 3
        ;;
esac
exit 1
SH);

    bootstrapPreflightWriteStub($scratch.'/bin/ss', <<<SH
#!/usr/bin/env bash
for arg in "\$@"; do
    case "\${arg}" in
        -t) cat '{$scratch}/ss-tcp.txt' 2>/dev/null ;;
        -x) cat '{$scratch}/ss-unix.txt' 2>/dev/null ;;
    esac
done
exit 0
SH);

    bootstrapPreflightWriteStub($scratch.'/bin/df', <<<'SH'
#!/usr/bin/env bash
printf 'Filesystem 1024-blocks Used Available Capacity Mounted on\n'
printf '/dev/vda1 40000000 8000000 28000000 23%% /\n'
SH);

    $loopbackLine = ($options['loopback'] ?? true)
        ? "printf '1: lo    inet 127.0.0.1/8 scope host lo\\n'"
        : ':';
    bootstrapPreflightWriteStub($scratch.'/bin/ip', "#!/usr/bin/env bash\n{$loopbackLine}\nexit 0\n");

    $dnsBody = ($options['dns'] ?? true)
        ? "printf '185.125.190.36 archive.ubuntu.com\\n'\nexit 0"
        : 'exit 2';
    bootstrapPreflightWriteStub($scratch.'/bin/getent', "#!/usr/bin/env bash\n{$dnsBody}\n");

    // getfacl: consults the acl-table — "DIR|granted" prints an ACL holding
    // the exact user:www-data:--x entry, "DIR|present" prints one without
    // it, and an unlisted directory fails like getfacl on an absent or
    // unreadable path.
    $aclTablePath = $scratch.'/acl-table.txt';
    bootstrapPreflightWriteStub($scratch.'/bin/getfacl', <<<SH
#!/usr/bin/env bash
dir="\${!#}"
state="\$(awk -F'|' -v d="\${dir}" '\$1 == d { print \$2; exit }' '{$aclTablePath}' 2>/dev/null)"
case "\${state}" in
    granted)
        printf '# file: %s\\n# owner: sim\\n# group: sim\\nuser::rwx\\nuser:www-data:--x\\ngroup::rwx\\nmask::rwx\\nother::---\\n' "\${dir#/}"
        ;;
    present)
        printf '# file: %s\\n# owner: sim\\n# group: sim\\nuser::rwx\\ngroup::rwx\\nother::---\\n' "\${dir#/}"
        ;;
    *)
        exit 1
        ;;
esac
SH);

    $aclRows = $options['aclTable'] ?? [
        '/home/www/rateguru/staging/shared|granted',
        '/home/www/rateguru/staging/shared/storage|granted',
    ];
    file_put_contents($aclTablePath, implode("\n", $aclRows)."\n");

    bootstrapPreflightWriteReadlinkStub($scratch, $options);
}

/**
 * readlink: consults the readlink-table (path|resolved) for `-f` symlink
 * resolution — the queue activation target-state classification resolves `current`
 * and `releases` through it. An unlisted path fails like GNU readlink -f on
 * an unresolvable path, which is how the "releases directory is missing
 * while current exists" branch is reachable.
 *
 * @param  array<string, mixed>  $options
 */
function bootstrapPreflightWriteReadlinkStub(string $scratch, array $options): void
{
    $readlinkTablePath = $scratch.'/readlink-table.txt';

    bootstrapPreflightWriteStub($scratch.'/bin/readlink', <<<SH
#!/usr/bin/env bash
path="\${!#}"
awk -F'|' -v p="\${path}" '\$1 == p { print \$2; found = 1; exit } END { exit !found }' '{$readlinkTablePath}'
SH);

    $readlinkRows = $options['readlinkTable'] ?? [
        '/home/www/rateguru/staging/current|/home/www/rateguru/staging/releases/20260101120000',
        '/home/www/rateguru/staging/releases|/home/www/rateguru/staging/releases',
    ];
    file_put_contents($readlinkTablePath, implode("\n", $readlinkRows)."\n");
}

/**
 * Build a fully simulated host and return the environment to run the script
 * against it. The default is the compliant profile — the current staging
 * host — and every option knocks one aspect back toward a clean or broken
 * host. This function only composes the focused helpers above.
 *
 * Options:
 *   os:              'ubuntu-22.04' | 'ubuntu-24.04' | 'debian' | 'absent'
 *   systemd:         bool
 *   tools:           'all' | list<string>
 *   services:        array<string,string> unit => running|stopped ('all-running' default)
 *   passwd/group:    file content overrides
 *   statTable:       list<string> rows (default compliant)
 *   tcpPorts:        list<int> occupied TCP ports
 *   unixSockets:     list<string> occupied unix socket paths
 *   runtimeRegistry: 'parity' | 'drift' | 'absent'
 *   runtimeConf:     'parity' | 'drift' | 'absent'
 *   euid:            string
 *   swap:            bool
 *   timezone:        string
 *   loopback:        bool
 *   dns:             bool
 *
 * @param  array<string, mixed>  $options
 * @return array<string, string>
 */
function bootstrapPreflightFixture(string $scratch, array $options = []): array
{
    $fs = $scratch.'/fs';

    bootstrapPreflightWriteHostFiles($fs, $options);
    bootstrapPreflightWriteToolStubs($scratch, $options);
    bootstrapPreflightWriteServiceFixture($scratch, $options);

    [$runtimeRegistryPath, $runtimeConfPath, $extraStatRows] =
        bootstrapPreflightWriteRegistryFixtures($scratch, $options);

    $statTable = array_merge(
        $options['statTable'] ?? bootstrapPreflightCompliantStatTable(),
        $extraStatRows,
    );
    file_put_contents($scratch.'/stat-table.txt', implode("\n", $statTable)."\n");

    bootstrapPreflightWriteListenerFixtures($scratch, $options);
    bootstrapPreflightWriteProbeStubs($scratch, $options);

    return [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_PREFLIGHT_OS_RELEASE_FILE' => $fs.'/os-release',
        'RATEGURU_PREFLIGHT_MEMINFO_FILE' => $fs.'/meminfo',
        'RATEGURU_PREFLIGHT_PASSWD_FILE' => $fs.'/passwd',
        'RATEGURU_PREFLIGHT_GROUP_FILE' => $fs.'/group',
        'RATEGURU_PREFLIGHT_TIMEZONE_FILE' => $fs.'/timezone',
        'RATEGURU_PREFLIGHT_SYSTEMD_RUNTIME_DIR' => $fs.'/run-systemd',
        'RATEGURU_PREFLIGHT_TOOL_PATH' => $scratch.'/tools',
        'RATEGURU_PREFLIGHT_SYSTEMCTL_BIN' => $scratch.'/bin/systemctl',
        'RATEGURU_PREFLIGHT_SS_BIN' => $scratch.'/bin/ss',
        'RATEGURU_PREFLIGHT_DF_BIN' => $scratch.'/bin/df',
        'RATEGURU_PREFLIGHT_IP_BIN' => $scratch.'/bin/ip',
        'RATEGURU_PREFLIGHT_GETENT_BIN' => $scratch.'/bin/getent',
        'RATEGURU_PREFLIGHT_STAT_BIN' => $scratch.'/bin/stat',
        'RATEGURU_PREFLIGHT_GETFACL_BIN' => $scratch.'/bin/getfacl',
        'RATEGURU_PREFLIGHT_READLINK_BIN' => $scratch.'/bin/readlink',
        'RATEGURU_PREFLIGHT_HOSTNAME' => 'preflight-fixture-host',
        'RATEGURU_PREFLIGHT_KERNEL' => '6.8.0-fixture',
        'RATEGURU_PREFLIGHT_ARCH' => 'x86_64',
        'RATEGURU_PREFLIGHT_EUID' => $options['euid'] ?? '0',
        'RATEGURU_PREFLIGHT_RUNTIME_REGISTRY_FILE' => $runtimeRegistryPath,
        'RATEGURU_PREFLIGHT_RUNTIME_DEPLOYMENT_CONF_FILE' => $runtimeConfPath,
    ];
}

/**
 * The clean-VPS profile: a fresh Ubuntu 22.04 host with only a minimal tool
 * set, no services, no RateGuru accounts, and an empty filesystem.
 *
 * @return array<string, string>
 */
function bootstrapPreflightCleanHostFixture(string $scratch): array
{
    return bootstrapPreflightFixture($scratch, [
        'tools' => ['bash', 'grep', 'sed', 'awk', 'tar', 'apt-get', 'dpkg', 'systemctl'],
        'services' => [],
        'passwd' => "root:x:0:0:root:/root:/bin/bash\n",
        'group' => "root:x:0:\n",
        'statTable' => [],
        'tcpPorts' => [],
        'unixSockets' => [],
        'runtimeRegistry' => 'absent',
        'runtimeConf' => 'absent',
    ]);
}

/**
 * Content + structure snapshot for mutation-free proofs.
 *
 * @return array<string, string>
 */
function bootstrapPreflightTreeSnapshot(string $dir): array
{
    $snapshot = [];
    // SELF_FIRST so directories themselves are part of the snapshot — a
    // created or removed (even empty) directory must invalidate it.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        $snapshot[$path] = $entry->isFile() ? md5_file($path) : 'dir';
    }

    ksort($snapshot);

    return $snapshot;
}

// =============================================================================
// Static contract
// =============================================================================

it('never sources common and offers no --apply mode', function () {
    // common aborts when deployment.conf is missing — exactly the clean-host
    // situation this script exists for — so the preflight must not source it
    // (or anything else).
    expect(preg_match('/^\s*(source|\.)\s/m', bootstrapPreflightSource()))
        ->toBe(0, 'preflight must not source anything');

    // --apply is not a mode in this slice: it falls through the generic
    // unknown-argument rejection, proven behaviorally.
    [$exit, $output] = bootstrapPreflightRun(['--apply'], ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);
    expect($exit)->toBe(1);
    expect($output)->toContain('unknown argument: --apply');
});

it('contains no mutating service or package commands', function () {
    $source = bootstrapPreflightSource();

    foreach ([
        'apt-get install', 'apt install', 'add-apt-repository', 'apt-key',
        'systemctl start', 'systemctl stop', 'systemctl restart',
        'systemctl reload', 'systemctl enable', 'systemctl disable',
        'ufw ', 'iptables', 'timedatectl set', 'hostnamectl set',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
});

it('is listed in the required CLI manifest', function () {
    expect(requiredCliManifestNames())->toContain('bootstrap-host-preflight');
});

// =============================================================================
// CLI semantics
// =============================================================================

it('prints usage on --help and exits 0', function () {
    [$exit, $output] = bootstrapPreflightRun(['--help'], ['PATH' => getenv('PATH') ?: '/usr/bin:/bin']);

    expect($exit)->toBe(0);
    expect($output)->toContain('--check');
    expect($output)->toContain('--report');
    expect($output)->toContain('read-only');
});

it('rejects unknown arguments, a missing mode, and a duplicated mode', function () {
    $env = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin'];

    [$exit, $output] = bootstrapPreflightRun(['--bogus'], $env);
    expect($exit)->toBe(1);
    expect($output)->toContain('unknown argument: --bogus');

    [$exit, $output] = bootstrapPreflightRun([], $env);
    expect($exit)->toBe(1);
    expect($output)->toContain('one of --check or --report is required');

    [$exit, $output] = bootstrapPreflightRun(['--check', '--report'], $env);
    expect($exit)->toBe(1);
    expect($output)->toContain('mode given more than once');
});

// =============================================================================
// Compliant host (the current staging VPS)
// =============================================================================

it('passes --check on a fully compliant host with zero MISSING, WARN and CONFLICT', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($output)->toContain('MISSING: 0');
        expect($output)->toContain('WARN: 0');
        expect($output)->toContain('CONFLICT: 0');
        expect($output)->toContain('HOST READY: YES');
        expect($exit)->toBe(0, "compliant host must pass --check:\n{$output}");
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('recognizes the existing installation as present rather than conflicting', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch);
        [, $output] = bootstrapPreflightRun(['--check'], $env);

        foreach ([
            'service:nginx.service — STATE: installed-running',
            'service:php8.5-fpm.service — STATE: installed-running',
            'user:rateguru-staging — exists',
            'user:deploy-rateguru-staging — exists',
            'membership:rateguru-staging:rateguru-staging-code — rateguru-staging is a member',
            'path:/home/www/rateguru — directory, root:root, mode 755',
            'path:/home/www/rateguru/staging/current — symbolic link',
            'registry:runtime — byte-identical to the source registry (parity)',
            'deployment-conf:runtime — byte-identical to the committed template (parity)',
            'port:80 — occupied by expected service nginx.service',
            'socket:/run/php/rateguru-staging.sock — occupied by expected service php8.5-fpm.service',
            'secret:laravel-env:staging-main — present at /home/www/rateguru/staging/shared/.env',
        ] as $needle) {
            expect($output)->toContain($needle);
        }

        // rclone is recognized as the managed external runtime binary the
        // install-bootstrap-runtime contract pins — dpkg package ownership is deliberately
        // not required (the real staging binary is standalone).
        expect($output)->toContain(sprintf(
            'PASS     tool:rclone — managed external runtime binary present (pinned v%s; dpkg ownership not required)',
            bootstrapPreflightRclonePin(),
        ));
        expect($output)->not->toContain('tool:rclone — runtime/service tool present (package: rclone)');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

// =============================================================================
// Clean host
// =============================================================================

it('fails --check on a clean host while still printing every section and the summary', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightCleanHostFixture($scratch);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);

        // A truly clean host has no current release anywhere, so the
        // PRE_DEPLOY summary shape applies: host bootstrap readiness is
        // judged separately from first-deploy readiness.
        expect($output)->toContain('HOST BOOTSTRAP READY: NO');
        expect($output)->toContain('APPLICATION READY: DEFERRED — no release has been deployed (PRE_DEPLOY)');

        foreach (['HOST', 'TOOLS', 'SERVICES', 'USERS/GROUPS', 'FILESYSTEM', 'NETWORK', 'SECRETS REQUIRED LATER', 'SUMMARY'] as $sectionHeader) {
            expect($output)->toContain("\n{$sectionHeader}\n");
        }

        foreach ([
            'MISSING  tool:rclone',
            'MISSING  tool:jq',
            'MISSING  service:nginx.service',
            'MISSING  user:rateguru-staging',
            'MISSING  group:rateguru-staging-code',
            'MISSING  path:/home/www/rateguru — absent',
            'MISSING  registry:runtime — absent',
            // Deploy-time external material is DEFERRED on a PRE_DEPLOY
            // host — required before first deploy, never a host-bootstrap
            // blocker — while the 5.4-hard TLS/Basic Auth material stays
            // MISSING.
            'DEFERRED secret:laravel-env:staging-main',
            'DEFERRED secret:github-deploy-key:staging-main',
            'DEFERRED secret:rclone-credentials',
            'MISSING  secret:tls:staging-main',
            'MISSING  secret:basic-auth',
            'DEFERRED path:/home/www/rateguru/staging/current',
            'PASS     os-release — ID=ubuntu VERSION_ID=22.04',
        ] as $needle) {
            expect($output)->toContain($needle);
        }
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('keeps --report usable on a clean host: exit 0 plus intended bootstrap actions', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightCleanHostFixture($scratch);
        [$exit, $output] = bootstrapPreflightRun(['--report'], $env);

        expect($exit)->toBe(0, "--report is inventory, never a gate:\n{$output}");
        expect($output)->toContain('HOST BOOTSTRAP READY: NO');
        expect($output)->toContain(
            '-> bootstrap: install verified rclone v'.bootstrapPreflightRclonePin().' via install-bootstrap-runtime --apply',
        );
        expect($output)->toContain('-> bootstrap: create via install-bootstrap-host-layout (never by preflight)');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('stays fully usable when jq is genuinely unresolvable on the process PATH', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        // A restricted real PATH: every tool the script itself executes,
        // resolved to the host's genuine binaries — but no jq anywhere.
        // This starves main()'s own resolution, not just the TOOL_PATH
        // inventory probe the clean-host fixture already constrains.
        expect(@mkdir($scratch.'/realbin', 0o755))->toBeTrue();

        foreach ([
            'bash', 'awk', 'sed', 'grep', 'head', 'tr', 'cut', 'sort',
            'cmp', 'basename', 'dirname', 'hostname', 'uname', 'cat',
        ] as $bin) {
            $real = trim((string) shell_exec('command -v '.$bin));
            expect($real)->not->toBe('', "test host is missing {$bin}");
            symlink($real, $scratch.'/realbin/'.$bin);
        }

        $env = bootstrapPreflightCleanHostFixture($scratch);
        $env['PATH'] = $scratch.'/realbin';

        [$reportExit, $reportOutput] = bootstrapPreflightRun(['--report'], $env);

        // No early abort: the full grouped report and summary must print.
        expect($reportOutput)->not->toContain('jq is required');
        expect($reportOutput)->not->toContain('ERROR:');

        foreach (['HOST', 'TOOLS', 'SERVICES', 'USERS/GROUPS', 'FILESYSTEM', 'NETWORK', 'SECRETS REQUIRED LATER', 'SUMMARY'] as $sectionHeader) {
            expect($reportOutput)->toContain("\n{$sectionHeader}\n");
        }

        expect($reportOutput)->toContain('MISSING  tool:jq');
        // The target-derived contract is reported as not evaluable — never
        // silently skipped, never invented.
        expect($reportOutput)->toContain('cannot evaluate the source target contract without jq');
        expect($reportOutput)->toContain('HOST READY: NO');
        expect($reportExit)->toBe(0, "--report must stay usable without jq:\n{$reportOutput}");

        [$checkExit, $checkOutput] = bootstrapPreflightRun(['--check'], $env);
        expect($checkOutput)->toContain("\nSUMMARY\n");
        expect($checkExit)->toBe(1, '--check must fail closed without jq, after the complete report');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('reports a partially configured host with both PASS and MISSING items and fails --check', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        // Users and tools exist; services and the filesystem do not yet.
        $env = bootstrapPreflightFixture($scratch, [
            'services' => [],
            'statTable' => [],
            'tcpPorts' => [],
            'unixSockets' => [],
            'runtimeRegistry' => 'absent',
            'runtimeConf' => 'absent',
        ]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('PASS     user:rateguru-staging — exists');
        expect($output)->toContain('PASS     tool:jq');
        expect($output)->toContain('MISSING  service:nginx.service');
        expect($output)->toContain('MISSING  path:/home/www/rateguru — absent');

        preg_match('/^PASS: (\d+)$/m', $output, $pass);
        preg_match('/^MISSING: (\d+)$/m', $output, $missing);
        expect((int) $pass[1])->toBeGreaterThan(0);
        expect((int) $missing[1])->toBeGreaterThan(0);
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

// =============================================================================
// OS contract
// =============================================================================

it('treats a wrong OS family as CONFLICT and fails --check even on an otherwise compliant host', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch, ['os' => 'debian']);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT os-release — unsupported OS family ID=debian');
        expect($output)->toContain('HOST READY: NO');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('treats any Ubuntu release other than the exact 22.04 baseline as CONFLICT', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch, ['os' => 'ubuntu-24.04']);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($output)->toContain('CONFLICT os-release — ID=ubuntu VERSION_ID=24.04 is not the supported baseline ubuntu 22.04');
        expect($output)->toContain('HOST READY: NO');
        expect($exit)->toBe(1, "the OS baseline is an exact hard contract, never silently expanded:\n{$output}");

        // --report still completes as inventory on the unsupported release.
        [$reportExit, $reportOutput] = bootstrapPreflightRun(['--report'], $env);
        expect($reportExit)->toBe(0);
        expect($reportOutput)->toContain('HOST READY: NO');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('reports missing systemd as MISSING and degrades every service to missing', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch, ['systemd' => false]);
        $env['RATEGURU_PREFLIGHT_SYSTEMCTL_BIN'] = '';
        // Remove systemctl from the fixture PATH too: systemd truly absent.
        unlink($scratch.'/tools/systemctl');

        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  systemd — systemd not detected');
        expect($output)->toContain('MISSING  service:nginx.service — STATE: missing');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

// =============================================================================
// Services and network
// =============================================================================

it('reports installed-stopped services as WARN and never starts them', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch, [
            'services' => [
                'nginx.service' => 'stopped',
                'php8.5-fpm.service' => 'running',
                'postgresql.service' => 'running',
                'redis-server.service' => 'running',
                'supervisor.service' => 'running',
                'staging-mailpit.service' => 'running',
                'staging-mailtrap-local.service' => 'running',
            ],
            // A stopped nginx must not leave 80/443 occupied.
            'tcpPorts' => [5432, 6379, 1025, 8025, 3535, 3550],
        ]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($output)->toContain('WARN     service:nginx.service — STATE: installed-stopped');
        expect($output)->toContain('PASS     port:80 — free');
        expect($exit)->toBe(0, "installed-stopped is a WARN, not a blocker:\n{$output}");
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('labels the mail capture as a shared-host-service, never a per-target service', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch);
        [, $output] = bootstrapPreflightRun(['--check'], $env);

        preg_match('/^.*service:staging-mailpit\.service.*$/m', $output, $mailpit);
        preg_match('/^.*service:staging-mailtrap-local\.service.*$/m', $output, $mailtrap);

        expect($mailpit[0])->toContain('shared-host-service');
        expect($mailtrap[0])->toContain('shared-host-service');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('flags a port occupied by an unknown listener as CONFLICT and fails --check', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        // Port 80 is occupied but nginx is not even installed.
        $env = bootstrapPreflightFixture($scratch, [
            'services' => [
                'php8.5-fpm.service' => 'running',
                'postgresql.service' => 'running',
                'redis-server.service' => 'running',
                'supervisor.service' => 'running',
                'staging-mailpit.service' => 'running',
                'staging-mailtrap-local.service' => 'running',
            ],
        ]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT port:80 — occupied, but expected service nginx.service is not running — occupied/unknown');

        // The intended action (manual resolution, never killing processes)
        // is part of the --report inventory.
        [, $reportOutput] = bootstrapPreflightRun(['--report'], $env);
        expect($reportOutput)->toContain('preflight never kills processes');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

// =============================================================================
// Users and groups
// =============================================================================

it('reports a missing runtime user and its impossible group membership as MISSING', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $passwd = str_replace(
            "rateguru-staging:x:1001:1001::/home/www/rateguru/staging:/usr/sbin/nologin\n",
            '',
            bootstrapPreflightCompliantPasswd(),
        );

        $env = bootstrapPreflightFixture($scratch, ['passwd' => $passwd]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  user:rateguru-staging — missing');
        expect($output)->toContain('MISSING  membership:rateguru-staging:rateguru-staging-code — user or group absent');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('reports a runtime user missing from the code group as a MISSING membership', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $group = str_replace(
            'rateguru-staging-code:x:1010:rateguru-staging,deploy-rateguru-staging,www-data',
            'rateguru-staging-code:x:1010:deploy-rateguru-staging',
            bootstrapPreflightCompliantGroup(),
        );

        $env = bootstrapPreflightFixture($scratch, ['group' => $group]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  membership:rateguru-staging:rateguru-staging-code — rateguru-staging is not a member');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('asserts the install-bootstrap-host-layout identity contract for managed target accounts: deploy home/shell and runtime shell', function () {
    foreach ([
        // [compliant passwd line, drifted passwd line, expected CONFLICT detail]
        [
            'deploy-rateguru-staging:x:1002:1002::/home/deploy-rateguru-staging:/bin/bash',
            'deploy-rateguru-staging:x:1002:1002::/root:/bin/bash',
            'CONFLICT user:deploy-rateguru-staging — home is /root, required /home/deploy-rateguru-staging',
        ],
        [
            'deploy-rateguru-staging:x:1002:1002::/home/deploy-rateguru-staging:/bin/bash',
            'deploy-rateguru-staging:x:1002:1002::/home/deploy-rateguru-staging:/usr/sbin/nologin',
            'CONFLICT user:deploy-rateguru-staging — shell is /usr/sbin/nologin, required /bin/bash',
        ],
        [
            'rateguru-staging:x:1001:1001::/home/www/rateguru/staging:/usr/sbin/nologin',
            'rateguru-staging:x:1001:1001::/home/www/rateguru/staging:/bin/bash',
            'CONFLICT user:rateguru-staging — shell is /bin/bash, required /usr/sbin/nologin',
        ],
    ] as [$compliantLine, $driftedLine, $expectedConflict]) {
        $scratch = bootstrapPreflightScratchDir();

        try {
            $passwd = str_replace($compliantLine, $driftedLine, bootstrapPreflightCompliantPasswd());
            expect($passwd)->not->toBe(bootstrapPreflightCompliantPasswd(), 'fixture drift line did not apply');

            $env = bootstrapPreflightFixture($scratch, ['passwd' => $passwd]);
            [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

            expect($exit)->toBe(1, "an incompatible managed identity must fail --check:\n{$output}");
            expect($output)->toContain($expectedConflict);

            // --report annotates the conflict with the operator-review path
            // (never an automatic rewrite).
            [, $reportOutput] = bootstrapPreflightRun(['--report'], $env);
            expect($reportOutput)->toContain('operator review required');
            expect($reportOutput)->toContain("never rewrites an existing account's home/shell");
        } finally {
            bootstrapPreflightCleanup($scratch);
        }
    }

    // A divergent historic runtime home alone stays PASS: the deploy home
    // is critical to SSH, the runtime home is deliberately not contract.
    $scratch = bootstrapPreflightScratchDir();

    try {
        $passwd = str_replace(
            'rateguru-staging:x:1001:1001::/home/www/rateguru/staging:/usr/sbin/nologin',
            'rateguru-staging:x:1001:1001::/var/lib/rateguru-staging:/usr/sbin/nologin',
            bootstrapPreflightCompliantPasswd(),
        );

        $env = bootstrapPreflightFixture($scratch, ['passwd' => $passwd]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(0, "a divergent runtime home alone must not fail --check:\n{$output}");
        expect($output)->toContain('PASS     user:rateguru-staging — exists');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

// =============================================================================
// Filesystem contract
// =============================================================================

it('flags wrong ownership, wrong mode, and a symlink where a directory is required as CONFLICT', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $statTable = bootstrapPreflightCompliantStatTable();
        $statTable = array_map(fn (string $row): string => match (true) {
            str_starts_with($row, '/home/www/rateguru|') => '/home/www/rateguru|directory|deploy-rateguru-staging|root|755',
            str_starts_with($row, '/etc/sudoers.d/rateguru-deploy|') => '/etc/sudoers.d/rateguru-deploy|regular file|root|root|644',
            str_starts_with($row, '/home/www/rateguru/staging/releases|') => '/home/www/rateguru/staging/releases|symbolic link|root|root|777',
            default => $row,
        }, $statTable);

        $env = bootstrapPreflightFixture($scratch, ['statTable' => $statTable]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT path:/home/www/rateguru — owned by deploy-rateguru-staging:root, expected owner root');
        expect($output)->toContain('CONFLICT path:/etc/sudoers.d/rateguru-deploy — mode 644, expected 440');
        expect($output)->toContain('CONFLICT path:/home/www/rateguru/staging/releases — is a symbolic link, expected directory');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('flags a regular directory where the current symlink belongs as CONFLICT, in the shared 5.4 vocabulary', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $statTable = array_map(fn (string $row): string => str_starts_with($row, '/home/www/rateguru/staging/current|')
            ? '/home/www/rateguru/staging/current|directory|root|root|755'
            : $row, bootstrapPreflightCompliantStatTable());

        $env = bootstrapPreflightFixture($scratch, ['statTable' => $statTable]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT path:/home/www/rateguru/staging/current — current exists but is not a symlink');
        expect($output)->not->toContain('DEFERRED path:/home/www/rateguru/staging/current');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('classifies every broken current shape as CONFLICT — never DEFERRED — in parity with install-bootstrap-services', function () {
    // Dangling: current is a symlink whose resolution does not exist (the
    // resolved path has no stat row).
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch, [
            'statTable' => array_values(array_filter(
                bootstrapPreflightCompliantStatTable(),
                fn (string $row): bool => ! str_starts_with($row, '/home/www/rateguru/staging/releases/20260101120000|'),
            )),
        ]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT path:/home/www/rateguru/staging/current — current is a dangling symlink');
        expect($output)->not->toContain('DEFERRED path:/home/www/rateguru/staging/current');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }

    // Outside releases: current resolves to a directory that exists but is
    // not directly under releases/.
    $scratch = bootstrapPreflightScratchDir();

    try {
        $statTable = bootstrapPreflightCompliantStatTable();
        $statTable[] = '/home/www/rateguru/staging/rogue-release|directory|root|root|755';

        $env = bootstrapPreflightFixture($scratch, [
            'statTable' => $statTable,
            'readlinkTable' => [
                '/home/www/rateguru/staging/current|/home/www/rateguru/staging/rogue-release',
                '/home/www/rateguru/staging/releases|/home/www/rateguru/staging/releases',
            ],
        ]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT path:/home/www/rateguru/staging/current — current resolves outside the releases directory');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }

    // Non-directory resolution: current points at a regular file inside
    // releases/.
    $scratch = bootstrapPreflightScratchDir();

    try {
        $statTable = array_map(
            fn (string $row): string => str_starts_with($row, '/home/www/rateguru/staging/releases/20260101120000|')
                ? '/home/www/rateguru/staging/releases/20260101120000|regular file|root|root|644'
                : $row,
            bootstrapPreflightCompliantStatTable(),
        );

        $env = bootstrapPreflightFixture($scratch, ['statTable' => $statTable]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT path:/home/www/rateguru/staging/current — current resolves to a non-directory release');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }

    // Releases unresolvable while current exists: current itself resolves,
    // but the releases directory it must live under does not, so the
    // containment comparison can never be made.
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch, [
            'readlinkTable' => [
                '/home/www/rateguru/staging/current|/home/www/rateguru/staging/releases/20260101120000',
            ],
        ]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT path:/home/www/rateguru/staging/current — releases directory is missing while current exists');
        expect($output)->not->toContain('DEFERRED path:/home/www/rateguru/staging/current');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('never infers PRE_DEPLOY from an unreadable current: a non-root probe is WARN and keeps the strict summary shape', function () {
    // A failing stat cannot distinguish "absent" from "unreadable" without
    // root. Inferring PRE_DEPLOY from a permission error would soften
    // current and the deploy-time secrets to DEFERRED and flip the whole
    // summary on a host that may well be DEPLOYED — the same caveat
    // report_secret already applies to secret material.
    $scratch = bootstrapPreflightScratchDir();

    try {
        $statTable = array_values(array_filter(
            bootstrapPreflightCompliantStatTable(),
            fn (string $row): bool => ! str_starts_with($row, '/home/www/rateguru/staging/current|'),
        ));

        $env = bootstrapPreflightFixture($scratch, [
            'statTable' => $statTable,
            'euid' => '1000',
        ]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($output)->toContain('WARN     path:/home/www/rateguru/staging/current — absent or unverifiable without root');
        expect($output)->not->toContain('DEFERRED path:/home/www/rateguru/staging/current');

        // The strict deployed-host summary shape is kept — the pre-deploy
        // split is never entered on a guess.
        expect($output)->not->toContain('HOST BOOTSTRAP READY');
        expect($output)->not->toContain('APPLICATION READY');
        expect($output)->toMatch('/^HOST READY: (YES|NO)$/m');

        // Deploy-time secret material is not softened either.
        expect($output)->not->toContain('DEFERRED secret:laravel-env:staging-main');

        // Root on the same fixture is the authoritative verdict: PRE_DEPLOY.
        $rootEnv = bootstrapPreflightFixture($scratch, ['statTable' => $statTable]);
        [, $rootOutput] = bootstrapPreflightRun(['--check'], $rootEnv);

        expect($rootOutput)->toContain('DEFERRED path:/home/www/rateguru/staging/current');
        expect($rootOutput)->toContain('HOST BOOTSTRAP READY');
        expect($exit)->toBeIn([0, 1]);
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('asserts the install-bootstrap-host-layout structural contract authoritatively for active-target directories', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        // Drift every 5.3-contract aspect the old preflight left unasserted:
        // releases ownership, shared mode, incoming mode, a group-writable
        // deploy home — plus a missing shared/storage row.
        $statTable = array_values(array_filter(array_map(fn (string $row): ?string => match (true) {
            str_starts_with($row, '/home/www/rateguru/staging/releases|') => '/home/www/rateguru/staging/releases|directory|root|root|2750',
            str_starts_with($row, '/home/www/rateguru/staging/shared|') => '/home/www/rateguru/staging/shared|directory|rateguru-staging|rateguru-staging|770',
            str_starts_with($row, '/home/www/rateguru/staging/shared/storage|') => null,
            str_starts_with($row, '/home/deploy-rateguru-staging/incoming|') => '/home/deploy-rateguru-staging/incoming|directory|deploy-rateguru-staging|deploy-rateguru-staging|755',
            str_starts_with($row, '/home/deploy-rateguru-staging|') => '/home/deploy-rateguru-staging|directory|deploy-rateguru-staging|deploy-rateguru-staging|775',
            default => $row,
        }, bootstrapPreflightCompliantStatTable())));

        $env = bootstrapPreflightFixture($scratch, ['statTable' => $statTable]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT path:/home/www/rateguru/staging/releases — owned by root:root, expected owner deploy-rateguru-staging');
        expect($output)->toContain('CONFLICT path:/home/www/rateguru/staging/shared — mode 770, expected 2770');
        expect($output)->toContain('MISSING  path:/home/www/rateguru/staging/shared/storage — absent');
        expect($output)->toContain('CONFLICT path:/home/deploy-rateguru-staging/incoming — mode 755, expected 750');
        expect($output)->toContain('CONFLICT path:/home/deploy-rateguru-staging — mode 775 is group- or other-writable');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('treats an absent current as legitimate PRE_DEPLOY state: DEFERRED, deployment-owned, exit 0 on a bootstrapped host', function () {
    // the queue activation PRE_DEPLOY contract: a host whose 5.2-5.4 bootstrap is
    // complete but which has never received a release is a legitimate,
    // correctly bootstrapped host. The absent current and the deploy-time
    // external material are DEFERRED — never MISSING — and --check exits 0.
    $scratch = bootstrapPreflightScratchDir();

    try {
        $statTable = array_values(array_filter(
            bootstrapPreflightCompliantStatTable(),
            fn (string $row): bool => ! str_starts_with($row, '/home/www/rateguru/staging/current|')
                && ! str_starts_with($row, '/home/www/rateguru/staging/shared/.env|')
                && ! str_starts_with($row, '/home/deploy-rateguru-staging/.ssh/authorized_keys|')
                && ! str_starts_with($row, '/root/.config/rclone/rclone.conf|'),
        ));

        $env = bootstrapPreflightFixture($scratch, ['statTable' => $statTable]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        // Target state: PRE_DEPLOY. current is DEFERRED, never MISSING, and
        // no fake release/current is ever suggested or fabricated.
        expect($output)->toContain('DEFERRED path:/home/www/rateguru/staging/current — absent — first immutable deployment has not happened yet');
        expect($output)->not->toContain('MISSING  path:/home/www/rateguru/staging/current');

        preg_match('/^.*path:\/home\/www\/rateguru\/staging\/current.*$/m', $output, $line);
        expect($line[0])->toContain('deployment-owned');

        // Application-only secret readiness is DEFERRED — REQUIRED BEFORE
        // FIRST DEPLOY — while the 5.4-hard TLS/Basic Auth material (present
        // in this fixture) stays PASS.
        expect($output)->toContain('DEFERRED secret:laravel-env:staging-main');
        expect($output)->toContain('DEFERRED secret:database-credentials:staging-main');
        expect($output)->toContain('DEFERRED secret:github-deploy-key:staging-main');
        expect($output)->toContain('DEFERRED secret:rclone-credentials');
        expect($output)->toContain('REQUIRED BEFORE FIRST DEPLOY');

        // The split summary: host bootstrap is complete, the application is
        // legitimately not deployed yet, and the gate passes.
        expect($output)->toContain('MISSING: 0');
        expect($output)->toContain('CONFLICT: 0');
        expect($output)->toMatch('/^DEFERRED: [1-9]\d*$/m');
        expect($output)->toContain('HOST BOOTSTRAP READY: YES');
        expect($output)->toContain('APPLICATION READY: DEFERRED — no release has been deployed (PRE_DEPLOY)');
        expect($exit)->toBe(0, "a correctly bootstrapped PRE_DEPLOY host must pass --check:\n{$output}");
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('keeps the 5.4-hard external material a MISSING blocker even on a PRE_DEPLOY host', function () {
    // TLS and Basic Auth are prerequisites install-bootstrap-services needs
    // to activate committed host configuration — a PRE_DEPLOY host without
    // them is NOT a completely bootstrapped host, and the pre-deploy
    // softening never downgrades them.
    $scratch = bootstrapPreflightScratchDir();

    try {
        $statTable = array_values(array_filter(
            bootstrapPreflightCompliantStatTable(),
            fn (string $row): bool => ! str_starts_with($row, '/home/www/rateguru/staging/current|')
                && ! str_starts_with($row, '/etc/nginx/rateguru-staging.htpasswd|')
                && ! str_starts_with($row, '/etc/letsencrypt/live/rateguru.staging.myprojects.pp.ua/fullchain.pem|'),
        ));

        $env = bootstrapPreflightFixture($scratch, ['statTable' => $statTable]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  secret:basic-auth');
        expect($output)->toContain('MISSING  secret:tls:staging-main');
        expect($output)->toContain('HOST BOOTSTRAP READY: NO');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('keeps the strict deployed-host secret contract: absent material on a DEPLOYED host stays MISSING', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        // DEPLOYED (current present and valid) with shared/.env absent: the
        // pre-deploy softening must not apply.
        $statTable = array_values(array_filter(
            bootstrapPreflightCompliantStatTable(),
            fn (string $row): bool => ! str_starts_with($row, '/home/www/rateguru/staging/shared/.env|'),
        ));

        $env = bootstrapPreflightFixture($scratch, ['statTable' => $statTable]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  secret:laravel-env:staging-main — absent');
        expect($output)->not->toContain('DEFERRED secret:laravel-env:staging-main');
        expect($output)->toContain('HOST READY: NO');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

// =============================================================================
// Source/runtime registry parity
// =============================================================================

it('reports runtime registry and deployment.conf drift as WARN without modifying either file', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch, [
            'runtimeRegistry' => 'drift',
            'runtimeConf' => 'drift',
        ]);

        $registryBefore = md5_file($scratch.'/fs/deployment-targets.json');
        $confBefore = md5_file($scratch.'/fs/deployment.conf');

        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($output)->toContain('WARN     registry:runtime — differs from the source registry (drift)');
        expect($output)->toContain('WARN     deployment-conf:runtime — differs from the committed template (drift)');
        expect($exit)->toBe(0, "drift alone is WARN, not a blocker:\n{$output}");

        expect(md5_file($scratch.'/fs/deployment-targets.json'))->toBe($registryBefore, 'preflight must never modify the runtime registry');
        expect(md5_file($scratch.'/fs/deployment.conf'))->toBe($confBefore, 'preflight must never modify the runtime deployment.conf');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('validates the source registry through the standalone targets CLI', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch);
        [, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($output)->toContain('PASS     registry:source — valid (1 active target(s): staging-main)');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

// =============================================================================
// Secrets
// =============================================================================

it('never prints secret content, even when a probed file contains a sentinel', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        // The drifted runtime registry contains a sentinel the script cmp's
        // (bytes read, content never echoed); the .env sentinel lives in a
        // real file the script has no reason to ever open.
        file_put_contents($scratch.'/fs/shared.env', "DB_PASSWORD=env-secret-sentinel-hunter2\n");

        $env = bootstrapPreflightFixture($scratch, ['runtimeRegistry' => 'drift']);
        [, $output] = bootstrapPreflightRun(['--report'], $env);

        expect($output)->not->toContain('hunter2');
        expect($output)->not->toContain('DRIFT-SECRET-SENTINEL');
        expect($output)->not->toContain('env-secret-sentinel');
        expect($output)->toContain('content never read or validated');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('degrades absent secret material to WARN when not running as root', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $statTable = array_filter(
            bootstrapPreflightCompliantStatTable(),
            fn (string $row): bool => ! str_starts_with($row, '/root/.config/rclone/rclone.conf|'),
        );

        $rootEnv = bootstrapPreflightFixture($scratch, ['statTable' => array_values($statTable)]);
        [, $rootOutput] = bootstrapPreflightRun(['--check'], $rootEnv);
        expect($rootOutput)->toContain('MISSING  secret:rclone-credentials — absent');

        $userEnv = bootstrapPreflightFixture($scratch, [
            'statTable' => array_values($statTable),
            'euid' => '1000',
        ]);
        [, $userOutput] = bootstrapPreflightRun(['--check'], $userEnv);
        expect($userOutput)->toContain('WARN     secret:rclone-credentials — absent or unverifiable without root');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

// =============================================================================
// Read-only and deterministic execution
// =============================================================================

it('mutates nothing in either mode: the simulated host is byte-identical before and after', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch);
        $before = bootstrapPreflightTreeSnapshot($scratch);

        [$checkExit] = bootstrapPreflightRun(['--check'], $env);
        [$reportExit] = bootstrapPreflightRun(['--report'], $env);

        expect($checkExit)->toBe(0);
        expect($reportExit)->toBe(0);
        expect(bootstrapPreflightTreeSnapshot($scratch))->toBe($before, 'preflight must never create, modify or delete anything');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('produces byte-identical output and the same exit code across repeated runs', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightCleanHostFixture($scratch);

        [$firstExit, $firstOutput] = bootstrapPreflightRun(['--report'], $env);
        [$secondExit, $secondOutput] = bootstrapPreflightRun(['--report'], $env);

        expect($secondExit)->toBe($firstExit);
        expect($secondOutput)->toBe($firstOutput);
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

/**
 * @param  array<string, string>  $env
 */
function bootstrapPreflightAssertSummaryMatchesItems(array $env): string
{
    [, $output] = bootstrapPreflightRun(['--check'], $env);

    foreach (['PASS', 'MISSING', 'WARN', 'CONFLICT', 'DEFERRED'] as $status) {
        preg_match("/^{$status}: (\\d+)$/m", $output, $summary);
        // A single space suffices as separator: CONFLICT fills the whole
        // fixed-width %-8s field, so only one space follows it.
        $counted = preg_match_all("/^  {$status} /m", $output);

        expect($counted)->toBe(
            (int) $summary[1],
            "summary {$status} count must match the item lines",
        );
    }

    return $output;
}

it('reports summary counts that exactly match the counted item lines', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        bootstrapPreflightAssertSummaryMatchesItems(bootstrapPreflightCleanHostFixture($scratch));
    } finally {
        bootstrapPreflightCleanup($scratch);
    }

    // The clean host has zero CONFLICT items, so the invariant above is
    // vacuous for that status — prove it against a conflicting host too.
    $conflictScratch = bootstrapPreflightScratchDir();

    try {
        $output = bootstrapPreflightAssertSummaryMatchesItems(
            bootstrapPreflightFixture($conflictScratch, ['os' => 'debian']),
        );

        preg_match('/^CONFLICT: (\d+)$/m', $output, $conflicts);
        expect((int) $conflicts[1])->toBeGreaterThan(0, 'the conflicting fixture must produce CONFLICT items');
    } finally {
        bootstrapPreflightCleanup($conflictScratch);
    }
});

it('ignores every RATEGURU_PREFLIGHT_* override unless test overrides are explicitly allowed', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch, ['os' => 'debian']);
        unset($env['RATEGURU_ALLOW_TEST_OVERRIDES']);

        [$exit, $output] = bootstrapPreflightRun(['--report'], $env);

        expect($exit)->toBe(0);
        // The fixture-only sentinels would appear if any ungated override
        // were honored. (Deliberately no assertion on a bare 'ID=debian' —
        // a real Debian dev host would legitimately print that from its own
        // /etc/os-release.)
        expect($output)->not->toContain('sentinel-bookworm');
        expect($output)->not->toContain('preflight-fixture-host');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

// =============================================================================
// Slice 5.4 parity: the preflight asserts the same service-file modes and
// public-storage ACL contract install-bootstrap-services installs, so the
// two can never disagree.
// =============================================================================

it('asserts the service-file modes: a drifted installed mode is CONFLICT, not PASS', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $statTable = array_map(
            fn (string $row): string => str_replace(
                '/etc/nginx/sites-available/rateguru-staging|regular file|root|root|644',
                '/etc/nginx/sites-available/rateguru-staging|regular file|root|root|600',
                $row,
            ),
            bootstrapPreflightCompliantStatTable(),
        );

        $env = bootstrapPreflightFixture($scratch, ['statTable' => $statTable]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('CONFLICT path:/etc/nginx/sites-available/rateguru-staging — mode 600, expected 644');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('reports the public-storage ACL contract: PASS when granted, MISSING when the www-data entry is absent, WARN without root', function () {
    // Absent entry on shared/storage: MISSING, pointing at the owning
    // installer — never a chmod or group-membership suggestion.
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch, [
            'aclTable' => [
                '/home/www/rateguru/staging/shared|granted',
                '/home/www/rateguru/staging/shared/storage|present',
            ],
        ]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  acl:public-storage:staging-main — user:www-data:--x is absent on /home/www/rateguru/staging/shared/storage');

        // The intended action (printed by --report) names the owning
        // installer — never a chmod or group-membership suggestion.
        [, $reportOutput] = bootstrapPreflightRun(['--report'], $env);
        expect($reportOutput)->toContain('install-public-storage-access --apply --target staging-main');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }

    // Unreadable directories without root: WARN, never a verdict.
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch, ['aclTable' => [], 'euid' => '1000']);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($output)->toContain('WARN     acl:public-storage:staging-main — cannot read the target directories without root');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }

    // The compliant fixture (default acl table) is PASS — proven by the
    // existing fully-compliant test; assert the exact item here too.
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch);
        [, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($output)->toContain('PASS     acl:public-storage:staging-main — user:www-data:--x present on shared and shared/storage');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }

    // getfacl unavailable (not on the tool PATH, no gated override): WARN
    // that ACLs cannot be enumerated — never an invented verdict.
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch, [
            'tools' => array_values(array_filter(
                bootstrapPreflightAllTools(),
                fn (string $tool): bool => $tool !== 'getfacl',
            )),
        ]);
        unset($env['RATEGURU_PREFLIGHT_GETFACL_BIN']);

        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('WARN     acl:public-storage:staging-main — cannot enumerate ACLs (getfacl unavailable)');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('requires the service-support log directory with the exact runtime ownership and setgid mode', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $statTable = array_values(array_filter(
            bootstrapPreflightCompliantStatTable(),
            fn (string $row): bool => ! str_starts_with($row, '/home/www/rateguru/staging/shared/storage/logs|'),
        ));

        $env = bootstrapPreflightFixture($scratch, ['statTable' => $statTable]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  path:/home/www/rateguru/staging/shared/storage/logs');
        expect($output)->toContain('service-support log directory for staging-main (install-bootstrap-services contract');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

// =============================================================================
// www-data as a code-group reader (the clean-VPS blocker #2).
// =============================================================================

it('reports a missing www-data code-group membership, the exact clean-VPS 404 cause', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        // The clean-VPS identity state: www-data has only its own group,
        // while the runtime user is already a code-group member. Nginx then
        // cannot traverse the 0750 release tree and every request 404s with
        // "stat() ... failed (13: Permission denied)".
        $group = str_replace(
            'rateguru-staging-code:x:1010:rateguru-staging,deploy-rateguru-staging,www-data',
            'rateguru-staging-code:x:1010:rateguru-staging,deploy-rateguru-staging',
            bootstrapPreflightCompliantGroup(),
        );
        expect($group)->not->toBe(bootstrapPreflightCompliantGroup(), 'fixture drift did not apply');

        $env = bootstrapPreflightFixture($scratch, ['group' => $group]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1, 'a host Nginx cannot serve from must not report ready');
        expect($output)->toContain('MISSING  membership:www-data:rateguru-staging-code — www-data is not a member');
        // The runtime relation is unaffected and still passes.
        expect($output)->toContain('PASS     membership:rateguru-staging:rateguru-staging-code');

        // The reason names the real mechanism, not a vague permission note.
        preg_match('/^.*membership:www-data:rateguru-staging-code.*$/m', $output, $line);
        expect($line[0])->toContain('try_files');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('passes both code-group memberships on a compliant host and keeps the ACL boundary independent', function () {
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('PASS     membership:rateguru-staging:rateguru-staging-code');
        expect($output)->toContain('PASS     membership:www-data:rateguru-staging-code');

        // The two boundaries stay distinct: immutable code by group
        // membership, shared mutable storage by the narrow ACL.
        expect($output)->toContain('PASS     acl:public-storage:staging-main — user:www-data:--x present on shared and shared/storage');

        // www-data is a code-group reader, never a runtime-group member.
        expect($output)->not->toContain('membership:www-data:rateguru-staging —');
        preg_match('/^.*user:www-data —.*$/m', $output, $userLine);
        expect($userLine[0])->toContain('CODE group');
        expect($userLine[0])->toContain('never a runtime group');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});

it('keeps the public-storage ACL assertion independent of code-group membership', function () {
    // Removing the ACL must not disturb the membership verdicts, and
    // removing the membership must not disturb the ACL verdict — they are
    // separate boundaries with separate owners.
    $scratch = bootstrapPreflightScratchDir();

    try {
        $env = bootstrapPreflightFixture($scratch, [
            'aclTable' => [
                '/home/www/rateguru/staging/shared|granted',
                '/home/www/rateguru/staging/shared/storage|present',
            ],
        ]);
        [$exit, $output] = bootstrapPreflightRun(['--check'], $env);

        expect($exit)->toBe(1);
        expect($output)->toContain('MISSING  acl:public-storage:staging-main');
        // Memberships are unaffected.
        expect($output)->toContain('PASS     membership:www-data:rateguru-staging-code');
    } finally {
        bootstrapPreflightCleanup($scratch);
    }
});
