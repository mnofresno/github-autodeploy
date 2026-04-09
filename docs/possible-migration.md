# Possible Migration to Go

## Executive Summary

This application is a small deployment controller. It exposes a PHP web entrypoint, a CLI trigger script, and a self-update path. Its main job is to authorize webhook-style requests, run repo-specific shell commands, update git checkouts, persist deployment status to JSON files, and surface logs/status back to callers.

My conclusion is that a migration to Go is **not justified now** based on the repository evidence. The code is dominated by git, shell, filesystem, nginx/PHP-FPM, and external-process orchestration rather than PHP CPU work. A Go rewrite would mostly replace one orchestration layer with another while introducing substantial compatibility risk around command semantics, config formats, status files, and self-update behavior.

## Final Verdict

**DO NOT MIGRATE NOW**

Why:

- Fact: the hot path is not PHP computation; it is `proc_open`, `git fetch`, `git reset`, `git clone`, `composer install`, file I/O, and polling.
- Fact: the application behavior depends on many externally visible contracts: routes, query params, JSON body fields, status codes, HTML/JSON response shapes, log format, config keys, and `.git-auto-deploy.yml/json/yaml` semantics.
- Strong inference: a rewrite would spend most of its effort preserving behavior, not removing a proven bottleneck.
- Strong inference: the operational risks of regressions are higher than the likely performance gain.

## Evidence-Based Analysis

### Runtime model

- Fact: the public web entrypoint is [`public/index.php`](../public/index.php), which loads the autoloader, creates a DI container, and dispatches either `/self-update` or the main application.
- Fact: the main flow is request-driven and synchronous by default, but it has an explicit background mode and a long-poll/wait mode in [`src/Hamster.php`](../src/Hamster.php).
- Fact: the CLI entrypoint is [`bin/trigger_for_repo`](../bin/trigger_for_repo), which injects repo/key query params and runs the same deployment flow under `CliSecurity`.
- Strong inference: this is an orchestration service, not a compute service.

### Endpoints and Routing

- Fact: `/self-update` is routed specially in `public/index.php` and shells out to `install.sh --self-update`.
- Fact: all other requests go through `Hamster::run()`.
- Fact: the request handler branches on query params:
  - `previous_run_id` for log/status lookup
  - `deployment_status=true` for deployment status JSON
  - `wait_deployment=true` for blocking until completion
  - `run_in_background=true` in JSON body or query params
  - `wait=true` for a blocking wait variant
  - `repo`, `key`, `fields`, and `create_repo_if_not_exists`
- Fact: the response codes used in code are 200, 201, 400, 403, 404, 408, and 500 across the various branches and exceptions.

### External Contracts

- Fact: config is read from `config.json` in the repo root via [`src/ConfigReader.php`](../src/ConfigReader.php).
- Fact: repo-specific deployment config is read from `.git-auto-deploy.json`, `.git-auto-deploy.yaml`, or `.git-auto-deploy.yml` under the repo checkout via [`src/DeployConfigReader.php`](../src/DeployConfigReader.php).
- Fact: the code supports multiple command sources:
  - global `custom_commands`
  - repo-level `customCommands`, `preFetchCommands`, `postFetchCommands`, `verboseMatchers`
  - built-in default git commands
- Fact: log search depends on the persistent log file `deploy-log.log` and `RunSearcher` parsing rules in [`src/RunSearcher.php`](../src/RunSearcher.php).
- Strong inference: a Go rewrite would need to preserve these contracts exactly, or it would break existing deployments and monitoring.

### Config Files and Config Semantics

- Fact: `config.example.json` documents keys such as `debug_level`, `website`, `IPsAllowList`, `SSHKeysPath`, `ReposBasePath`, `repos_template_uri`, `custom_commands`, `log_request_body`, `expose_raw_log`, `github_meta_api_url`, `github_ranges_lists`, `command_timeout`, `secrets`, and `whitelisted_command_strings`.
- Fact: `ConfigReader` exposes additional keys such as `enable_clone`, `ips_allow_list_file`, and `command_timeout`.
- Fact: `CustomCommands` supports placeholder expansion for:
  - `$repo`
  - `$key`
  - `$ReposBasePath`
  - `$SSHKeysPath`
  - `$secrets.<name>`
- Fact: `DeployConfigReader` validates that command lists are arrays of strings and rejects invalid `.git-auto-deploy` files with `InvalidDeployFileException`.
- Strong inference: config compatibility is a first-class migration constraint.

### Command Execution Model

- Fact: [`src/Runner.php`](../src/Runner.php) changes into the target repo directory, optionally clones a repo if missing, and then runs pre-fetch, fetch, and post-fetch command collections.
- Fact: default built-in commands are:
  - `echo $PWD`
  - `whoami`
  - `GIT_SSH_COMMAND="ssh -i ... " git fetch origin`
  - `git reset --hard origin/$(git symbolic-ref --short HEAD)`
- Fact: repo-specific commands can override the default flow.
- Fact: command failures and timeouts are turned into deployment failures and persisted in `DeploymentStatus`.
- Strong inference: preserving shell command semantics matters more than the PHP language choice.

