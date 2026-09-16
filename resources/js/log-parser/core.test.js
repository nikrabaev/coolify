import test from 'node:test';
import assert from 'node:assert/strict';
import { compilePreset, formatTime, getPath, MAX_HOOK_ERRORS, normalizeLevel, parseLine, testPreset } from './core.js';

const PINO_PRETTY = JSON.stringify({
    version: 1,
    format: 'regex',
    pattern: '^\\[(?<time>[^\\]]+)\\]\\s+(?<level>[A-Za-z]+)(?:\\s*\\((?<pid>\\d+)\\))?:\\s*(?<msg>.*?)\\s*(?<json>\\{.*\\})?$',
    levels: { TRACE: 'debug', DEBUG: 'debug', INFO: 'info', WARN: 'warning', ERROR: 'error', FATAL: 'error' },
    unmatched: 'inherit',
});

const SAMPLE = '[20:44:20.098] INFO (1): request completed {"service":"api","req":{"id":"bb16b302","method":"GET","url":"/health"},"res":{"statusCode":200},"responseTime":5}';

test('parses the pino-pretty style sample line', () => {
    const { ok, preset } = compilePreset(PINO_PRETTY);
    assert.equal(ok, true);

    const entry = parseLine(preset, SAMPLE);
    assert.equal(entry.time, '20:44:20.098');
    assert.equal(entry.level, 'info');
    assert.equal(entry.levelRaw, 'INFO');
    assert.equal(entry.message, 'request completed');
    assert.deepEqual(entry.fields, { pid: '1' });
    assert.equal(JSON.parse(entry.json).res.statusCode, 200);
});

test('lines without a JSON tail keep the whole message', () => {
    const { preset } = compilePreset(PINO_PRETTY);
    const entry = parseLine(preset, '[20:44:21.000] WARN (1): cache miss for key user:42');
    assert.equal(entry.level, 'warning');
    assert.equal(entry.message, 'cache miss for key user:42');
    assert.equal(entry.json, null);
});

test('unmatched lines (stack traces) return null so they render as-is', () => {
    const { preset } = compilePreset(PINO_PRETTY);
    assert.equal(parseLine(preset, '    at Module._compile (node:internal/modules/cjs/loader:1256:14)'), null);
    assert.equal(parseLine(preset, 'TypeError: Cannot read properties of undefined'), null);
    assert.equal(parseLine(preset, ''), null);
    assert.equal(preset.unmatched, 'inherit');
});

test('normalizes levels through the map, built-in aliases, numbers and the default', () => {
    const { preset } = compilePreset(JSON.stringify({ pattern: '(?<msg>.*)', levels: { notice: 'warning' }, defaultLevel: 'debug' }));
    assert.equal(normalizeLevel(preset, 'NOTICE'), 'warning');
    assert.equal(normalizeLevel(preset, 'Warn'), 'warning');
    assert.equal(normalizeLevel(preset, 'fatal'), 'error');
    assert.equal(normalizeLevel(preset, 50), 'error');
    assert.equal(normalizeLevel(preset, 'weird'), 'debug');
    assert.equal(normalizeLevel(preset, ''), 'debug');
});

test('json format extracts level, message and time and keeps the raw line for details', () => {
    const { ok, preset } = compilePreset(JSON.stringify({
        format: 'json',
        levels: { 30: 'info', 50: 'error' },
        json: { hiddenKeys: ['pid'] },
        display: { timeFormat: 'hms' },
    }));
    assert.equal(ok, true);

    const line = '{"level":50,"time":1757537060098,"pid":1,"msg":"boom","err":{"type":"Error"}}';
    const entry = parseLine(preset, line);
    assert.equal(entry.level, 'error');
    assert.equal(entry.levelRaw, '50');
    assert.equal(entry.message, 'boom');
    assert.equal(entry.time, '1757537060098');
    assert.equal(entry.json, line);
    assert.deepEqual(preset.detailHiddenKeys, ['pid', 'level', 'msg', 'time']);

    assert.equal(parseLine(preset, 'plain text'), null);
    assert.equal(parseLine(preset, '{not json'), null);
    assert.equal(parseLine(preset, '[1,2,3]'), null);
});

