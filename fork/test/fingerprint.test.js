import { test } from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync, mkdirSync, writeFileSync, symlinkSync, rmSync } from 'node:fs'
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

test('SECURITY: a symlink under the hashed tree throws, naming the offending path (does not smuggle state/ content into the hash)', () => {
  const root = mkdtempSync(join(tmpdir(), 'fp-'))
  try {
    mkdirSync(join(root, 'fork'), { recursive: true })
    mkdirSync(join(root, 'state'), { recursive: true })
    writeFileSync(join(root, 'state/secret.json'), '{"leak":true}\n')
    // Mirrors the reviewer's repro: a symlink under fork/ pointing at state/.
    symlinkSync(join(root, 'state/secret.json'), join(root, 'fork/leak.json'))

    assert.throws(() => collectToolingFiles(root), /fork\/leak\.json/)
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})

test('SECURITY: crafted tab/newline id no longer reproduces the preimage of a different two-entry branchShas map', () => {
  const shaA = 'a'.repeat(40)
  const shaB = 'b'.repeat(40)
  const twoEntries = { ...BASE, branchShas: new Map([['a', shaA], ['b', shaB]]) }

  // Under the old unescaped `branch\t${id}\t${sha}\n` format, this single
  // entry's preimage line is byte-for-byte identical to the two lines
  // produced by `twoEntries` above (id embeds a tab + `\n` + the literal
  // text of the second line).
  const craftedId = `a\t${shaA}\nbranch\tb`
  const collidingSingleEntry = { ...BASE, branchShas: new Map([[craftedId, shaB]]) }

  assert.notEqual(computeFingerprint(twoEntries), computeFingerprint(collidingSingleEntry))
})

test('a dangling symlink among real files throws instead of silently truncating the file list', () => {
  const root = mkdtempSync(join(tmpdir(), 'fp-'))
  try {
    mkdirSync(join(root, 'fork'), { recursive: true })
    writeFileSync(join(root, 'fork/a.js'), 'export const a = 1\n')
    // Sorts between a.js and c.js so a.js is collected before the walk hits it.
    symlinkSync(join(root, 'fork/does-not-exist.js'), join(root, 'fork/broken-link'))
    writeFileSync(join(root, 'fork/c.js'), 'export const c = 1\n')

    assert.throws(() => collectToolingFiles(root))
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})

test('a genuinely missing top-level hashed directory is still tolerated', () => {
  const root = mkdtempSync(join(tmpdir(), 'fp-'))
  try {
    mkdirSync(join(root, '.github/workflows'), { recursive: true })
    writeFileSync(join(root, '.github/workflows/fork-sync.yml'), 'name: fork-sync\n')
    // Deliberately no `fork/` directory at all.

    const files = collectToolingFiles(root)
    assert.deepEqual([...files.keys()], ['.github/workflows/fork-sync.yml'])
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})

test('SECURITY: a symlinked fork/ pointing outside the fingerprinted tree throws (top-level entry point)', () => {
  const root = mkdtempSync(join(tmpdir(), 'fp-'))
  try {
    // A sibling directory standing in for "somewhere outside the repo",
    // reachable only because `fork` itself is a symlink to it.
    const outside = join(root, 'outside-fork')
    mkdirSync(outside, { recursive: true })
    writeFileSync(join(outside, 'secret.js'), 'export const leak = true\n')
    symlinkSync(outside, join(root, 'fork'))

    assert.throws(() => collectToolingFiles(root), /fork/)
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})

test('SECURITY: a symlinked fork-*.yml workflow file throws instead of being read directly', () => {
  const root = mkdtempSync(join(tmpdir(), 'fp-'))
  try {
    mkdirSync(join(root, 'fork'), { recursive: true })
    mkdirSync(join(root, '.github/workflows'), { recursive: true })
    mkdirSync(join(root, 'state'), { recursive: true })
    writeFileSync(join(root, 'state/secret.json'), '{"leak":true}\n')
    symlinkSync(join(root, 'state/secret.json'), join(root, '.github/workflows/fork-leak.yml'))

    assert.throws(() => collectToolingFiles(root), /fork-leak\.yml/)
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})

test('SECURITY: a symlinked .github/workflows directory throws before it is listed', () => {
  const root = mkdtempSync(join(tmpdir(), 'fp-'))
  try {
    mkdirSync(join(root, 'fork'), { recursive: true })
    const outsideWorkflows = join(root, 'outside-workflows')
    mkdirSync(outsideWorkflows, { recursive: true })
    writeFileSync(join(outsideWorkflows, 'fork-leak.yml'), 'name: fork-leak\n')
    mkdirSync(join(root, '.github'), { recursive: true })
    symlinkSync(outsideWorkflows, join(root, '.github/workflows'))

    assert.throws(() => collectToolingFiles(root), /\.github\/workflows/)
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})
