import { test } from 'node:test'
import assert from 'node:assert/strict'
import { normalizeRemoteUrl, resolveRemotes } from '../lib/remotes.js'

test('normalizes every github url form to owner/repo', () => {
  const forms = [
    'git@github.com:coollabsio/coolify.git',
    'https://github.com/coollabsio/coolify.git',
    'https://github.com/coollabsio/coolify',
    'ssh://git@github.com/coollabsio/coolify.git',
    'https://x-access-token:secret@github.com/coollabsio/coolify',
  ]
  for (const url of forms) {
    assert.equal(normalizeRemoteUrl(url), 'coollabsio/coolify', url)
  }
})

test('returns null for a non-github url', () => {
  assert.equal(normalizeRemoteUrl('https://gitlab.com/a/b.git'), null)
})

test('resolves the local worktree layout where origin is upstream', () => {
  const r = resolveRemotes([
    'fork\tgit@github.com:nikrabaev/coolify.git (fetch)',
    'fork\tgit@github.com:nikrabaev/coolify.git (push)',
    'origin\thttps://github.com/coollabsio/coolify.git (fetch)',
    'origin\thttps://github.com/coollabsio/coolify.git (push)',
  ])
  assert.deepEqual(r, { upstream: 'origin', fork: 'fork' })
})

test('resolves the actions/checkout layout where origin is the fork', () => {
  const r = resolveRemotes([
    'origin\thttps://github.com/nikrabaev/coolify (fetch)',
    'origin\thttps://github.com/nikrabaev/coolify (push)',
  ])
  assert.deepEqual(r, { upstream: null, fork: 'origin' })
})

test('throws when two remotes point at the same repository', () => {
  assert.throws(
    () => resolveRemotes([
      'a\thttps://github.com/coollabsio/coolify (fetch)',
      'b\tgit@github.com:coollabsio/coolify.git (fetch)',
    ]),
    /ambiguous/,
  )
})

test('ignores push lines so a single remote is not seen as ambiguous', () => {
  const r = resolveRemotes([
    'origin\thttps://github.com/nikrabaev/coolify (fetch)',
    'origin\thttps://github.com/nikrabaev/coolify (push)',
  ])
  assert.equal(r.fork, 'origin')
})
