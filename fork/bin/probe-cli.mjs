#!/usr/bin/env node
import { appendFileSync, readFileSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { loadManifest } from '../lib/manifest.js'
import { FORK_REPO, UPSTREAM_REPO } from '../lib/remotes.js'
import { classifyPrEntries } from './probe.mjs'

/**
 * Compose the title and body for a pin-update or lifecycle-change issue.
 *
 * Only called for non-"pinned" rows. The body cites the moved head only as a
 * SHA reported by the GitHub API — nothing from that head is fetched,
 * built, or executed to produce it.
 *
 * @param {{id: string, number: number, status: string}} row a non-"pinned" classified row
 * @param {object} entry the manifest patch entry backing `row`
 * @param {{state: string, merged: boolean, headSha: string}} live
 * @returns {{title: string, body: string}}
 */
export function composeIssue(row, entry, live) {
  const title = `patches.yaml: PR #${row.number} is ${row.status}`
  const body = [
    `Manifest entry \`${row.id}\` tracks upstream PR #${row.number}, now **${row.status}**.`,
    '',
    `- pinned: \`${entry.sha}\``,
    `- current head: \`${live.headSha}\``,
    '',
    row.status === 'head-moved'
      ? 'Review the incremental diff, then update the `sha` pin in `patches.yaml` if you accept it. ' +
        'Nothing from the new head has been fetched, built, or executed.'
      : 'Decide whether to drop this entry from `patches.yaml`. Removals are never automatic.',
    '',
    `Compare: https://github.com/${UPSTREAM_REPO}/compare/${entry.sha}...${live.headSha}`,
  ].join('\n')
  return { title, body }
}

/**
 * Build the list of issues to propose from a classified report: every row
 * whose status is not "pinned" — head-moved, merged, or closed. `patches.yaml`
 * itself is never touched here; each proposal only describes a change for a
 * human to review and apply.
 *
 * @param {object[]} patches manifest patches, for the pinned sha
 * @param {Array<{id: string, number: number, status: string}>} report classifyPrEntries() output
 * @param {Map<number, {state: string, merged: boolean, headSha: string}>} states
 * @returns {Array<{id: string, number: number, status: string, title: string, body: string}>}
 */
export function buildProposals(patches, report, states) {
  const proposals = []
  for (const row of report) {
    if (row.status === 'pinned') {
      continue
    }
    const entry = patches.find((p) => p.id === row.id)
    const live = states.get(row.number)
    const { title, body } = composeIssue(row, entry, live)
    proposals.push({ ...row, title, body })
  }
  return proposals
}

/** Read-only GitHub API call. Nothing fetched here is ever executed. */
function prState(number) {
  const raw = execFileSync(
    'gh',
    ['api', `repos/${UPSTREAM_REPO}/pulls/${number}`,
      '-q', '{state: .state, merged: .merged, headSha: .head.sha}'],
    { encoding: 'utf8' },
  )
  return JSON.parse(raw)
}

/**
 * Number of open `fork-sync`-labeled issues to fetch when checking for
 * duplicates before filing. High enough that a truncated page is
 * exceptional, not routine, for this repo's issue volume.
 */
const ISSUE_LIST_LIMIT = 200

/**
 * Whether `title` exactly matches one of `existingTitles`. Exact string
 * equality only — no substring, case-insensitive, or fuzzy matching — so a
 * title that merely contains another as a substring is never treated as a
 * duplicate.
 *
 * @param {string[]} existingTitles open issue titles already filed
 * @param {string} title candidate proposal title
 * @returns {boolean}
 */
export function isAlreadyFiled(existingTitles, title) {
  return existingTitles.includes(title)
}

/**
 * Fetch titles of open `fork-sync`-labeled issues, for duplicate suppression.
 * Deterministic listing + exact-match comparison, not GitHub's search API:
 * search tokenization cannot be trusted to phrase-match titles containing
 * `:` and `#`, and a search-based miss or false positive on a notification
 * path is not acceptable.
 */
function openForkSyncIssueTitles() {
  const raw = execFileSync(
    'gh',
    ['issue', 'list', '--repo', FORK_REPO, '--label', 'fork-sync', '--state', 'open',
      '--json', 'title', '--limit', String(ISSUE_LIST_LIMIT)],
    { encoding: 'utf8' },
  )
  const issues = JSON.parse(raw)
  if (issues.length === ISSUE_LIST_LIMIT) {
    console.warn(
      `gh issue list returned ${ISSUE_LIST_LIMIT} open fork-sync issues (the --limit); ` +
      'the list may be truncated, so duplicate suppression may miss some titles.',
    )
  }
  return issues.map((issue) => issue.title)
}

/**
 * Idempotently ensure the `fork-sync` label exists. `gh` does not
 * auto-create labels, and issue creation fails outright without it. Attempt
 * creation and swallow an "already exists" failure; anything else is
 * logged, not thrown, because filing issues is best-effort and must never
 * block the pipeline.
 */
function ensureForkSyncLabel() {
  try {
    execFileSync('gh', [
      'label', 'create', 'fork-sync',
      '--repo', FORK_REPO,
      '--description', 'Automated fork-sync notifications',
      '--color', 'ededed',
    ], { encoding: 'utf8', stdio: 'pipe' })
  } catch (error) {
    const message = String(error.stderr ?? error.message ?? error)
    if (!/already exists/i.test(message)) {
      console.warn(`could not ensure the "fork-sync" label exists: ${message}`)
    }
  }
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const manifest = loadManifest(readFileSync('patches.yaml', 'utf8'))

  const states = new Map()
  for (const entry of manifest.patches) {
    if (entry.type === 'pr') {
      states.set(entry.number, prState(entry.number))
    }
  }

  const report = classifyPrEntries(manifest.patches, states)
  const proposals = buildProposals(manifest.patches, report, states)

  ensureForkSyncLabel()
  const existingTitles = openForkSyncIssueTitles()

  for (const { title, body } of proposals) {
    if (isAlreadyFiled(existingTitles, title)) {
      continue
    }
    try {
      execFileSync('gh', [
        'issue', 'create',
        '--repo', FORK_REPO,
        '--title', title,
        '--body', body,
        '--label', 'fork-sync',
      ], { stdio: 'inherit' })
    } catch (error) {
      console.warn(`failed to file issue "${title}": ${error.message}`)
    }
  }

  const out = `report=${JSON.stringify(report)}`
  if (process.env.GITHUB_OUTPUT) {
    appendFileSync(process.env.GITHUB_OUTPUT, `${out}\n`)
  }
  console.log(out)
}
