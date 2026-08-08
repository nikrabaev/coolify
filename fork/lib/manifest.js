import { parse } from 'yaml'

const FULL_SHA = /^[0-9a-f]{40}$/

/**
 * Parse and validate patches.yaml.
 *
 * The mandatory `sha` pin on pr entries is a security control, not a
 * convenience: privileged builds merge the pin, never the live PR head, so a
 * contributor force-push cannot introduce unreviewed code into a release.
 *
 * @param {string} text
 * @returns {{ base: string, patches: object[] }}
 */
export function loadManifest(text) {
  const raw = parse(text)
  if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
    throw new Error('manifest: top level must be a mapping')
  }
  const patches = raw.patches ?? []
  if (!Array.isArray(patches)) {
    throw new Error('manifest: patches must be a list')
  }

  const seen = new Set()
  for (const entry of patches) {
    if (!entry || typeof entry !== 'object') {
      throw new Error('manifest: each patch must be a mapping')
    }
    const { id } = entry
    if (typeof id !== 'string' || id === '') {
      throw new Error('manifest: every patch needs a non-empty id')
    }
    if (seen.has(id)) {
      throw new Error(`manifest: duplicate id ${id}`)
    }
    seen.add(id)

    if (entry.type === 'pr') {
      if (!Number.isInteger(entry.number)) {
        throw new Error(`manifest: ${id}: pr entry needs an integer number`)
      }
      if (typeof entry.sha !== 'string' || !FULL_SHA.test(entry.sha)) {
        throw new Error(`manifest: ${id}: pr entry needs a full 40-character sha pin`)
      }
    } else if (entry.type === 'branch') {
      if (typeof entry.ref !== 'string' || entry.ref === '') {
        throw new Error(`manifest: ${id}: branch entry needs a ref`)
      }
    } else {
      throw new Error(`manifest: ${id}: unknown type ${entry.type}`)
    }
  }

  return { base: raw.base ?? 'latest-tag', patches }
}
