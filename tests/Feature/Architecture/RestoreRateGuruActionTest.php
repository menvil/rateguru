<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * the controlled code alignment: .github/actions/restore-rateguru — the ONE GitHub-side restore
 * implementation, and the only remote route into a restore that exists.
 *
 * What these tests are about is not "does it call ssh". It is the two
 * properties the whole operator layer rests on:
 *
 *   * the action builds a command, it never accepts one. There is no remote
 *     command input, no path input, no commit input, and every value that
 *     reaches an argument vector has passed a closed-class check first;
 *   * the action reads a machine-readable result, never prose. Exactly one
 *     JSON object, every field validated, and a caller's own request checked
 *     against the answer — so a run can never branch on a log line.
 */
function restoreActionPath(): string
{
    return base_path('.github/actions/restore-rateguru/action.yml');
}

function restoreActionSource(): string
{
    return File::get(restoreActionPath());
}

function restoreActionDefinition(): array
{
    return Yaml::parse(restoreActionSource());
}

it('defines one hardened, three-mode reusable restore action', function () {
    expect(File::exists(restoreActionPath()))->toBeTrue();

    $action = restoreActionDefinition();
    $steps = collect(data_get($action, 'runs.steps'));

    expect(data_get($action, 'name'))->toBe('Restore RateGuru target')
        ->and(data_get($action, 'runs.using'))->toBe('composite')
        ->and(data_get($action, 'inputs.deploy-port.default'))->toBe('22');

    foreach (['mode', 'deployment-target', 'environment', 'deploy-host', 'deploy-user', 'restore-wrapper', 'ssh-private-key', 'known-hosts'] as $required) {
        expect(data_get($action, "inputs.{$required}.required"))->toBeTrue("{$required} must be a required input");
    }

    // The three selection inputs default to empty, because each one is
    // forbidden in at least one mode.
    foreach (['restore-source', 'backup-id', 'operation-id'] as $optional) {
        expect(data_get($action, "inputs.{$optional}.required"))->toBeFalse()
            ->and(data_get($action, "inputs.{$optional}.default"))->toBe('');
    }

    // No remote command, no remote path, no commit, no credential beyond the
    // existing restricted deploy one. An operator picks a mode and a backup or
    // an operation, and nothing else.
    foreach ([
        'command', 'remote-command', 'script', 'ssh-command',
        'source-sha', 'required-source-sha', 'commit', 'ref',
        'restore-target-bin', 'backup-path', 'b2-key-id', 'b2-application-key',
        'rclone-config',
    ] as $forbidden) {
        expect(data_get($action, "inputs.{$forbidden}"))->toBeNull("restore-rateguru must not accept a {$forbidden} input");
    }

    expect($steps->pluck('name')->all())->toBe([
        'Validate restore inputs',
        'Configure SSH',
        'Run the restore operation through the target-aware wrapper',
        'Read the machine-readable restore result',
        'Write restore summary',
        'Remove temporary SSH material',
    ]);

    // Every operator-supplied value reaches the shell as an environment
    // variable, never as an expression interpolated into a script body.
    foreach ($steps as $step) {
        if (! data_get($step, 'run')) {
            continue;
        }

        expect(data_get($step, 'shell'))->toBe('bash')
            ->and(data_get($step, 'run'))->not->toContain('${{ inputs.');
    }
});

it('validates every identifier against the same closed classes the server uses', function () {
    $source = restoreActionSource();

    expect($source)
        ->toContain("target_regex='^[a-z0-9]+(-[a-z0-9]+)*$'")
        ->toContain("backup_regex='^[0-9]{8}-[0-9]{6}$'")
        ->toContain("operation_regex='^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$'")
        ->toContain("sha_regex='^[0-9a-f]{40}$'");

    // Mode-specific input rules, both directions.
    expect($source)
        ->toContain('operation-id must be empty when mode=apply')
        ->toContain('restore-source must be local or offsite when mode=apply')
        ->toContain('backup-id must be an exact YYYYMMDD-HHMMSS timestamp when mode=apply')
        ->toContain('restore-source must be empty when mode=${MODE}')
        ->toContain('backup-id must be empty when mode=${MODE}')
        ->toContain('operation-id is required and must be a restore operation ID when mode=${MODE}')
        ->toContain('mode must be apply, inspect or resume');
});

it('fails closed on any wrapper that is not the generic restore wrapper', function () {
    $source = restoreActionSource();

    expect($source)
        ->toContain('if [[ ! "${RESTORE_WRAPPER}" =~ ^/[A-Za-z0-9._/-]+$ ]]; then')
        ->toContain('if [[ "$(basename "${RESTORE_WRAPPER}")" != "rateguru-restore" ]]; then')
        ->toContain('RESTORE_WRAPPER must be the generic rateguru-restore wrapper');

    // The wrapper check happens in the validation step, which runs before SSH
    // is even configured.
    $steps = collect(data_get(restoreActionDefinition(), 'runs.steps'));
    $validateIndex = $steps->search(static fn (array $step): bool => $step['name'] === 'Validate restore inputs');
    $sshIndex = $steps->search(static fn (array $step): bool => $step['name'] === 'Configure SSH');

    expect($validateIndex)->toBeLessThan($sshIndex);
});

