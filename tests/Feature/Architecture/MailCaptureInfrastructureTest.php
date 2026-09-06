<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

function mailCaptureSource(string $path): string
{
    $full = base_path('infrastructure/'.$path);

    expect(File::exists($full))->toBeTrue("missing infrastructure file: {$path}");

    return File::get($full);
}

/**
 * Parse an env/`KEY=VALUE` file into an ordered map, ignoring blank and
 * commented lines. Values are returned verbatim (trailing CR stripped).
 *
 * @return array<string, string>
 */
function mailCaptureEnvValues(string $path): array
{
    $out = [];

    foreach (preg_split('/\R/', mailCaptureSource($path)) as $line) {
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '#') || ! str_contains($trimmed, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $trimmed, 2);
        $out[trim($key)] = rtrim($value, "\r");
    }

    return $out;
}

/**
 * Collect the values of a systemd directive from uncommented `Key=Value`
 * lines only (skips comments, section headers and continuation lines).
 *
 * @return array<int, string>
 */
function mailCaptureDirectiveValues(string $path, string $key): array
{
    $values = [];

    foreach (preg_split('/\R/', mailCaptureSource($path)) as $line) {
        $trimmed = ltrim($line);

        if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === ';' || $trimmed[0] === '[') {
            continue;
        }

        if (! str_contains($trimmed, '=')) {
            continue;
        }

        [$lineKey, $value] = explode('=', $trimmed, 2);

        if (trim($lineKey) === $key) {
            $values[] = rtrim($value, "\r");
        }
    }

    return $values;
}

/**
 * Extract one of the installer's marked, sourceable blocks so the behavioural
 * tests run the shipped code itself instead of a copy of it.
 */
function mailCaptureBlock(string $marker): string
{
    $quoted = preg_quote($marker, '/');
    $pattern = '/^# --- '.$quoted.' \(begin\) ---$\R(.*?)^# --- '.$quoted.' \(end\) ---$/ms';

    expect(preg_match($pattern, mailCaptureSource('scripts/install-mail-capture'), $matches))
        ->toBe(1, "could not locate the '{$marker}' block in scripts/install-mail-capture");

    return $matches[1];
}

/**
 * Create a throwaway workspace holding command stubs (systemctl, ss, curl,
 * nginx, journalctl) plus the state directory that drives them.
 *
 * @return array{root:string, bin:string, state:string}
 */
function mailCaptureStubWorkspace(): array
{
    $root = sys_get_temp_dir().'/mail-capture-runtime-'.uniqid();
    $bin = $root.'/bin';
    $state = $root.'/state';

    mkdir($bin, 0o700, true);
    mkdir($state, 0o700, true);

    // systemctl: every answer comes from a plain file in $STUB_STATE_DIR, and
    // every state-changing verb writes those files back, so a stubbed rollback
    // really does move the recorded runtime state.
    $systemctl = <<<'SH'
#!/usr/bin/env bash
set -uo pipefail
state_dir="${STUB_STATE_DIR}"
printf '%s\n' "systemctl $*" >>"${state_dir}/calls"
read_state() { cat "${state_dir}/$1" 2>/dev/null || printf '%s' "$2"; }

cmd="${1:-}"
shift || true

unit=""
for arg in "$@"; do
    case "${arg}" in
        --*) ;;
        *) [[ -n "${unit}" ]] || unit="${arg}" ;;
    esac
done

case "${cmd}" in
    show)
        prop=""
        for arg in "$@"; do
            case "${arg}" in --property=*) prop="${arg#--property=}" ;; esac
        done
        case "${prop}" in
            ActiveState) read_state "${unit}.active" inactive ;;
            SubState) read_state "${unit}.sub" dead ;;
            Result) read_state "${unit}.result" success ;;
            ExecMainStatus) read_state "${unit}.exec_status" 0 ;;
            NRestarts)
                count="$(read_state "${unit}.nrestarts" 0)"
                # A ".nrestarts_step" marker makes the counter climb on every
                # read: that is a service restarting under the installer.
                if [[ -f "${state_dir}/${unit}.nrestarts_step" ]]; then
                    printf '%s' "$((count + 1))" >"${state_dir}/${unit}.nrestarts"
                fi
                printf '%s' "${count}"
                ;;
            *) printf '' ;;
        esac
        printf '\n'
        ;;
    is-enabled)
        current="$(read_state "${unit}.enabled" not-found)"
        printf '%s\n' "${current}"
        [[ "${current}" == enabled || "${current}" == enabled-runtime ]] || exit 1
        ;;
    is-active)
        [[ "$(read_state "${unit}.active" inactive)" == active ]] || exit 3
        ;;
    enable)
        [[ ! -f "${state_dir}/fail_enable_${unit}" ]] || exit 1
        printf 'enabled' >"${state_dir}/${unit}.enabled"
        ;;
    disable)
        [[ ! -f "${state_dir}/fail_disable_${unit}" ]] || exit 1
        printf 'disabled' >"${state_dir}/${unit}.enabled"
        ;;
    mask)
        printf 'masked' >"${state_dir}/${unit}.enabled"
        ;;
    restart|start)
        if [[ -f "${state_dir}/fail_restart_${unit}" ]]; then
            printf 'failed' >"${state_dir}/${unit}.active"
            printf 'failed' >"${state_dir}/${unit}.sub"
            exit 1
        fi
        printf 'active' >"${state_dir}/${unit}.active"
        printf 'running' >"${state_dir}/${unit}.sub"
        ;;
    stop)
        [[ ! -f "${state_dir}/fail_stop_${unit}" ]] || exit 1
        printf 'inactive' >"${state_dir}/${unit}.active"
        printf 'dead' >"${state_dir}/${unit}.sub"
        ;;
    reload)
        [[ ! -f "${state_dir}/fail_reload_${unit}" ]] || exit 1
        ;;
    *) ;;
esac
exit 0
SH;

    // ss: one LISTEN row per "host:port" recorded in $STUB_STATE_DIR/listeners.
    $ss = <<<'SH'
#!/usr/bin/env bash
set -uo pipefail
while read -r hostport; do
    [[ -n "${hostport}" ]] || continue
    printf 'LISTEN 0 4096 %s 0.0.0.0:*\n' "${hostport}"
done <"${STUB_STATE_DIR}/listeners"
exit 0
SH;

    // curl: only the URLs recorded in $STUB_STATE_DIR/apis answer.
    $curl = <<<'SH'
#!/usr/bin/env bash
set -uo pipefail
url=""
for arg in "$@"; do
    case "${arg}" in http*) url="${arg}" ;; esac
done
grep -Fxq "${url}" "${STUB_STATE_DIR}/apis" 2>/dev/null || exit 22
exit 0
SH;

    $nginx = <<<'SH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "nginx $*" >>"${STUB_STATE_DIR}/calls"
if [[ -f "${STUB_STATE_DIR}/nginx_invalid" ]]; then
    printf 'nginx: configuration file test failed\n' >&2
    exit 1
fi
printf 'nginx: configuration file test is successful\n'
exit 0
SH;

    $stubs = [
        'systemctl' => $systemctl,
        'ss' => $ss,
        'curl' => $curl,
        'nginx' => $nginx,
        'journalctl' => "#!/usr/bin/env bash\nexit 0\n",
    ];

    foreach ($stubs as $name => $body) {
        file_put_contents($bin.'/'.$name, rtrim($body, "\n")."\n");
        chmod($bin.'/'.$name, 0o755);
    }

    foreach (['calls', 'listeners', 'apis'] as $file) {
        file_put_contents($state.'/'.$file, '');
    }

    return ['root' => $root, 'bin' => $bin, 'state' => $state];
}

/**
 * Record the state of two enabled, active, serving mail-capture services.
 */
