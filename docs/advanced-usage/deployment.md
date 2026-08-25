---
title: "Production Deployment Reference"
description: "Prerequisites, process supervision, environment variables and operational runbook for running the autoscale manager in production"
weight: 32
---

# Production Deployment Reference

> **Looking for platform-specific steps?** Start at [Deployment → Platforms](../deployment/_index.md).
> You'll find short, concrete guides for self-hosted VPS, Laravel Forge, Ploi, and Docker.

This page is the **general production reference** — prerequisites, installation, supervision, and
the operational details that apply across all platforms.

## Prerequisites

From `composer.json`:

- PHP **8.4 or 8.5** (`"php": "^8.4|^8.5"`)
- The **pcntl** and **posix** extensions (signal handling and process ownership — both are hard
  requirements, not suggestions)
- Laravel 12 or 13 (`illuminate/contracts: ^12.0||^13.0`)
- `cboxdk/laravel-queue-metrics: ^3.0` — installed automatically as a dependency
- A queue backend the metrics package can observe (Redis or database)
- A process supervisor for the manager daemon (Supervisor or systemd)

`cboxdk/system-metrics` is **not** a direct dependency; it arrives transitively through
`laravel-queue-metrics` and is what `CapacityCalculator` reads CPU and memory from.

`cboxdk/laravel-telemetry` is optional (`suggest`). Install it only if you want the OpenTelemetry
integration described in [Integrations & Developer Hooks](integrations.md).

Verify the extensions before deploying:

```bash
php -m | grep -E '^(pcntl|posix)$'
```

## Installation Steps

### 1. Install the package

```bash
composer require cboxdk/laravel-queue-autoscale
```

### 2. Run the guided installer

```bash
php artisan queue:autoscale:install --topology=single-redis
```

The available presets are `single-low` (single host, database metrics, no Redis), `single-redis`
(single host with Redis) and `cluster` (multi-host with cluster coordination). Useful options:

| Option | Effect |
|---|---|
| `--metrics-connection=` | Metrics backend connection name |
| `--publish-migrations` | Publish the `laravel-queue-metrics` migrations |
| `--write-env` | Write the recommended values into your env file |
| `--env-file=` | Env file to update (default `base_path('.env')`) |
| `--force` | Overwrite already-published config files |
| `--no-publish` | Skip the `vendor:publish` steps |

To publish config by hand instead:

```bash
php artisan vendor:publish --tag=queue-autoscale-config
php artisan vendor:publish --tag=queue-metrics-config
```

### 3. Configure the metrics backend

The autoscaler cannot function without `laravel-queue-metrics` — queue discovery and every input
signal come from it.

**Redis (recommended for production):**

```ini
QUEUE_METRICS_STORAGE=redis
QUEUE_METRICS_CONNECTION=default
```

`QUEUE_METRICS_CONNECTION` must name a connection in `config/database.php`.

**Database (persistent history):**

```ini
QUEUE_METRICS_STORAGE=database
```

```bash
php artisan vendor:publish --tag=queue-metrics-migrations
php artisan migrate
```

| | Redis | Database |
|---|---|---|
| Storage | In-memory, TTL-based | Persistent tables |
| Retention | Limited by TTL | Full |
| Extra infrastructure | Redis server | None beyond your DB |

Verify metrics are being collected:

```bash
php artisan tinker
```

```php
\Cbox\LaravelQueueMetrics\Facades\QueueMetrics::getAllQueuesWithMetrics();
\Cbox\LaravelQueueMetrics\Facades\QueueMetrics::getOverview();
```

`getAllQueuesWithMetrics()` is the exact call the manager makes each cycle. If it returns an empty
array, the autoscaler has nothing to act on — fix that before going further.

### 4. Configure SLA targets

```php
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\BalancedProfile;
use Cbox\LaravelQueueAutoscale\Configuration\Profiles\CriticalProfile;

return [
    'enabled' => env('QUEUE_AUTOSCALE_ENABLED', true),

    // Profile applied to every queue unless overridden below.
    'sla_defaults' => BalancedProfile::class,

    'queues' => [
        // Pick a shipped profile:
        'critical' => CriticalProfile::class,

        // Or deep-merge a partial override on top of sla_defaults:
        'exports' => [
            'sla' => ['target_seconds' => 45],
            'workers' => ['max' => 20],
        ],
    ],

    'scaling' => [
        'cooldown_seconds' => 60,
    ],
];
```

See [Configuration](../basic-usage/configuration.md) for every key and
[Workload Profiles](../basic-usage/workload-profiles.md) for the shipped profiles.

