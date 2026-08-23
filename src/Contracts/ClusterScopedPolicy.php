<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Contracts;

/**
 * Opt-in marker for scaling policies that express cluster-wide constraints.
 *
 * A policy implementing this interface is additionally consulted by the
 * cluster leader, against a decision whose scope is ScalingScope::Cluster
 * and whose worker counts are the workload's cluster-wide totals, before
 * the target is distributed across hosts. That is where a global budget
 * belongs (an external API's concurrency ceiling, license seats, a provider
 * rate limit): a cap applied only on the per-host apply path caps each host
 * at the full budget, multiplying the intended ceiling by the host count.
 *
 * The policy still runs on every host's apply path like any other policy,
 * where the decision's scope is ScalingScope::Host. A policy that must act
 * only once for the whole cluster should check $decision->scope and return
 * Host-scoped decisions unchanged. Policies that do not implement this
 * interface are never consulted by the leader, so existing behavior is
 * unchanged unless a policy explicitly opts in.
 */
interface ClusterScopedPolicy extends ScalingPolicy {}