it('builds the remote command as a fixed argument vector, never a string', function () {
    $source = restoreActionSource();

    expect($source)
        ->toContain('remote_command=(')
        ->toContain('sudo -n "${RESTORE_WRAPPER}"')
        ->toContain('"--${MODE}"')
        ->toContain('--target "${DEPLOYMENT_TARGET}"')
        ->toContain('remote_command+=(--source "${RESTORE_SOURCE}" --backup "${BACKUP_ID}")')
        ->toContain('remote_command+=(--operation "${OPERATION_ID}")')
        ->toContain('"${remote_command[@]@Q}"');

    // No shell construction of any kind, anywhere — scanned over executable
    // lines only, the same way install-target-perimeter's own
    // verify_wrapper_static_contract does it. A whole-file grep would forbid
    // the action from DOCUMENTING that it builds no shell string, which is
    // exactly the incident that check was hardened against.
    $executable = executableSourceLines($source);

    foreach (['eval ', 'bash -c', 'sh -c'] as $forbidden) {
        expect($executable)->not->toContain($forbidden);
    }
});

it('uses hardened, non-interactive SSH and removes its key material unconditionally', function () {
    $source = restoreActionSource();

    expect($source)
        ->toContain('-o BatchMode=yes')
        ->toContain('-o IdentitiesOnly=yes')
        ->toContain('-o StrictHostKeyChecking=yes')
        ->toContain('-o UserKnownHostsFile="${RATEGURU_KNOWN_HOSTS_PATH}"')
        ->toContain('install -m 0600 /dev/null "${key_path}"')
        ->toContain('install -m 0600 /dev/null "${known_hosts_path}"');

    $steps = collect(data_get(restoreActionDefinition(), 'runs.steps'))->keyBy('name');
    $cleanup = $steps->get('Remove temporary SSH material');

    expect(data_get($cleanup, 'if'))->toBe('${{ always() }}')
        ->and(data_get($cleanup, 'run'))
        ->toContain('rm -f')
        ->toContain('"${RATEGURU_SSH_KEY_PATH:-}"')
        ->toContain('"${RATEGURU_KNOWN_HOSTS_PATH:-}"');
});

it('accepts exactly one machine-readable result and validates every field of it', function () {
    $source = restoreActionSource();

    expect($source)
        ->toContain("mapfile -t result_lines < <(grep '^RATEGURU_RESTORE_RESULT=' \"\${RATEGURU_RESTORE_LOG_PATH}\" || true)")
        ->toContain('if (( ${#result_lines[@]} != 1 )); then')
        ->toContain('Expected exactly one RATEGURU_RESTORE_RESULT line')
        ->toContain("jq -e 'type == \"object\"'");

    // Every field the pipeline reads is required to be present AND a string.
    foreach ([
        'status', 'operation_id', 'target', 'backup', 'backup_release',
        'backup_source_sha', 'required_source_sha', 'current_source_sha',
        'code_alignment', 'runtime_resumed',
    ] as $field) {
        // No second argument: Pest's toContain is variadic, so a "message"
        // there is read as another needle rather than a diagnostic.
        expect($source)->toContain($field);
    }

    expect($source)->toContain("'.[\$f] | type == \"string\"'");

    // The answer must be about the request: same target, same operation, same
    // backup.
    expect($source)
        ->toContain('Restore result names target ${target}, not ${DEPLOYMENT_TARGET}')
        ->toContain('Restore result is about operation ${operation_id}, not the requested ${OPERATION_ID}')
        ->toContain('Restore result names backup ${backup}, not the requested ${BACKUP_ID}');

    // And internally consistent: each mode's legal outcomes, and the two
    // status/alignment/runtime combinations that are the only coherent ones.
    expect($source)
        ->toContain('mode=apply produced an unexpected status')
        ->toContain('mode=inspect produced an unexpected status')
        ->toContain('mode=resume produced an unexpected status')
        ->toContain('must be ALIGNED and resumed')
        ->toContain('status=held must be REQUIRED and not resumed');
});

it('exposes the outputs the restore workflows branch on', function () {
    $action = restoreActionDefinition();

    expect(array_keys((array) data_get($action, 'outputs')))->toBe([
        'status',
        'operation-id',
        'backup',
        'backup-release',
        'required-source-sha',
        'current-source-sha',
        'code-alignment',
        'runtime-resumed',
    ]);

    // Every output comes from the machine-readable result step, never from the
    // step that ran the command.
    foreach ((array) data_get($action, 'outputs') as $name => $output) {
        expect($output['value'])->toStartWith('${{ steps.result.outputs.', "output {$name} must come from the parsed result");
    }
});

it('is called only with a fixed target and a validated mode, by the two restore workflows', function () {
    $consumers = [];

    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        $workflow = Yaml::parse(File::get($path));

        foreach ((array) data_get($workflow, 'jobs', []) as $jobName => $job) {
            foreach ((array) data_get($job, 'steps', []) as $step) {
                if (data_get($step, 'uses') !== './.github/actions/restore-rateguru') {
                    continue;
                }

                $label = basename($path).":{$jobName}:".(data_get($step, 'name') ?? '(unnamed step)');
                $consumers[] = $label;

                // The target is a literal in the workflow, never an operator
                // input: there is no target dropdown anywhere.
                expect(data_get($step, 'with.deployment-target'))
                    ->toBeIn(['staging-main', 'tits-guru'], "{$label} must fix its target");

                expect(data_get($step, 'with.mode'))
                    ->toBeIn(['apply', 'inspect', 'resume'], "{$label} must name a literal mode");

                expect(data_get($step, 'with.restore-wrapper'))
                    ->toBe('${{ vars.RESTORE_WRAPPER }}', "{$label} must take the wrapper from the environment");
            }
        }
    }

    // Three call sites per workflow: apply, inspect, resume.
    expect($consumers)->toHaveCount(
        6,
        'expected exactly six restore-rateguru call sites (apply/inspect/resume in each of the two restore workflows); found: '.implode(', ', $consumers),
    );
});
