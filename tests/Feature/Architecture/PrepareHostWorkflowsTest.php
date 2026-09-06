<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * Prepare Host, GitHub side: the `Prepare staging host` and `Prepare production
 * host` workflows and the shared .github/actions/prepare-rateguru-host action
 * they both call.
 *
 * The properties pinned here are the ones an operator cannot see and a reviewer
 * would have to reconstruct by hand: that neither workflow lets anyone choose a
 * target, an environment or an application ref; that preparation uses a
 * privileged bootstrap credential which is NOT the deployment credential; that
 * host verification is strict; that uploaded material never outlives its run;
 * and that production stays fail-closed while tits-guru is lifecycle=planned.
 */

/** @return array<string, mixed> */
function phwWorkflow(string $name): array
{
    return Yaml::parse(File::get(base_path('.github/workflows/'.$name)));
}

function phwActionSource(): string
{
    return File::get(base_path('.github/actions/prepare-rateguru-host/action.yml'));
}

/**
 * The same text with comment lines removed.
 *
 * These files explain their own security properties in prose — "never the
 * deployment key", "no StrictHostKeyChecking=no anywhere" — so a naive
 * substring search over the whole file would fail on the very sentence that
 * promises the property. Only executable lines are searched.
 */
function phwExecutable(string $source): string
{
    return executableSourceLines($source);
}

/** @return array<string, mixed> */
function phwAction(): array
{
    return Yaml::parse(phwActionSource());
}

/**
 * The single prepare step of a prepare workflow.
 *
 * @return array<string, mixed>
 */
function phwPrepareStep(string $workflow): array
{
    $jobs = phwWorkflow($workflow)['jobs'];
    $job = reset($jobs);

    $step = collect($job['steps'])
        ->firstWhere('uses', './.github/actions/prepare-rateguru-host');

    expect($step)->not->toBeNull("{$workflow} must call the shared prepare action");

    return $step;
}

// =============================================================================
// Operator surface
// =============================================================================

it('offers both prepare workflows to an operator by environment name', function () {
    foreach ([
        'prepare-staging-host.yml' => 'Prepare staging host',
        'prepare-production-host.yml' => 'Prepare production host',
    ] as $file => $name) {
        expect(File::exists(base_path('.github/workflows/'.$file)))->toBeTrue();
        expect(phwWorkflow($file)['name'])->toBe($name);
    }
});

it('is dispatch-only, with no trigger that could prepare a host automatically', function () {
    foreach (['prepare-staging-host.yml', 'prepare-production-host.yml'] as $file) {
        // Symfony's YAML parser resolves the bare `on:` key to the boolean
        // true, exactly as the rest of this repository's workflow tests handle
        // it.
        $triggers = phwWorkflow($file)[true] ?? phwWorkflow($file)['on'];

        expect(array_keys($triggers))->toBe(['workflow_dispatch'],
            "{$file} must be workflow_dispatch only");
    }
});

it('gives the operator no input at all — no target, no environment, no ref', function () {
    foreach (['prepare-staging-host.yml', 'prepare-production-host.yml'] as $file) {
        $workflow = phwWorkflow($file);
        $triggers = $workflow[true] ?? $workflow['on'];

        expect($triggers['workflow_dispatch'] ?? null)->toBeNull(
            "{$file} must expose no workflow_dispatch inputs");

        $source = File::get(base_path('.github/workflows/'.$file));
        expect($source)->not->toContain('inputs:');
        expect($source)->not->toContain('${{ inputs.');
    }
});

it('fixes the target and the environment structurally in each workflow', function () {
    foreach ([
        'prepare-staging-host.yml' => ['staging-main', 'staging'],
        'prepare-production-host.yml' => ['tits-guru', 'production'],
    ] as $file => [$target, $environment]) {
        $jobs = phwWorkflow($file)['jobs'];
        $job = reset($jobs);

        expect($job['environment'])->toBe($environment, "{$file} must pin the GitHub Environment");

        $step = phwPrepareStep($file);
        expect($step['with']['deployment-target'])->toBe($target);
        expect($step['with']['environment'])->toBe($environment);
    }
});

it('has no application source input anywhere in the preparation path', function () {
    $sources = array_map('phwExecutable', [
        phwActionSource(),
        File::get(base_path('.github/workflows/prepare-staging-host.yml')),
        File::get(base_path('.github/workflows/prepare-production-host.yml')),
    ]);

    foreach ($sources as $source) {
        foreach (['ref:', 'source-sha', 'source_sha', 'run-migrations', 'artifact-path', 'release-id'] as $forbidden) {
            // `ref: develop` on the checkout step is the trusted TOOLING ref,
            // and is the one legitimate occurrence.
            $occurrences = substr_count($source, $forbidden);

            if ($forbidden === 'ref:') {
                expect($occurrences)->toBeLessThanOrEqual(1);

                continue;
            }

            expect($occurrences)->toBe(0, "preparation must not involve {$forbidden}");
        }
    }
});

