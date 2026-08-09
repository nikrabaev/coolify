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

const LS_REMOTE_LINE = /^([0-9a-f]{40})\trefs\/tags\/(.+?)(\^\{\})?$/

/**
 * Select the newest upstream release tag from `git ls-remote --tags <upstream>`.
 *
 * Reads the remote namespace, never local tags: git tags are not namespaced per
 * remote, so the fork's own release tags would otherwise be candidates. The
 * strict three-component pattern excludes both fork tags (`4.2.0.7`) and
 * prereleases (`v4.0.0-beta.474`).
 *
 * Dereferenced entries (`^{}`) win, so annotated tags resolve to their commit.
 *
 * @param {string} lsRemoteOutput
 * @returns {{ tag: string, sha: string }}
 */
export function selectBaseTag(lsRemoteOutput) {
  const shaByTag = new Map()
  for (const line of lsRemoteOutput.split('\n')) {
    const m = LS_REMOTE_LINE.exec(line.trim())
    if (!m || !UPSTREAM_TAG.test(m[2])) {
      continue
    }
    if (m[3] || !shaByTag.has(m[2])) {
      shaByTag.set(m[2], m[1])
    }
  }
  if (shaByTag.size === 0) {
    throw new Error('no upstream v4 release tag found')
  }
  const sorted = [...shaByTag.keys()].sort((a, b) => {
    const pa = baseVersionFromTag(a).split('.').map(Number)
    const pb = baseVersionFromTag(b).split('.').map(Number)
    for (let i = 0; i < 3; i += 1) {
      if (pa[i] !== pb[i]) {
        return pb[i] - pa[i]
      }
    }
    return 0
  })
  return { tag: sorted[0], sha: shaByTag.get(sorted[0]) }
}