function mailCaptureHealthyState(string $state): void
{
    foreach (['staging-mailtrap-local.service', 'staging-mailpit.service'] as $unit) {
        file_put_contents($state.'/'.$unit.'.active', 'active');
        file_put_contents($state.'/'.$unit.'.sub', 'running');
        file_put_contents($state.'/'.$unit.'.enabled', 'enabled');
        file_put_contents($state.'/'.$unit.'.nrestarts', '0');
    }

    file_put_contents(
        $state.'/listeners',
        "127.0.0.2:3535\n127.0.0.1:3550\n127.0.0.1:1025\n127.0.0.1:8025\n",
    );
    file_put_contents(
        $state.'/apis',
        "http://127.0.0.1:3550/api/v1/version\nhttp://127.0.0.1:8025/api/v1/info\n",
    );
}

/**
 * Run a harness script (installer block + scenario) against the stubs.
 *
 * @param  array<string, string>  $env
 * @return array{exit:int, output:string}
 */
function mailCaptureRunHarness(array $workspace, string $script, array $env = []): array
{
    $file = $workspace['root'].'/harness.sh';
    file_put_contents($file, $script);

    $exports = array_merge([
        'STUB_STATE_DIR' => $workspace['state'],
        'PATH' => $workspace['bin'].':'.(getenv('PATH') ?: '/usr/bin:/bin'),
        // Deterministic stubs: the bounded waits only need to be non-zero.
        'MAIL_CAPTURE_RUNTIME_WAIT' => '1',
        'MAIL_CAPTURE_STABILITY_WAIT' => '1',
    ], $env);

    $prefix = '';
    foreach ($exports as $name => $value) {
        $prefix .= $name.'='.escapeshellarg($value).' ';
    }

    $output = [];
    $exit = 0;
    exec($prefix.'bash '.escapeshellarg($file).' 2>&1', $output, $exit);

    return ['exit' => $exit, 'output' => implode("\n", $output)];
}

/**
 * Minimal `log`/`fail` so an extracted block can run standalone.
 */
function mailCaptureHarnessPreamble(): string
{
    return "set -uo pipefail\n"
        ."log()  { printf '[log] %s\\n' \"\$*\"; }\n"
        ."fail() { printf '[ERR] %s\\n' \"\$*\" >&2; exit 1; }\n";
}

it('ships every required mail-capture file', function () {
    $required = [
        'ROADMAP.md',
        'config/mail-capture/versions.env',
        'config/mail-capture/mailpit.env',
        'config/mail-capture/mailpit-relay.yml',
        'config/mail-capture/mailtrap-local.yml',
        'config/mail-capture/SHA256SUMS',
        'config/systemd/staging-mailpit.service',
        'config/systemd/staging-mailtrap-local.service',
        'config/nginx/mailpit-staging',
        'config/nginx/mailtrap-local-staging',
        'scripts/install-mail-capture',
        'scripts/verify-mail-capture',
        'scripts/status-mail-capture',
        'runbooks/mail-capture.md',
    ];

    foreach ($required as $path) {
        expect(File::exists(base_path('infrastructure/'.$path)))
            ->toBeTrue("missing infrastructure file: {$path}");
    }
});

it('passes bash -n syntax check on every mail-capture script', function () {
    foreach (['install-mail-capture', 'verify-mail-capture', 'status-mail-capture'] as $script) {
        $path = base_path('infrastructure/scripts/'.$script);
        $output = [];
        $exit = 0;
        exec('bash -n '.escapeshellarg($path).' 2>&1', $output, $exit);

        expect($exit)->toBe(0, "bash -n failed for {$script}: ".implode("\n", $output));
    }
});

it('pins exact versions and never uses latest', function () {
    $versions = mailCaptureSource('config/mail-capture/versions.env');

    expect($versions)
        ->toContain('MAILPIT_VERSION=1.30.5')
        ->toContain('MAILTRAP_LOCAL_VERSION=0.2.0')
        // No component is pinned to a moving tag.
        ->not->toContain('=latest');
});

it('commits verifiable SHA-256 checksums for both pinned releases', function () {
    // A single SHA256SUMS pins every archive the installer may download.
    $checksums = mailCaptureSource('config/mail-capture/SHA256SUMS');

    expect($checksums)
        ->toMatch('/^[0-9a-f]{64}  mailpit-linux-amd64\.tar\.gz$/m')
        ->toMatch('/^[0-9a-f]{64}  mailpit-linux-arm64\.tar\.gz$/m')
        ->toMatch('/^[0-9a-f]{64}  mailtrap-local_0\.2\.0_linux_amd64\.tar\.gz$/m')
        ->toMatch('/^[0-9a-f]{64}  mailtrap-local_0\.2\.0_linux_arm64\.tar\.gz$/m');
});

it('binds every capture listener to loopback only', function () {
    $mailpit = mailCaptureSource('config/mail-capture/mailpit.env');
    $mailtrapUnit = mailCaptureSource('config/systemd/staging-mailtrap-local.service');

    expect($mailpit)
        ->toContain('MP_SMTP_BIND_ADDR=127.0.0.1:1025')
        ->toContain('MP_UI_BIND_ADDR=127.0.0.1:8025')
        ->not->toContain('0.0.0.0')
        ->and($mailtrapUnit)
        // SMTP binds 127.0.0.2 to dodge Mailtrap Local 0.2.0's IPv6 [::1]
        // expansion; HTTP/API stays on 127.0.0.1.
        ->toContain('--smtp-listen 127.0.0.2:3535')
        ->toContain('--http-listen 127.0.0.1:3550')
        ->not->toContain('--smtp-listen 127.0.0.1:3535')
        ->not->toContain('0.0.0.0');
});

it('binds Mailtrap Local SMTP to 127.0.0.2 and HTTP to 127.0.0.1', function () {
    // The IPv4/IPv6 expansion workaround: SMTP on 127.0.0.2, HTTP/API on
    // 127.0.0.1 — asserted as two distinct loopback hosts.
    $unit = mailCaptureSource('config/systemd/staging-mailtrap-local.service');

    expect($unit)
        ->toMatch('/--smtp-listen\s+127\.0\.0\.2:3535\b/')
        ->toMatch('/--http-listen\s+127\.0\.0\.1:3550\b/')
        // IPv6 is never enabled: no listener flag binds a bracketed IPv6 address
        // (the explanatory comment may mention [::1], but no `--*-listen` does).
        ->not->toMatch('/--smtp-listen\s+\[::/')
        ->not->toMatch('/--http-listen\s+\[::/');
});

it('keeps every capture listener inside the IPv4 loopback range', function () {
    // Collect every host:port the slice binds or dials, and prove each host is
    // in 127.0.0.0/8 — never a routable or wildcard address.
    $endpoints = [
        // mailpit.env bind addresses.
        ...(function () {
            $env = mailCaptureEnvValues('config/mail-capture/mailpit.env');

            return [$env['MP_SMTP_BIND_ADDR'], $env['MP_UI_BIND_ADDR']];
        })(),
        // Mailtrap Local unit listeners.
        '127.0.0.2:3535',
        '127.0.0.1:3550',
        // Mailpit relay target.
        (function () {
            $relay = Yaml::parse(mailCaptureSource('config/mail-capture/mailpit-relay.yml'));

            return $relay['host'].':'.$relay['port'];
        })(),
    ];

    foreach ($endpoints as $endpoint) {
        [$host] = explode(':', $endpoint, 2);
        expect($host)->toMatch('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', "non-loopback host: {$endpoint}");
    }

    // Belt-and-braces: nothing binds the IPv4 wildcard, and no listener flag
    // binds an IPv6 address (comments may reference [::1]; binds never do).
    foreach (['config/systemd/staging-mailtrap-local.service', 'config/mail-capture/mailpit-relay.yml', 'config/mail-capture/mailpit.env'] as $path) {
        expect(mailCaptureSource($path))->not->toContain('0.0.0.0');
    }

    expect(mailCaptureSource('config/systemd/staging-mailtrap-local.service'))
        ->not->toMatch('/-listen\s+\[::/');
});

