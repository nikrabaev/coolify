#!/usr/bin/env node

/**
 * Rewrite only `coolify.v4.version`. Helper, Realtime, Sentinel and the whole
 * traefik map must survive: CheckHelperImageJob and the Traefik checks in
 * ProxyStatusChangedNotification read them from this same file.
 *
 * @param {string} builtVersionsJson contents of versions.json from the built tree
 * @param {string} version e.g. `4.2.0.1`
 * @returns {string}
 */
export function deriveVersionsJson(builtVersionsJson, version) {
  const data = JSON.parse(builtVersionsJson)
  if (!data.coolify?.v4) {
    throw new Error('versions.json: coolify.v4 missing')
  }
  data.coolify.v4.version = version
  return `${JSON.stringify(data, null, 4)}\n`
}

const COOLIFY_ARRAY_OPEN = /'coolify'\s*=>\s*\[/g
const VERSION_KEY = /'version'\s*=>\s*/y

/**
 * Build a same-length copy of `php` where the interior of every string
 * literal (single- or double-quoted) and every comment (`//`, `#`, or
 * `/* ... *\/`) is blanked out with spaces. Structural bytes — brackets,
 * the quote characters that delimit a string, `=>`, commas, whitespace
 * between tokens — are copied through unchanged, so every surviving byte
 * in the mask sits at the exact same offset as in `php`.
 *
 * This is used purely as an oracle, never as a source of text: a `[` or
 * `]` inside a string ("bracket opens here [") or a comment no longer
 * shows up as a bracket in the mask, so a depth scan over the mask can't
 * be fooled by it. Likewise, a quote character only survives in the mask
 * at a position where the source has a *real*, top-level quote — an
 * embedded quote inside a string's content (escaped or not) or inside a
 * comment is blanked along with the rest of that region. So comparing
 * `mask[i]` against `php[i]` at a candidate match position tells you
 * whether that position is genuine code or buried inside a string/comment,
 * without ever needing to read matched text back out of the mask itself
 * (the mask has erased it).
 *
 * Escapes: both single- and double-quoted strings treat a backslash as
 * escaping whatever character follows it (covers `\'`/`\\` and `\"`/`\\`),
 * so an escaped quote never terminates the string early.
 *
 * Out of scope: PHP heredoc/nowdoc (`<<<EOT ... EOT`) are not recognized.
 * They don't appear in config/constants.php; if that ever changes, this
 * function needs updating or it will misread the heredoc body as code.
 *
 * @param {string} php
 * @returns {string}
 */
function maskStringsAndComments(php) {
  const out = php.split('')
  let i = 0

  while (i < php.length) {
    const ch = php[i]

    if (ch === "'" || ch === '"') {
      const quote = ch
      i++
      while (i < php.length && php[i] !== quote) {
        if (php[i] === '\\' && i + 1 < php.length) {
          out[i] = ' '
          out[i + 1] = ' '
          i += 2
          continue
        }
        out[i] = ' '
        i++
      }
      if (i < php.length) {
        i++ // consume the closing quote, leave it unmasked
      }
      continue
    }

    if (ch === '/' && php[i + 1] === '/') {
      while (i < php.length && php[i] !== '\n') {
        out[i] = ' '
        i++
      }
      continue
    }

    if (ch === '#') {
      while (i < php.length && php[i] !== '\n') {
        out[i] = ' '
        i++
      }
      continue
    }

    if (ch === '/' && php[i + 1] === '*') {
      out[i] = ' '
      out[i + 1] = ' '
      i += 2
      while (i < php.length && !(php[i] === '*' && php[i + 1] === '/')) {
        out[i] = ' '
        i++
      }
      if (i < php.length) {
        out[i] = ' '
        out[i + 1] = ' '
        i += 2
      }
      continue
    }

    i++
  }

  return out.join('')
}

/**
 * Scan forward from `start` (a byte offset immediately after a top-level
 * `'version' =>`) to the offset of the statement's terminating comma — the
 * comma that ends this array entry, at the same bracket depth the value
 * started at (relative depth 0). Falls back to the offset of the enclosing
 * array's closing `]` when there is no trailing comma; that shouldn't
 * happen for a Laravel config array (they always trail with a comma), but
 * it keeps the scan from running past the array unbounded.
 *
 * `mask` (see `maskStringsAndComments`) is used throughout so a comma or
 * bracket embedded in a string literal or a trailing comment — e.g.
 * `env('X') ?: '4.3.2', // note, with a comma` — can't be mistaken for the
 * real terminator.
 *
 * @param {string} mask
 * @param {number} start
 * @returns {number}
 */
function findStatementEnd(mask, start) {
  let depth = 0
  for (let i = start; i < mask.length; i++) {
    const ch = mask[i]
    if (ch === '[') {
      depth++
      continue
    }
    if (ch === ']') {
      if (depth === 0) {
        return i
      }
      depth--
      continue
    }
    if (ch === ',' && depth === 0) {
      return i
    }
  }
  return mask.length
}

/**
 * Find the last single-quoted string literal within `[start, end)` — the
 * span of a `'version' => ...` statement. For the plain
 * `'version' => '4.2.0'` shape this is the only literal in the span. For
 * upstream's newer `'version' => env('COOLIFY_VERSION') ?: '4.3.2'` shape
 * it's the fallback literal after `?:`, which is exactly the value that
 * takes effect wherever this config is read through an empty/stubbed
 * `env()` — including the release pipeline's own version-stamp check,
 * `php bootstrap/getVersion.php`. So the fallback literal, not the `env()`
 * lookup, is the correct patch target in both shapes.
 *
 * Only `'`-delimited spans survive in `mask` at their true open/close
 * offsets (see `maskStringsAndComments`) — interior bytes of every string
 * and comment are blanked. Pairing up surviving `'` positions therefore
 * finds real single-quoted literals only, never text that merely looks
 * like one inside a `"..."` string or a comment.
 *
 * @param {string} mask
 * @param {number} start
 * @param {number} end
 * @returns {{start: number, end: number} | null} offsets of the literal's
 *   contents (excluding the quotes), or null if the span has no
 *   single-quoted literal.
 */
function findLastStringLiteral(mask, start, end) {
  let open = -1
  let lastOpen = -1
  let lastClose = -1
  for (let i = start; i < end; i++) {
    if (mask[i] !== "'") {
      continue
    }
    if (open === -1) {
      open = i
    } else {
      lastOpen = open
      lastClose = i
      open = -1
    }
  }
  return lastOpen === -1 ? null : { start: lastOpen + 1, end: lastClose }
}

/**
 * Find the `'version' => ...` entry that sits directly inside the
 * `'coolify' => [ ... ]` array — not one nested inside a sub-array of it,
 * and not one that merely looks that way because it's sitting inside a
 * string literal or a comment — and return the offsets of the last
 * single-quoted literal in its value expression.
 *
 * Upstream has used two shapes for this line across releases:
 *   'version' => '4.2.0',
 *   'version' => env('COOLIFY_VERSION') ?: '4.3.2',
 * Locating "the last string literal before the terminating comma" handles
 * both: for the plain shape that literal is the version itself; for the
 * `env() ?:` shape it's the fallback literal, which is what a stubbed
 * `env()` (as used by the release pipeline's verification step) actually
 * evaluates to.
 *
 * A plain non-greedy regex can't express "at this nesting depth", so this
 * walks the block character by character tracking bracket depth and only
 * attempts the version match while depth === 1 (i.e. still directly inside
 * the coolify array, not inside one of its nested arrays). Depth is
 * tracked against a masked copy of the source (see
 * `maskStringsAndComments`) so that `[`/`]` characters that merely appear
 * inside a string value or a comment — e.g. `'note' => 'bracket opens
 * here ['` — don't perturb the count.
 *
 * @param {string} constantsPhp
 * @returns {{start: number, end: number} | null} byte offsets of the
 *   version literal (excluding the surrounding quotes), or null if no
 *   top-level version entry (with a patchable literal) exists inside the
 *   coolify array.
 */
function findTopLevelCoolifyVersion(constantsPhp) {
  const mask = maskStringsAndComments(constantsPhp)

  let openMatch = null
  COOLIFY_ARRAY_OPEN.lastIndex = 0
  for (const m of constantsPhp.matchAll(COOLIFY_ARRAY_OPEN)) {
    // The masking pass blanks the *interior* of every string, so the word
    // "coolify" itself is always masked away — even for the genuine
    // top-level key. What survives unmasked is the opening quote: it's
    // only left untouched when it's a real, unnested string delimiter.
    // An occurrence of this same literal text sitting inside a comment or
    // inside another string's content has that leading quote blanked
    // along with everything else in its region, so it fails this check.
    if (mask[m.index] === "'") {
      openMatch = m
      break
    }
  }
  if (!openMatch) {
    return null
  }

  const bodyStart = openMatch.index + openMatch[0].length
  let depth = 1

  for (let i = bodyStart; i < constantsPhp.length && depth > 0; i++) {
    const maskCh = mask[i]
    if (maskCh === '[') {
      depth++
      continue
    }
    if (maskCh === ']') {
      depth--
      continue
    }
    if (depth !== 1) {
      continue
    }
    // Only attempt a key match where the mask agrees with the source —
    // i.e. real code, not the interior of a string or a comment (where a
    // stray `'version' => '...'`-shaped run of text must be ignored).
    if (maskCh !== "'") {
      continue
    }
    VERSION_KEY.lastIndex = i
    const m = VERSION_KEY.exec(constantsPhp)
    if (m && m.index === i) {
      const valueStart = i + m[0].length
      const statementEnd = findStatementEnd(mask, valueStart)
      return findLastStringLiteral(mask, valueStart, statementEnd)
    }
  }

  return null
}

/**
 * @param {string} constantsPhp contents of config/constants.php
 * @param {string} version
 * @returns {string}
 */
export function patchConstantsVersion(constantsPhp, version) {
  const match = findTopLevelCoolifyVersion(constantsPhp)
  if (!match) {
    throw new Error('constants.php: coolify version line not found')
  }
  return constantsPhp.slice(0, match.start) + version + constantsPhp.slice(match.end)
}

const CDN_URL = /\$\{?CDN\}?\/([A-Za-z0-9._${}/-]+)/g

/**
 * Extract every path the upgrade script fetches from the CDN, so the release
 * gate can assert each one maps to a published artifact.
 *
 * This matches the script's literal source text, so it only sees paths written
 * as `$CDN/...` or `${CDN}/...` directly. A path assembled through an
 * intermediate shell variable is NOT detected, and the omission is silent —
 * the gate would then verify fewer artifacts and still pass. `scripts/upgrade.sh`
 * on `patch/fork-cdn-hardening` carries a matching comment warning against that
 * refactor. Revisit this coupling before relying on the gate for new artifacts.
 *
 * @param {string} upgradeScript
 * @returns {string[]} unique path suffixes, in first-seen order
 */
export function parseCdnUrls(upgradeScript) {
  const seen = new Set()
  for (const m of upgradeScript.matchAll(CDN_URL)) {
    seen.add(m[1].replace(/["']$/, ''))
  }
  return [...seen]
}