// =============================================================================
// Trusted tooling
// =============================================================================

it('always prepares with tooling from develop, never from an application ref', function () {
    foreach (['prepare-staging-host.yml', 'prepare-production-host.yml'] as $file) {
        $jobs = phwWorkflow($file)['jobs'];
        $job = reset($jobs);

        $checkouts = collect($job['steps'])
            ->filter(static fn (array $step): bool => str_starts_with($step['uses'] ?? '', 'actions/checkout@'))
            ->values();

        expect($checkouts)->toHaveCount(1, "{$file} must check out exactly one tree");
        expect($checkouts[0]['with']['ref'])->toBe('develop');
        expect($checkouts[0]['with']['persist-credentials'])->toBeFalse();
    }
});

it('bundles only infrastructure/, never the application', function () {
    $source = phwActionSource();

    expect($source)->toContain('--directory "${GITHUB_WORKSPACE}"');
    expect($source)->toContain("\n          infrastructure\n");
    expect($source)->not->toContain('composer install');
    expect($source)->not->toContain('npm ');
    expect($source)->not->toContain('download-artifact');
});

// =============================================================================
// Credential separation and strict host verification
// =============================================================================

it('uses a bootstrap credential that is separate from the deployment credential', function () {
    foreach (['prepare-staging-host.yml', 'prepare-production-host.yml'] as $file) {
        $with = phwPrepareStep($file)['with'];

        expect($with['bootstrap-ssh-key'])->toContain('secrets.BOOTSTRAP_SSH_KEY');
        expect($with['bootstrap-known-hosts'])->toContain('secrets.BOOTSTRAP_KNOWN_HOSTS');
        expect($with['bootstrap-user'])->toContain('vars.BOOTSTRAP_USER');

        // Never the deployment key: that credential is restricted to the
        // deploy wrappers and must not become a host administration key.
        $source = phwExecutable(File::get(base_path('.github/workflows/'.$file)));
        expect($source)->not->toContain('DEPLOY_SSH_KEY');
        expect($source)->not->toContain('DEPLOY_KNOWN_HOSTS');
        expect($source)->not->toContain('DEPLOY_USER');

        // The physical host binding, however, is deliberately reused: a GitHub
        // Environment is where a logical target is bound to its current host.
        expect($with['bootstrap-host'])->toContain('vars.DEPLOY_HOST');
    }
});

it('refuses to fall back to the deployment credential when no bootstrap key exists', function () {
    $source = phwActionSource();

    expect($source)->toContain('has no bootstrap SSH credential');
    expect($source)->toContain('that key is restricted to the deploy wrappers and cannot bootstrap a host');
    expect(phwExecutable($source))->not->toContain('DEPLOY_SSH_KEY');
});

it('verifies the host key strictly and never falls back to a password or TOFU', function () {
    $source = phwExecutable(phwActionSource());

    expect(substr_count($source, 'StrictHostKeyChecking=yes'))->toBeGreaterThanOrEqual(4);
    expect($source)->not->toContain('StrictHostKeyChecking=no');
    expect($source)->not->toContain('StrictHostKeyChecking=accept-new');
    expect($source)->not->toContain('-o UserKnownHostsFile=/dev/null');
    expect($source)->not->toContain('sshpass');

    // Every SSH and SCP invocation is non-interactive and key-only.
    expect(substr_count($source, 'BatchMode=yes'))->toBeGreaterThanOrEqual(4);
    expect(substr_count($source, 'IdentitiesOnly=yes'))->toBeGreaterThanOrEqual(4);
    expect($source)->toContain('UserKnownHostsFile="${RATEGURU_BOOTSTRAP_KNOWN_HOSTS_PATH}"');
});

it('requires root or non-interactive sudo before it uploads anything', function () {
    $steps = phwAction()['runs']['steps'];
    $names = array_column($steps, 'name');

    $accessIndex = array_search('Verify privileged access on the host', $names, true);
    $uploadIndex = array_search('Upload bootstrap bundle and material', $names, true);

    expect($accessIndex)->not->toBeFalse();
    expect($uploadIndex)->toBeGreaterThan($accessIndex,
        'privileged access must be proven before any material reaches the host');

    $source = phwActionSource();
    expect($source)->toContain("ssh_bootstrap 'sudo -n true'");
    expect($source)->toContain('nothing was uploaded and nothing was changed');
});

// =============================================================================
// Secrets in, nothing out
// =============================================================================