it('configures a best-effort relay-all mirror to Mailtrap Local', function () {
    // mailpit.env enables relay-all and references the relay config file...
    $env = mailCaptureEnvValues('config/mail-capture/mailpit.env');

    expect($env['MP_SMTP_RELAY_ALL'])->toBe('true');
    expect($env['MP_SMTP_RELAY_CONFIG'])->toBe('/etc/staging-mail-capture/mailpit-relay.yml');
    // The relay target moved out of the env file.
    expect($env)
        ->not->toHaveKey('MP_SMTP_RELAY_HOST')
        ->not->toHaveKey('MP_SMTP_RELAY_PORT');

    // ...and mailpit-relay.yml defines the loopback target using Mailpit's
    // top-level relay schema, with failures logged rather than forwarded.
    $relay = Yaml::parse(mailCaptureSource('config/mail-capture/mailpit-relay.yml'));

    // The relay must dial Mailtrap Local's 127.0.0.2 SMTP listener (not
    // 127.0.0.1), matching the IPv6-expansion workaround in the unit.
    expect($relay['host'])->toBe('127.0.0.2');
    expect($relay['port'])->toBe(3535);
    expect($relay['auth'])->toBe('none');
    // forward-smtp-errors must stay false so a mirror outage never fails delivery.
    expect($relay['forward-smtp-errors'])->toBeFalse();
});

it('enforces retention limits', function () {
    $mailpit = mailCaptureSource('config/mail-capture/mailpit.env');
    $mailtrap = mailCaptureSource('config/mail-capture/mailtrap-local.yml');

    expect($mailpit)
        ->toContain('MP_MAX_MESSAGES=5000')
        ->toContain('MP_MAX_AGE=14d')
        ->and($mailtrap)
        ->toContain('max_messages: 5000');
});

it('hardens both systemd units', function () {
    // Exact configured values, asserted against parsed (uncommented) directives.
    $expected = [
        'NoNewPrivileges' => 'true',
        'PrivateTmp' => 'true',
        'PrivateDevices' => 'true',
        'ProtectSystem' => 'strict',
        'ProtectHome' => 'true',
        'ProtectKernelTunables' => 'true',
        'ProtectKernelModules' => 'true',
        'ProtectControlGroups' => 'true',
        'RestrictSUIDSGID' => 'true',
        'LockPersonality' => 'true',
        'CapabilityBoundingSet' => '', // empty: no capabilities at all
    ];

    $readWritePaths = [
        'staging-mailpit' => '/var/lib/staging-mail-capture/mailpit',
        'staging-mailtrap-local' => '/var/lib/staging-mail-capture/mailtrap-local',
    ];

    foreach ($readWritePaths as $unit => $stateDir) {
        $path = 'config/systemd/'.$unit.'.service';

        foreach ($expected as $key => $value) {
            // toContain is variadic (all args are needles), so assert the exact
            // value without a message argument.
            expect(mailCaptureDirectiveValues($path, $key))->toContain($value);
        }

        // Exactly one ReadWritePaths, pointing at this service's state dir only.
        expect(mailCaptureDirectiveValues($path, 'ReadWritePaths'))
            ->toBe([$stateDir], "{$unit}: must grant write access to exactly {$stateDir}");
    }
});

it('makes Mailpit want, but not require, Mailtrap Local', function () {
    $mailpit = mailCaptureSource('config/systemd/staging-mailpit.service');

    expect($mailpit)
        ->toContain('Wants=network-online.target staging-mailtrap-local.service')
        ->toContain('After=network-online.target staging-mailtrap-local.service')
        // No Requires= directive (matching the start of a line, not the comment
        // that explains why we deliberately avoid it).
        ->not->toMatch('/^Requires=/m');

    // The mirror must never depend on Mailpit.
    expect(mailCaptureSource('config/systemd/staging-mailtrap-local.service'))
        ->not->toContain('staging-mailpit.service');
});

it('publishes both web UIs on environment-owned staging hostnames', function () {
    // The slice belongs to the shared staging environment, so the hostnames
    // carry no project segment.
    $hosts = [
        'mailpit-staging' => 'mailpit.staging.myprojects.pp.ua',
        'mailtrap-local-staging' => 'mailtrap.staging.myprojects.pp.ua',
    ];

    foreach ($hosts as $vhost => $host) {
        expect(mailCaptureSource('config/nginx/'.$vhost))->toContain('server_name '.$host.';');
    }
});

it('serves both web UIs from one shared SAN certificate', function () {
    // Both hostnames are one operational service and share a single Certbot
    // lineage; per-hostname certificate directories must not come back.
    foreach (['mailpit-staging', 'mailtrap-local-staging'] as $vhost) {
        expect(mailCaptureSource('config/nginx/'.$vhost))
            ->toContain('ssl_certificate /etc/letsencrypt/live/staging-mail-capture/fullchain.pem;')
            ->toContain('ssl_certificate_key /etc/letsencrypt/live/staging-mail-capture/privkey.pem;')
            ->not->toContain('/etc/letsencrypt/live/mailpit.staging.myprojects.pp.ua/')
            ->not->toContain('/etc/letsencrypt/live/mailtrap.staging.myprojects.pp.ua/');
    }

    // The runbook must provision exactly that lineage, covering both names.
    expect(mailCaptureSource('runbooks/mail-capture.md'))
        ->toContain('--cert-name staging-mail-capture')
        ->toContain('-d mailpit.staging.myprojects.pp.ua')
        ->toContain('-d mailtrap.staging.myprojects.pp.ua');
});

/**
 * Every fenced `bash` block in the runbook — i.e. the parts an operator copies
 * and runs, as opposed to the prose explaining them.
 *
 * @return list<string>
 */
function mailCaptureRunbookCommands(): array
{
    preg_match_all(
        '/```bash\n(.*?)```/s',
        mailCaptureSource('runbooks/mail-capture.md'),
        $matches,
    );

    expect($matches[1])->not->toBeEmpty('no ```bash blocks found in the runbook');

    return $matches[1];
}

