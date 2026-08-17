---
name: coolify-fork-deploy
description: Use when updating the Coolify server to a fork release, verifying a deployment, rolling back, switching between the fork and upstream Coolify, or diagnosing why the server is not offered an update.
---

# Deploying and Rolling Back

## Inputs this skill needs from the caller

This file is public. It contains no host, key, or credential. The caller — you in
chat, or a scheduled routine's prompt — must supply:

- **SSH target** (user@host)
- **How to select the SSH key**, if the agent holds several. Offering many keys
  can exhaust the server's `MaxAuthTries` before the right one is tried; pin one:
  ```bash
  ssh-add -L | grep '<key comment>$' > /tmp/k.pub
  ssh -o IdentitiesOnly=yes -i /tmp/k.pub <user>@<host> …
  ```
- **CDN base URL** of the fork's published releases.

If any is missing, ask. Never guess a production host.

## The single most important trap

`docker-compose.prod.yml` resolves the image as `${LATEST_IMAGE:-latest}`, and
`LATEST_IMAGE` is **not persisted in `.env`**. The running version exists only in
the container's already-resolved image reference.

So `docker compose up -d` — to "reload env", "apply a change", anything — falls
back to `:latest` and **silently changes the running version**. This has already
caused an unintended two-minor-version production upgrade.

```bash
# Reloading .env — safe. Reuses the resolved image ID, never re-interpolates.
docker restart coolify

# NOT safe unless you pin explicitly:
LATEST_IMAGE=<exact-current-version> docker compose \
  --env-file /data/coolify/source/.env \
  -f /data/coolify/source/docker-compose.yml \
  -f /data/coolify/source/docker-compose.prod.yml up -d
```

Always capture ground truth first — nothing else records it:

```bash
docker inspect --format '{{.Config.Image}} {{.Image}}' coolify
```

## Updating to a fork release

Prefer Coolify's own Update button. It calls `upgrade.sh` with the target
version, which fetches that release's artifacts from immutable paths — the
supported path, and the one the fork is built around.

Before updating:

```bash
# What the server will be offered
curl -s <cdn>/coolify/versions.json | jq -r .coolify.v4.version
# What is running
docker exec coolify php artisan tinker --execute 'echo config("constants.coolify.version");'
```

The offered version must be **greater under PHP `version_compare`** than the
running one, or Coolify's downgrade prevention silently refuses it. `4.3.7.1` >
`4.3.7`; `4.3.6.1` < `4.3.7`.

## Switching to the fork

1. Confirm the fork advertises a version greater than what is running.
2. Back up: `cp /data/coolify/source/.env /data/coolify/source/.env.bak-$(date +%F)`
3. Set `CDN_URL` to the fork's base URL in `/data/coolify/source/.env`.
4. `docker restart coolify` — **not** `compose up`.
5. Confirm it took: `docker exec coolify printenv CDN_URL`
6. Click Update in the UI when you are watching.

The fork's `docker-compose.prod.yml` pins the app image to the fork's registry,
so the first fork upgrade also changes image source. Snapshot the server first.

## Switching back to upstream

1. Restore `CDN_URL` to upstream's CDN (or remove the line) in `.env`.
2. `docker restart coolify`.
3. Upgrade to an upstream version **greater** than the running fork version, or
   downgrade prevention blocks it. Going from `4.3.7.1` back to upstream requires
   upstream `4.3.8`+, or a deliberate manual pin:
   ```bash
   LATEST_IMAGE=<upstream-version> docker compose … up -d
   ```
   which bypasses the app's downgrade guard.

## Auto-update

Check before changing anything — an enabled auto-update can apply a change you
made unattended:

```bash
docker exec coolify php artisan tinker --execute \
  'echo var_export(App\Models\InstanceSettings::find(0)->is_auto_update_enabled, true);'
```

Keep it **off** while the fork setup is still settling. Re-enable once a fork
upgrade has completed cleanly at least once — that is what makes the whole thing
hands-off.

## Verifying a deployment

```bash
docker inspect coolify --format '{{.State.Status}} {{.State.Health.Status}}'
docker exec coolify php artisan tinker --execute 'echo config("constants.coolify.version");'
docker logs coolify --since 10m 2>&1 | grep -iE "error|migrat|exception" | head
```

Expect `running healthy` and the version you intended. Migrations run at
container start, so a version jump does real schema work — check the logs, not
just health.

## Rolling back

```bash
cp /data/coolify/source/.env.bak-<date> /data/coolify/source/.env
LATEST_IMAGE=<known-good-version> docker compose \
  --env-file /data/coolify/source/.env \
  -f /data/coolify/source/docker-compose.yml \
  -f /data/coolify/source/docker-compose.prod.yml up -d
```

Migrations are **not** rolled back by reverting the image. If the failed upgrade
migrated the schema, restore from a snapshot instead.

## Not being offered an update

| Check | Command |
|---|---|
| Server reaches the CDN | `curl -fsS <cdn>/coolify/versions.json` |
| Container actually has `CDN_URL` | `docker exec coolify printenv CDN_URL` |
| Advertised > running under `version_compare` | compare both values |
| A release actually published | see `coolify-fork-release` |

`CDN_URL` in `.env` alone is not enough — the container must be restarted to see
it.
