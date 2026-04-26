---
title: "Docker / Compose"
description: "Run Queue Autoscale as a dedicated container service"
weight: 4
---

# Docker / Compose

Run the autoscaler in its own container, alongside your web and scheduler containers.

- Single-host mode: exactly one autoscaler container per app.
- Cluster mode: one autoscaler replica per host/pod is supported, provided Redis-backed cluster mode is enabled.

## docker-compose.yml

```yaml
services:
  app:
    # your main Laravel web container
    image: your-app:latest
    # ...

  queue-autoscale:
    image: your-app:latest
    command: php artisan queue:autoscale
    restart: unless-stopped
    depends_on:
      - redis
    environment:
      # Same env as your app — REDIS_*, DB_*, APP_KEY, etc.
      - APP_ENV=production
    # Let the manager shut down cleanly before Docker kills the container.
    # Must be >= queue-autoscale.workers.shutdown_timeout_seconds (default 30).
    stop_grace_period: 60s
    # Forward signals to PID 1 so SIGTERM reaches PHP, not /bin/sh.
    init: true
```

`init: true` matters. Without it, `php artisan` runs as PID 1 via the shell, which Docker cannot signal cleanly — you'd get abrupt kills and orphaned `queue:work` children.

## Dockerfile considerations

If your base image uses a CMD wrapper, override it with `exec` form so signals forward correctly:

```dockerfile
# This is what your main app image already does, but shown for the autoscale container:
CMD ["php", "artisan", "queue:autoscale"]
```

Avoid shell form (`CMD php artisan queue:autoscale`) because it spawns a shell that swallows signals.

## Replica count

For Docker Swarm:

```yaml
services:
  queue-autoscale:
    # ...
    deploy:
      replicas: 1 # single-host mode
      restart_policy:
        condition: any
```

For Kubernetes single-host mode, a `Deployment` with `replicas: 1` plus `strategy.type: Recreate` avoids two autoscalers running simultaneously during rollouts:

```yaml
spec:
  replicas: 1
  strategy:
    type: Recreate
  template:
    spec:
      containers:
        - name: queue-autoscale
          command: ["php", "artisan", "queue:autoscale"]
          terminationGracePeriodSeconds: 60
```

For cluster mode, multiple replicas are valid. Each replica still runs exactly one local `queue:autoscale` process, and cluster coordination happens through Redis.

For cluster mode, multiple replicas are valid. Each replica still runs exactly one local `queue:autoscale` process, and cluster coordination happens through Redis.

## Zero-downtime deploys

With Compose, `docker compose up -d queue-autoscale` stops the current container (SIGTERM, graceful shutdown, new workers spawned by old manager exit cleanly) and starts the new one (which picks up current state from Redis — no warm-up gap).

## Logs

The manager writes to stdout/stderr by default. Ship them to your log aggregator:

```yaml
logging:
  driver: "json-file"
  options:
    max-size: "10m"
    max-file: "3"
```

Or point at Loki/Fluent/CloudWatch via `logging.driver`.

## Sanity check

```bash
docker compose logs -f queue-autoscale
```

Should show `Autoscale manager started` within a second, then worker-spawn activity as soon as jobs arrive.
