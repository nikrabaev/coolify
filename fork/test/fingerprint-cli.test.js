import { test } from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { git } from '../lib/git.js'
import { FORK_REPO, UPSTREAM_REPO } from '../lib/remotes.js'
import { writeLastSuccess } from '../lib/state.js'
import { resolveBuildInputs } from '../bin/fingerprint-cli.mjs'

const MANIFEST_TEXT = `base: latest-tag
patches:
  - id: rw
    type: branch
    ref: patch/rewrite
`

function repo() {
  const dir = mkdtempSync(join(tmpdir(), 'fp-'))
  git(['init', '-q', '-b', 'v4.x'], dir)
  git(['config', 'user.email', 't@example.com'], dir)
  git(['config', 'user.name', 'Test'], dir)
  writeFileSync(join(dir, 'app.txt'), 'base\n')
  git(['add', '.'], dir)
  git(['commit', '-qm', 'base'], dir)
  git(['tag', 'v4.2.0'], dir)
  return dir
}

/**
 * A second on-disk repository, cloned from `baseDir`, used as a real `git
 * remote` so tests exercise the actual `ls-remote`/`fetch` paths — no
 * network involved.
 *
 * @param {string} baseDir
 */
function remoteRepo(baseDir) {
  const parent = mkdtempSync(join(tmpdir(), 'fp-remote-'))
  const dir = join(parent, 'remote')
  git(['clone', '-q', baseDir, dir], parent)
  git(['config', 'user.email', 't@example.com'], dir)
  git(['config', 'user.name', 'Test'], dir)
  return dir
}

/**
 * Sets up a local repo with a real `upstream` remote (tags) and `fork`
 * remote (a `patch/rewrite` branch), plus `remoteLines` shaped like `git
 * remote -v` output pointing at the real UPSTREAM_REPO/FORK_REPO URLs so
 * `resolveRemotes` recognizes them by name — without any of those URLs
 * actually being dialed. `resolveBuildInputs` takes `remoteLines` as data,
 * decoupled from what `cwd`'s git config reports, which is what makes this
 * offline-safe: the *names* it resolves to ('upstream', 'fork') are real,
 * locally fetchable remotes; only the URL text used for matching is fake.
 */
function setup() {
  const dir = repo()
  const upstream = remoteRepo(dir)
  const fork = remoteRepo(dir)
  git(['checkout', '-qb', 'patch/rewrite', 'v4.2.0'], fork)
  writeFileSync(join(fork, 'v1.txt'), 'v1\n')
  git(['add', '.'], fork)
  git(['commit', '-qm', 'v1'], fork)

  git(['remote', 'add', 'upstream', upstream], dir)
  git(['remote', 'add', 'fork', fork], dir)
  const remoteLines = [
    `upstream\thttps://github.com/${UPSTREAM_REPO}.git (fetch)`,
    `upstream\thttps://github.com/${UPSTREAM_REPO}.git (push)`,
    `fork\thttps://github.com/${FORK_REPO}.git (fetch)`,
    `fork\thttps://github.com/${FORK_REPO}.git (push)`,
  ]

  return { dir, upstream, fork, remoteLines }
}

test('collects the fetched sha for each branch patch and the selected base tag', () => {
  const { dir, fork, remoteLines } = setup()
  const forkSha = git(['rev-parse', 'refs/heads/patch/rewrite'], fork)
  const statePath = join(dir, 'state', 'last-success.json')

  const result = resolveBuildInputs({ cwd: dir, manifestText: MANIFEST_TEXT, remoteLines, statePath })

  assert.equal(result.baseTag, 'v4.2.0')
  assert.equal(result.branchShas.get('rw'), forkSha)
  assert.equal(git(['rev-parse', 'refs/remotes/fork-patch/rw'], dir), forkSha)
})

test('changed is true with no recorded state, and false once a matching fingerprint is recorded', () => {
  const { dir, remoteLines } = setup()
  const statePath = join(dir, 'state', 'last-success.json')

  const first = resolveBuildInputs({ cwd: dir, manifestText: MANIFEST_TEXT, remoteLines, statePath })
  assert.equal(first.changed, true)

  writeLastSuccess(statePath, { fingerprint: first.fingerprint, version: '4.2.0.1' })

  const second = resolveBuildInputs({ cwd: dir, manifestText: MANIFEST_TEXT, remoteLines, statePath })
  assert.equal(second.fingerprint, first.fingerprint)
  assert.equal(second.changed, false)
})

test('changed is true once the recorded fingerprint no longer matches', () => {
  const { dir, remoteLines } = setup()
  const statePath = join(dir, 'state', 'last-success.json')

  writeLastSuccess(statePath, { fingerprint: 'stale-fingerprint', version: '4.2.0.1' })

  const result = resolveBuildInputs({ cwd: dir, manifestText: MANIFEST_TEXT, remoteLines, statePath })
  assert.equal(result.changed, true)
})

test('re-resolving after the fork branch is rewritten succeeds and picks up the new sha (force refspec)', () => {
  const { dir, fork, remoteLines } = setup()
  const statePath = join(dir, 'state', 'last-success.json')

  const first = resolveBuildInputs({ cwd: dir, manifestText: MANIFEST_TEXT, remoteLines, statePath })
  const firstSha = first.branchShas.get('rw')

  // Rebase-equivalent history rewrite on the fork branch, same branch name.
  git(['commit', '--amend', '-qm', 'v1 rewritten'], fork)
  const rewrittenSha = git(['rev-parse', 'HEAD'], fork)
  assert.notEqual(rewrittenSha, firstSha)

  const second = resolveBuildInputs({ cwd: dir, manifestText: MANIFEST_TEXT, remoteLines, statePath })
  assert.equal(second.branchShas.get('rw'), rewrittenSha)
  assert.equal(git(['rev-parse', 'refs/remotes/fork-patch/rw'], dir), rewrittenSha)
})