it('transports material by logical name and never names a canonical destination', function () {
    $sources = [
        phwActionSource(),
        File::get(base_path('.github/workflows/prepare-staging-host.yml')),
        File::get(base_path('.github/workflows/prepare-production-host.yml')),
    ];

    // GitHub transports; the repository's server-side installers own where
    // each file belongs.
    foreach ($sources as $source) {
        foreach ([
            '/etc/nginx/',
            '/etc/letsencrypt/',
            '/home/www/rateguru',
            '/home/deploy-rateguru',
            '/root/.config/rclone',
        ] as $destination) {
            expect($source)->not->toContain($destination);
        }
    }

    // The logical names, however, are the shared vocabulary.
    $inputs = phwAction()['inputs'];

    foreach ([
        'laravel-env', 'deploy-authorized-keys', 'rclone-config', 'basic-auth',
        'tls-certificate', 'tls-private-key', 'tls-dhparams', 'nginx-tls-options',
        'mail-tls-certificate', 'mail-tls-private-key',
    ] as $name) {
        expect($inputs)->toHaveKey($name);
        expect($inputs[$name]['required'] ?? false)->toBeFalse("{$name} must be optional");
    }
});

it('never puts a secret on a command line, in a log or in a summary', function () {
    $source = phwActionSource();

    // Values are referenced through the environment and redirected into files
    // that are created restrictive first.
    expect($source)->toContain('install -d -m 0700 "${material_dir}"');
    expect($source)->toContain('install -m 0600 /dev/null "${material_dir}/${name}"');
    expect($source)->toContain('printf \'%s\n\' "${value}" > "${material_dir}/${name}"');
    expect($source)->toContain('names only; no content is ever reported');

    // The run summary carries the verification SUMMARY block and nothing else.
    expect($source)->toContain('awk \'/^SUMMARY$/ { collecting = 1 } collecting { print }\'');
    expect($source)->not->toMatch('/echo\s+"\$\{MATERIAL_/');
    expect($source)->not->toMatch('/cat\s+"\$\{material_dir\}/');
});

it('removes uploaded material from the host and the runner on success and on failure', function () {
    $steps = phwAction()['runs']['steps'];

    $remote = collect($steps)->firstWhere('name', 'Remove remote bootstrap material');
    $local = collect($steps)->firstWhere('name', 'Remove temporary local material');

    expect($remote['if'])->toBe('${{ always() }}');
    expect($local['if'])->toBe('${{ always() }}');

    $source = phwActionSource();
    expect($source)->toContain('rm -rf "${RATEGURU_MATERIAL_DIR:-}"');
    expect($source)->toContain('"${RATEGURU_BOOTSTRAP_SSH_KEY_PATH:-}"');
    expect($source)->toContain('WARNING: could not remove');
});

it('stages material into a root-only directory on the host', function () {
    $source = phwActionSource();

    expect($source)->toContain('install -d -m 0700 -o root -g root');
    expect($source)->toContain('chown -R root:root');
    expect($source)->toContain('chmod -R go-rwx');
    expect($source)->toContain('umask 077');
});

// =============================================================================
// Invocation: the repository scripts stay authoritative
// =============================================================================