it('never documents a bare standalone Certbot run', function () {
    // Nginx owns :80 on this host, so `--standalone` cannot bind. Prose may name
    // the flag to explain why it is wrong; no runnable block may use it.
    foreach (mailCaptureRunbookCommands() as $block) {
        expect($block)->not->toContain('--standalone');
    }

    $runbook = mailCaptureSource('runbooks/mail-capture.md');

    // Every certonly invocation must name an authenticator that coexists with a
    // running Nginx. Parse the actual commands rather than trusting prose.
    expect(preg_match_all('/certbot\s+certonly((?:\s*\\\\\s*\n\s*[^\n]+)+)/', $runbook, $matches))
        ->toBeGreaterThan(0, 'no `certbot certonly` invocation found in the runbook');

    foreach ($matches[1] as $invocation) {
        expect($invocation)
            ->toContain('--nginx')
            ->not->toContain('--standalone')
            ->not->toContain('--webroot');
    }

    // Where the prose does mention it, it must be a prohibition.
    expect($runbook)->toContain('`certbot --standalone`
**cannot** be used');
});

it('documents a reproducible no-downtime certificate bootstrap', function () {
    $runbook = mailCaptureSource('runbooks/mail-capture.md');

    // Why standalone is wrong here, stated explicitly.
    expect($runbook)
        ->toContain('Could not bind TCP port 80')
        // A temporary HTTP-only vhost gives the ACME challenge a server block.
        ->toContain('staging-mail-capture-bootstrap')
        ->toContain('listen 80;')
        // ...and it is torn down again, not left behind.
        ->toContain('rm -f \\')
        ->toContain('/etc/nginx/sites-enabled/staging-mail-capture-bootstrap');

    // Nginx is validated and reloaded on both sides of the certificate request:
    // once to activate the bootstrap vhost, once after removing it.
    expect(substr_count($runbook, 'sudo nginx -t && sudo systemctl reload nginx'))
        ->toBeGreaterThanOrEqual(2, 'bootstrap must validate + reload before and after certbot');

    // Ordering is checked inside the runnable block, not across the whole
    // document — the prose references these steps out of order on purpose.
    $blocks = array_values(array_filter(
        mailCaptureRunbookCommands(),
        fn (string $block): bool => str_contains($block, 'certbot certonly'),
    ));

    expect($blocks)->toHaveCount(1, 'expected exactly one certificate bootstrap block');

    $bootstrap = $blocks[0];

    $order = [
        'tee /etc/nginx/sites-available/staging-mail-capture-bootstrap',
        'certbot certonly',
        'rm -f',
        'certbot renew --dry-run',
        'install-mail-capture --apply',
    ];

    $previous = -1;

    foreach ($order as $step) {
        $position = strpos($bootstrap, $step);
        expect($position)->not->toBeFalse("bootstrap step missing: {$step}");
        expect($position)->toBeGreaterThan($previous, "bootstrap step out of order: {$step}");
        $previous = $position;
    }
});

it('documents renewal verification and the Nginx reload deploy hook', function () {
    $runbook = mailCaptureSource('runbooks/mail-capture.md');

    // Renewal must be proven, not assumed.
    expect($runbook)->toContain('certbot renew --dry-run');

    // The old unsubstantiated claim must not come back: `certonly` runs no
    // installer, so renewal does not reload Nginx by itself.
    expect($runbook)
        ->not->toContain('`certbot renew` reloads Nginx automatically')
        ->toContain('**Renewal does not
reload Nginx on its own.**');

    // Because the runbook states Nginx must reload, it must also install or
    // verify the hook that makes that true.
    expect($runbook)
        ->toContain('/etc/letsencrypt/renewal-hooks/deploy/')
        ->toContain('renew_deploy_hook')
        ->toContain('reload-nginx.sh')
        // The hook itself validates before reloading, and is executable.
        ->toContain('systemctl reload nginx')
        ->toContain('chmod 0755 /etc/letsencrypt/renewal-hooks/deploy/reload-nginx.sh');
});

it('protects both web UIs with the shared staging Basic Auth', function () {
    foreach (['mailpit-staging', 'mailtrap-local-staging'] as $vhost) {
        expect(mailCaptureSource('config/nginx/'.$vhost))
            // Active auth_basic directive with a non-empty realm (not commented).
            ->toMatch('/^\s*auth_basic\s+"[^"]+";\s*$/m')
            // Active auth_basic_user_file pointing at the exact shared htpasswd.
            ->toMatch('#^\s*auth_basic_user_file\s+/etc/nginx/rateguru-staging\.htpasswd;\s*$#m');
    }
});

it('proxies WebSockets to loopback only', function () {
    // Each vhost proxies to its own loopback upstream and uses its own uniquely
    // named connection-upgrade map variable (so the two vhosts never collide).
    $vhosts = [
        'mailpit-staging' => ['http://127.0.0.1:8025', '$mailpit_connection_upgrade'],
        'mailtrap-local-staging' => ['http://127.0.0.1:3550', '$mailtrap_connection_upgrade'],
    ];

    foreach ($vhosts as $vhost => [$upstream, $connectionVar]) {
        expect(mailCaptureSource('config/nginx/'.$vhost))
            ->toContain('proxy_pass '.$upstream.';')
            ->toContain('proxy_http_version 1.1;')
            ->toContain('proxy_set_header Upgrade $http_upgrade;')
            ->toContain('proxy_set_header Connection '.$connectionVar.';');
    }
});

it('never exposes an SMTP or raw capture port publicly through Nginx', function () {
    foreach (['mailpit-staging', 'mailtrap-local-staging'] as $vhost) {
        $source = mailCaptureSource('config/nginx/'.$vhost);

        // Only 80 and 443 may be listened on.
        expect($source)
            ->toContain('listen 80;')
            ->toContain('listen 443 ssl http2;');

        foreach (['1025', '3535', '8025', '3550'] as $port) {
            expect($source)->not->toContain('listen '.$port);
            expect($source)->not->toContain('listen [::]:'.$port);
        }
    }
});

it('exposes no public SMTP listener anywhere in the slice', function () {
    // Every SMTP bind is a loopback address: Mailpit on 127.0.0.1:1025, Mailtrap
    // Local on 127.0.0.2:3535, and the relay only dials that loopback target.
    $mailpitEnv = mailCaptureEnvValues('config/mail-capture/mailpit.env');
    expect($mailpitEnv['MP_SMTP_BIND_ADDR'])->toBe('127.0.0.1:1025');

    expect(mailCaptureSource('config/systemd/staging-mailtrap-local.service'))
        ->toContain('--smtp-listen 127.0.0.2:3535')
        ->not->toContain('0.0.0.0');

    // Nginx only ever proxies the HTTP UIs (8025 / 3550); no SMTP port is
    // proxied or listened on by either vhost.
    foreach (['mailpit-staging', 'mailtrap-local-staging'] as $vhost) {
        $source = mailCaptureSource('config/nginx/'.$vhost);
        foreach (['1025', '3535'] as $smtpPort) {
            expect($source)
                ->not->toContain(':'.$smtpPort)
                ->not->toContain('listen '.$smtpPort);
        }
    }
});

it('points staging Laravel mail at the Mailpit loopback SMTP', function () {
    $env = mailCaptureEnvValues('templates/environment/staging.env.example');

    // Exact values, including the deliberately empty credential/scheme keys.
    expect($env)
        ->toHaveKey('MAIL_USERNAME')
        ->toHaveKey('MAIL_PASSWORD')
        // MAIL_SCHEME is the key config/mail.php reads; MAIL_ENCRYPTION is a
        // legacy name Laravel no longer consults, so it must not linger here.
        ->toHaveKey('MAIL_SCHEME')
        ->not->toHaveKey('MAIL_ENCRYPTION');

    expect($env['MAIL_MAILER'])->toBe('smtp');
    expect($env['MAIL_HOST'])->toBe('127.0.0.1');
    expect($env['MAIL_PORT'])->toBe('1025');
    expect($env['MAIL_USERNAME'])->toBe('');
    expect($env['MAIL_PASSWORD'])->toBe('');
    // Empty, so the transport stays plain `smtp://` for Mailpit's loopback.
    expect($env['MAIL_SCHEME'])->toBe('');
    expect($env['MAIL_FROM_ADDRESS'])->toBe('noreply@staging.invalid');
    expect($env['MAIL_FROM_NAME'])->toBe('"${APP_NAME}"');
});

it('wires staging mail to the env key config/mail.php actually reads', function () {
    // Guards the rename at its source: if config/mail.php ever goes back to
    // MAIL_ENCRYPTION, the staging template must follow, not silently rot.
    expect(File::get(base_path('config/mail.php')))
        ->toContain("'scheme' => env('MAIL_SCHEME')")
        ->not->toContain('MAIL_ENCRYPTION');

    // No mail-capture-owned file may reference the dead key.
    foreach ([
        'templates/environment/staging.env.example',
        'templates/environment/production.env.example',
        'runbooks/mail-capture.md',
        'config/mail-capture/mailpit.env',
    ] as $path) {
        expect(str_contains(mailCaptureSource($path), 'MAIL_ENCRYPTION='))
            ->toBeFalse("dead MAIL_ENCRYPTION key still set in {$path}");
    }
});

it('leaves the production mail configuration unchanged', function () {
    $env = mailCaptureEnvValues('templates/environment/production.env.example');

    // Production mail keys stay exactly empty; no SMTP mailer/sender is injected.
    expect($env['MAIL_MAILER'])->toBe('');
    expect($env['MAIL_FROM_ADDRESS'])->toBe('');
    expect($env['MAIL_FROM_NAME'])->toBe('');

    // No staging SMTP wiring leaked into the production template — neither the
    // loopback transport keys nor the scheme key staging now sets.
    expect($env)
        ->not->toHaveKey('MAIL_HOST')
        ->not->toHaveKey('MAIL_PORT')
        ->not->toHaveKey('MAIL_USERNAME')
        ->not->toHaveKey('MAIL_PASSWORD')
        ->not->toHaveKey('MAIL_SCHEME')
        ->not->toHaveKey('MAIL_ENCRYPTION');

    // Production must not point at Mailpit/Mailtrap in any form.
    $raw = mailCaptureSource('templates/environment/production.env.example');

    foreach (['127.0.0.1', '1025', '3535', 'mailpit', 'mailtrap', 'staging.invalid'] as $needle) {
        expect(str_contains($raw, $needle))
            ->toBeFalse("staging mail capture leaked into production: {$needle}");
    }
});

it('dispatches --check to a root-free code path', function () {
    $installer = mailCaptureSource('scripts/install-mail-capture');

    // --check must dispatch to run_check; apply-only work lives in run_apply,
    // which is the path that requires root.
    expect($installer)
        ->toMatch('/--check\)\s*\n\s*MODE="check"/')
        ->toContain('require_root')
        ->toContain('run_apply');
});

it('installs every artefact under environment-owned names', function () {
    $installer = mailCaptureSource('scripts/install-mail-capture');

    $targets = [
        'BIN_MAILPIT' => '/usr/local/bin/staging-mailpit',
        'BIN_MAILTRAP' => '/usr/local/bin/staging-mailtrap-local',
        'ETC_DIR' => '/etc/staging-mail-capture',
        'STATE_ROOT' => '/var/lib/staging-mail-capture',
        'BACKUP_ROOT' => '/var/backups/staging-mail-capture',
        'USER_MAILPIT' => 'staging-mailpit',
        'USER_MAILTRAP' => 'staging-mailtrap-local',
        'NGINX_MAILPIT_NAME' => 'mailpit-staging',
        'NGINX_MAILTRAP_NAME' => 'mailtrap-local-staging',
    ];

    foreach ($targets as $variable => $value) {
        expect($installer)->toContain($variable.'="'.$value.'"');
    }

    // Unit paths are built from UNIT_DIR, so assert the unit file names.
    expect($installer)
        ->toContain('UNIT_MAILPIT="${UNIT_DIR}/staging-mailpit.service"')
        ->toContain('UNIT_MAILTRAP="${UNIT_DIR}/staging-mailtrap-local.service"');
});

it('cannot report apply success while a service is activating or restart-looping', function () {
    $installer = mailCaptureSource('scripts/install-mail-capture');

    // Runtime health is verified before the success banner is ever printed.
    $applyPos = strpos($installer, 'log "apply complete"');
    $verifyPos = strpos($installer, 'verify_runtime_health');
    expect($verifyPos)->not->toBeFalse('installer never calls verify_runtime_health');
    expect($applyPos)->not->toBeFalse();
    // The (last) call site precedes the success log.
    expect(strrpos($installer, 'verify_runtime_health'))->toBeLessThan($applyPos);

    // Only a stable active/running (or exited) state passes; failed fails fast
    // and everything else (activating auto-restart, inactive) times out to a
    // failure. The gate must fail the apply, not just warn.
    expect($installer)
        ->toContain('wait_service_active')
        ->toContain('state="$(service_property "${unit}" ActiveState)"')
        ->toContain('"${state}" == "active"')
        ->toMatch('/"\$\{state\}"\s*==\s*"failed"/')
        ->toContain('fail "mail-capture services are not healthy after apply');

    // On failure it must dump status + restart-loop properties + UNFILTERED
    // recent journal (no priority filter that would hide bind/restart lines).
    expect($installer)
        ->toContain('systemctl status')
        ->toContain('ActiveState=')
        ->toContain('SubState=')
        ->toContain('Result=')
        ->toContain('ExecMainStatus=')
        ->toContain('NRestarts=')
        ->toContain('journalctl -u')
        ->not->toMatch('/journalctl[^\n]* -p /');

    // It also checks the real listeners and HTTP APIs, including the 127.0.0.2
    // Mailtrap SMTP endpoint.
    expect($installer)
        ->toContain('wait_listener')
        ->toContain('wait_http_api')
        ->toContain('127.0.0.2:3535');
});

it('commits the apply only after runtime health has passed', function () {
    $installer = mailCaptureSource('scripts/install-mail-capture');

    // The whole point of the transaction is that activation and runtime health
    // — the most likely failure point — are still covered by rollback. So the
    // commit flag must be raised after the gate, never after `nginx -t`.
    $commitPos = strpos($installer, "\n    APPLY_COMMITTED=true");
    $gatePos = strrpos($installer, "\n    verify_runtime_health");
    $restartPos = strpos($installer, 'systemctl restart staging-mailtrap-local.service');

    expect($commitPos)->not->toBeFalse('run_apply never commits the apply');
    expect($gatePos)->not->toBeFalse('run_apply never calls verify_runtime_health');
    expect($restartPos)->not->toBeFalse();
    expect($gatePos)->toBeGreaterThan($restartPos, 'runtime health runs before the services are restarted');
    expect($commitPos)->toBeGreaterThan($gatePos, 'the apply is committed before runtime health is verified');

    // Rollback is armed, and the pre-apply runtime state snapshotted, before
    // the first change; the trap is disarmed only after the commit.
    $trapPos = strpos($installer, 'trap on_apply_error ERR EXIT');
    $snapshotPos = strpos($installer, "\n    capture_service_states\n");
    $firstChangePos = strpos($installer, '    create_user "${USER_MAILPIT}"');

    expect($snapshotPos)->not->toBeFalse('run_apply never snapshots the pre-apply service state');
    expect($snapshotPos)->toBeGreaterThan($trapPos, 'the state snapshot runs before rollback is armed');
    expect($snapshotPos)->toBeLessThan($firstChangePos, 'the state snapshot runs after the first change');
    expect($commitPos)->toBeLessThan(strrpos($installer, 'trap - ERR EXIT'));

    // Rollback restores runtime state, not just files.
    expect($installer)
        ->toContain('systemctl daemon-reload')
        ->toContain('restore_runtime_state')
        ->toContain('restore_service_enablement')
        ->toContain('restore_service_activation')
        ->toContain('restore_nginx_runtime')
        ->toContain('rollback INCOMPLETE');
});

it('treats enabling services for boot as fatal, never best-effort', function () {
    $installer = mailCaptureSource('scripts/install-mail-capture');

    // A service that cannot be enabled survives this run but disappears on the
    // next reboot, so its failure must not be swallowed.
    expect($installer)
        ->not->toMatch('/systemctl enable staging-[^\n]*\|\|\s*true/')
        ->not->toMatch('/systemctl enable staging-[^\n]*2>&1\s*$/m')
        ->toContain('fail "could not enable staging-mailtrap-local.service for boot"')
        ->toContain('fail "could not enable staging-mailpit.service for boot"');

    // ...and the health gate independently requires both to be enabled.
    expect($installer)
        ->toContain('systemctl is-enabled --quiet')
        ->toContain('is not enabled for boot');
});

it('requires a stability window before calling a service healthy', function () {
    $installer = mailCaptureSource('scripts/install-mail-capture');

    expect($installer)
        ->toContain('assert_service_stable')
        ->toContain('RUNTIME_STABILITY_WAIT')
        // NRestarts now drives the decision, not just the diagnostics dump.
        ->toContain('service_restart_count')
        ->toContain('(( after > before ))')
        ->toContain('it is in a restart loop');

    // The stability window cannot be disabled by a stray environment variable.
    expect($installer)
        ->toMatch('/\[\[ "\$\{RUNTIME_STABILITY_WAIT\}" =~ \^\[1-9\]\[0-9\]\*\$ \]\] \|\| RUNTIME_STABILITY_WAIT=\d+/')
        ->toMatch('/\[\[ "\$\{RUNTIME_WAIT\}" =~ \^\[1-9\]\[0-9\]\*\$ \]\] \|\| RUNTIME_WAIT=\d+/');
});

it('passes the runtime health gate only for enabled, stably active services', function (
    callable $scenario,
    int $expectedExit,
    ?string $expectedMessage,
) {
    $workspace = mailCaptureStubWorkspace();

    try {
        mailCaptureHealthyState($workspace['state']);
        $scenario($workspace['state']);

        $result = mailCaptureRunHarness($workspace, mailCaptureHarnessPreamble()
            .mailCaptureBlock('runtime health')
            ."\nverify_runtime_health\n");

        expect($result['exit'])->toBe($expectedExit, "unexpected exit status:\n".$result['output']);

        if ($expectedMessage !== null) {
            expect($result['output'])->toContain($expectedMessage);
        }
    } finally {
        exec('rm -rf '.escapeshellarg($workspace['root']));
    }
})->with([
    'healthy, enabled and active' => [
        fn (string $state) => null,
        0,
        'runtime health verified for both services',
    ],
    'activating (auto-restart)' => [
        function (string $state) {
            file_put_contents($state.'/staging-mailtrap-local.service.active', 'activating');
            file_put_contents($state.'/staging-mailtrap-local.service.sub', 'auto-restart');
        },
        1,
        'did not reach a stable active state',
    ],
    'failed' => [
        function (string $state) {
            file_put_contents($state.'/staging-mailpit.service.active', 'failed');
            file_put_contents($state.'/staging-mailpit.service.sub', 'failed');
        },
        1,
        'did not reach a stable active state',
    ],
    'active but disabled' => [
        fn (string $state) => file_put_contents(
            $state.'/staging-mailtrap-local.service.enabled',
            'disabled',
        ),
        1,
        'is not enabled for boot',
    ],
    'restarting during the stability window' => [
        // NRestarts climbs between the two reads: a slow restart loop that was
        // briefly active and serving.
        fn (string $state) => touch($state.'/staging-mailtrap-local.service.nrestarts_step'),
        1,
        'it is in a restart loop',
    ],
    'missing listener' => [
        fn (string $state) => file_put_contents(
            $state.'/listeners',
            "127.0.0.1:3550\n127.0.0.1:1025\n127.0.0.1:8025\n",
        ),
        1,
        'is not listening on 127.0.0.2:3535',
    ],
    'silent HTTP API' => [
        fn (string $state) => file_put_contents(
            $state.'/apis',
            "http://127.0.0.1:3550/api/v1/version\n",
        ),
        1,
        'did not respond within',
    ],
]);

it('restores files and runtime state when the apply fails', function () {
    $workspace = mailCaptureStubWorkspace();
    $state = $workspace['state'];

    try {
        $etc = $workspace['root'].'/etc';
        $backup = $workspace['root'].'/backup';
        mkdir($etc, 0o700, true);
        mkdir($backup.$etc, 0o700, true);

        // One replaced file (backed up) and one brand-new file.
        file_put_contents($backup.$etc.'/unit.service', "previous\n");
        file_put_contents($etc.'/unit.service', "installed\n");
        file_put_contents($etc.'/fresh.conf', "installed\n");

        // Runtime "now": the failed apply left both services running the new
        // configuration, both enabled; nginx is up.
        mailCaptureHealthyState($state);
        file_put_contents($state.'/nginx.active', 'active');

        $script = mailCaptureHarnessPreamble()
            .mailCaptureBlock('runtime health')
            .mailCaptureBlock('rollback')
            ."\nBACKUP_DIR=".escapeshellarg($backup)."\n"
            ."CHANGED_UNIT=true\n"
            ."CHANGED_NGINX=true\n"
            .'ROLLBACK_RESTORE=('.escapeshellarg($etc.'/unit.service').")\n"
            .'ROLLBACK_REMOVE=('.escapeshellarg($etc.'/fresh.conf').")\n"
            // Before the apply the mirror ran and was enabled; Mailpit was
            // stopped and disabled.
            ."SERVICE_STATE_BEFORE=('staging-mailtrap-local.service|active|enabled' "
            ."'staging-mailpit.service|inactive|disabled')\n"
            ."rollback\n";

        $result = mailCaptureRunHarness($workspace, $script);

        expect($result['exit'])->toBe(0, "rollback reported failure:\n".$result['output']);

        // 1. Files: the replaced file is back, the new one is gone.
        expect(file_get_contents($etc.'/unit.service'))->toBe("previous\n");
        expect(file_exists($etc.'/fresh.conf'))->toBeFalse();

        // 2. systemd re-read the restored units, so the old configuration is
        //    loaded rather than merely present on disk.
        $calls = file_get_contents($state.'/calls');
        expect($calls)->toContain('systemctl daemon-reload');

        // 3. Boot and running state are back to the pre-apply values.
        expect(file_get_contents($state.'/staging-mailtrap-local.service.enabled'))->toBe('enabled');
        expect(file_get_contents($state.'/staging-mailtrap-local.service.active'))->toBe('active');
        expect(file_get_contents($state.'/staging-mailpit.service.enabled'))->toBe('disabled');
        expect(file_get_contents($state.'/staging-mailpit.service.active'))->toBe('inactive');

        // 4. Nginx was re-validated and reloaded with the restored files.
        expect($calls)
            ->toContain('nginx -t')
            ->toContain('systemctl reload nginx');

        expect($result['output'])->toContain('files and runtime state restored');
    } finally {
        exec('rm -rf '.escapeshellarg($workspace['root']));
    }
});

it('reports an incomplete rollback instead of claiming success', function () {
    $workspace = mailCaptureStubWorkspace();
    $state = $workspace['state'];

    try {
        mailCaptureHealthyState($state);
        file_put_contents($state.'/nginx.active', 'active');
        // The previously running mirror refuses to come back up.
        touch($state.'/fail_restart_staging-mailtrap-local.service');

        $script = mailCaptureHarnessPreamble()
            .mailCaptureBlock('runtime health')
            .mailCaptureBlock('rollback')
            ."\nBACKUP_DIR=".escapeshellarg($workspace['root'].'/backup')."\n"
            ."CHANGED_UNIT=true\n"
            ."CHANGED_NGINX=false\n"
            ."ROLLBACK_RESTORE=()\n"
            ."ROLLBACK_REMOVE=()\n"
            ."SERVICE_STATE_BEFORE=('staging-mailtrap-local.service|active|enabled')\n"
            ."rollback\n";

        $result = mailCaptureRunHarness($workspace, $script);

        expect($result['exit'])->toBe(1, "rollback claimed success:\n".$result['output']);
        expect($result['output'])
            ->toContain('could not return staging-mailtrap-local.service to its previous active state')
            ->toContain('rollback INCOMPLETE')
            ->toContain('diagnostics: staging-mailtrap-local.service')
            ->not->toContain('files and runtime state restored');
    } finally {
        exec('rm -rf '.escapeshellarg($workspace['root']));
    }
});

it('reports exact endpoints, restart-loop state and unfiltered journal in status', function () {
    $status = mailCaptureSource('scripts/status-mail-capture');

    // Exact endpoints, including the 127.0.0.2 Mailtrap SMTP host.
    expect($status)
        ->toContain('listener_line "Mailpit SMTP" "127.0.0.1" "1025"')
        ->toContain('listener_line "Mailpit HTTP/API" "127.0.0.1" "8025"')
        ->toContain('listener_line "Mailtrap SMTP" "127.0.0.2" "3535"')
        ->toContain('listener_line "Mailtrap HTTP/API" "127.0.0.1" "3550"');

    // Restart-loop diagnostics surfaced for both services.
    expect($status)
        ->toContain('ActiveState=')
        ->toContain('SubState=')
        ->toContain('Result=')
        ->toContain('NRestarts=');

    // The journal must be unfiltered: the journalctl invocation carries no `-p`
    // priority filter that would hide the level=ERROR bind/restart lines. (The
    // explanatory comment may name `-p err`; the actual command must not use it.)
    expect($status)
        ->toContain("journalctl -u \"\${unit}\" --no-pager --since '-1h'")
        ->not->toMatch('/journalctl[^\n]* -p /');
});

// =============================================================================
// Slice 5.5: the read-only / E2E split.
//
// verify-mail-capture's default mode sends SMTP, deletes messages and
// stops/starts the mirror service. Automated bootstrap verification
// (install-bootstrap-services --check/--verify, and therefore bootstrap-host
// --check/--verify) must never do any of that, so --read-only exists and must
// be provably inert.
// =============================================================================

/**
 * Replace the workspace curl stub with one that logs every invocation, so a
 * DELETE can be detected rather than merely assumed absent.
 */
function mailCaptureInstallLoggingCurl(array $workspace): void
{
    $path = $workspace['bin'].'/curl';
    file_put_contents($path, <<<'SH'
#!/usr/bin/env bash
set -uo pipefail
printf '%s\n' "curl $*" >>"${STUB_STATE_DIR}/calls"
url=""
for arg in "$@"; do
    case "${arg}" in http*) url="${arg}" ;; esac
done
grep -Fxq "${url}" "${STUB_STATE_DIR}/apis" 2>/dev/null || exit 22
exit 0
SH);
    chmod($path, 0o755);
}

it('verify-mail-capture --read-only performs zero mutation: no SMTP, no deletion, no service state change', function () {
    $workspace = mailCaptureStubWorkspace();

    try {
        mailCaptureHealthyState($workspace['state']);
        mailCaptureInstallLoggingCurl($workspace);

        $result = mailCaptureRunHarness(
            $workspace,
            'exec bash '.escapeshellarg(base_path('infrastructure/scripts/verify-mail-capture')).' --read-only'."\n",
        );

        expect($result['exit'])->toBe(0, "read-only verification must succeed on a healthy host:\n{$result['output']}");
        expect($result['output'])->toContain('read-only; no mutation performed');
        expect($result['output'])->toContain('read-only verification succeeded');

        $calls = (string) file_get_contents($workspace['state'].'/calls');

        // Zero service state change.
        foreach (['stop', 'start', 'restart', 'reload', 'enable', 'disable', 'mask'] as $verb) {
            // toContain() is variadic in Pest — a second argument would be
            // another needle, not a failure message.
            expect(str_contains($calls, "systemctl {$verb}"))
                ->toBeFalse("read-only mode ran a mutating systemctl verb: {$verb}");
        }

        // Zero message deletion.
        expect($calls)->not->toContain('-X DELETE');
        expect($calls)->not->toContain('/api/v1/search');

        // Zero SMTP submission — proven behaviourally: the only e2e step that
        // reports success after a real send never appears, and none of the
        // mutating acceptance milestones ran.
        expect($result['output'])->not->toContain('SMTP message accepted by Mailpit');
        expect($result['output'])->not->toContain('mirrored copy appears in Mailtrap Local');
        expect($result['output'])->not->toContain('stopping Mailtrap Local');
        expect($result['output'])->not->toContain('cleaning up test messages');

        // The read-only probes really did run.
        expect($calls)->toContain('systemctl is-active');
        expect($result['output'])->toContain('Mailpit API is available');
        expect($result['output'])->toContain('Mailtrap Local API is available');
    } finally {
        exec('rm -rf '.escapeshellarg($workspace['root']));
    }
});

it('keeps --e2e as the mutating acceptance mode, and the default when no mode is given', function () {
    $verify = mailCaptureSource('scripts/verify-mail-capture');

    // The mutating acceptance behavior still exists, unchanged.
    expect($verify)
        ->toContain('systemctl stop staging-mailtrap-local.service')
        ->toContain('systemctl start staging-mailtrap-local.service')
        ->toContain('delete_by_token')
        ->toContain('run_e2e_checks');

    // Backward compatibility: a bare invocation is still the full acceptance
    // run, so existing operational usage keeps working.
    expect($verify)->toContain('MODE="e2e"');

    // Only the mutating mode demands root and arms the cleanup trap.
    expect($verify)
        ->toContain('require_root')
        ->toContain('trap cleanup EXIT');

    // A non-root --e2e still refuses, while --read-only does not require root.
    if (getmyuid() !== 0) {
        $script = escapeshellarg(base_path('infrastructure/scripts/verify-mail-capture'));
        exec("bash {$script} --e2e 2>&1", $e2eOutput, $e2eExit);
        expect($e2eExit)->not->toBe(0);
        // Either the root gate or a missing systemd tool stops it — never a
        // silent mutating run.
        expect(implode("\n", $e2eOutput))->toMatch('/executed as root|required tool not found/');
    }
});

it('uses distinct Mailtrap SMTP and HTTP hosts in the verifier', function () {
    $verify = mailCaptureSource('scripts/verify-mail-capture');

    expect($verify)
        ->toContain('MAILTRAP_SMTP_HOST="127.0.0.2"')
        ->toContain('MAILTRAP_HTTP_HOST="127.0.0.1"')
        ->toContain('MAILPIT_SMTP_HOST="127.0.0.1"')
        ->toContain('MAILPIT_HTTP_HOST="127.0.0.1"')
        // The old single-host variables must be gone.
        ->not->toContain('MAILTRAP_HOST=')
        ->not->toContain('MAILPIT_HOST=');

    // SMTP submission targets the SMTP host, and the mirror API targets the
    // HTTP host — the two must not be conflated.
    expect($verify)
        ->toContain('smtp_send "${MAILPIT_SMTP_HOST}"')
        ->toContain('MAILTRAP_API="http://${MAILTRAP_HTTP_HOST}:${MAILTRAP_HTTP_PORT}"')
        // After restarting the mirror it waits for a stable active state before
        // asserting mirroring resumes.
        ->toContain('wait_service_active "staging-mailtrap-local.service"');
});

it('keeps project-scoped names out of the shared staging slice', function () {
    $paths = [
        'config/systemd/staging-mailpit.service',
        'config/systemd/staging-mailtrap-local.service',
        'config/nginx/mailpit-staging',
        'config/nginx/mailtrap-local-staging',
        'config/mail-capture/mailpit.env',
        'config/mail-capture/mailpit-relay.yml',
        'config/mail-capture/mailtrap-local.yml',
        'scripts/install-mail-capture',
        'scripts/verify-mail-capture',
        'scripts/status-mail-capture',
        'runbooks/mail-capture.md',
    ];

    // Two project-scoped paths are deliberate and pre-existing: the shared
    // staging htpasswd, and the deployed location of this runbook (the RateGuru
    // repository is the temporary source of truth). Nothing else may be.
    $deliberate = [
        '/etc/nginx/rateguru-staging.htpasswd',
        '/home/www/rateguru/runbooks/mail-capture.md',
    ];

    foreach ($paths as $path) {
        expect(str_replace($deliberate, '', mailCaptureSource($path)))
            ->not->toContain('rateguru-mailpit')
            ->not->toContain('rateguru-mailtrap')
            ->not->toContain('rateguru-mail-capture')
            ->not->toContain('/etc/rateguru/')
            ->not->toContain('.rateguru.staging.');
    }
});

it('runs installer --check with stubbed commands and mutates nothing', function () {
    $installer = base_path('infrastructure/scripts/install-mail-capture');
    $stubDir = sys_get_temp_dir().'/mc-check-stubs-'.uniqid();
    $log = $stubDir.'/invoked.log';

    expect(mkdir($stubDir, 0o755, true))->toBeTrue();

    try {
        // Fake `uname` so run_check proceeds past the Linux/arch gate on any host.
        file_put_contents(
            $stubDir.'/uname',
            "#!/usr/bin/env bash\ncase \"\$1\" in\n  -s) echo Linux;;\n  -m) echo x86_64;;\n  *) echo Linux;;\nesac\n",
        );
        chmod($stubDir.'/uname', 0o755);

        // Any mutating command (filesystem, network, users, services) records
        // its invocation. A side-effect-free check must never trigger one.
        $mutating = [
            'useradd', 'systemctl', 'systemd-analyze', 'nginx',
            'curl', 'wget', 'install', 'mkdir', 'rm', 'cp', 'mv',
            'chmod', 'chown', 'ln', 'tar', 'sha256sum',
        ];

        foreach ($mutating as $cmd) {
            file_put_contents(
                $stubDir.'/'.$cmd,
                "#!/usr/bin/env bash\necho \"{$cmd} \$*\" >> ".escapeshellarg($log)."\nexit 0\n",
            );
            chmod($stubDir.'/'.$cmd, 0o755);
        }

        $command = 'PATH='.escapeshellarg($stubDir).':"$PATH" '
            .escapeshellarg($installer).' --check 2>&1';

        $output = [];
        $exit = 0;
        exec($command, $output, $exit);

        expect($exit)->toBe(0, "check mode failed:\n".implode("\n", $output));
        expect(file_exists($log))
            ->toBeFalse('check mode invoked a mutating command: '
                .(file_exists($log) ? file_get_contents($log) : ''));
    } finally {
        array_map('unlink', glob($stubDir.'/*') ?: []);
        @rmdir($stubDir);
    }
});

it('marks the mail-capture phase completed, and the phase after it too', function () {
    $roadmap = mailCaptureSource('ROADMAP.md');

    // Status table: phase 3 is done, and phase 4 — which took over from it —
    // has since closed as well.
    expect($roadmap)
        ->toMatch('/^\|\s*3\s*\|\s*Staging mail capture\s*\|\s*✅ completed\s*\|$/m')
        ->toMatch('/^\|\s*4\s*\|\s*Multi-target production model\s*\|\s*✅ completed\s*\|$/m')
        // Section headings must agree with the table.
        ->toContain('## 3. Staging mail capture — completed')
        ->toContain('## 4. Multi-target production model — completed');

    // At most one phase is current at a time; right now none is, because
    // the target-aware migration closed before the clean-host bootstrap implementation started.
    expect(substr_count($roadmap, '🚧 current'))->toBeLessThanOrEqual(1);

    // No stale "current"/"planned" wording left on either phase.
    expect($roadmap)
        ->not->toContain('## 3. Staging mail capture — current')
        ->not->toContain('## 4. Multi-target production model — planned')
        ->not->toContain('## 4. Multi-target production model — current');
});

it('states the correct Mailtrap Local listeners in the roadmap', function () {
    $roadmap = mailCaptureSource('ROADMAP.md');

    // SMTP moved to 127.0.0.2; HTTP/API deliberately stayed on 127.0.0.1.
    expect($roadmap)
        ->toContain('`127.0.0.2:3535` SMTP')
        ->toContain('`127.0.0.1:3550` HTTP/API')
        // Mailpit's own listeners are unaffected by the Mailtrap workaround.
        ->toContain('`127.0.0.1:1025` SMTP')
        ->toContain('`127.0.0.1:8025`');
});

it('never claims Mailtrap Local SMTP listens on 127.0.0.1 anywhere in infrastructure', function () {
    // `127.0.0.1:3535` may only survive where the runbook explains the broken
    // upstream behavior we work around — never as a claim about what we run.
    $offenders = [];

    foreach (File::allFiles(base_path('infrastructure')) as $file) {
        $relative = str_replace('\\', '/', $file->getRelativePathname());

        foreach (preg_split('/\R/', File::get($file->getPathname())) as $number => $line) {
            if (! str_contains($line, '127.0.0.1:3535')) {
                continue;
            }

            $offenders[] = $relative.':'.($number + 1).': '.trim($line);
        }
    }

    // Outside the runbook there is no legitimate mention at all.
    foreach ($offenders as $offender) {
        expect($offender)->toStartWith(
            'runbooks/mail-capture.md:',
            "stale 127.0.0.1:3535 claim outside the runbook explanation: {$offender}",
        );
    }

    // Inside the runbook, only two phrasings may carry the stale address: the
    // failure-mode explanation and the troubleshooting contrast. Both are
    // matched on whitespace-normalized text, because the first wraps across
    // lines — a per-line check would reject its own continuation.
    $allowed = [
        'expands a `--smtp-listen 127.0.0.1:3535` bind into **both** '
            .'IPv4 `127.0.0.1:3535` and IPv6 `[::1]:3535`',
        '`127.0.0.2:3535` (not `127.0.0.1:3535`)',
    ];

    $normalized = preg_replace('/\s+/', ' ', mailCaptureSource('runbooks/mail-capture.md'));

    // Both explanations must still be there — the address may not simply vanish.
    foreach ($allowed as $phrase) {
        expect(str_contains($normalized, $phrase))
            ->toBeTrue("runbook lost its workaround explanation: {$phrase}");
    }

    // With exactly those phrases removed, no mention may remain anywhere else in
    // the runbook: any other prose naming the stale address is a stale claim.
    expect(str_contains(str_replace($allowed, '', $normalized), '127.0.0.1:3535'))
        ->toBeFalse('runbook mentions 127.0.0.1:3535 outside the two allowed explanations');
});

it('explains the Mailtrap Local IPv6 loopback workaround in the runbook', function () {
    $runbook = mailCaptureSource('runbooks/mail-capture.md');

    expect($runbook)
        ->toContain('### Why Mailtrap Local SMTP binds 127.0.0.2')
        // The reason: 0.2.0 expands an IPv4 loopback bind onto [::1].
        ->toContain('Mailtrap Local 0.2.0')
        ->toContain('[::1]:3535')
        ->toContain('bind: cannot assign requested address')
        // ...and the resolution, without enabling IPv6 anywhere.
        ->toContain('distinct IPv4 loopback address')
        ->toContain('IPv6 stays disabled.');
});

it('documents the split loopback addresses in the runbook security model', function () {
    $runbook = mailCaptureSource('runbooks/mail-capture.md');

    expect(preg_match('/## Security model\n(.*?)(?=\n## )/s', $runbook, $matches))
        ->toBe(1, 'could not locate the Security model section in the runbook');

    $security = $matches[1];

    // Every listener is named with its actual address, so the section cannot
    // drift from the units.
    expect($security)
        ->toContain('`127.0.0.1:1025`')
        ->toContain('`127.0.0.1:8025`')
        ->toContain('`127.0.0.2:3535`')
        ->toContain('`127.0.0.1:3550`')
        // The 127.0.0.2 exception is called out as deliberate, not incidental.
        ->toContain('`127.0.0.2`**, and that difference is intentional')
        // ...and is justified as no less private than 127.0.0.1.
        ->toContain('`127.0.0.0/8`')
        ->toContain('no less private than')
        ->toContain('routable off-host');
});

it('accepts a wildcard DNS record for the mail-capture hostnames', function () {
    $runbook = mailCaptureSource('runbooks/mail-capture.md');

    // DNS may come from per-host records or an existing wildcard; the runbook
    // must not demand explicit records that already exist by wildcard.
    expect($runbook)
        ->toContain('*.staging.myprojects.pp.ua')
        ->toContain('wildcard')
        ->toContain('`A`/`AAAA` records')
        // Wording is normalized so a re-wrap of the paragraph cannot break it.
        ->and(preg_replace('/\s+/', ' ', $runbook))
        ->toContain('Resolution may come from explicit `A`/`AAAA` records **or** from an existing wildcard record')
        ->toContain('no per-host records need to be created when it is in place');
});

it('documents the recovery drill distinctions in the roadmap', function () {
    $roadmap = mailCaptureSource('ROADMAP.md');

    expect($roadmap)
        ->toContain('Staging mail capture')
        ->toContain('Backup creation')
        ->toContain('Restore-test')
        ->toContain('Clean-server recovery rehearsal')
        ->toContain('Production disaster recovery');
});

it('excludes captured staging mail from disaster-recovery backups', function () {
    $runbook = mailCaptureSource('runbooks/mail-capture.md');
    $backup = mailCaptureSource('scripts/backup');

    expect($runbook)->toContain('exclude');

    // moved the allowlist into
    // build_server_configuration_archive(), which builds a lowercase
    // `infra_paths=(...)` array — now the sole, target-only allowlist, since
    // the target-aware migration's legacy-selector removal deleted the parallel legacy-selector
    // copy this test used to also check — plus an empty
    // `local -a infra_paths=()` declaration above it, which the same regex
    // also matches (with an empty captured body). Filtered out here so the
    // assertion genuinely proves the real allowlist was found and checked,
    // rather than trivially passing against an empty capture.
    expect(preg_match_all('/infra_paths=\((.*?)\)/s', $backup, $allowlists))
        ->toBeGreaterThanOrEqual(1, 'could not locate the infra_paths allowlist in scripts/backup');

    $nonEmptyAllowlists = array_values(array_filter(
        $allowlists[1],
        fn (string $allowlist): bool => trim($allowlist) !== '',
    ));

    expect($nonEmptyAllowlists)
        ->toHaveCount(1, 'expected exactly one non-empty infra_paths allowlist in scripts/backup');

    foreach ($nonEmptyAllowlists as $allowlist) {
        expect($allowlist)
            ->not->toContain('staging-mail-capture')
            ->not->toContain('var/lib');
    }
});
