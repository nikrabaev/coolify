import { test } from 'node:test'
import assert from 'node:assert/strict'
import { loadManifest } from '../lib/manifest.js'

const SHA = 'a'.repeat(40)

test('parses a valid manifest and preserves order', () => {
  const m = loadManifest(`
base: latest-tag
patches:
  - id: pr-one
    type: pr
    number: 11094
    sha: ${SHA}
  - id: branch-one
    type: branch
    ref: patch/foo
`)
  assert.equal(m.base, 'latest-tag')
  assert.deepEqual(m.patches.map((p) => p.id), ['pr-one', 'branch-one'])
})

test('rejects a pr entry with no sha pin', () => {
  assert.throws(
    () => loadManifest(`patches:\n  - id: x\n    type: pr\n    number: 1\n`),
    /needs a full 40-character sha pin/,
  )
})

test('rejects a pr entry with an abbreviated sha', () => {
  assert.throws(
    () => loadManifest(`patches:\n  - id: x\n    type: pr\n    number: 1\n    sha: abc1234\n`),
    /needs a full 40-character sha pin/,
  )
})

test('rejects a branch entry with no ref', () => {
  assert.throws(
    () => loadManifest(`patches:\n  - id: x\n    type: branch\n`),
    /needs a ref/,
  )
})

test('rejects duplicate ids', () => {
  assert.throws(
    () => loadManifest(
      `patches:\n  - id: x\n    type: branch\n    ref: a\n  - id: x\n    type: branch\n    ref: b\n`,
    ),
    /duplicate id/,
  )
})

test('rejects an unknown entry type', () => {
  assert.throws(
    () => loadManifest(`patches:\n  - id: x\n    type: tarball\n`),
    /unknown type/,
  )
})

test('defaults base to latest-tag', () => {
  assert.equal(loadManifest('patches: []').base, 'latest-tag')
})
