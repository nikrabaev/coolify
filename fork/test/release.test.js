import { test } from 'node:test'
import assert from 'node:assert/strict'
import { deriveVersionsJson, parseCdnUrls, patchConstantsVersion } from '../bin/release.mjs'

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