### 5. Test locally

```bash
php artisan queue:autoscale -vvv
```

In another terminal, generate work using any job your app already has. For a quick smoke test,
queued closures work fine:

```bash
php artisan tinker
>>> for ($i = 0; $i < 50; $i++) { dispatch(function () { sleep(1); }); }
```

## Production Deployment

The manager is a long-running foreground process. Supervise it; do not background it manually.

### Option 1: Supervisor

`/etc/supervisor/conf.d/queue-autoscale.conf`:

```ini
[program:queue-autoscale]
process_name=%(program_name)s
command=php /path/to/your/app/artisan queue:autoscale --interval=5
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/path/to/your/app/storage/logs/autoscale-supervisor.log
stopwaitsecs=60
```

Important settings:

- `stopasgroup=true` — sends the stop signal to the whole process group, including spawned workers
- `killasgroup=true` — same for a forced kill
- `stopwaitsecs=60` — must exceed `workers.shutdown_timeout_seconds` (default `30`) so the manager
  can drain its workers before Supervisor escalates to SIGKILL
- `numprocs=1` — one manager per host; a second instance is refused by the host lock

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start queue-autoscale
sudo supervisorctl status queue-autoscale
```

### Option 2: systemd

`/etc/systemd/system/queue-autoscale.service`:

```ini
[Unit]
Description=Queue Autoscale Manager
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/path/to/your/app
ExecStart=/usr/bin/php /path/to/your/app/artisan queue:autoscale --interval=5
Restart=always
RestartSec=10
KillMode=mixed
KillSignal=SIGTERM
TimeoutStopSec=60

StandardOutput=append:/path/to/your/app/storage/logs/autoscale.log
StandardError=append:/path/to/your/app/storage/logs/autoscale-error.log

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now queue-autoscale
sudo systemctl status queue-autoscale
sudo journalctl -u queue-autoscale -f
```

### Option 3: Docker

```dockerfile
FROM php:8.4-cli

RUN apt-get update && apt-get install -y supervisor \
    && docker-php-ext-install pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html
WORKDIR /var/www/html

RUN composer install --no-dev --optimize-autoloader

COPY docker/supervisor-autoscale.conf /etc/supervisor/conf.d/

CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
```

The base image must be PHP 8.4 or newer — `php:8.3-cli` cannot install this package. `posix` is
enabled by default in the official images; `pcntl` is not, hence the `docker-php-ext-install` line.

`docker/supervisor-autoscale.conf`:

```ini
[program:queue-autoscale]
process_name=%(program_name)s
command=php artisan queue:autoscale --interval=5
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
stderr_logfile=/dev/stderr
stderr_logfile_maxbytes=0
```

```yaml
services:
  autoscale:
    build: .
    container_name: queue-autoscale
    environment:
      - QUEUE_AUTOSCALE_ENABLED=true
      - QUEUE_METRICS_STORAGE=redis
    volumes:
      - ./storage:/var/www/html/storage
    restart: unless-stopped
    networks:
      - app-network

  redis:
    image: redis:7-alpine
    networks:
      - app-network

networks:
  app-network:
    driver: bridge
```

See [Docker / Compose](../deployment/docker.md) for the fuller container walkthrough.

## Commands

```text
queue:autoscale {--interval=5} {--replace}
queue:autoscale:cluster {--json}
queue:autoscale:debug {--queue=default} {--connection=}
queue:autoscale:install {--topology=} {--metrics-connection=} {--publish-migrations}
                        {--write-env} {--env-file=} {--force} {--no-publish}
queue:autoscale:restart
queue-autoscale:migrate-config {--source=} {--destination=}
```

`--interval` is the only way to change the evaluation cadence. `queue:autoscale` starts with
`--interval=5` and passes that value to `AutoscaleManager::configure()`.

`--replace` stops the manager already holding this host's lock and takes over — useful in a deploy
script where the old process has not exited yet.

`queue:autoscale:restart` broadcasts a restart signal; supervised managers exit after the current
evaluation tick and are restarted by Supervisor/systemd with fresh code and config. Use it in your
deploy pipeline instead of killing the process.

## Environment variables

Only these `QUEUE_AUTOSCALE_*` variables are read by `config/queue-autoscale.php`. Variables named
`AUTOSCALE_*` (such as `AUTOSCALE_EVALUATION_INTERVAL`, `AUTOSCALE_MIN_WORKERS` or
`AUTOSCALE_DEFAULT_SLA`) are **not** read by this package — worker counts and SLA targets are set in
the config file, and the interval is a CLI flag.

```ini
# Master switch — queue:autoscale exits immediately when false
QUEUE_AUTOSCALE_ENABLED=true

