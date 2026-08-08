import { test } from 'node:test'
import assert from 'node:assert/strict'
import { allocateVersion, baseVersionFromTag } from '../lib/version.js'

test('strips the v prefix from an upstream tag', () => {
  assert.equal(baseVersionFromTag('v4.2.0'), '4.2.0')
})

test('rejects a tag that is not a three-component upstream release', () => {
  assert.throws(() => baseVersionFromTag('4.2.0.1'), /not an upstream release tag/)
  assert.throws(() => baseVersionFromTag('v4.2'), /not an upstream release tag/)
  assert.throws(() => baseVersionFromTag('v4.2.0-beta.1'), /not an upstream release tag/)
})

test('allocates .1 when no fork tag exists for the base', () => {
  assert.equal(allocateVersion('v4.2.0', []), '4.2.0.1')
})

test('allocates the next suffix above the maximum existing', () => {
  assert.equal(allocateVersion('v4.2.0', ['4.2.0.1', '4.2.0.3', '4.2.0.2']), '4.2.0.4')
})

test('ignores fork tags belonging to a different base version', () => {
  assert.equal(allocateVersion('v4.3.0', ['4.2.0.7', 'v4.2.0', 'v4.3.0']), '4.3.0.1')
})

test('compares suffixes numerically, not lexically', () => {
  assert.equal(allocateVersion('v4.2.0', ['4.2.0.9', '4.2.0.10']), '4.2.0.11')
})
