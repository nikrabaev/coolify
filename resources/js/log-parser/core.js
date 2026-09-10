/**
 * Log parser core: compiles preset configs and parses single log lines.
 *
 * Pure and DOM-free so it can be unit tested with `node --test`.
 * The config schema is validated server-side by App\Support\LogParserConfig;
 * everything here is still defensive because a preset may be edited by hand.
 */

export const LEVELS = ['error', 'warning', 'debug', 'info'];

const RESERVED_GROUPS = new Set(['time', 'level', 'msg', 'message', 'json', 'rest']);

const BUILTIN_LEVELS = {
    fatal: 'error', panic: 'error', emerg: 'error', emergency: 'error', alert: 'error',
    crit: 'error', critical: 'error', error: 'error', err: 'error',
    warn: 'warning', warning: 'warning', wrn: 'warning',
    notice: 'info', info: 'info', inf: 'info', information: 'info',
    debug: 'debug', dbg: 'debug', trace: 'debug', verbose: 'debug', silly: 'debug',
};

// pino / bunyan numeric levels
const NUMERIC_LEVELS = { 10: 'debug', 20: 'debug', 30: 'info', 40: 'warning', 50: 'error', 60: 'error' };

export const MAX_HOOK_ERRORS = 20;

const CACHE_LIMIT = 16;
const compiledCache = new Map();

/**
 * Compile a preset config (JSON string). Results are memoised per source string,
 * so re-rendering the same logs panel never recompiles regexes or hooks.
 *
 * @param {string} source
 * @returns {{ ok: boolean, error: string|null, preset: object|null }}
 */
export function compilePreset(source) {
    if (typeof source !== 'string' || source.trim() === '') {
        return { ok: false, error: 'Empty config.', preset: null };
    }

    const cached = compiledCache.get(source);
    if (cached) {
        return cached;
    }

    let result;
    try {
        result = { ok: true, error: null, preset: buildPreset(JSON.parse(source)) };
    } catch (error) {
        result = { ok: false, error: error instanceof Error ? error.message : String(error), preset: null };
    }

    if (compiledCache.size >= CACHE_LIMIT) {
        compiledCache.delete(compiledCache.keys().next().value);
    }
    compiledCache.set(source, result);

    return result;
}

function buildPreset(config) {
    if (!isPlainObject(config)) {
        throw new Error('Config must be a JSON object.');
    }

    const format = config.format ?? 'regex';
    if (format !== 'regex' && format !== 'json') {
        throw new Error(`Unknown format "${format}".`);
    }

    let regex = null;
    if (format === 'regex') {
        if (typeof config.pattern !== 'string' || config.pattern === '') {
            throw new Error('pattern is required when format is "regex".');
        }
        const flags = typeof config.flags === 'string' ? config.flags.replace(/[^isu]/g, '') : '';
        regex = new RegExp(config.pattern, flags);
    }

    const levels = new Map();
    if (isPlainObject(config.levels)) {
        for (const [raw, level] of Object.entries(config.levels)) {
            if (LEVELS.includes(level)) {
                levels.set(raw, level);
            }
        }
        for (const [raw, level] of [...levels]) {
            if (!levels.has(raw.toUpperCase())) {
                levels.set(raw.toUpperCase(), level);
            }
        }
    }

    const jsonOptions = isPlainObject(config.json) ? config.json : {};
    const displayOptions = isPlainObject(config.display) ? config.display : {};

    const preset = {
        config,
        format,
        regex,
        levels,
        defaultLevel: LEVELS.includes(config.defaultLevel) ? config.defaultLevel : 'info',
        unmatched: config.unmatched === 'inherit' ? 'inherit' : 'raw',
        levelKey: nonEmptyString(config.levelKey, 'level'),
        messageKey: nonEmptyString(config.messageKey, 'msg'),
        timeKey: nonEmptyString(config.timeKey, 'time'),
        json: {
            prettify: jsonOptions.prettify !== false,
            collapsed: jsonOptions.collapsed !== false,
            maxDepth: clampInt(jsonOptions.maxDepth, 1, 20, 8),
            previewChars: clampInt(jsonOptions.previewChars, 0, 1000, 160),
            hiddenKeys: Array.isArray(jsonOptions.hiddenKeys)
                ? jsonOptions.hiddenKeys.filter((key) => typeof key === 'string' && key !== '')
                : [],
        },
        display: {
            showTime: displayOptions.showTime !== false,
            timeFormat: displayOptions.timeFormat === 'hms' ? 'hms' : 'raw',
            levelBadge: displayOptions.levelBadge !== false,
            extraFields: displayOptions.extraFields !== false,
        },
        hook: null,
        hookErrors: 0,
    };

    // In json mode the extracted keys are already shown in the entry header.
    preset.detailHiddenKeys = format === 'json'
        ? [...preset.json.hiddenKeys, preset.levelKey, preset.messageKey, preset.timeKey]
        : preset.json.hiddenKeys;

    if (typeof config.hook === 'string' && config.hook.trim() !== '') {
        // Admin-authored code (see LogParserPresetPolicy); compiled once per preset.
        const hook = new Function(`"use strict"; return (${config.hook});`)();
        if (typeof hook !== 'function') {
            throw new Error('hook must evaluate to a function.');
        }
        preset.hook = hook;
    }

    return preset;
}

