import { test } from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync, mkdirSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { collectToolingFiles, computeFingerprint } from '../lib/fingerprint.js'

const BASE = {
  baseTag: 'v4.2.0',
  baseSha: 'b'.repeat(40),
  manifestText: 'patches: []\n',
  branchShas: new Map([['cdn-hardening', 'c'.repeat(40)]]),
  toolingFiles: new Map([['fork/lib/x.js', 'export const a = 1\n']]),
}

function fixture() {
  const root = mkdtempSync(join(tmpdir(), 'fp-'))
  mkdirSync(join(root, 'fork/lib'), { recursive: true })
  mkdirSync(join(root, '.github/workflows'), { recursive: true })
  mkdirSync(join(root, 'state/rr-cache'), { recursive: true })
  writeFileSync(join(root, 'fork/lib/x.js'), 'export const a = 1\n')
  writeFileSync(join(root, '.github/workflows/fork-sync.yml'), 'name: fork-sync\n')
  writeFileSync(join(root, '.github/workflows/claude.yml'), 'name: claude\n')
  writeFileSync(join(root, 'state/last-success.json'), '{"fingerprint":"old"}\n')
  return root
}

test('is stable across calls with identical inputs', () => {
  assert.equal(computeFingerprint(BASE), computeFingerprint(BASE))
})

test('changes when the manifest changes', () => {
  const other = { ...BASE, manifestText: 'patches: [{id: a, type: branch, ref: r}]\n' }
  assert.notEqual(computeFingerprint(BASE), computeFingerprint(other))
})

test('changes when a branch head moves', () => {
  const other = { ...BASE, branchShas: new Map([['cdn-hardening', 'd'.repeat(40)]]) }
  assert.notEqual(computeFingerprint(BASE), computeFingerprint(other))
})

test('changes when the base tag changes', () => {
  assert.notEqual(computeFingerprint(BASE), computeFingerprint({ ...BASE, baseTag: 'v4.3.0' }))
})

test('does not depend on map insertion order', () => {
  const a = {
    ...BASE,
    toolingFiles: new Map([['fork/a.js', '1'], ['fork/b.js', '2']]),
  }
  const b = {
    ...BASE,
    toolingFiles: new Map([['fork/b.js', '2'], ['fork/a.js', '1']]),
  }
  assert.equal(computeFingerprint(a), computeFingerprint(b))
})

test('collects only fork tooling, never state or foreign workflows', () => {
  const files = collectToolingFiles(fixture())
  const paths = [...files.keys()].sort()
  assert.deepEqual(paths, ['.github/workflows/fork-sync.yml', 'fork/lib/x.js'])
})

test('INVARIANT: writing CI state does not change the fingerprint', () => {
  const root = fixture()
  const inputs = () => ({ ...BASE, toolingFiles: collectToolingFiles(root) })
  const before = computeFingerprint(inputs())

  // Simulate a full successful run's state write.
  writeFileSync(join(root, 'state/last-success.json'), '{"fingerprint":"new","version":"4.2.0.1"}\n')
  mkdirSync(join(root, 'state/rr-cache/abcdef'), { recursive: true })
  writeFileSync(join(root, 'state/rr-cache/abcdef/preimage'), 'conflict\n')

  assert.equal(computeFingerprint(inputs()), before)
})
