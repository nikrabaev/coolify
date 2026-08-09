import { mkdirSync, readFileSync, renameSync, writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { randomBytes } from 'node:crypto'

/**
 * Read the last recorded successful build.
 *
 * Unparseable content — most commonly a truncated write left behind by a CI
 * runner killed or cancelled mid-write, before `writeLastSuccess` was made
 * atomic — is treated the same as a missing file: it returns `null` rather
 * than throwing. `null` means "no prior success", which drives
 * `needsRebuild` to true — the safe direction, since the alternative is an
 * uncaught `SyntaxError` that wedges every subsequent scheduled run until a
 * human deletes the file. The failure is still logged via `console.warn` so
 * it is visible in CI logs rather than silently swallowed.
 *
 * @param {string} path
 * @returns {object|null} null when no run has succeeded yet, or the
 *   recorded file could not be parsed
 */
export function readLastSuccess(path) {
  let raw
  try {
    raw = readFileSync(path, 'utf8')
  } catch (err) {
    if (err.code === 'ENOENT') {
      return null
    }
    throw err
  }

  try {
    return JSON.parse(raw)
  } catch (err) {
    console.warn(`state: ${path} is corrupt or unparseable, treating as no prior success (${err.message})`)
    return null
  }
}

/**
 * Write the last-success record atomically: write to a temp file in the
 * same directory, then `renameSync` over the target. A rename within one
 * filesystem is atomic, so a reader (including a concurrently starting CI
 * run) never observes a partially written file, even if this process is
 * killed or cancelled mid-write.
 *
 * @param {string} path
 * @param {{fingerprint: string, version: string}} record
 */
export function writeLastSuccess(path, record) {
  const dir = dirname(path)
  mkdirSync(dir, { recursive: true })
  const tmpPath = join(dir, `.last-success.${process.pid}.${randomBytes(6).toString('hex')}.tmp`)
  writeFileSync(tmpPath, `${JSON.stringify(record, null, 2)}\n`)
  renameSync(tmpPath, path)
}

/**
 * @param {object|null} last
 * @param {string} fingerprint
 * @returns {boolean}
 */
export function needsRebuild(last, fingerprint) {
  return !last || last.fingerprint !== fingerprint
}
