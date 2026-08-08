import { test } from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { git } from '../lib/git.js'
import { rebuild } from '../bin/rebuild.mjs'

function repo() {
  const dir = mkdtempSync(join(tmpdir(), 'rb-'))
  git(['init', '-q', '-b', 'v4.x'], dir)
  git(['config', 'user.email', 't@example.com'], dir)
  git(['config', 'user.name', 'Test'], dir)
  writeFileSync(join(dir, 'app.txt'), 'base\n')
  git(['add', '.'], dir)
  git(['commit', '-qm', 'base'], dir)
  git(['tag', 'v4.2.0'], dir)
  return dir
}

test('merges a branch patch and reports it merged', () => {
  const dir = repo()
  git(['checkout', '-qb', 'patch/feature', 'v4.2.0'], dir)
  writeFileSync(join(dir, 'feature.txt'), 'x\n')
  git(['add', '.'], dir)
  git(['commit', '-qm', 'feature'], dir)
  git(['checkout', '-q', 'v4.x'], dir)

  const { results } = rebuild({
    cwd: dir,
    manifest: { base: 'latest-tag', patches: [{ id: 'f', type: 'branch', ref: 'patch/feature' }] },
    upstreamRemote: null,
    forkRemote: null,
    baseTag: 'v4.2.0',
  })

  assert.deepEqual(results, [{ id: 'f', outcome: 'merged' }])
  assert.equal(git(['rev-parse', '--abbrev-ref', 'HEAD'], dir), 'prod-next')
  assert.match(git(['show', 'HEAD:feature.txt'], dir), /x/)
})

test('reports already-upstream when the patch is an ancestor of the base tag', () => {
  const dir = repo()
  git(['checkout', '-qb', 'patch/landed', 'v4.2.0'], dir)
  writeFileSync(join(dir, 'landed.txt'), 'y\n')
  git(['add', '.'], dir)
  git(['commit', '-qm', 'landed'], dir)
  git(['checkout', '-q', 'v4.x'], dir)
  git(['merge', '-q', '--ff-only', 'patch/landed'], dir)
  git(['tag', '-f', 'v4.2.1'], dir)

  const { results } = rebuild({
    cwd: dir,
    manifest: { base: 'latest-tag', patches: [{ id: 'l', type: 'branch', ref: 'patch/landed' }] },
    upstreamRemote: null,
    forkRemote: null,
    baseTag: 'v4.2.1',
  })

  assert.deepEqual(results, [{ id: 'l', outcome: 'already-upstream' }])
})

test('throws with the conflicting path when a patch conflicts', () => {
  const dir = repo()
  git(['checkout', '-qb', 'patch/conflict', 'v4.2.0'], dir)
  writeFileSync(join(dir, 'app.txt'), 'theirs\n')
  git(['commit', '-qam', 'theirs'], dir)
  git(['checkout', '-q', 'v4.x'], dir)
  writeFileSync(join(dir, 'app.txt'), 'ours\n')
  git(['commit', '-qam', 'ours'], dir)
  git(['tag', 'v4.2.2'], dir)

  assert.throws(
    () => rebuild({
      cwd: dir,
      manifest: { base: 'latest-tag', patches: [{ id: 'c', type: 'branch', ref: 'patch/conflict' }] },
      upstreamRemote: null,
      forkRemote: null,
      baseTag: 'v4.2.2',
    }),
    /conflict.*app\.txt/s,
  )
})

test('merges a pr entry at its pinned sha, not at the branch head', () => {
  const dir = repo()
  git(['checkout', '-qb', 'pr-head', 'v4.2.0'], dir)
  writeFileSync(join(dir, 'reviewed.txt'), 'reviewed\n')
  git(['add', '.'], dir)
  git(['commit', '-qm', 'reviewed'], dir)
  const pinned = git(['rev-parse', 'HEAD'], dir)
  writeFileSync(join(dir, 'malicious.txt'), 'unreviewed\n')
  git(['add', '.'], dir)
  git(['commit', '-qm', 'pushed after approval'], dir)
  git(['update-ref', 'refs/remotes/upstream-pr/1', 'pr-head'], dir)
  git(['checkout', '-q', 'v4.x'], dir)

  rebuild({
    cwd: dir,
    manifest: { base: 'latest-tag', patches: [{ id: 'p', type: 'pr', number: 1, sha: pinned }] },
    upstreamRemote: null,
    forkRemote: null,
    baseTag: 'v4.2.0',
  })

  assert.match(git(['show', 'HEAD:reviewed.txt'], dir), /reviewed/)
  assert.throws(() => git(['show', 'HEAD:malicious.txt'], dir))
})

test('throws when a pinned sha is not present in the repository', () => {
  const dir = repo()
  assert.throws(
    () => rebuild({
      cwd: dir,
      manifest: {
        base: 'latest-tag',
        patches: [{ id: 'p', type: 'pr', number: 1, sha: 'e'.repeat(40) }],
      },
      upstreamRemote: null,
      forkRemote: null,
      baseTag: 'v4.2.0',
    }),
    /pinned sha .* is not reachable/,
  )
})
