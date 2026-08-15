#!/usr/bin/env node

import { execFileSync } from 'node:child_process'
import { readdirSync, readFileSync, writeFileSync } from 'node:fs'
import { allocateVersion } from '../lib/version.js'
import { deriveVersionsJson, parseCdnUrls, patchConstantsVersion } from './release.mjs'

const FIXED_PATHS = new Set(['versions.json', 'upgrade.sh'])
const RELEASE_PREFIX = /^releases\/\$\{?LATEST_IMAGE\}?\//

/**
 * Assert every CDN path the upgrade script requests maps to a published
 * artifact. A URL with no artifact is a build failure, not a runtime 404.
 *
 * @param {string[]} requestedPaths from parseCdnUrls()
 * @param {string[]} releaseFiles basenames published under releases/<version>/
 */
export function assertArtifactCoverage(requestedPaths, releaseFiles) {
  const published = new Set(releaseFiles)
  const uncovered = []
  for (const path of requestedPaths) {
    if (FIXED_PATHS.has(path)) {
      continue
    }
    if (!RELEASE_PREFIX.test(path)) {
      uncovered.push(`${path} (not a versioned release path)`)
      continue
    }
    const name = path.replace(RELEASE_PREFIX, '')
    if (!published.has(name)) {
      uncovered.push(path)
    }
  }
  if (uncovered.length > 0) {
    throw new Error(`upgrade.sh requests artifacts that are not published:\n  ${uncovered.join('\n  ')}`)
  }
}

/**
 * Decide the version to release and stamp it into `config/constants.php`'s
 * contents. Pure composition of two already-tested library calls — kept as
 * one function so the workflow can call a single, unit-testable step instead
 * of the awkward chained `node -e` blocks this replaces. Filesystem and
 * subprocess I/O (reading/writing the file, verifying it with
 * `bootstrap/getVersion.php`) live in the CLI plumbing below, not here.
 *
 * @param {string} baseTag upstream tag, e.g. `v4.2.0`
 * @param {string[]} existingTags every tag in the fork repository (`git tag -l`)
 * @param {string} constantsPhp contents of config/constants.php
 * @returns {{ version: string, patchedConstants: string }}
 */
export function resolveReleaseVersion(baseTag, existingTags, constantsPhp) {
  const version = allocateVersion(baseTag, existingTags)
  const patchedConstants = patchConstantsVersion(constantsPhp, version)
  return { version, patchedConstants }
}

/**
 * Verify artifact coverage and derive the fork's `versions.json` patch, for
 * the "Assemble the Pages site" workflow step. The result is written to
 * `versions.fork.json`, not into `site/`: the "Advertise the release"
 * workflow step copies it into `site/versions.json` only after the
 * Pages deploy and hash verification that follow this step, because it is
 * the pointer that advertises the release.
 *
 * @param {object} opts
 * @param {string} opts.upgradeScript contents of scripts/upgrade.sh from the built tree
 * @param {string[]} opts.releaseFiles basenames already copied under the release dir
 * @param {string} opts.builtVersionsJson contents of versions.json from the built tree
 * @param {string} opts.version e.g. `4.2.0.1`
 * @returns {string} versions.fork.json contents, ready to write and held back
 *   from `site/` until the release is otherwise fully published
 */
export function buildVersionsForkJson({ upgradeScript, releaseFiles, builtVersionsJson, version }) {
  assertArtifactCoverage(parseCdnUrls(upgradeScript), releaseFiles)
  return deriveVersionsJson(builtVersionsJson, version)
}

/**
 * Run `node fork/bin/publish.mjs allocate-version`, invoked with
 * `working-directory: .build` and `BASE_TAG` in the environment. Prints only
 * the allocated version to stdout so the workflow can capture it into a
 * shell variable for the same step's commit message.
 */
function runAllocateVersion() {
  const baseTag = process.env.BASE_TAG
  const existingTags = execFileSync('git', ['tag', '-l'], { encoding: 'utf8' })
    .split('\n')
    .filter(Boolean)
  const constantsPath = 'config/constants.php'
  const constantsPhp = readFileSync(constantsPath, 'utf8')

  const { version, patchedConstants } = resolveReleaseVersion(baseTag, existingTags, constantsPhp)
  writeFileSync(constantsPath, patchedConstants)

  const stamped = execFileSync('php', ['bootstrap/getVersion.php'], { encoding: 'utf8' }).trim()
  if (stamped !== version) {
    throw new Error(`bootstrap/getVersion.php reports "${stamped}", expected the stamped version "${version}"`)
  }

  console.log(version)
}

/**
 * Run `node fork/bin/publish.mjs assemble-site`, invoked from the workspace
 * root (not `.build`) with `VERSION` and `DEST_DIR` in the environment,
 * after the artifact `cp` commands have populated `DEST_DIR`. Writes
 * `versions.fork.json` at the workspace root; the "Advertise the release"
 * step copies it into `site/` only once every other artifact is deployed and
 * hash-verified.
 */
function runAssembleSite() {
  const upgradeScript = readFileSync('.build/scripts/upgrade.sh', 'utf8')
  const releaseFiles = readdirSync(process.env.DEST_DIR)
  const builtVersionsJson = readFileSync('.build/versions.json', 'utf8')

  const versionsForkJson = buildVersionsForkJson({
    upgradeScript,
    releaseFiles,
    builtVersionsJson,
    version: process.env.VERSION,
  })

  writeFileSync('versions.fork.json', versionsForkJson)
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const command = process.argv[2]
  if (command === 'allocate-version') {
    runAllocateVersion()
  } else if (command === 'assemble-site') {
    runAssembleSite()
  } else {
    console.error('usage: publish.mjs <allocate-version|assemble-site>')
    process.exit(1)
  }
}
