import { createHash } from 'node:crypto'
import { lstatSync, readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative, sep } from 'node:path'

const HASHED_DIRS = ['fork']
const HASHED_WORKFLOW_DIR = '.github/workflows'
const HASHED_WORKFLOW_PREFIX = 'fork-'

function sha256(value) {
  return createHash('sha256').update(value).digest('hex')
}

function walk(dir, root, out) {
  for (const name of readdirSync(dir).sort()) {
    const full = join(dir, name)
    const stat = lstatSync(full)
    if (stat.isSymbolicLink()) {
      throw new Error(
        `fingerprint: symlinks are not permitted inside the fingerprinted tree: ${relative(root, full).split(sep).join('/')}`
      )
    }
    if (stat.isDirectory()) {
      walk(full, root, out)
    } else {
      out.set(relative(root, full).split(sep).join('/'), readFileSync(full, 'utf8'))
    }
  }
}

function dirExists(path) {
  try {
    return statSync(path).isDirectory()
  } catch (err) {
    if (err.code === 'ENOENT') {
      return false
    }
    throw err
  }
}

/**
 * Collect the human-authored tooling files that feed the fingerprint.
 *
 * Deliberately excludes `state/` — that is where CI writes. Hashing anything
 * CI writes makes every successful run perturb its own fingerprint, so the
 * next scheduled run rebuilds an identical release, forever.
 *
 * @param {string} root repository root
 * @returns {Map<string,string>} relative path -> contents
 */
export function collectToolingFiles(root) {
  const files = new Map()
  for (const dir of HASHED_DIRS) {
    const full = join(root, dir)
    if (dirExists(full)) {
      walk(full, root, files)
    }
  }
  if (dirExists(join(root, HASHED_WORKFLOW_DIR))) {
    for (const name of readdirSync(join(root, HASHED_WORKFLOW_DIR)).sort()) {
      if (name.startsWith(HASHED_WORKFLOW_PREFIX) && name.endsWith('.yml')) {
        const rel = `${HASHED_WORKFLOW_DIR}/${name}`
        files.set(rel, readFileSync(join(root, rel), 'utf8'))
      }
    }
  }
  return files
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