# Stable identity for this manager; auto-generated when unset
QUEUE_AUTOSCALE_MANAGER_ID=

# Signal stores: auto | redis | null | a FQCN
QUEUE_AUTOSCALE_PICKUP_TIME_STORE=auto
QUEUE_AUTOSCALE_SPAWN_LATENCY_TRACKER=auto

# Failure fuse
QUEUE_AUTOSCALE_FUSE_ENABLED=true
QUEUE_AUTOSCALE_FUSE_STORE=auto

# Algorithm tuning
QUEUE_AUTOSCALE_FALLBACK_JOB_TIME=2.0

# Manager
QUEUE_AUTOSCALE_LOG_CHANNEL=stack
QUEUE_AUTOSCALE_RESTART_SCOPE=
QUEUE_AUTOSCALE_HONOR_QUEUE_RESTART=true

# Cluster mode
QUEUE_AUTOSCALE_CLUSTER_ENABLED=false
QUEUE_AUTOSCALE_CLUSTER_HEARTBEAT_TTL=15
QUEUE_AUTOSCALE_CLUSTER_LEADER_LEASE=15
QUEUE_AUTOSCALE_CLUSTER_RECOMMENDATION_TTL=30
QUEUE_AUTOSCALE_CLUSTER_SUMMARY_TTL=30
QUEUE_AUTOSCALE_DECISION_HISTORY=3600
QUEUE_AUTOSCALE_DECISION_HISTORY_MAX=10000

# Alerting and telemetry
QUEUE_AUTOSCALE_ALERT_COOLDOWN=300
QUEUE_AUTOSCALE_TELEMETRY_ENABLED=true
QUEUE_AUTOSCALE_TELEMETRY_CACHE_TTL=10
```

Plus the metrics package's own variables — at minimum `QUEUE_METRICS_STORAGE` and, for Redis,
`QUEUE_METRICS_CONNECTION`.

### Setting the evaluation interval

`manager.evaluation_interval_seconds` (default `5`) is the fleet-wide setting. `queue:autoscale
--interval=` overrides it for one process, which is the right tool for a single host that needs to
differ — not the only way to set it.

## What the spawned workers actually run

`WorkerSpawner` builds the command as an explicit argument array — no shell, no interpolation:

```bash
php artisan queue:work {connection} \
    --queue={queue} \
    --tries={workers.tries} \
    --max-time={workers.max_time_seconds} \
    --timeout={workers.timeout_seconds} \
    --sleep={workers.sleep_seconds}
```

Those six flags are the complete list. In particular:

- **The two time limits are separate.** `workers.max_time_seconds` becomes `--max-time` and bounds
  the worker process's *lifetime* (default `3600` seconds); `workers.timeout_seconds` becomes
  `--timeout` and bounds how long a *single job* may run (default `900`). Configuration refuses a job
  timeout that is not shorter than the process lifetime, since a job that outlives its worker can
  never finish.
- **There is no memory flag.** The spawner never passes `--memory`. Worker memory is bounded by
  PHP's own `memory_limit` and by the manager's `limits.max_memory_percent` ceiling, which stops new
  workers being spawned rather than stopping existing ones.

Group workers run the same command with a comma-separated queue list
(`--queue=high,medium,low`), which gives Laravel's strict left-to-right priority.

Three environment variables are injected into every spawned worker, and only these three:

| Variable | Value |
|---|---|
| `LARAVEL_AUTOSCALE_WORKER` | `true` |
| `AUTOSCALE_MANAGER_ID` | The manager's id |
| `AUTOSCALE_WORKER_GROUP` | The group name — **group workers only** |

Use `LARAVEL_AUTOSCALE_WORKER` in your app if you need to distinguish autoscaler-spawned workers
from manually supervised ones.

## Monitoring

```bash
# Follow the autoscaler's log channel (queue-autoscale.manager.log_channel)
tail -f storage/logs/laravel.log | grep -i autoscale

# Cluster state, human-readable or as JSON
php artisan queue:autoscale:cluster
php artisan queue:autoscale:cluster --json

# What the autoscaler sees for one queue
php artisan queue:autoscale:debug --queue=default --connection=redis

# Live worker processes
watch -n 1 'ps aux | grep "[q]ueue:work"'
```

For a health endpoint, hang it off the event stream rather than a policy — `WorkersScaled` and
`ScalingDecisionMade` are dispatched on every cycle that reaches a decision:

```php
use Cbox\LaravelQueueAutoscale\Events\ScalingDecisionMade;
use Illuminate\Support\Facades\Event;