### Subprocess / Shell Behavior

- Fact: [`src/Executer.php`](../src/Executer.php) uses `proc_open` with stdout/stderr pipes, non-blocking reads, and a timeout loop.
- Fact: single-line commands are escaped with `escapeshellcmd`, while multiline commands are executed via `bash -c` with `escapeshellarg`.
- Fact: a whitelist exists for command substrings that must survive escaping, including `$(git symbolic-ref --short HEAD)` and `echo $PWD`.
- Fact: the implementation reads command output line by line and can stream matching output into deployment status when a command matches `verbose_matcher`.
- Strong inference: the shell behavior is not incidental. It is part of the product contract.

### Deployment Status Persistence / Polling Behavior

- Fact: deployment state is stored in JSON files under `deployment-statuses/` by [`src/DeploymentStatus.php`](../src/DeploymentStatus.php).
- Fact: the status file tracks `run_id`, `repo`, `key`, `status`, timestamps, phases, steps, exit codes, failed step details, and output.
- Fact: `wait_deployment` and the explicit wait path poll that JSON file every few seconds until success, failure, or timeout.
- Fact: the code uses `fastcgi_finish_request` when available to detach the request from the background execution path.
- Strong inference: long-running request semantics and file-based state are part of the current architecture and would need exact replication.

### Self-Update Behavior

- Fact: `/self-update` calls `install.sh --self-update`.
- Fact: `install.sh` downloads the current release zip from GitHub, extracts it, rsyncs files into `/opt/git-autodeploy`, preserves ignored files using `.gitignore`, chowns files to `www-data`, and runs `composer install`.
- Fact: installation also writes an nginx site file, symlinks the webroot, and depends on PHP-FPM.
- Strong inference: self-update is not a trivial endpoint; it is a deployment mechanism with filesystem, package-manager, and privilege requirements.

### Tests and Current Coverage Shape

- Fact: there is a meaningful test suite covering `Runner`, `Executer`, `CustomCommands`, `DeployConfigReader`, `Request`, `Security`, `IPAllowListManager`, `GithubClient`, `RunSearcher`, `Logger`, `Hamster`, and a deployment-status integration test.
- Fact: the repo has no `vendor/` directory in this workspace, so I could inspect tests but not execute them here.
- Fact: the coverage is strongest around unit behavior and data-shape checks, weaker around full end-to-end HTTP + nginx/PHP-FPM + shell + git + self-update behavior.
- Strong inference: test coverage is good enough to characterize the current behavior, but not strong enough to make a rewrite low-risk.

### Likely Performance Bottlenecks

- Fact: the expensive work is external: git operations, shell commands, network requests to GitHub meta API, file reads/writes, and Composer/self-update work.
- Strong inference: the PHP runtime itself is unlikely to be the dominant bottleneck.
- Strong inference: any latency reduction from Go would probably be small unless the external command model is also redesigned, which would be a larger change than a language swap.

### Likely Operational Bottlenecks

- Strong inference from code: the bigger operational risks are long-held PHP-FPM workers, background/wait polling, filesystem contention on log/status JSON files, and brittleness around shell command failures.
- Fact: `wait=true` can keep the HTTP connection open for up to 40 minutes by default.
- Fact: the background flow still relies on PHP request lifecycle behavior and `fastcgi_finish_request`.

### Compatibility Risks of a Rewrite

- Fact: many user-visible behaviors are encoded in the repo today:
  - route names and query params
  - response codes
  - response body shapes
  - `deploy-log.log` parsing
  - `deployment-statuses/*.json` schema
  - config key names and repo config file names
  - shell escaping and multiline command handling
  - self-update mechanics
- Strong inference: a Go rewrite would almost certainly break at least some of these unless it is treated as a strict compatibility project.
- Weak inference: the most likely regressions would be around quoting, environment handling, and edge-case parsing, not pure algorithmic logic.

### Maintenance Costs of Staying in PHP

- Fact: the current codebase already carries custom shell/process logic, status persistence, and multiple request modes.
- Fact: it also depends on PHP-FPM, Composer, Monolog, Guzzle, Symfony YAML, and PHP DI.
- Strong inference: staying in PHP means continuing to maintain these integration points and the ad hoc shell safety rules.
- But: the codebase is relatively small, and the existing model already matches its deployment environment.

### Maintenance Costs of Migrating to Go

- Strong inference: migration would require rewriting and revalidating all of the following:
  - request parsing and routing
  - config loading and validation
  - shell command execution and escaping
  - background execution / wait semantics
  - status persistence format
  - log format and search behavior
  - self-update packaging and deployment flow
  - IP allowlist and GitHub CIDR update logic
- Strong inference: that is more maintenance work than the repo appears to justify today.

## What Is Actually Likely to Improve with Go

### Realistic Benefits

- A single static binary could simplify distribution in environments where PHP-FPM and Composer are undesirable.
- If the long-lived request/wait path were later redesigned, Go could make concurrency and cancellation handling cleaner.
- Go could make some process supervision and file-watching code more uniform.

