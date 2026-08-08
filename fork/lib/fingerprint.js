import { createHash } from 'node:crypto'
import { git } from './git.js'

const HASHED_DIR = 'fork'
const HASHED_WORKFLOW_DIR = '.github/workflows'
const HASHED_WORKFLOW_PREFIX = 'fork-'
const HASHED_WORKFLOW_SUFFIX = '.yml'
const SYMLINK_MODE = '120000'

// `git ls-files -s` output: "<mode> <objectSha> <stage>\t<path>"
const LS_FILES_LINE = /^(\d+) ([0-9a-f]+) (\d)\t(.+)$/

function sha256(value) {
  return createHash('sha256').update(value).digest('hex')
}

function isHashedWorkflowFile(path) {
  if (!path.startsWith(`${HASHED_WORKFLOW_DIR}/`)) {
    return false
  }
  const name = path.slice(HASHED_WORKFLOW_DIR.length + 1)
  return !name.includes('/') && name.startsWith(HASHED_WORKFLOW_PREFIX) && name.endsWith(HASHED_WORKFLOW_SUFFIX)
}

/**
 * Collect the human-authored tooling files that feed the fingerprint.
 *
 * Sourced from `git ls-files` rather than a filesystem walk. Git tracks a
 * symlink as a `120000`-mode entry whose blob content is the *target path
 * string*, and it never follows the link while listing — so there is no
 * traversal for a symlink to escape through in the first place. A missing
 * hashed directory needs no special handling either: git simply reports no
 * entries for a pathspec that matches nothing.
 *
 * Deliberately excludes `state/` — that is where CI writes. Hashing anything
 * CI writes makes every successful run perturb its own fingerprint, so the
 * next scheduled run rebuilds an identical release, forever.
 *
 * Note: blob SHAs reflect committed and staged content (Git index), not
 * working-tree bytes. This is intentional: CI fingerprints a clean checkout
 * where index matches working tree. Locally, unstaged edits to hashed files
 * won't affect the fingerprint until staged.
 *
 * @param {string} root repository root
 * @returns {Map<string,string>} relative path -> git blob SHA (an opaque
 *   content hash; `computeFingerprint` never needs the file's bytes)
 */
export function collectToolingFiles(root) {
  const output = git(['ls-files', '-s', '--', HASHED_DIR, HASHED_WORKFLOW_DIR], root)
  const entries = []
  for (const line of output ? output.split('\n') : []) {
    const match = LS_FILES_LINE.exec(line)
    if (!match) {
      continue
    }
    const [, mode, blobSha, , path] = match
    const isForkFile = path === HASHED_DIR || path.startsWith(`${HASHED_DIR}/`)
    if (!isForkFile && !isHashedWorkflowFile(path)) {
      continue
    }
    if (mode === SYMLINK_MODE) {
      throw new Error(`fingerprint: symlinks are not permitted inside the fingerprinted tree: ${path}`)
    }
    entries.push([path, blobSha])
  }
  entries.sort((a, b) => a[0].localeCompare(b[0]))
  return new Map(entries)
}

/**
 * @param {{ baseTag: string, baseSha: string, manifestText: string,
 *           branchShas: Map<string,string>, toolingFiles: Map<string,string> }} inputs
 * @returns {string} 64-char hex digest
 */
export function computeFingerprint({ baseTag, baseSha, manifestText, branchShas, toolingFiles }) {
  const h = createHash('sha256')
  h.update('coolify-fork-fingerprint/v1\n')
  h.update(`base\t${baseTag}\t${baseSha}\n`)
  h.update(`manifest\t${sha256(manifestText)}\n`)
  for (const [id, sha] of [...branchShas].sort((a, b) => a[0].localeCompare(b[0]))) {
    h.update(`branch\t${sha256(id)}\t${sha}\n`)
  }
  for (const [path, content] of [...toolingFiles].sort((a, b) => a[0].localeCompare(b[0]))) {
    h.update(`tool\t${sha256(path)}\t${sha256(content)}\n`)
  }
  return h.digest('hex')
}
