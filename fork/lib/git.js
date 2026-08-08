import { execFileSync } from 'node:child_process'

/**
 * Run a git command and return its trimmed stdout.
 *
 * @param {string[]} args arguments passed to `git`, e.g. `['ls-files', '-s']`
 * @param {string} cwd working directory git runs in
 * @returns {string} trimmed stdout
 */
export function git(args, cwd) {
  try {
    return execFileSync('git', args, { cwd, encoding: 'utf8' }).trim()
  } catch (err) {
    const stderr = err.stderr ? err.stderr.toString() : ''
    throw new Error(`git ${args.join(' ')} failed: ${err.message}\n${stderr}`)
  }
}
