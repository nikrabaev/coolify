import { test } from 'node:test'
import assert from 'node:assert/strict'
import { classifyPrEntries } from '../bin/probe.mjs'
import { buildProposals, composeIssue, isAlreadyFiled } from '../bin/probe-cli.mjs'

const PIN = 'a'.repeat(40)
const MOVED = 'b'.repeat(40)
const entry = { id: 'p', type: 'pr', number: 7, sha: PIN }

test('composeIssue titles a head-moved row and cites both shas without touching the manifest', () => {
  const row = { id: 'p', number: 7, status: 'head-moved' }
  const live = { state: 'open', merged: false, headSha: MOVED }

  const { title, body } = composeIssue(row, entry, live)

  assert.equal(title, 'patches.yaml: PR #7 is head-moved')
  assert.match(body, /pinned: `a{40}`/)
  assert.match(body, /current head: `b{40}`/)
  assert.match(body, /update the `sha` pin in `patches\.yaml` if you accept it/)
  assert.match(body, new RegExp(`compare/${PIN}\\.\\.\\.${MOVED}`, 'i'))
})

test('composeIssue for merged/closed rows asks for a manual removal decision, not a pin update', () => {
  const row = { id: 'p', number: 7, status: 'merged' }
  const live = { state: 'closed', merged: true, headSha: PIN }

  const { body } = composeIssue(row, entry, live)

  assert.match(body, /Decide whether to drop this entry/)
  assert.doesNotMatch(body, /update the `sha` pin/)
})

test('buildProposals skips pinned rows and proposes nothing for an all-pinned manifest', () => {
  const patches = [entry]
  const states = new Map([[7, { state: 'open', merged: false, headSha: PIN }]])
  const report = classifyPrEntries(patches, states)

  assert.deepEqual(buildProposals(patches, report, states), [])
})

test('buildProposals proposes an issue with title and body for a head-moved entry', () => {
  const patches = [entry]
  const states = new Map([[7, { state: 'open', merged: false, headSha: MOVED }]])
  const report = classifyPrEntries(patches, states)

  const proposals = buildProposals(patches, report, states)

  assert.equal(proposals.length, 1)
  assert.equal(proposals[0].id, 'p')
  assert.equal(proposals[0].status, 'head-moved')
  assert.equal(proposals[0].title, 'patches.yaml: PR #7 is head-moved')
  assert.match(proposals[0].body, /Manifest entry `p`/)
})

test('buildProposals proposes one issue per non-pinned entry across a mixed manifest', () => {
  const merged = { id: 'm', type: 'pr', number: 8, sha: PIN }
  const patches = [entry, merged]
  const states = new Map([
    [7, { state: 'open', merged: false, headSha: PIN }],
    [8, { state: 'closed', merged: true, headSha: PIN }],
  ])
  const report = classifyPrEntries(patches, states)

  const proposals = buildProposals(patches, report, states)

  assert.deepEqual(
    proposals.map((p) => [p.id, p.status]),
    [['m', 'merged']],
  )
})

test('isAlreadyFiled matches an exact title only, not a near-miss', () => {
  const title = 'patches.yaml: PR #7 is head-moved'
  const existingTitles = [title]

  assert.equal(isAlreadyFiled(existingTitles, title), true)
  assert.equal(isAlreadyFiled(existingTitles, title.toUpperCase()), false)
  assert.equal(isAlreadyFiled(existingTitles, `${title} `), false)
  // A search-based check (`in:title`) would token-match this as a hit even
  // though it is a different proposal for a different PR; exact equality
  // must not.
  assert.equal(isAlreadyFiled(existingTitles, 'patches.yaml: PR #7 is head-moved and more'), false)
  assert.equal(isAlreadyFiled(existingTitles, 'PR #7 is head-moved'), false)
})

test('isAlreadyFiled round-trips a title containing both `:` and `#`', () => {
  const { title } = composeIssue(
    { id: 'p', number: 7, status: 'head-moved' },
    entry,
    { state: 'open', merged: false, headSha: MOVED },
  )

  assert.equal(title, 'patches.yaml: PR #7 is head-moved')
  assert.equal(isAlreadyFiled([title], title), true)
  assert.equal(isAlreadyFiled(['some other title'], title), false)
})
