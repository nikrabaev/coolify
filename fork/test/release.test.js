import { test } from 'node:test'
import assert from 'node:assert/strict'
import { existsSync, readFileSync } from 'node:fs'
import { deriveVersionsJson, parseCdnUrls, patchConstantsVersion } from '../bin/release.mjs'

// Points outside this repository (the upstream-sync worktree). Update this
// path if that worktree moves; the test skips cleanly when it's absent.
const REAL_CONSTANTS_PHP_PATH =
  '/Users/nikrabaev/Work/oss/coolify/.claude/worktrees/coolify-fork-upstream-sync-42951e/config/constants.php'

const UPSTREAM_VERSIONS = JSON.stringify(
  {
    coolify: {
      v4: { version: '4.2.0' },
      nightly: { version: '4.2.0' },
      helper: { version: '1.0.14' },
      realtime: { version: '1.0.16' },
      sentinel: { version: '0.0.21' },
    },
    traefik: { 'v3.7': '3.7.8', 'v2.11': '2.11.52' },
  },
  null,
  4,
)

test('rewrites only coolify.v4.version', () => {
  const out = JSON.parse(deriveVersionsJson(UPSTREAM_VERSIONS, '4.2.0.1'))
  assert.equal(out.coolify.v4.version, '4.2.0.1')
  assert.equal(out.coolify.nightly.version, '4.2.0')
})

test('preserves helper, realtime, sentinel and the whole traefik map', () => {
  const out = JSON.parse(deriveVersionsJson(UPSTREAM_VERSIONS, '4.2.0.1'))
  assert.equal(out.coolify.helper.version, '1.0.14')
  assert.equal(out.coolify.realtime.version, '1.0.16')
  assert.equal(out.coolify.sentinel.version, '0.0.21')
  assert.deepEqual(out.traefik, { 'v3.7': '3.7.8', 'v2.11': '2.11.52' })
})

test('patches the coolify version in constants.php without touching helper_version', () => {
  const php = `<?php\n\nreturn [\n    'coolify' => [\n        'version' => '4.2.0',\n        'helper_version' => '1.0.14',\n    ],\n];\n`
  const out = patchConstantsVersion(php, '4.2.0.1')
  assert.match(out, /'version' => '4\.2\.0\.1'/)
  assert.match(out, /'helper_version' => '1\.0\.14'/)
})

test('throws if the constants version line is not found', () => {
  assert.throws(() => patchConstantsVersion('<?php return [];', '4.2.0.1'), /version line not found/)
})

