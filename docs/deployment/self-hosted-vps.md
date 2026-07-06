---
title: "Self-Hosted VPS"
description: "Run Queue Autoscale on a self-managed server via systemd or Supervisor"
weight: 1
---

# Self-Hosted VPS

For servers you SSH into and control directly: DigitalOcean droplets, Hetzner, Linode, any bare-metal Ubuntu/Debian, etc.

## Option A — systemd (recommended)

Create `/etc/systemd/system/queue-autoscale.service`:

```ini
[Unit]
Description=Laravel Queue Autoscale manager
After=network.target redis-server.service

[Service]
Type=simple
User=forge
Group=forge
Restart=always
RestartSec=5s
WorkingDirectory=/home/forge/your-app.com/current
ExecStart=/usr/bin/php artisan queue:autoscale
StandardOutput=append:/var/log/queue-autoscale.log
StandardError=append:/var/log/queue-autoscale.err.log

# Graceful shutdown: SIGTERM, wait up to 60s, then SIGKILL.
# Match or exceed queue-autoscale.workers.shutdown_timeout_seconds.
TimeoutStopSec=60s
KillSignal=SIGTERM

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable queue-autoscale
sudo systemctl start queue-autoscale
sudo systemctl status queue-autoscale
```

On deploy, signal the manager so it drains workers and exits. systemd will start it again from the current release:

```bash
php artisan queue:autoscale:restart
```

If the manager is wedged and does not exit, use `sudo systemctl restart queue-autoscale` as an operational fallback.

## Option B — Supervisor

`/etc/supervisor/conf.d/queue-autoscale.conf`:

```ini
[program:queue-autoscale]
process_name=%(program_name)s
command=php /home/forge/your-app.com/current/artisan queue:autoscale
autostart=true
autorestart=true
user=forge
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/queue-autoscale.log
stopwaitsecs=60
stopsignal=TERM
```

Reload:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status queue-autoscale
```

## Zero-downtime deploys

Either restart command works — most deploy scripts already run the first one:

```bash
php artisan queue:restart            # standard Laravel deploy step, restarts workers AND the manager
php artisan queue:autoscale:restart  # restarts only the autoscale manager
```

Your existing deploy script's `php artisan queue:restart` step is enough: spawned workers finish their current job and exit, the manager notices the same signal on its next evaluation tick, gracefully terminates any remaining workers, and exits. Your process supervisor then starts a fresh manager with the new code/config.

`php artisan queue:autoscale:restart` still works and restarts only the autoscale manager — useful when you also run separately-supervised `queue:work` daemons that should keep running.

Direct `systemctl` / `supervisorctl` restarts are still fine as manual fallbacks; they send SIGTERM immediately, and the manager performs the same graceful shutdown path.

Set `QUEUE_AUTOSCALE_HONOR_QUEUE_RESTART=false` to restore the old behaviour where only `queue:autoscale:restart` restarts the manager.

## Log rotation

For systemd on a modern distro, journald handles rotation. If you append to a file (as shown above), drop a logrotate config:

`/etc/logrotate.d/queue-autoscale`:

```
/var/log/queue-autoscale*.log {
    daily
    missingok
    rotate 14
    compress
    delaycompress
    notifempty
    copytruncate
}
```

## Troubleshooting

**Manager keeps restarting every few seconds.** Check the log — usually config validation failure or missing Redis. `journalctl -u queue-autoscale -n 100`.

**Workers spawn but die immediately.** Run `php artisan queue:work redis --queue=default` manually as the same user. The error will be obvious (missing `.env`, wrong path, bad permissions).

**Manager runs but nothing scales.** Verify metrics: `php artisan queue-autoscale:debug-queue default`. If metrics are empty, `laravel-queue-metrics` isn't collecting — check its storage backend.
