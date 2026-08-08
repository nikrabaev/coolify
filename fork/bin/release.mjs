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

const COOLIFY_ARRAY_OPEN = /'coolify'\s*=>\s*\[/
const VERSION_KEY = /'version'\s*=>\s*'([^']*)'/yd

/**
 * Find the `'version' => '...'` entry that sits directly inside the
 * `'coolify' => [ ... ]` array — not one nested inside a sub-array of it.
 * A plain non-greedy regex can't express "at this nesting depth", so this
 * walks the block character by character tracking bracket depth and only
 * attempts the version match while depth === 1 (i.e. still directly inside
 * the coolify array, not inside one of its nested arrays).
 *
 * @param {string} constantsPhp
 * @returns {{start: number, end: number} | null} byte offsets of the
 *   version value (excluding the surrounding quotes), or null if no
 *   top-level version entry exists inside the coolify array.
 */
function findTopLevelCoolifyVersion(constantsPhp) {
  const openMatch = COOLIFY_ARRAY_OPEN.exec(constantsPhp)
  if (!openMatch) {
    return null
  }

  const bodyStart = openMatch.index + openMatch[0].length
  let depth = 1

  for (let i = bodyStart; i < constantsPhp.length && depth > 0; i++) {
    const ch = constantsPhp[i]
    if (ch === '[') {
      depth++
      continue
    }
    if (ch === ']') {
      depth--
      continue
    }
    if (depth !== 1) {
      continue
    }
    VERSION_KEY.lastIndex = i
    const m = VERSION_KEY.exec(constantsPhp)
    if (m && m.index === i) {
      const [start, end] = m.indices[1]
      return { start, end }
    }
  }

  return null
}

/**
 * @param {string} constantsPhp contents of config/constants.php
 * @param {string} version
 * @returns {string}
 */
export function patchConstantsVersion(constantsPhp, version) {
  const match = findTopLevelCoolifyVersion(constantsPhp)
  if (!match) {
    throw new Error('constants.php: coolify version line not found')
  }
  return constantsPhp.slice(0, match.start) + version + constantsPhp.slice(match.end)
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
