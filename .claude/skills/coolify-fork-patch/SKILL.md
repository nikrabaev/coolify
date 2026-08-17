---
name: coolify-fork-patch
description: Use when adding, changing, or retiring your own modification to Coolify's code in this fork — a fix upstream does not have, a local behaviour change, or removing one you no longer need.
---

# Carrying Your Own Patch

Your changes live on long-lived `patch/*` branches, referenced from
`patches.yaml`. They are never merged into a mainline; the release job merges
them onto each new upstream tag from scratch.

**Design constraint that shapes everything here:** every line you add is
re-merged onto a fresh upstream tag forever. Diff size is a permanent
maintenance cost. Keep patches minimal and surgical.

## Adding a patch

```bash
# 1. Base it on the CURRENT upstream release tag, not on fork-main.
git fetch upstream --tags
BASE=$(git ls-remote --tags upstream | grep -oE 'refs/tags/v4\.[0-9]+\.[0-9]+$' \
       | sed 's|refs/tags/||' | sort -t. -k2,2n -k3,3n | tail -1)
git checkout -b patch/<short-name> "$BASE"

# 2. Make the change. Touch as few files as possible.

# 3. Verify it applies and is minimal.
git diff "$BASE" --stat

git push -u origin patch/<short-name>
```

Then add it to `patches.yaml` on `fork-main`:

```yaml
  - id: <short-name>
    type: branch
    ref: patch/<short-name>
    reason: <why this exists — the future you needs this>
```

Push `fork-main`. The fingerprint changes, so the next run rebuilds. To release
immediately rather than waiting for the daily run, see `coolify-fork-release`.

## Changing a patch

Push to the `patch/*` branch. The branch head is part of the build fingerprint,
so the next scheduled run picks it up. Force-push is fine — the release job
fetches with a force refspec precisely because these branches get rebased.

## Retiring a patch

Delete its entry from `patches.yaml` and push. Keep the branch around until a
release has gone out without it, then delete the branch.

## Rules

- **Order in the file is application order.** If two patches touch the same
  file, the later one merges onto the earlier one's result.
- **`type: branch` entries are not SHA-pinned.** They are refs in your own
  repository, inside the trust boundary. Upstream PRs are different — see
  `coolify-fork-upstream-pr`.
- **Base on a release tag, not on `main` or `prod`.** Basing on a moving branch
  drags unrelated upstream commits into your merge.
- **If a patch touches PHP,** the release currently has no test coverage to catch
  a regression. See the "no test gate" note in `coolify-fork-overview` and decide
  whether you want the base-vs-merged delta built before shipping it.

## When a rebuild conflicts

A conflict means upstream changed code your patch also changes. The run halts and
files an issue with the conflicting paths — it never auto-resolves, by design.

Resolve by rebasing your patch onto the new tag:

```bash
git checkout patch/<name>
git rebase --onto <new-tag> <old-base>
# resolve, keeping the patch minimal
git push --force-with-lease origin patch/<name>
```

Re-run the workflow. Do not resolve the conflict inside the release job's
integration tree — that result is thrown away on the next rebuild.

## Common mistakes

| Mistake | Consequence |
|---|---|
| Basing the branch on `fork-main` | `fork-main` is an orphan branch with no upstream code; the merge is meaningless |
| Large or reformatting diffs | Conflicts on nearly every upstream release, forever |
| Editing files in the `.build` worktree during a run | Discarded; the tree is rebuilt from scratch each time |
| Forgetting the `patches.yaml` entry | Branch exists, nothing is built from it, no error anywhere |
