import { test } from 'node:test'
import assert from 'node:assert/strict'
import { execFileSync } from 'node:child_process'
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

/**
 * Build a throwaway git repository under a temp directory and return its
 * root. `collectToolingFiles` reads from `git ls-files`, so every fixture
 * used against it must be a real repo with the relevant paths committed —
 * untracked files simply do not appear in git's listing.
 */
function gitFixture() {
  const root = mkdtempSync(join(tmpdir(), 'fp-'))
  git(root, ['init', '-q'])
  git(root, ['config', 'user.email', 'fixture@example.com'])
  git(root, ['config', 'user.name', 'Fixture'])
  return root
}

function git(cwd, args) {
  execFileSync('git', args, { cwd, stdio: 'pipe' })
}

function commitAll(root, message = 'commit') {
  git(root, ['add', '-A'])
  git(root, ['commit', '-q', '-m', message])
}

function writeAndTrack(root, relPath, contents) {
  const full = join(root, relPath)
  mkdirSync(join(full, '..'), { recursive: true })
  writeFileSync(full, contents)
}

test('collects only fork tooling, never state or foreign workflows', () => {
  const root = gitFixture()
  try {
    writeAndTrack(root, 'fork/lib/x.js', 'export const a = 1\n')
    writeAndTrack(root, '.github/workflows/fork-sync.yml', 'name: fork-sync\n')
    writeAndTrack(root, '.github/workflows/claude.yml', 'name: claude\n')
    writeAndTrack(root, 'state/last-success.json', '{"fingerprint":"old"}\n')
    commitAll(root)

    const files = collectToolingFiles(root)
    const paths = [...files.keys()].sort()
    assert.deepEqual(paths, ['.github/workflows/fork-sync.yml', 'fork/lib/x.js'])
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})

test('INVARIANT: writing CI state does not change the fingerprint', () => {
  const root = gitFixture()
  try {
    writeAndTrack(root, 'fork/lib/x.js', 'export const a = 1\n')
    writeAndTrack(root, '.github/workflows/fork-sync.yml', 'name: fork-sync\n')
    writeAndTrack(root, 'state/last-success.json', '{"fingerprint":"old"}\n')
    commitAll(root)

    const inputs = () => ({ ...BASE, toolingFiles: collectToolingFiles(root) })
    const before = computeFingerprint(inputs())

    // Simulate a full successful run's state write, committed like CI would.
    writeAndTrack(root, 'state/last-success.json', '{"fingerprint":"new","version":"4.2.0.1"}\n')
    writeAndTrack(root, 'state/rr-cache/abcdef/preimage', 'conflict\n')
    commitAll(root, 'ci: state write')

    assert.equal(computeFingerprint(inputs()), before)
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})

test('SECURITY: a symlink committed inside fork/ throws, naming the offending path', () => {
  const root = gitFixture()
  try {
    writeAndTrack(root, 'fork/a.js', 'export const a = 1\n')
    symlinkSync('../state/secret.json', join(root, 'fork/leak.json'))
    commitAll(root)

    assert.throws(() => collectToolingFiles(root), /fork\/leak\.json/)
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})

test('SECURITY: a committed symlink at .github/workflows/fork-x.yml throws', () => {
  const root = gitFixture()
  try {
    writeAndTrack(root, 'fork/a.js', 'export const a = 1\n')
    mkdirSync(join(root, '.github/workflows'), { recursive: true })
    symlinkSync('../../state/secret.json', join(root, '.github/workflows/fork-leak.yml'))
    commitAll(root)

    assert.throws(() => collectToolingFiles(root), /fork-leak\.yml/)
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})

test('a missing fork/ directory is tolerated and workflow files are still returned', () => {
  const root = gitFixture()
  try {
    writeAndTrack(root, '.github/workflows/fork-sync.yml', 'name: fork-sync\n')
    // Deliberately no `fork/` directory at all.
    commitAll(root)

    const files = collectToolingFiles(root)
    assert.deepEqual([...files.keys()], ['.github/workflows/fork-sync.yml'])
  } finally {
    rmSync(root, { recursive: true, force: true })
  }
})

test('changing a hashed file content changes its collected blob sha', () => {
  const root = gitFixture()
  try {
    writeAndTrack(root, 'fork/a.js', 'export const a = 1\n')
    commitAll(root)
    const before = collectToolingFiles(root).get('fork/a.js')

    writeAndTrack(root, 'fork/a.js', 'export const a = 2\n')
    commitAll(root, 'change a.js')
    const after = collectToolingFiles(root).get('fork/a.js')

    assert.notEqual(before, after)
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
