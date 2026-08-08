export const UPSTREAM_REPO = 'coollabsio/coolify'
export const FORK_REPO = 'nikrabaev/coolify'

const GITHUB_URL =
  /^(?:git@github\.com:|ssh:\/\/git@github\.com\/|https:\/\/(?:[^@/]+(?::[^@/]*)?@)?github\.com\/)([^/]+\/[^/]+?)(?:\.git)?$/

/**
 * @param {string} url
 * @returns {string|null} `owner/repo`, lowercased, or null if not a GitHub URL
 */
export function normalizeRemoteUrl(url) {
  const m = GITHUB_URL.exec(url.trim())
  return m ? m[1].toLowerCase() : null
}

/**
 * Resolve remote *names* by URL. Names are environment-dependent —
 * actions/checkout makes `origin` the fork, while a contributor clone often
 * makes `origin` upstream — so nothing may rely on them.
 *
 * @param {string[]} lines output of `git remote -v`, split by newline
 * @returns {{ upstream: string|null, fork: string|null }}
 */
export function resolveRemotes(lines) {
  const namesByRepo = new Map()
  for (const line of lines) {
    const m = /^(\S+)\s+(\S+)\s+\((fetch|push)\)$/.exec(line.trim())
    if (!m || m[3] !== 'fetch') {
      continue
    }
    const repo = normalizeRemoteUrl(m[2])
    if (!repo) {
      continue
    }
    if (!namesByRepo.has(repo)) {
      namesByRepo.set(repo, new Set())
    }
    namesByRepo.get(repo).add(m[1])
  }

  const pick = (repo) => {
    const names = namesByRepo.get(repo)
    if (!names || names.size === 0) {
      return null
    }
    if (names.size > 1) {
      throw new Error(`ambiguous: remotes ${[...names].join(', ')} all point at ${repo}`)
    }
    return [...names][0]
  }

  return { upstream: pick(UPSTREAM_REPO), fork: pick(FORK_REPO) }
}