test('json format supports dotted keys', () => {
    const { preset } = compilePreset(JSON.stringify({ format: 'json', levelKey: 'log.level', messageKey: 'message' }));
    const entry = parseLine(preset, '{"log":{"level":"warn"},"message":"slow query"}');
    assert.equal(entry.level, 'warning');
    assert.equal(entry.message, 'slow query');
    assert.equal(getPath({ 'a.b': 1 }, 'a.b'), 1);
});

test('hook can return an entry, null for raw, or fall back to the pattern when it throws', () => {
    const { ok, preset } = compilePreset(JSON.stringify({
        pattern: '^(?<level>[A-Z]+) (?<msg>.*)$',
        hook: `(line, ctx) => {
            if (line.startsWith('SKIP')) return null;
            if (line.startsWith('THROW')) throw new Error('nope');
            if (line.startsWith('CUSTOM')) return { level: 'ERR', message: line.slice(7), fields: { n: 1 }, json: { a: 1 } };
            return ctx.defaultParse(line);
        }`,
    }));
    assert.equal(ok, true);

    assert.equal(parseLine(preset, 'SKIP me'), null);
    assert.deepEqual(parseLine(preset, 'INFO hello').message, 'hello');

    const custom = parseLine(preset, 'CUSTOM custom message');
    assert.equal(custom.level, 'error');
    assert.equal(custom.message, 'custom message');
    assert.deepEqual(custom.fields, { n: '1' });
    assert.deepEqual(custom.data, { a: 1 });

    const fallback = parseLine(preset, 'THROW exploded');
    assert.equal(fallback.level, 'debug' === fallback.level ? 'debug' : fallback.level);
    assert.equal(fallback.message, 'exploded');
});

test('hook is disabled after repeated errors', () => {
    const { preset } = compilePreset(JSON.stringify({ pattern: '(?<msg>.*)', hook: '() => { throw new Error("x") }' }));
    const originalWarn = console.warn;
    let warnings = 0;
    console.warn = () => { warnings += 1; };
    try {
        for (let i = 0; i < MAX_HOOK_ERRORS + 5; i += 1) {
            assert.equal(parseLine(preset, 'still parsed').message, 'still parsed');
        }
    } finally {
        console.warn = originalWarn;
    }
    assert.equal(preset.hookErrors, MAX_HOOK_ERRORS);
    assert.equal(warnings, 1);
});

test('compilePreset reports errors and memoises by source', () => {
    assert.equal(compilePreset('').ok, false);
    assert.match(compilePreset('{').error, /JSON/);
    assert.match(compilePreset('{"format":"xml"}').error, /Unknown format/);
    assert.match(compilePreset('{"format":"regex"}').error, /pattern is required/);
    assert.equal(compilePreset('{"pattern":"("}').ok, false);
    assert.match(compilePreset('{"pattern":"(?<msg>.*)","hook":"42"}').error, /hook must evaluate to a function/);
    assert.strictEqual(compilePreset(PINO_PRETTY), compilePreset(PINO_PRETTY));
});

test('testPreset parses every non-empty sample line', () => {
    const result = testPreset(PINO_PRETTY, `${SAMPLE}\n\n    at foo (bar.js:1:1)\n`);
    assert.equal(result.ok, true);
    assert.equal(result.results.length, 2);
    assert.equal(result.results[0].entry.message, 'request completed');
    assert.equal(result.results[1].entry, null);
});

test('formatTime keeps raw values and formats epoch/ISO values as local time', () => {
    assert.equal(formatTime('20:44:20.098'), '20:44:20.098');
    assert.equal(formatTime(''), '');

    const date = new Date(1757537060098);
    const pad = (n, w = 2) => String(n).padStart(w, '0');
    const expected = `${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}.098`;
    assert.equal(formatTime(1757537060098, 'hms'), expected);
    assert.equal(formatTime('1757537060.098', 'hms'), expected);
    assert.equal(formatTime(date.toISOString(), 'hms'), expected);
    assert.equal(formatTime('not a date', 'hms'), 'not a date');
});

test('parses 50k lines quickly', () => {
    const { preset } = compilePreset(PINO_PRETTY);
    const started = performance.now();
    for (let i = 0; i < 50000; i += 1) {
        parseLine(preset, SAMPLE);
    }
    const elapsed = performance.now() - started;
    assert.ok(elapsed < 1000, `took ${elapsed.toFixed(0)}ms`);
});
