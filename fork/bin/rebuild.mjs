#!/usr/bin/env node
import { readFileSync } from 'node:fs'
import { git, refExists } from '../lib/git.js'
import { loadManifest } from '../lib/manifest.js'
import { resolveRemotes } from '../lib/remotes.js'

/**
 * Fetch the ref backing a manifest entry into a namespaced remote-tracking
 * ref, and return the exact SHA to merge.
 *
 * PR entries resolve to the manifest's pinned sha — never the live head.
 * Namespacing matters: a fork-side PR number must never shadow an upstream one.
 *
 * @param {object} entry a manifest patch entry
 * @param {{ cwd: string, upstreamRemote: string|null, forkRemote: string|null }} ctx
 * @returns {string} the SHA to merge
 */
function resolveEntry(entry, { cwd, upstreamRemote, forkRemote }) {
  if (entry.type === 'pr') {
    if (upstreamRemote) {
      try {
        git(
          ['fetch', '--quiet', upstreamRemote,
            `refs/pull/${entry.number}/head:refs/remotes/upstream-pr/${entry.number}`],
          cwd,
        )
      } catch {
        // Head may have been force-pushed away from the pin; fall through and
        // try fetching the pinned object directly.
      }
      if (!refExists(entry.sha, cwd)) {
        try {
          git(['fetch', '--quiet', upstreamRemote, entry.sha], cwd)
        } catch {
          // Fall through to the reachability check below for a clear message.
        }
      }
    }
    if (!refExists(entry.sha, cwd)) {
      throw new Error(
        `${entry.id}: pinned sha ${entry.sha} is not reachable — it may have been ` +
          'orphaned and garbage-collected upstream. Re-review the PR and re-pin.',
      )
    }
    return entry.sha
  }

  if (forkRemote) {
    // Force refspec (`+`): patch branches are rebased onto each new upstream
    // tag by design, so a force-push is the normal case. Without `+`, a
    // fetch that would move an existing local `fork-patch/<id>` ref
    // non-fast-forward is rejected outright. The destination is a local
    // remote-tracking ref that exists only to name what was fetched, and the
    // fetched SHA is recorded and merged explicitly, so overwriting it here
    // is safe.
    git(
      ['fetch', '--quiet', forkRemote, `+refs/heads/${entry.ref}:refs/remotes/fork-patch/${entry.id}`],
      cwd,
    )
    return git(['rev-parse', `refs/remotes/fork-patch/${entry.id}`], cwd)
  }
  return git(['rev-parse', entry.ref], cwd)
}

/**
 * Rebuild `prod-next` as base tag + every manifest entry merged in order.
 *
 * `merge-base --is-ancestor` communicates its answer through the exit code,
 * not stdout, and `git()` throws on a non-zero exit — so a non-ancestor
 * result surfaces as a thrown error, not a falsy return value.
 *
 * @param {{ cwd: string, manifest: { base: string, patches: object[] }, upstreamRemote: string|null, forkRemote: string|null, baseTag: string }} opts
 * @returns {{ version: null, results: Array<{id: string, outcome: string}> }}
 */
export function rebuild({ cwd, manifest, upstreamRemote, forkRemote, baseTag }) {
  git(['checkout', '-qB', 'prod-next', `${baseTag}^{commit}`], cwd)

  const results = []
  for (const entry of manifest.patches) {
    const sha = resolveEntry(entry, { cwd, upstreamRemote, forkRemote })

    let alreadyUpstream
    try {
      git(['merge-base', '--is-ancestor', sha, 'HEAD'], cwd)
      alreadyUpstream = true
    } catch {
      alreadyUpstream = false
    }
    if (alreadyUpstream) {
      results.push({ id: entry.id, outcome: 'already-upstream' })
      continue
    }

    try {
      git(['merge', '--no-ff', '--no-edit', '-m', `patch(${entry.id}): merge ${sha}`, sha], cwd)
    } catch (err) {
      let conflicts = ''
      try {
        conflicts = git(['diff', '--name-only', '--diff-filter=U'], cwd)
      } catch {
        conflicts = '(unavailable)'
      }
      try {
        git(['merge', '--abort'], cwd)
      } catch {
        // Best-effort: some merge failures (e.g. an untracked file that would
        // be overwritten) never create MERGE_HEAD, so the abort itself fails.
        // That failure must never displace the informative error below.
      }
      throw new Error(`${entry.id}: conflict merging ${sha}\nConflicting paths:\n${conflicts}\n${err.message}`)
    }
    results.push({ id: entry.id, outcome: 'merged' })
  }

  return { version: null, results }
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const cwd = process.cwd()
  // The manifest path is an argument because in CI this runs inside the
  // .build worktree, where fork-main's files do not exist.
  const manifest = loadManifest(readFileSync(process.argv[3] ?? 'patches.yaml', 'utf8'))
  const { upstream, fork } = resolveRemotes(git(['remote', '-v'], cwd).split('\n'))
  const baseTag = process.argv[2]
  if (!baseTag) {
    console.error('usage: rebuild.mjs <base-tag>')
    process.exit(2)
  }
  const { results } = rebuild({ cwd, manifest, upstreamRemote: upstream, forkRemote: fork, baseTag })
  console.log(JSON.stringify(results, null, 2))
}