### Exaggerated or Unlikely Benefits

- Large speedups are unlikely because the dominant work is still git, shell, disk, and network I/O.
- Major memory wins are unlikely to matter unless the current PHP-FPM deployment is already resource constrained.
- Go will not automatically remove command-escaping risk, shell semantics risk, or deployment contract risk.
- A rewrite will not materially reduce operational complexity if the system still depends on nginx, ssh, git, Composer-like dependency management, and custom shell scripts.

## What Is Likely Not the Real Bottleneck

- Not the PHP language runtime itself.
- Not local algorithmic CPU work.
- Not JSON encoding/decoding in isolation.
- Not request routing complexity.

The repo is mostly dominated by git, shell commands, filesystem writes, polling, and external processes. That is the main bottleneck profile I see from the code.

## Risk Assessment

- Contract break risk: high
  - routes, status codes, JSON shapes, log formats, and config keys are externally observable.
- Hidden behavior risk: high
  - placeholder expansion, repo-specific command fallback rules, and `wait/run_in_background` branches are easy to miss.
- Shell escaping / command semantics risk: high
  - `escapeshellcmd`, `bash -c`, whitelist replacement, multiline commands, and `GIT_SSH_COMMAND` behavior must be preserved.
- Async/background behavior replication risk: high
  - the background flow depends on PHP request lifecycle details and file-based status polling.
- Self-update replication risk: high
  - `/self-update` and `install.sh --self-update` are operationally coupled to the current layout.
- Config compatibility risk: high
  - both global JSON config and per-repo YAML/JSON configs are part of the contract.
- Observability regression risk: medium to high
  - log format, context payloads, and status JSON are used for debugging and run lookup.
- Test insufficiency risk: medium
  - the repo has a useful test suite, but it does not fully cover the real deployment stack.

## Recommendation

**Keep the current PHP app and improve it incrementally.**

This is the lowest-risk choice given the evidence. If a rewrite ever happens, it should be because measured bottlenecks or deployment constraints justify the cost, not because Go is presumed to be faster by default.

## Concrete Next Steps

1. Add characterization tests around the public HTTP contract, especially:
   - `/self-update`
   - `previous_run_id`
   - `deployment_status`
   - `wait_deployment`
   - `run_in_background`
   - `create_repo_if_not_exists`
2. Add timing instrumentation around:
   - `proc_open` command execution
   - git fetch/reset/clone
   - wait polling
   - self-update
3. Freeze config and response contracts explicitly in tests before any structural changes.
4. Measure real bottlenecks in production-like usage.
5. Only then decide whether a migration is justified.

## Migration Trigger Conditions

If the verdict changes later, migration would only be justified if one or more of these are proven:

- Measured PHP overhead is material compared with the actual deployment workload.
- Memory footprint or worker utilization is a documented operational problem.
- Long-lived requests and polling create a proven concurrency ceiling.
- The current PHP-FPM / Composer / nginx packaging path becomes the dominant operational cost.
- A need emerges to replace the shell-command model with a new execution architecture, and that change is already expected to be invasive.

## Confidence

- High confidence that this is an orchestration-bound application rather than a PHP-compute-bound one.
- High confidence that the rewrite risk is substantial because the code exposes many observable contracts.
- Medium confidence on precise performance conclusions, because this repository does not contain production profiling data.
- Medium confidence on the maintenance-cost comparison, because some operational pain may exist outside the repository evidence.

## Evidence inspected

- `public/index.php`
- `bin/trigger_for_repo`
- `install.sh`
- `composer.json`
- `config.example.json`
- `README.md`
- `ORIGINAL.md`
- `src/Hamster.php`
- `src/Runner.php`
- `src/Executer.php`
- `src/Request.php`
- `src/Response.php`
- `src/DeploymentStatus.php`
- `src/ConfigReader.php`
- `src/DeployConfigReader.php`
- `src/CustomCommands.php`
- `src/GithubClient.php`
- `src/Security.php`
- `src/IPAllowListManager.php`
- `src/RunSearcher.php`
- `src/ContainerProvider.php`
- `src/LoggerContext.php`
- `src/cli/CliSecurity.php`
- `src/views/Command.php`
- `src/views/RanCommand.php`
- `src/views/errors/DeploymentFailed.php`
- `src/exceptions/BaseException.php`
- `src/exceptions/BadRequestException.php`
- `test/RunnerTest.php`
- `test/DeploymentWaitIntegrationTest.php`
- `test/ExecuterTest.php`
- `test/CustomCommandsTest.php`
- `test/DeployConfigReaderTest.php`
- `test/HamsterSmokeTest.php`
- `test/RequestTest.php`
- `test/SecurityTest.php`
- `test/IPAllowListManagerTest.php`
- `test/GithubClientTest.php`
- `test/RunSearcherTest.php`
- `test/LoggerTest.php`
- `test/MockRepoCreator.php`
- `phpunit.xml`
- `linter/lint.sh`
