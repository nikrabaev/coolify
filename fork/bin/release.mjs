#!/usr/bin/env node

/**
 * Rewrite only `coolify.v4.version`. Helper, Realtime, Sentinel and the whole
 * traefik map must survive: CheckHelperImageJob and the Traefik checks in
 * ProxyStatusChangedNotification read them from this same file.
 *
 * @param {string} builtVersionsJson contents of versions.json from the built tree
 * @param {string} version e.g. `4.2.0.1`
 * @returns {string}
 */
export function deriveVersionsJson(builtVersionsJson, version) {
  const data = JSON.parse(builtVersionsJson)
  if (!data.coolify?.v4) {
    throw new Error('versions.json: coolify.v4 missing')
  }
  data.coolify.v4.version = version
  return `${JSON.stringify(data, null, 4)}\n`
}

const CONSTANTS_VERSION = /('coolify'\s*=>\s*\[[\s\S]*?'version'\s*=>\s*')([^']+)(')/

/**
 * @param {string} constantsPhp contents of config/constants.php
 * @param {string} version
 * @returns {string}
 */
export function patchConstantsVersion(constantsPhp, version) {
  if (!CONSTANTS_VERSION.test(constantsPhp)) {
    throw new Error('constants.php: coolify version line not found')
  }
  return constantsPhp.replace(CONSTANTS_VERSION, `$1${version}$3`)
}

const CDN_URL = /\$\{?CDN\}?\/([A-Za-z0-9._${}/-]+)/g

/**
 * Extract every path the upgrade script fetches from the CDN, so the release
 * gate can assert each one maps to a published artifact.
 *
 * @param {string} upgradeScript
 * @returns {string[]} unique path suffixes, in first-seen order
 */
export function parseCdnUrls(upgradeScript) {
  const seen = new Set()
  for (const m of upgradeScript.matchAll(CDN_URL)) {
    seen.add(m[1].replace(/["']$/, ''))
  }
  return [...seen]
}
