import { test } from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync } from 'node:fs'
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
