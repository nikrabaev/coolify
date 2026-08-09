import { test } from 'node:test'
import assert from 'node:assert/strict'
import { classifyPrEntries } from '../bin/probe.mjs'

const PIN = 'a'.repeat(40)
const MOVED = 'b'.repeat(40)
const entry = { id: 'p', type: 'pr', number: 7, sha: PIN }

test('reports pinned when the head still matches the pin', () => {
  const out = classifyPrEntries([entry], new Map([[7, { state: 'open', merged: false, headSha: PIN }]]))
  assert.deepEqual(out, [{ id: 'p', number: 7, status: 'pinned' }])
})

test('reports head-moved when the author pushed after approval', () => {
  const out = classifyPrEntries([entry], new Map([[7, { state: 'open', merged: false, headSha: MOVED }]]))
  assert.deepEqual(out, [{ id: 'p', number: 7, status: 'head-moved' }])
})

test('reports merged when upstream merged the pr', () => {
  const out = classifyPrEntries([entry], new Map([[7, { state: 'closed', merged: true, headSha: PIN }]]))
  assert.deepEqual(out, [{ id: 'p', number: 7, status: 'merged' }])
})

test('reports closed when the pr was closed without merging', () => {
  const out = classifyPrEntries([entry], new Map([[7, { state: 'closed', merged: false, headSha: PIN }]]))
  assert.deepEqual(out, [{ id: 'p', number: 7, status: 'closed' }])
})

test('ignores branch entries entirely', () => {
  assert.deepEqual(classifyPrEntries([{ id: 'b', type: 'branch', ref: 'r' }], new Map()), [])
})

test('throws when a pr entry has no state (api gap must not be silent)', () => {
  assert.throws(() => classifyPrEntries([entry], new Map()), /no state for pr 7/)
})
