const UPSTREAM_TAG = /^v(\d+\.\d+\.\d+)$/
const FORK_TAG = /^(\d+\.\d+\.\d+)\.(\d+)$/

/**
 * @param {string} tag an upstream release tag such as `v4.2.0`
 * @returns {string} the bare version, `4.2.0`
 */
export function baseVersionFromTag(tag) {
  const m = UPSTREAM_TAG.exec(tag)
  if (!m) {
    throw new Error(`not an upstream release tag: ${tag}`)
  }
  return m[1]
}

/**
 * Allocate the next fork version for a base tag. Existing fork tags are the
 * allocation record — there is no separate counter to drift.
 *
 * @param {string} baseTag upstream tag, e.g. `v4.2.0`
 * @param {string[]} existingTags every tag in the fork repository
 * @returns {string} e.g. `4.2.0.2`
 */
export function allocateVersion(baseTag, existingTags) {
  const base = baseVersionFromTag(baseTag)
  let highest = 0
  for (const tag of existingTags) {
    const m = FORK_TAG.exec(tag)
    if (m && m[1] === base) {
      highest = Math.max(highest, Number(m[2]))
    }
  }
  return `${base}.${highest + 1}`
}