test('patches only the top-level coolify.version, not a version key nested inside a sub-array', () => {
  const php =
    `<?php\n\nreturn [\n` +
    `    'coolify' => [\n` +
    `        'some_nested' => [ 'version' => 'nested-should-not-match' ],\n` +
    `        'version' => '4.2.0',\n` +
    `        'helper_version' => '1.0.14',\n` +
    `    ],\n` +
    `];\n`
  const out = patchConstantsVersion(php, '9.9.9')
  assert.match(out, /'coolify' => \[[\s\S]*'version' => '9\.9\.9'/)
  assert.match(out, /'nested-should-not-match'/)
})

test('patches the real top-level version even when a stray "[" inside a string value inflates naive bracket depth', () => {
  const php =
    `<?php\n\nreturn [\n` +
    `    'coolify' => [\n` +
    `        'note' => 'bracket opens here [',\n` +
    `        'version' => '4.2.0',        // real one -- left untouched\n` +
    `        'helper_version' => '1.0.14',\n` +
    `    ],\n` +
    `    'version' => 'SPURIOUS',         // silently rewritten instead\n` +
    `];\n`
  const out = patchConstantsVersion(php, '9.9.9')
  assert.match(out, /'coolify' => \[[\s\S]*'version' => '9\.9\.9'/)
  assert.match(out, /'version' => 'SPURIOUS'/)
})

test('patches the real top-level version even when a "]" inside a string value deflates naive bracket depth', () => {
  const php =
    `<?php\n\nreturn [\n` +
    `    'coolify' => [\n` +
    `        'note' => 'bracket closes here ]',\n` +
    `        'version' => '4.2.0',\n` +
    `        'helper_version' => '1.0.14',\n` +
    `    ],\n` +
    `    'version' => 'SPURIOUS',\n` +
    `];\n`
  const out = patchConstantsVersion(php, '9.9.9')
  assert.match(out, /'coolify' => \[[\s\S]*'version' => '9\.9\.9'/)
  assert.match(out, /'version' => 'SPURIOUS'/)
})

test('ignores brackets inside // and /* */ comments between keys in the coolify block', () => {
  const php =
    `<?php\n\nreturn [\n` +
    `    'coolify' => [\n` +
    `        // a stray comment with brackets: [ [ [ ]\n` +
    `        /* another one with brackets: [ ] [ ] */\n` +
    `        'version' => '4.2.0',\n` +
    `        'helper_version' => '1.0.14',\n` +
    `    ],\n` +
    `    'version' => 'SPURIOUS',\n` +
    `];\n`
  const out = patchConstantsVersion(php, '9.9.9')
  assert.match(out, /'coolify' => \[[\s\S]*'version' => '9\.9\.9'/)
  assert.match(out, /'version' => 'SPURIOUS'/)
})

test('handles an escaped quote followed by a bracket without terminating the string early', () => {
  const php =
    `<?php\n\nreturn [\n` +
    `    'coolify' => [\n` +
    `        'note' => 'it\\'s [ here',\n` +
    `        'version' => '4.2.0',\n` +
    `        'helper_version' => '1.0.14',\n` +
    `    ],\n` +
    `    'version' => 'SPURIOUS',\n` +
    `];\n`
  const out = patchConstantsVersion(php, '9.9.9')
  assert.match(out, /'coolify' => \[[\s\S]*'version' => '9\.9\.9'/)
  assert.match(out, /'version' => 'SPURIOUS'/)
})

test('writes a version containing regex replacement patterns literally', () => {
  const php = `<?php\n\nreturn [\n    'coolify' => [\n        'version' => '4.2.0',\n        'helper_version' => '1.0.14',\n    ],\n];\n`
  const out = patchConstantsVersion(php, '4.2.0$1-weird$&')
  assert.match(out, /'version' => '4\.2\.0\$1-weird\$&'/)
  assert.match(out, /'helper_version' => '1\.0\.14'/)
})

test('patches the env() fallback literal in the v4.3.2 shape, leaving the env() lookup intact', () => {
  const php =
    `<?php\n\nreturn [\n` +
    `    'coolify' => [\n` +
    `        'version' => env('COOLIFY_VERSION') ?: '4.3.2',\n` +
    `        'helper_version' => '1.0.14',\n` +
    `    ],\n` +
    `];\n`
  const out = patchConstantsVersion(php, '4.3.2.1')
  assert.match(out, /'version' => env\('COOLIFY_VERSION'\) \?: '4\.3\.2\.1',/)
  assert.match(out, /'helper_version' => '1\.0\.14'/)
})

test('still patches the plain v4.2.0 shape (regression guard for the old form)', () => {
  const php = `<?php\n\nreturn [\n    'coolify' => [\n        'version' => '4.2.0',\n        'helper_version' => '1.0.14',\n    ],\n];\n`
  const out = patchConstantsVersion(php, '4.2.0.1')
  assert.match(out, /'version' => '4\.2\.0\.1',/)
  assert.match(out, /'helper_version' => '1\.0\.14'/)
})

test('patches only the top-level coolify.version in the env() shape, not a version key nested inside a sub-array', () => {
  const php =
    `<?php\n\nreturn [\n` +
    `    'coolify' => [\n` +
    `        'some_nested' => [ 'version' => 'nested-should-not-match' ],\n` +
    `        'version' => env('COOLIFY_VERSION') ?: '4.3.2',\n` +
    `        'helper_version' => '1.0.14',\n` +
    `    ],\n` +
    `];\n`
  const out = patchConstantsVersion(php, '9.9.9')
  assert.match(out, /'coolify' => \[[\s\S]*'version' => env\('COOLIFY_VERSION'\) \?: '9\.9\.9'/)
  assert.match(out, /'nested-should-not-match'/)
})

test('writes a version containing regex replacement patterns literally in the env() shape', () => {
  const php =
    `<?php\n\nreturn [\n` +
    `    'coolify' => [\n` +
    `        'version' => env('COOLIFY_VERSION') ?: '4.3.2',\n` +
    `        'helper_version' => '1.0.14',\n` +
    `    ],\n` +
    `];\n`
  const out = patchConstantsVersion(php, '4.3.2$1-weird$&')
  assert.match(out, /'version' => env\('COOLIFY_VERSION'\) \?: '4\.3\.2\$1-weird\$&',/)
  assert.match(out, /'helper_version' => '1\.0\.14'/)
})

test('a comma inside a trailing comment does not terminate the version statement early', () => {
  const php =
    `<?php\n\nreturn [\n` +
    `    'coolify' => [\n` +
    `        'version' => env('X') ?: '4.3.2', // note, with a comma\n` +
    `        'helper_version' => '1.0.14',\n` +
    `    ],\n` +
    `];\n`
  const out = patchConstantsVersion(php, '9.9.9')
  assert.match(out, /'version' => env\('X'\) \?: '9\.9\.9', \/\/ note, with a comma/)
  assert.match(out, /'helper_version' => '1\.0\.14'/)
})

test('patches the real upstream constants.php, leaving sibling version keys untouched', { skip: !existsSync(REAL_CONSTANTS_PHP_PATH) }, () => {
  const original = readFileSync(REAL_CONSTANTS_PHP_PATH, 'utf8')
  const out = patchConstantsVersion(original, '4.2.0.1')
  assert.match(out, /'coolify' => \[[\s\S]*?'version' => '4\.2\.0\.1'/)

  const helperMatch = original.match(/'helper_version'\s*=>\s*'([^']*)'/)
  const realtimeMatch = original.match(/'realtime_version'\s*=>\s*'([^']*)'/)
  const railpackMatch = original.match(/'railpack_version'\s*=>\s*'([^']*)'/)
  assert.ok(helperMatch && realtimeMatch && railpackMatch, 'expected version keys present in real file')

  assert.match(out, new RegExp(`'helper_version' => '${helperMatch[1].replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}'`))
  assert.match(out, new RegExp(`'realtime_version' => '${realtimeMatch[1].replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}'`))
  assert.match(out, new RegExp(`'railpack_version' => '${railpackMatch[1].replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}'`))
})

test('extracts every CDN path the upgrade script requests', () => {
  const script = [
    'CDN="https://nikrabaev.github.io/coolify"',
    'curl -fsSL -L "$CDN/releases/${LATEST_IMAGE}/docker-compose.yml" -o /tmp/a',
    'curl -fsSL -L "${CDN}/releases/${LATEST_IMAGE}/upgrade-postgres.sh" -o /tmp/b',
  ].join('\n')
  assert.deepEqual(parseCdnUrls(script), [
    'releases/${LATEST_IMAGE}/docker-compose.yml',
    'releases/${LATEST_IMAGE}/upgrade-postgres.sh',
  ])
})

test('returns no duplicates when a path is requested twice', () => {
  const script = 'curl "$CDN/releases/${LATEST_IMAGE}/x.yml"\ncurl "$CDN/releases/${LATEST_IMAGE}/x.yml"'
  assert.deepEqual(parseCdnUrls(script), ['releases/${LATEST_IMAGE}/x.yml'])
})