/**
 * Map a raw level (e.g. "WARN", 40) onto one of LEVELS.
 */
export function normalizeLevel(preset, raw) {
    if (raw === undefined || raw === null || raw === '') {
        return preset.defaultLevel;
    }

    const key = String(raw).trim();
    const mapped = preset.levels.get(key) ?? preset.levels.get(key.toUpperCase());
    if (mapped) {
        return mapped;
    }

    if (LEVELS.includes(raw)) {
        return raw;
    }

    return BUILTIN_LEVELS[key.toLowerCase()] ?? NUMERIC_LEVELS[key] ?? preset.defaultLevel;
}

/**
 * Parse one log line (without the Docker timestamp).
 *
 * @returns {null|{level: string, levelRaw: string, time: string, message: string,
 *           fields: Record<string, string>, json: string|null, data?: unknown}}
 *          null when the line does not match and must be rendered as-is.
 */
export function parseLine(preset, text) {
    if (typeof text !== 'string' || text === '') {
        return null;
    }

    if (preset.hook && preset.hookErrors < MAX_HOOK_ERRORS) {
        try {
            const result = preset.hook(text, {
                config: preset.config,
                defaultParse: (line) => defaultParse(preset, String(line)),
                normalizeLevel: (raw) => normalizeLevel(preset, raw),
            });

            return result ? finalizeHookEntry(preset, result) : null;
        } catch (error) {
            preset.hookErrors += 1;
            if (preset.hookErrors === MAX_HOOK_ERRORS) {
                console.warn('[log-parser] hook disabled after repeated errors; falling back to the pattern.', error);
            }
        }
    }

    return defaultParse(preset, text);
}

function defaultParse(preset, text) {
    try {
        return preset.format === 'json' ? parseJsonLine(preset, text) : parseRegexLine(preset, text);
    } catch {
        return null;
    }
}

function parseRegexLine(preset, text) {
    const match = preset.regex.exec(text);
    if (!match) {
        return null;
    }

    const groups = match.groups ?? {};
    const fields = {};
    for (const [name, value] of Object.entries(groups)) {
        if (value !== undefined && value !== '' && !RESERVED_GROUPS.has(name)) {
            fields[name] = value;
        }
    }

    const levelRaw = groups.level ?? '';
    const jsonText = groups.json ?? groups.rest;

    return {
        level: normalizeLevel(preset, levelRaw),
        levelRaw,
        time: groups.time ?? '',
        message: groups.msg ?? groups.message ?? '',
        fields,
        json: jsonText !== undefined && jsonText.trim() !== '' ? jsonText.trim() : null,
    };
}

