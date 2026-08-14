import { test } from 'node:test'
import assert from 'node:assert/strict'
import { assertArtifactCoverage, buildVersionsForkJson, resolveReleaseVersion } from '../bin/publish.mjs'

const FILES = ['docker-compose.yml', 'docker-compose.prod.yml', '.env.production', 'upgrade-postgres.sh']

test('accepts a script whose requests are all published', () => {
  assert.doesNotThrow(() =>
    assertArtifactCoverage(FILES.map((f) => `releases/\${LATEST_IMAGE}/${f}`), FILES))
})

test('throws naming the artifact the script requests but nobody publishes', () => {
  assert.throws(
    () => assertArtifactCoverage(['releases/${LATEST_IMAGE}/missing.sh'], FILES),
    /missing\.sh/,
  )
})

test('ignores the two fixed-path files, which are published separately', () => {
  assert.doesNotThrow(() => assertArtifactCoverage(['versions.json', 'upgrade.sh'], FILES))
})

test('resolveReleaseVersion allocates the next suffix and stamps constants.php', () => {
  const constantsPhp = `<?php\nreturn [\n    'coolify' => [\n        'version' => '4.2.0',\n    ],\n];\n`
  const { version, patchedConstants } = resolveReleaseVersion('v4.2.0', ['4.2.0.1', '4.1.0.9'], constantsPhp)

  assert.equal(version, '4.2.0.2')
  assert.match(patchedConstants, /'version' => '4\.2\.0\.2'/)
})

test('resolveReleaseVersion starts at .1 when no fork tag exists for the base', () => {
  const constantsPhp = `<?php\nreturn [\n    'coolify' => [\n        'version' => '4.2.0',\n    ],\n];\n`
  const { version } = resolveReleaseVersion('v4.2.0', [], constantsPhp)

  assert.equal(version, '4.2.0.1')
})

const UPSTREAM_VERSIONS = JSON.stringify(
  {
    coolify: {
      v4: { version: '4.2.0' },
      helper: { version: '1.0.14' },
    },
    traefik: { 'v3.7': '3.7.8' },
  },
  null,
  4,
)

test('buildVersionsForkJson derives versions.fork.json when every request is covered', () => {
  const upgradeScript = FILES.map((f) => `curl -fsSL "\${CDN}/releases/\${LATEST_IMAGE}/${f}"`).join('\n')

  const out = buildVersionsForkJson({
    upgradeScript,
    releaseFiles: FILES,
    builtVersionsJson: UPSTREAM_VERSIONS,
    version: '4.2.0.1',
  })

  assert.equal(JSON.parse(out).coolify.v4.version, '4.2.0.1')
  assert.equal(JSON.parse(out).coolify.helper.version, '1.0.14')
})

test('buildVersionsForkJson throws instead of writing a manifest when an artifact is missing', () => {
  const upgradeScript = 'curl -fsSL "${CDN}/releases/${LATEST_IMAGE}/missing.sh"'

  assert.throws(
    () =>
      buildVersionsForkJson({
        upgradeScript,
        releaseFiles: FILES,
        builtVersionsJson: UPSTREAM_VERSIONS,
        version: '4.2.0.1',
      }),
    /missing\.sh/,
  )
})
