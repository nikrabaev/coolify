#!/usr/bin/env node
import { appendFileSync, readFileSync } from 'node:fs'
import { computeFingerprint, collectToolingFiles } from '../lib/fingerprint.js'
import { git } from '../lib/git.js'
import { loadManifest } from '../lib/manifest.js'
import { FORK_REPO, resolveRemotes, UPSTREAM_REPO } from '../lib/remotes.js'
import { needsRebuild, readLastSuccess } from '../lib/state.js'
import { selectBaseTag } from '../lib/version.js'

/**
 * Resolve every input the build-gate fingerprint depends on: the upstream
 * and fork remotes (auto-adding either that is missing), the selected
 * upstream base tag, the fetched SHA of every branch-type patch, the
 * resulting fingerprint, and whether it differs from the last recorded
 * success.
 *
 * Branch fetches use a force refspec (`+refs/heads/<ref>:...`) because fork
 * patch branches are rebased onto each new upstream tag by design — a
 * non-force fetch would fail outright once a local tracking ref from a
 * prior run exists and the branch has since been rewritten. The destination
 * is a local remote-tracking ref that exists only to name what was fetched,
 * so overwriting it is safe.
 *
 * @param {{ cwd: string, manifestText: string, remoteLines: string[], statePath?: string }} opts
 * @returns {{ fingerprint: string, baseTag: string, baseSha: string, changed: boolean, branchShas: Map<string,string> }}
 */
export function resolveBuildInputs({ cwd, manifestText, remoteLines, statePath = 'state/last-success.json' }) {
  const manifest = loadManifest(manifestText)

  let { upstream, fork } = resolveRemotes(remoteLines)
  if (!upstream) {
    git(['remote', 'add', 'upstream', `https://github.com/${UPSTREAM_REPO}.git`], cwd)
    upstream = 'upstream'
  }
  if (!fork) {
    git(['remote', 'add', 'fork', `https://github.com/${FORK_REPO}.git`], cwd)
    fork = 'fork'
  }

  const base = selectBaseTag(git(['ls-remote', '--tags', upstream], cwd))
  git(['fetch', '--quiet', upstream, `refs/tags/${base.tag}:refs/tags/${base.tag}`], cwd)

  const branchShas = new Map()
  for (const entry of manifest.patches) {
    if (entry.type !== 'branch') {
      continue
    }
    git(
      ['fetch', '--quiet', fork, `+refs/heads/${entry.ref}:refs/remotes/fork-patch/${entry.id}`],
      cwd,
    )
    branchShas.set(entry.id, git(['rev-parse', `refs/remotes/fork-patch/${entry.id}`], cwd))
  }

  const fingerprint = computeFingerprint({
    baseTag: base.tag,
    baseSha: base.sha,
    manifestText,
    branchShas,
    toolingFiles: collectToolingFiles(cwd),
  })

  const changed = needsRebuild(readLastSuccess(statePath), fingerprint)

  return { fingerprint, baseTag: base.tag, baseSha: base.sha, changed, branchShas }
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const cwd = process.cwd()
  const manifestText = readFileSync('patches.yaml', 'utf8')
  const remoteLines = git(['remote', '-v'], cwd).split('\n')

  const { fingerprint, baseTag, baseSha, changed } = resolveBuildInputs({ cwd, manifestText, remoteLines })

  const out = [
    `fingerprint=${fingerprint}`,
    `base_tag=${baseTag}`,
    `base_sha=${baseSha}`,
    `changed=${changed}`,
  ].join('\n')

  if (process.env.GITHUB_OUTPUT) {
    appendFileSync(process.env.GITHUB_OUTPUT, `${out}\n`)
  }
  console.log(out)
}
