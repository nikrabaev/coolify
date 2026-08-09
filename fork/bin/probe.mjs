#!/usr/bin/env node

/**
 * Classify every pr entry against live upstream state.
 *
 * This runs in the UNPRIVILEGED job: it only reads and describes, and never
 * checks out or executes anything from a moved head.
 *
 * A missing entry in `prStates` throws rather than being treated as
 * "unchanged" — a gap in the API response must never let a merged or closed
 * PR keep being built.
 *
 * @param {object[]} entries manifest patches
 * @param {Map<number, {state: string, merged: boolean, headSha: string}>} prStates
 * @returns {Array<{id: string, number: number, status: string}>}
 */
export function classifyPrEntries(entries, prStates) {
  const out = []
  for (const entry of entries) {
    if (entry.type !== 'pr') {
      continue
    }
    const live = prStates.get(entry.number)
    if (!live) {
      throw new Error(`no state for pr ${entry.number} — refusing to guess`)
    }
    let status
    if (live.merged) {
      status = 'merged'
    } else if (live.state === 'closed') {
      status = 'closed'
    } else if (live.headSha !== entry.sha) {
      status = 'head-moved'
    } else {
      status = 'pinned'
    }
    out.push({ id: entry.id, number: entry.number, status })
  }
  return out
}
