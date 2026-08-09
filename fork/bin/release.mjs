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
const VERSION_KEY = /'version'\s*=>\s*'([^']*)'/yd

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
 * Find the `'version' => '...'` entry that sits directly inside the
 * `'coolify' => [ ... ]` array — not one nested inside a sub-array of it,
 * and not one that merely looks that way because it's sitting inside a
 * string literal or a comment.
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
 *   version value (excluding the surrounding quotes), or null if no
 *   top-level version entry exists inside the coolify array.
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
      const [start, end] = m.indices[1]
      return { start, end }
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
