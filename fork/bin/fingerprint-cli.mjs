#!/usr/bin/env node
import { appendFileSync, readFileSync } from 'node:fs'
import { computeFingerprint, collectToolingFiles } from '../lib/fingerprint.js'
import { git } from '../lib/git.js'
import { loadManifest } from '../lib/manifest.js'
import { FORK_REPO, resolveRemotes, UPSTREAM_REPO } from '../lib/remotes.js'
import { needsRebuild, readLastSuccess } from '../lib/state.js'
import { selectBaseTag } from '../lib/version.js'

const cwd = process.cwd()
const manifestText = readFileSync('patches.yaml', 'utf8')
const manifest = loadManifest(manifestText)

let { upstream, fork } = resolveRemotes(git(['remote', '-v'], cwd).split('\n'))
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
    ['fetch', '--quiet', fork, `refs/heads/${entry.ref}:refs/remotes/fork-patch/${entry.id}`],
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

const changed = needsRebuild(readLastSuccess('state/last-success.json'), fingerprint)
const out = [
  `fingerprint=${fingerprint}`,
  `base_tag=${base.tag}`,
  `base_sha=${base.sha}`,
  `changed=${changed}`,
].join('\n')

if (process.env.GITHUB_OUTPUT) {
  appendFileSync(process.env.GITHUB_OUTPUT, `${out}\n`)
}
console.log(out)