function parseJsonLine(preset, text) {
    const trimmed = text.trim();
    if (trimmed.charCodeAt(0) !== 123 /* { */) {
        return null;
    }

    const object = JSON.parse(trimmed);
    if (!isPlainObject(object)) {
        return null;
    }

    const levelRaw = getPath(object, preset.levelKey);
    const message = getPath(object, preset.messageKey);
    const time = getPath(object, preset.timeKey);

    return {
        level: normalizeLevel(preset, levelRaw),
        levelRaw: levelRaw === undefined || levelRaw === null ? '' : String(levelRaw),
        time: time === undefined || time === null ? '' : String(time),
        message: stringify(message),
        fields: {},
        // Keep the raw text: details are re-parsed lazily on expand, which keeps
        // memory flat for tens of thousands of collapsed lines.
        json: trimmed,
    };
}

function finalizeHookEntry(preset, result) {
    if (typeof result !== 'object') {
        return null;
    }

    const fields = {};
    if (isPlainObject(result.fields)) {
        for (const [name, value] of Object.entries(result.fields)) {
            if (value !== undefined && value !== null) {
                fields[name] = stringify(value);
            }
        }
    }

    const levelRaw = result.levelRaw ?? result.level;
    const entry = {
        level: normalizeLevel(preset, levelRaw),
        levelRaw: levelRaw === undefined || levelRaw === null ? '' : String(levelRaw),
        time: result.time === undefined || result.time === null ? '' : String(result.time),
        message: stringify(result.message ?? result.msg),
        fields,
        json: typeof result.json === 'string' && result.json.trim() !== '' ? result.json.trim() : null,
    };

    if (result.json !== undefined && result.json !== null && typeof result.json !== 'string') {
        entry.data = result.json;
    }

    return entry;
}

/**
 * Parse every non-empty line of a sample (used by the preset editor preview).
 */
export function testPreset(source, sample) {
    const compiled = compilePreset(source);
    if (!compiled.ok) {
        return { ...compiled, results: [] };
    }

    const results = String(sample ?? '')
        .split(/\r?\n/)
        .filter((line) => line.trim() !== '')
        .map((line) => ({ line, entry: parseLine(compiled.preset, line) }));

    return { ...compiled, results };
}

export function formatTime(value, format = 'raw') {
    if (value === undefined || value === null || value === '') {
        return '';
    }
    if (format !== 'hms') {
        return String(value);
    }

    const text = String(value);
    let date;
    if (/^\d+(\.\d+)?$/.test(text)) {
        const number = Number(text);
        date = new Date(number < 1e11 ? number * 1000 : number);
    } else {
        date = new Date(text);
    }

    if (Number.isNaN(date.getTime())) {
        return text;
    }

    const pad = (number, width = 2) => String(number).padStart(width, '0');

    return `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}.${pad(date.getMilliseconds(), 3)}`;
}

/**
 * Small non-cryptographic hash (FNV-1a) used to detect config changes cheaply.
 */
export function hashString(value) {
    let hash = 0x811c9dc5;
    for (let index = 0; index < value.length; index += 1) {
        hash ^= value.charCodeAt(index);
        hash = Math.imul(hash, 0x01000193);
    }

    return (hash >>> 0).toString(36);
}

export function getPath(object, path) {
    if (!isPlainObject(object)) {
        return undefined;
    }
    if (Object.hasOwn(object, path)) {
        return object[path];
    }

    let current = object;
    for (const segment of path.split('.')) {
        if (!isPlainObject(current) || !Object.hasOwn(current, segment)) {
            return undefined;
        }
        current = current[segment];
    }

    return current;
}

function stringify(value) {
    if (value === undefined || value === null) {
        return '';
    }
    if (typeof value === 'string') {
        return value;
    }
    if (typeof value === 'object') {
        try {
            return JSON.stringify(value);
        } catch {
            return String(value);
        }
    }

    return String(value);
}

function isPlainObject(value) {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function nonEmptyString(value, fallback) {
    return typeof value === 'string' && value !== '' ? value : fallback;
}

function clampInt(value, min, max, fallback) {
    return Number.isInteger(value) ? Math.min(max, Math.max(min, value)) : fallback;
}
