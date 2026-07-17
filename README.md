# Sitegeist.ScentMark

Mark and Bark on Neos via CLI. ScentMark helps orchestrate work in clustered
environments where a task must run once per deployment or on exactly one
replica.

This goes well with `sitegeist/treasuremap` for green/blue caching, but it can
also be used independently.

## Installation

```bash
composer require sitegeist/scentmark
```

## Deployment marks

`mark` records a deployment (the "pack") exactly once:

```bash
./flow scentmark:mark "$APP_VERSION"
```

It exits with status `0` when the pack was new and `1` when it was already
known. This is useful for once-per-release startup work:

```bash
if ./flow scentmark:mark "$APP_VERSION"; then
  ./flow cache:flushone Neos_Fusion_Content
fi
```

## Renewable leader leases

`bark` acquires leadership when a pack has no active leader. An active owner
can call it again to renew the lease:

```bash
./flow scentmark:bark "$APP_VERSION" "$REPLICA_ID" --lease-seconds 90
```

The command exits with status `0` for an acquired or renewed lease and `1`
when another replica owns it or the pack does not exist.

Acquisition and renewal run in a database transaction with a pessimistic row
lock. This ensures that two replicas cannot both acquire the same expired
lease.

Existing integrations remain compatible: omitting `--lease-seconds` uses the
configured default, which is one hour.

### Configuration

```yaml
Sitegeist:
  ScentMark:
    leaderLease:
      defaultSeconds: 3600
      minimumSeconds: 10
      maximumSeconds: 86400
```

Short leases should only be used with periodic renewal. Reducing the duration
without adding a heartbeat can let a second replica take over while the first
one is still working.

### Inspecting and releasing leases

`status` is read-only:

```bash
./flow scentmark:status "$APP_VERSION"
```

`release` clears the lease only when the supplied replica currently owns the
stored leader scent:

```bash
./flow scentmark:release "$APP_VERSION" "$REPLICA_ID"
```

Manual release is useful operationally, but a shared lease should generally
expire naturally when a replica dies.

## Supervising a long-running leader process

The package contains a reusable shell supervisor. Every replica runs it; only
the current leader runs the child command. Non-leaders keep retrying, and the
leader renews its lease while the child is active:

```bash
SCENTMARK_SCRIPT="./Packages/Application/Sitegeist.ScentMark/Resources/Private/Scripts/scentmark-supervise"

exec "$SCENTMARK_SCRIPT" \
  "$APP_VERSION" \
  "$REPLICA_ID" \
  --lease-seconds 90 \
  --heartbeat-seconds 20 \
  --retry-seconds 5 \
  -- \
  ./flow queue:work
```

With those values, a replacement normally starts within 95 seconds after an
ungraceful leader failure. A graceful shutdown stops the child first.

The supervisor also bounds each Flow heartbeat call. Its validation requires
the worst-case heartbeat failure and child shutdown window to be shorter than
the lease, preventing the old child from continuing after another replica can
take over.

The supervised command is restarted while the replica remains leader. This
replaces ad-hoc outer `while` loops in service scripts.

### Shared and exclusive leases

Several services can intentionally use the same pack scent and replica scent
to form one deployment-wide leader. Each service may renew that shared lease.
For this reason, the supervisor does **not** release a lease by default when
one service stops; healthy sibling services may still be running.

Use `--release-on-exit` only when the pack scent is exclusive to that one
supervisor. Otherwise, allow the short lease to expire.

Separate workflows that must elect leaders independently can use separate
pack scents, provided those packs are marked during startup.

## Scheduled jobs

For a scheduled invocation on every replica, use an atomic `bark` directly:

```bash
# Run this during startup on every replica; status 1 means it already exists.
./flow scentmark:mark "$APP_VERSION:hourly" || true

# Run this on every replica when the hourly schedule fires.
if ./flow scentmark:bark "$APP_VERSION:hourly" "$REPLICA_ID" --lease-seconds 900; then
  ./flow scheduled:work
fi
```

Choose a lease longer than the maximum expected job duration but shorter than
the schedule interval. Do not release this lease after the job: keeping it
until expiration prevents losing replicas from running the same invocation
sequentially.

If scheduled jobs should always run on the same deployment-wide leader as
long-running services, use the same pack and replica scents instead. The
service heartbeat then provides prompt leader failover between scheduled
invocations.

## Cleanup

Remove old packs while retaining the newest number of records:

```bash
./flow scentmark:cleanup 10
```

## Authors and sponsors

- Martin Ficzel — ficzel@sitegeist.de
- Melanie Wüst — wuest@sitegeist.de

Development and public releases of this package are generously sponsored by
[Sitegeist](https://sitegeist.de/).

## Contribution

Contributions are welcome. Please send pull requests.
