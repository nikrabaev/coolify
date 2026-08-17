---
name: coolify-fork-upstream-pr
description: Use when you want a fix from an unmerged upstream Coolify pull request before upstream accepts it, when a pin-update proposal issue appears, or when a pre-applied PR gets merged or closed upstream.
---

# Pre-Applying an Upstream PR

This is the fork's main reason to exist: run a fix before upstream merges it.

**The security rule, and why it is absolute.** Upstream PRs are written by people
outside this project, and an author can force-push new commits to a PR at any
time after you review it. The privileged release job holds credentials that
publish an image which then manages your servers. So `patches.yaml` pins a
reviewed 40-character SHA, and the build merges **that SHA only** — never the
live PR head.

The manifest parser rejects a `type: pr` entry without a full 40-character `sha`.
That validation is a security control, not a formatting preference. Do not
loosen it.

## Adding a PR

```bash
# 1. Read the diff. This review IS the security boundary — nothing downstream
#    re-checks it.
gh pr diff <n> --repo coollabsio/coolify

# 2. Capture the exact head you reviewed.
gh pr view <n> --repo coollabsio/coolify --json headRefOid -q .headRefOid
```

Add to `patches.yaml`:

```yaml
  - id: <short-name>
    type: pr
    number: <n>
    sha: <the 40-char SHA you just reviewed>
    reason: <what it fixes, and why you need it before upstream ships it>
```

Push `fork-main`. The manifest hash changes, so the next run rebuilds without
waiting for an upstream release.

## When the author pushes new commits

An issue appears titled `patches.yaml: PR #<n> is head-moved`, with a compare
link from your pinned SHA to the new head. Nothing rebuilt — that is deliberate.
Live PR heads are excluded from the build fingerprint precisely so a contributor
force-push cannot trigger a privileged build.

To accept the new commits: review the incremental diff, then update `sha:` in
`patches.yaml` and push. To keep the old behaviour: change nothing and close the
issue.

Nothing from the moved head is ever fetched, executed, or merged. The job that
inspects it runs with read-only permissions and no secrets.

## When upstream merges the PR

If upstream merged your exact commits, the merge becomes a no-op — its commits
are already ancestors of the new tag — and the entry is reported as
`already-upstream`. Delete the entry from `patches.yaml`.

If upstream merged a **modified** version (squashed, amended in review), the
merge is *not* a no-op and will likely conflict. That is the intended signal:
upstream's version differs from what you pinned. Drop your entry and take
upstream's, unless you specifically want your reviewed variant.

## When upstream closes the PR without merging

An issue appears. Decide deliberately: the change was rejected upstream, so
either keep carrying it — convert it to your own `patch/*` branch via
`coolify-fork-patch`, since there is no longer an upstream PR to track — or drop
it.

## Quick reference

| Reported status | Meaning | Action |
|---|---|---|
| `pinned` | Head still matches your pin | None |
| `head-moved` | Author pushed after your review | Review the diff, then re-pin or ignore |
| `merged` | Upstream merged it | Drop the entry; verify it applied as a no-op |
| `closed` | Rejected upstream | Convert to your own patch, or drop |

## Common mistakes

| Mistake | Consequence |
|---|---|
| Pinning an abbreviated SHA | Manifest validation rejects it; the run fails |
| Re-pinning without reading the incremental diff | Defeats the entire security model |
| Assuming a merged-upstream entry auto-disappears | Removals are never automatic; you must edit the manifest |
| Pinning a PR head from a fork of a fork | The SHA must be fetchable from `coollabsio/coolify`'s PR refs |

## If a pinned SHA becomes unreachable

The run halts with `pinned sha ... is not reachable`. The author force-pushed and
the commit was garbage-collected upstream, so the code you reviewed no longer
exists. Re-review the current head and re-pin — do not work around this.
