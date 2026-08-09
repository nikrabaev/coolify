import { test } from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync, readFileSync, readdirSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { needsRebuild, readLastSuccess, writeLastSuccess } from '../lib/state.js'

test('returns null when no state file exists yet', () => {
  assert.equal(readLastSuccess(join(mkdtempSync(join(tmpdir(), 'st-')), 'missing.json')), null)
})

test('round-trips a state record', () => {
  const path = join(mkdtempSync(join(tmpdir(), 'st-')), 'last-success.json')
  writeLastSuccess(path, { fingerprint: 'abc', version: '4.2.0.1' })
  assert.deepEqual(readLastSuccess(path), { fingerprint: 'abc', version: '4.2.0.1' })
})

test('rebuilds when there is no recorded success', () => {
  assert.equal(needsRebuild(null, 'abc'), true)
})

test('does not rebuild when the fingerprint matches', () => {
  assert.equal(needsRebuild({ fingerprint: 'abc' }, 'abc'), false)
})

test('rebuilds when the fingerprint differs', () => {
  assert.equal(needsRebuild({ fingerprint: 'abc' }, 'def'), true)
})

test('a truncated/corrupt state file yields null instead of throwing', () => {
  const path = join(mkdtempSync(join(tmpdir(), 'st-')), 'last-success.json')
  // Simulates a CI runner killed or cancelled mid-write, before
  // writeLastSuccess was made atomic.
  writeFileSync(path, '{"fingerprint": "ab')

  const warnings = []
  const originalWarn = console.warn
  console.warn = (msg) => warnings.push(msg)
  let result
  try {
    result = readLastSuccess(path)
  } finally {
    console.warn = originalWarn
  }

  assert.equal(result, null)
  assert.equal(warnings.length, 1)
  assert.match(warnings[0], /last-success\.json/)
})

test('a corrupt state file still triggers a rebuild', () => {
  const path = join(mkdtempSync(join(tmpdir(), 'st-')), 'last-success.json')
  writeFileSync(path, 'not json at all')
  const originalWarn = console.warn
  console.warn = () => {}
  let last
  try {
    last = readLastSuccess(path)
  } finally {
    console.warn = originalWarn
  }
  assert.equal(needsRebuild(last, 'anything'), true)
})

test('writeLastSuccess is atomic: no temp file survives and the target always parses', () => {
  const dir = mkdtempSync(join(tmpdir(), 'st-'))
  const path = join(dir, 'last-success.json')

  writeLastSuccess(path, { fingerprint: 'abc', version: '4.2.0.1' })

  assert.deepEqual(readdirSync(dir), ['last-success.json'])
  assert.deepEqual(JSON.parse(readFileSync(path, 'utf8')), { fingerprint: 'abc', version: '4.2.0.1' })

  // A second write must not leave a stray temp file behind either, and must
  // still leave the target fully parseable — never a partial overwrite.
  writeLastSuccess(path, { fingerprint: 'def', version: '4.2.0.2' })
  assert.deepEqual(readdirSync(dir), ['last-success.json'])
  assert.deepEqual(JSON.parse(readFileSync(path, 'utf8')), { fingerprint: 'def', version: '4.2.0.2' })
})
