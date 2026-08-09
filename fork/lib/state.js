import { mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import { dirname } from 'node:path'

/**
 * @param {string} path
 * @returns {object|null} null when no run has succeeded yet
 */
export function readLastSuccess(path) {
  try {
    return JSON.parse(readFileSync(path, 'utf8'))
  } catch (err) {
    if (err.code === 'ENOENT') {
      return null
    }
    throw err
  }
}

/**
 * @param {string} path
 * @param {{fingerprint: string, version: string}} record
 */
export function writeLastSuccess(path, record) {
  mkdirSync(dirname(path), { recursive: true })
  writeFileSync(path, `${JSON.stringify(record, null, 2)}\n`)
}

/**
 * @param {object|null} last
 * @param {string} fingerprint
 * @returns {boolean}
 */
export function needsRebuild(last, fingerprint) {
  return !last || last.fingerprint !== fingerprint
}