// AppServiceProvider::boot()
Event::listen(ScalingDecisionMade::class, function (ScalingDecisionMade $event): void {
    cache()->put('autoscale:last_decision_at', now()->timestamp, 600);
});
```

```php
// routes/web.php
Route::get('/health/autoscale', function () {
    $lastDecisionAt = cache()->get('autoscale:last_decision_at');

    if ($lastDecisionAt === null || $lastDecisionAt < now()->subMinutes(5)->timestamp) {
        return response()->json(['status' => 'unhealthy'], 503);
    }

    return response()->json(['status' => 'healthy']);
});
```

See [Monitoring](../basic-usage/monitoring.md) and
[Event Handling](../basic-usage/event-handling.md) for the full event list.

## Tuning under pressure

### CPU saturation

Lower the ceiling for one queue:

```php
'queues' => [
    'heavy' => ['workers' => ['max' => 10]],
],
```

Or tighten the global cap so the autoscaler backs off sooner:

```php
'limits' => [
    'max_cpu_percent' => 75,
    'reserve_cpu_cores' => 0.5,
],
```

`CapacityCalculator` samples CPU for roughly one second per refresh and caches the result for four
seconds, so an interval below about 5 seconds spends a noticeable share of each cycle sampling.
Raising `--interval` is the correct lever if the manager itself is expensive.

### Memory pressure

`limits.worker_memory_mb_estimate` (default `128`) is the per-worker estimate used to compute the
memory ceiling. Measure your workers' real RSS and set it accordingly — an estimate that is too low
lets the autoscaler over-commit the host.

```bash
ps -o rss= -C php --sort=-rss | head
```

### Flapping

`scaling.cooldown_seconds` (default `60`) does not suppress scaling in general — it blocks a
**scale-down that reverses a recent scale-up**. Scaling further in the same direction is always
allowed, and a scale-up is never held. Raise it if you see up/down oscillation, but raise it
carefully: the window is also how long an over-provisioned fleet stays that way. See
[Troubleshooting](../basic-usage/troubleshooting.md).

## Operational runbook

### Graceful restart on deploy

```bash
php artisan queue:autoscale:restart
```

The manager finishes the current tick, terminates its workers within
`workers.shutdown_timeout_seconds`, and exits. The supervisor restarts it.

### Taking over a wedged manager

```bash
php artisan queue:autoscale --replace
```

### Emergency stop

```bash
sudo supervisorctl stop queue-autoscale
# If anything survives the group stop:
pkill -f "artisan queue:work"
```

With `stopasgroup=true`/`killasgroup=true` (or systemd's `KillMode=mixed`) the workers go down with
the manager, so the second command should normally find nothing.

### Recovery

```bash
# Release standalone workers you run outside the autoscaler
php artisan queue:restart

sudo supervisorctl start queue-autoscale
ps aux | grep "[q]ueue:work"
```

Spawned workers are ordinary `queue:work` processes, so `queue:restart` stops them like any other
worker. In addition, `RestartSignal` watches the `illuminate:queue:restart` cache key, so
`php artisan queue:restart` also restarts the **manager** — set
`QUEUE_AUTOSCALE_HONOR_QUEUE_RESTART=false` if you want the manager to ignore it and only respond to
`queue:autoscale:restart`.

## Deployment checklist

- [ ] PHP 8.4+ with `pcntl` and `posix` enabled
- [ ] `laravel-queue-metrics` configured and returning data from `getAllQueuesWithMetrics()`
- [ ] `config/queue-autoscale.php` published, profiles chosen per queue
- [ ] `workers.max` set deliberately for every queue that matters
- [ ] `limits.worker_memory_mb_estimate` matched to measured worker RSS
- [ ] Supervisor/systemd unit deployed with group stop and `stopwaitsecs` > shutdown timeout
- [ ] `--interval` set on the command line
- [ ] Log channel routed somewhere you actually read
- [ ] Deploy pipeline calls `queue:autoscale:restart`
- [ ] Alerting on `Policy beforeScaling failed` and `Failed to spawn worker` log lines

## Next Steps

- [Monitoring](../basic-usage/monitoring.md) - Events, logs and the cluster snapshot
- [Performance Tuning](../basic-usage/performance.md) - Optimising for your workload
- [Troubleshooting](../basic-usage/troubleshooting.md) - Common issues and fixes
- [Security](security.md) - Reporting vulnerabilities and hardening notes