it('invokes prepare-host and implements no bootstrap logic in YAML', function () {
    $source = phwActionSource();

    expect($source)->toContain('/infrastructure/scripts/prepare-host');
    expect($source)->toContain('--apply');
    expect($source)->toContain('--verify');

    // Not one line of what preparation actually does lives here.
    foreach ([
        'apt-get', 'useradd', 'groupadd', 'systemctl', 'supervisorctl',
        'nginx -t', 'psql', 'createdb', 'setfacl', 'certbot', 'visudo',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }
});

it('verifies the prepared host in a separate, material-free invocation', function () {
    $source = phwActionSource();

    $verifyStep = collect(phwAction()['runs']['steps'])->firstWhere('id', 'verify');

    expect($verifyStep)->not->toBeNull();
    expect($verifyStep['run'])->toContain('--verify');
    expect(phwExecutable($verifyStep['run']))->not->toContain('--material-dir');
    expect($source)->toContain('judged by what is installed on it');
});

it('states plainly that a prepared host carries no application release', function () {
    expect(phwActionSource())->toContain(
        'No application release was deployed, no migration was run and no data was restored');
});

// =============================================================================
// Concurrency
// =============================================================================

it('serializes preparation against every other mutation of the same target', function () {
    expect(phwWorkflow('prepare-staging-host.yml')['concurrency']['group'])
        ->toBe('rateguru-staging-deployment');

    expect(phwWorkflow('prepare-production-host.yml')['concurrency']['group'])
        ->toBe('rateguru-production-release');

    // The same domains staging deploy/rollback and production release/rollback
    // already use, so a prepare run can never overlap one of them.
    expect(phwWorkflow('deploy-staging.yml')['concurrency']['group'])
        ->toBe('rateguru-staging-deployment');
    expect(phwWorkflow('rollback-staging.yml')['concurrency']['group'])
        ->toBe('rateguru-staging-deployment');
    expect(phwWorkflow('release.yml')['concurrency']['group'])
        ->toBe('rateguru-production-release');
    expect(phwWorkflow('rollback-production.yml')['concurrency']['group'])
        ->toBe('rateguru-production-release');

    foreach (['prepare-staging-host.yml', 'prepare-production-host.yml'] as $file) {
        expect(phwWorkflow($file)['concurrency']['cancel-in-progress'])->toBeFalse(
            'an in-flight preparation must never be cancelled mid-mutation');
    }
});

// =============================================================================
// Production stays unprovisioned
// =============================================================================

it('cannot bypass the lifecycle=planned gate from the production workflow', function () {
    $step = phwPrepareStep('prepare-production-host.yml');

    // Pinned to the real production target ID: the server refuses it, and the
    // workflow does not quietly aim somewhere that would pass.
    expect($step['with']['deployment-target'])->toBe('tits-guru');
    expect($step['with']['deployment-target'])->not->toBe('staging-main');

    // No override of any kind is offered. (The workflow's own comments explain
    // the lifecycle gate at length, so only executable lines are searched.)
    $source = phwExecutable(File::get(base_path('.github/workflows/prepare-production-host.yml')));
    foreach (['lifecycle', '--force', 'allow-planned', 'activate'] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }

    // And the gate itself is in the server-side script, not in YAML.
    expect(File::get(base_path('infrastructure/scripts/prepare-host')))
        ->toContain('not active — preparation is refused before any target-specific mutation');
});

it('refuses a planned target on the runner, before any material is staged', function () {
    $steps = phwAction()['runs']['steps'];
    $names = array_column($steps, 'name');

    $gateIndex = array_search('Validate the target lifecycle before any material is staged', $names, true);
    $stageIndex = array_search('Stage external material', $names, true);
    $uploadIndex = array_search('Upload bootstrap bundle and material', $names, true);

    expect($gateIndex)->not->toBeFalse();
    expect($stageIndex)->toBeGreaterThan($gateIndex,
        'no secret may be written to the runner for a target that must not be provisioned');
    expect($uploadIndex)->toBeGreaterThan($gateIndex);

    // The lifecycle rule itself is not reimplemented here: the repository's own
    // CLI answers, against the committed registry in the trusted checkout.
    $gate = $steps[$gateIndex]['run'];
    expect($gate)->toContain('infrastructure/scripts/targets');
    expect($gate)->toContain('infrastructure/config/deployment-targets.json');
    expect($gate)->toContain('lifecycle}" != "active"');

    // And the server-side gate stays authoritative regardless.
    expect(File::get(base_path('infrastructure/scripts/prepare-host')))
        ->toContain('not active — preparation is refused before any target-specific mutation');
});

it('keeps the runner-side and server-side lifecycle gates deciding the same thing', function () {
    // Two copies of one rule can drift, and a test that only pinned each copy's
    // own string would let them. Both are therefore reduced to the set of
    // lifecycle values they accept, and the sets are compared.
    $gate = collect(phwAction()['runs']['steps'])
        ->firstWhere('name', 'Validate the target lifecycle before any material is staged')['run'];

    $server = File::get(base_path('infrastructure/scripts/prepare-host'));

    $accepted = static function (string $source): array {
        // Every lifecycle value either gate compares against, however it is
        // spelled: `!= "active"`, `!= active`, `== active`.
        preg_match_all('/lifecycle[^\n]*?[!=]=\s*"?([a-z]+)"?/', $source, $matches);

        return array_values(array_unique($matches[1]));
    };

    expect($accepted($gate))->toBe(['active']);
    expect($accepted($server))->toBe(['active']);

    // Both also refuse, rather than warn, and say so in the same terms.
    expect($gate)->toContain('exit 1');
    expect($server)->toContain('fail "target ${TARGET_ID} has lifecycle=');
});

it('leaves tits-guru lifecycle=planned and changes no registry entry', function () {
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);

    expect($registry['targets']['tits-guru']['lifecycle'])->toBe('planned');
    expect($registry['targets']['staging-main']['lifecycle'])->toBe('active');

    // Nothing added in Prepare Host writes to the registry.
    foreach ([
        'infrastructure/scripts/prepare-host',
        'infrastructure/scripts/install-target-prerequisites',
        'infrastructure/scripts/install-target-database',
        'infrastructure/scripts/record-nightwatch-deployment',
    ] as $script) {
        $source = File::get(base_path($script));

        // The registry is read, never written: no redirection into it, no
        // in-place edit of it, and no temporary rewrite moved over it.
        expect($source)->not->toMatch('/(>|>>)\s*"?\$?\{?[A-Za-z_]*REGISTRY/');
        expect($source)->not->toMatch('/\b(sed\s+-i|mv|cp|install|tee)\b[^\n]*deployment-targets\.json/');
    }
});
