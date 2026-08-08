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

/**
 * A second on-disk repository, cloned from `baseDir`, used as a real `git
 * remote` so tests can exercise the actual fetch paths (`refs/pull/<n>/head`,
 * fork branches) instead of pre-seeding refs directly in the main repo.
 * Cloning (rather than an unrelated `git init`) gives it shared history with
 * `baseDir`, so merges do not fail with "refusing to merge unrelated
 * histories".
 *
 * @param {string} baseDir the repo created by `repo()`
 */
function remoteRepo(baseDir) {
  const parent = mkdtempSync(join(tmpdir(), 'rb-remote-'))
  const dir = join(parent, 'remote')
  git(['clone', '-q', baseDir, dir], parent)
  git(['config', 'user.email', 't@example.com'], dir)
  git(['config', 'user.name', 'Test'], dir)
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

test('an unguarded merge --abort never displaces the conflict error', () => {
  const dir = repo()
  git(['checkout', '-qb', 'patch/adds-file', 'v4.2.0'], dir)
  writeFileSync(join(dir, 'incoming.txt'), 'from-patch\n')
  git(['add', '.'], dir)
  git(['commit', '-qm', 'adds incoming.txt'], dir)
  git(['checkout', '-q', 'v4.x'], dir)
  // An untracked file (never committed) that the incoming merge would
  // overwrite. This makes `git merge` fail *before* it creates MERGE_HEAD,
  // so the subsequent `git merge --abort` itself fails with "There is no
  // merge to abort (MERGE_HEAD missing)". That abort failure must not
  // replace the informative conflict error.
  writeFileSync(join(dir, 'incoming.txt'), 'untracked-local\n')

  assert.throws(
    () => rebuild({
      cwd: dir,
      manifest: { base: 'latest-tag', patches: [{ id: 'untracked-conflict', type: 'branch', ref: 'patch/adds-file' }] },
      upstreamRemote: null,
      forkRemote: null,
      baseTag: 'v4.2.0',
    }),
    /untracked-conflict: conflict merging/,
  )
})

test('fetches a pr entry via refs/pull/<n>/head and merges the pinned sha', () => {
  const dir = repo()
  const upstream = remoteRepo(dir)
  writeFileSync(join(upstream, 'reviewed.txt'), 'reviewed\n')
  git(['add', '.'], upstream)
  git(['commit', '-qm', 'reviewed'], upstream)
  const pinned = git(['rev-parse', 'HEAD'], upstream)
  git(['update-ref', 'refs/pull/7/head', pinned], upstream)
  git(['remote', 'add', 'upstream', upstream], dir)

  const { results } = rebuild({
    cwd: dir,
    manifest: { base: 'latest-tag', patches: [{ id: 'p', type: 'pr', number: 7, sha: pinned }] },
    upstreamRemote: 'upstream',
    forkRemote: null,
    baseTag: 'v4.2.0',
  })

  assert.deepEqual(results, [{ id: 'p', outcome: 'merged' }])
  assert.match(git(['show', 'HEAD:reviewed.txt'], dir), /reviewed/)
  assert.equal(git(['rev-parse', 'refs/remotes/upstream-pr/7'], dir), pinned)
})

test('merges the pinned sha, not a force-pushed pr head, through a real fetch', () => {
  const dir = repo()
  const upstream = remoteRepo(dir)
  writeFileSync(join(upstream, 'reviewed.txt'), 'reviewed\n')
  git(['add', '.'], upstream)
  git(['commit', '-qm', 'reviewed'], upstream)
  const pinned = git(['rev-parse', 'HEAD'], upstream)
  // Simulate a force-push after review: refs/pull/<n>/head now points past
  // the pinned commit, at a commit that was never reviewed.
  writeFileSync(join(upstream, 'malicious.txt'), 'unreviewed\n')
  git(['add', '.'], upstream)
  git(['commit', '-qm', 'pushed after approval'], upstream)
  git(['update-ref', 'refs/pull/9/head', 'HEAD'], upstream)
  git(['remote', 'add', 'upstream', upstream], dir)

  rebuild({
    cwd: dir,
    manifest: { base: 'latest-tag', patches: [{ id: 'p', type: 'pr', number: 9, sha: pinned }] },
    upstreamRemote: 'upstream',
    forkRemote: null,
    baseTag: 'v4.2.0',
  })

  assert.match(git(['show', 'HEAD:reviewed.txt'], dir), /reviewed/)
  assert.throws(() => git(['show', 'HEAD:malicious.txt'], dir))
  // The fetch really did bring the force-pushed head across; the pin is
  // still what got merged.
  assert.notEqual(git(['rev-parse', 'refs/remotes/upstream-pr/9'], dir), pinned)
})

test('fetches a branch entry from a fork remote and merges by the fetched sha', () => {
  const dir = repo()
  const fork = remoteRepo(dir)
  git(['checkout', '-qb', 'patch/fork-feature'], fork)
  writeFileSync(join(fork, 'forked.txt'), 'forked\n')
  git(['add', '.'], fork)
  git(['commit', '-qm', 'forked feature'], fork)
  const forkedSha = git(['rev-parse', 'HEAD'], fork)
  git(['remote', 'add', 'fork', fork], dir)

  const { results } = rebuild({
    cwd: dir,
    manifest: { base: 'latest-tag', patches: [{ id: 'ff', type: 'branch', ref: 'patch/fork-feature' }] },
    upstreamRemote: null,
    forkRemote: 'fork',
    baseTag: 'v4.2.0',
  })

  assert.deepEqual(results, [{ id: 'ff', outcome: 'merged' }])
  assert.match(git(['show', 'HEAD:forked.txt'], dir), /forked/)
  assert.equal(git(['rev-parse', 'refs/remotes/fork-patch/ff'], dir), forkedSha)
})

test('halts with "is not reachable" when a pinned sha is gone even after fetching', () => {
  const dir = repo()
  const upstream = remoteRepo(dir)
  writeFileSync(join(upstream, 'orphan.txt'), 'orphan\n')
  git(['add', '.'], upstream)
  git(['commit', '-qm', 'orphaned commit'], upstream)
  const orphanSha = git(['rev-parse', 'HEAD'], upstream)
  // Simulate the pinned commit being orphaned and garbage-collected
  // upstream: no ref points at it any more, and it is pruned from the
  // remote's object store, so no fetch attempt can retrieve it.
  git(['reset', '--hard', 'HEAD~1'], upstream)
  git(['reflog', 'expire', '--expire=now', '--all'], upstream)
  git(['gc', '--prune=now', '-q'], upstream)
  git(['update-ref', 'refs/pull/3/head', 'HEAD'], upstream)
  git(['remote', 'add', 'upstream', upstream], dir)

  assert.throws(
    () => rebuild({
      cwd: dir,
      manifest: { base: 'latest-tag', patches: [{ id: 'p', type: 'pr', number: 3, sha: orphanSha }] },
      upstreamRemote: 'upstream',
      forkRemote: null,
      baseTag: 'v4.2.0',
    }),
    /pinned sha .* is not reachable/,
  )
})
