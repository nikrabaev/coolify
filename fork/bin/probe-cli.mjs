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
 * Whether an open issue with this exact title already exists, so a repeated
 * run never files the same proposal twice.
 */
function issueAlreadyOpen(title) {
  const count = execFileSync(
    'gh',
    ['issue', 'list', '--repo', FORK_REPO, '--state', 'open',
      '--search', `in:title "${title}"`, '--json', 'number', '-q', 'length'],
    { encoding: 'utf8' },
  ).trim()
  return count !== '0'
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

  for (const { title, body } of proposals) {
    if (issueAlreadyOpen(title)) {
      continue
    }
    execFileSync('gh', [
      'issue', 'create',
      '--repo', FORK_REPO,
      '--title', title,
      '--body', body,
      '--label', 'fork-sync',
    ], { stdio: 'inherit' })
  }

  const out = `report=${JSON.stringify(report)}`
  if (process.env.GITHUB_OUTPUT) {
    appendFileSync(process.env.GITHUB_OUTPUT, `${out}\n`)
  }
  console.log(out)
}
