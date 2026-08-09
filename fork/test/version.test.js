import { test } from 'node:test'
import assert from 'node:assert/strict'
import { allocateVersion, baseVersionFromTag, selectBaseTag } from '../lib/version.js'

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

const LS_REMOTE = [
  'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\trefs/tags/v4.1.2',
  'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\trefs/tags/v4.2.0',
  'cccccccccccccccccccccccccccccccccccccccc\trefs/tags/v4.2.0^{}',
  'dddddddddddddddddddddddddddddddddddddddd\trefs/tags/v4.0.0-beta.474',
  'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee\trefs/tags/4.2.0.7',
].join('\n')

test('selects the newest three-component v4 tag', () => {
  assert.deepEqual(selectBaseTag(LS_REMOTE), { tag: 'v4.2.0', sha: 'c'.repeat(40) })
})

test('never selects a fork tag or a prerelease tag', () => {
  const only = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee\trefs/tags/4.2.0.7\n' +
    'dddddddddddddddddddddddddddddddddddddddd\trefs/tags/v4.0.0-beta.474'
  assert.throws(() => selectBaseTag(only), /no upstream v4 release tag/)
})

test('orders numerically so v4.10.0 beats v4.9.0', () => {
  const out = selectBaseTag(
    'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\trefs/tags/v4.9.0\n' +
    'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\trefs/tags/v4.10.0',
  )
  assert.equal(out.tag, 'v4.10.0')
})
