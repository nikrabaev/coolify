---
name: coolify-fork-release
description: Use when a fork-sync run fails or files an issue, when a release is needed immediately rather than on the daily schedule, when a rebuild hits a merge conflict, or before editing the fork-sync workflow.
---

# Releases and Failures

## Forcing a release

```bash
gh workflow run fork-sync --repo <fork> --ref fork-main
gh run watch --repo <fork>
```

A run rebuilds only when the **input fingerprint** changes. Inputs are: the
newest upstream tag, `patches.yaml`, each `patch/*` branch head, and the fork
tooling. If none changed, the run exits early — that is correct, not a fault.

To force a rebuild with genuinely unchanged inputs, change an input. There is no
override flag, deliberately: the fingerprint is the record of what was built.

## Versions

`<upstream version>.<n>` — `4.3.7.1` is the first fork build on upstream `v4.3.7`.

The fourth component exists because Coolify compares versions with PHP
`version_compare`, which ranks unrecognised alphabetic segments *below* numbers.
A tag like `4.3.7-fork.1` compares as **older** than `4.3.7` and the updater
silently refuses it. Never use a suffix.

The counter resets per upstream version and is allocated from existing fork tags,
so tags are the allocation record. Fork tags carry no `v` prefix; upstream's do.
That asymmetry keeps fork tags out of the base-tag candidate set.

## When a run fails

A failure files one issue labelled `fork-sync` (deduplicated by exact title, so a
stuck failure will not spam daily). Start there, then:

```bash
gh run view <id> --repo <fork> --log-failed
```

### Failure catalogue

Every entry below actually happened. Check here before theorising.

| Symptom | Cause | Fix |
|---|---|---|
| Merge conflict on a patch | Upstream changed code your patch touches | Rebase the patch branch — see `coolify-fork-patch`. Never resolve inside the run |
| `pinned sha ... is not reachable` | PR author force-pushed; reviewed commit gone | Re-review and re-pin — see `coolify-fork-upstream-pr` |
| `constants.php: coolify version line not found` | Upstream changed the version line's shape | Update `patchConstantsVersion`; it targets the last string literal in the statement |
| `couldn't find remote ref <branch>` | Upstream renamed a branch | Resolve branch names via the API, never hardcode |
| `refusing to allow a GitHub App to ... workflow` | Push touches `.github/workflows/`; `GITHUB_TOKEN` may never do that, and no `permissions:` grant fixes it | Push must use `FORK_SYNC_TOKEN`. If it *is* set and this still appears, see the auth-header trap below |
| Artifact 404s through all retries | Published path is wrong | The Pages project-site root **is already** `/<repo>/`; publish at the artifact root, not under a nested dir |
| Build runs for hours | arm64 under QEMU on an amd64 runner | Job must be on a native arm runner; never add a second platform to one build step |
| `empty ident name` | No git identity on the runner | Set one before any step that commits |
| Upstream's own workflows failing daily | Pushing refs that carry upstream's workflow files | Push tags only; they trigger none of upstream's workflows |

### Traps that produce no error at all

These are the expensive ones — they fail silently or misleadingly.

- **`actions/checkout` installs an `http.extraheader`** carrying `GITHUB_TOKEN`'s
  Authorization for every github.com URL, and an explicit header **overrides
  credentials embedded in a remote URL**. A correctly configured PAT is ignored
  and pushes still authenticate as the GitHub App. The tell: the rejection
  message says "a GitHub App" even though you configured a PAT. Fix with
  `persist-credentials: false`.
- **A pipeline whose status is its last command's.** `curl … | sha256sum` exits 0
  on a 404 because `sha256sum` succeeded, so a retry loop breaks out immediately
  with the hash of empty input and then reports a misleading mismatch. Use
  `set -o pipefail`.
- **Disabling an inherited upstream workflow does not persist.** Those files are
  not on the default branch, so GitHub recreates the workflow record — enabled —
  each time a push triggers it.

## Publish ordering — do not reorder

GitHub Pages is CDN-cached, so edges can briefly serve a mix of releases. The
design makes staleness harmless rather than fighting it:

1. Deploy every artifact **except** `versions.json`.
2. GET each one and compare content hashes against the build.
3. Publish `versions.json` — the pointer that advertises the release.
4. Promote `:latest` only now.

Two files sit at fixed paths because upstream app code hardcodes them
(`versions.json`, `upgrade.sh`); everything else lives under write-once
`releases/<version>/`. `upgrade.sh` derives all its downloads from the version
argument it is invoked with, so a stale cached copy still installs the right
release.

Collapsing the two deploys, or promoting `:latest` earlier, reintroduces a window
where a release is advertised but its artifacts are missing or wrong. Both have
been exercised for real: on two separate failed runs the release was correctly
never advertised.

## Before editing the workflow

- Run `npm test` — the fingerprint purity invariant is enforced there, and
  breaking it causes a rebuild loop that republishes identical releases daily.
- Never add anything CI writes to a fingerprinted path.
- Actions are SHA-pinned on purpose: this job holds write scopes and the release
  token. Keep the `# vN` comment when updating.
