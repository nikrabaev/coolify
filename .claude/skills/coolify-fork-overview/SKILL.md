---
name: coolify-fork-overview
description: Use when orienting in this Coolify fork repository, when unsure which fork workflow applies, or before changing anything on fork-main, patches.yaml, or the fork-sync workflow.
---

# Coolify Fork Overview

This repository is a personal fork of Coolify that rebuilds itself from upstream
releases plus a small set of local patches, publishes its own container image and
release artifacts, and feeds them to a self-hosted Coolify instance through that
instance's normal update mechanism.

**The core idea:** the fork never diverges from upstream. Every release is
`newest upstream release tag + patches applied in order`, rebuilt from scratch.

## What is automatic

A scheduled workflow (`.github/workflows/fork-sync.yml`, daily) does all of this
without a human:

1. Picks the newest upstream `v4.x.y` tag.
2. Rebuilds an integration tree: that tag, plus each entry in `patches.yaml`.
3. Allocates a version (`<upstream>.<n>`, e.g. `4.3.7.1`) and stamps it in.
4. Builds and pushes an arm64 image, publishes release artifacts, advertises the
   release by publishing `versions.json` last.
5. Records the result so an unchanged input set does not rebuild.

You do not run a "sync" command. Syncing is the default state.

## What needs a human

| Situation | Skill |
|---|---|
| Carry your own change to Coolify's code | `coolify-fork-patch` |
| Use an upstream PR before upstream merges it | `coolify-fork-upstream-pr` |
| A run failed, or you need a release now | `coolify-fork-release` |
| Update, verify, or roll back the server | `coolify-fork-deploy` |

## Layout

`fork-main` is an orphan branch. It shares no files with upstream, which is why
it never conflicts during a sync.

```
patches.yaml            what gets built, in order — the only input you edit
fork/lib/               pure logic, unit tested
fork/bin/               CLIs the workflow calls
fork/test/              npm test
state/                  CI-written; never hand-edit
.github/workflows/      fork-sync.yml
```

`state/` is deliberately outside every path the build fingerprint hashes. If it
were hashed, each successful run would change its own fingerprint and rebuild an
identical release forever.

## Things that are deliberately NOT done

Read this before "improving" the pipeline. Each of these was tried or considered
and rejected for a specific reason, recorded in comments at the relevant line.

- **No test gate.** Upstream runs no tests in CI at all, and upstream's own
  release tags fail ~750 tests in a clean environment. A bare suite run cannot
  distinguish our breakage from upstream's. The right tool is a base-vs-merged
  failure delta; it is scoped but unbuilt because no patch currently touches PHP.
- **No `rerere`.** It replayed recorded conflict resolutions onto new upstream
  bases with no re-review and no signal, which silently violates the property the
  design rests on: only human-reviewed content reaches the image.
- **No `prod` branch push, no mirror of upstream's default branch.** Both refs
  carry upstream's own workflow files, and pushing them triggers upstream's
  workflows inside the fork, which then fail daily on missing secrets. Disabling
  those workflows does not persist. Tags trigger none of them.
- **Single architecture, native runner.** Adding a second platform to the same
  build step reintroduces QEMU emulation and multi-hour builds. The correct way
  to add one is a matrix of native runners plus a manifest merge.

## The security property

Only content a human has explicitly reviewed reaches the image. Upstream PRs are
pinned to a reviewed 40-character SHA in `patches.yaml`, and the privileged job
merges that SHA — never the live PR head, which the author can move at any time.
Live PR heads are deliberately absent from the build fingerprint so a contributor
force-push cannot trigger a privileged rebuild.

If you are about to weaken this, don't. See `coolify-fork-upstream-pr`.

## Quick orientation commands

```bash
cat patches.yaml                                    # what is being built
cat state/last-success.json                         # last released version
gh run list --repo <fork> --workflow fork-sync      # recent runs
curl -s <cdn>/coolify/versions.json | jq .coolify.v4.version   # what is advertised
```
