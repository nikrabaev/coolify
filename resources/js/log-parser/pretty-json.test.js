import test from 'node:test';
import assert from 'node:assert/strict';
import { tokenizeJson } from './pretty-json.js';

const text = (tokens) => tokens.map((token) => token.text).join('');

test('pretty prints JSON exactly like JSON.stringify with 2-space indent', () => {
    const value = { service: 'api', req: { id: 'x', query: {}, params: { path: ['health'] } }, ok: true, none: null, n: 5 };
    assert.equal(text(tokenizeJson(value)), JSON.stringify(value, null, 2));
});

test('classifies tokens for colouring', () => {
    const types = tokenizeJson({ a: 'b', c: 1, d: false, e: null })
        .filter((token) => token.type !== 'punct')
        .map((token) => `${token.type}:${token.text}`);
    assert.deepEqual(types, ['key:"a"', 'string:"b"', 'key:"c"', 'number:1', 'key:"d"', 'bool:false', 'key:"e"', 'null:null']);
});

test('hides top-level and dotted keys, including inside arrays', () => {
    const value = { pid: 1, req: { headers: { a: 1 }, url: '/' }, items: [{ secret: 1, keep: 2 }] };
    const output = JSON.parse(text(tokenizeJson(value, { hiddenKeys: ['pid', 'req.headers', 'items.secret'] })));
    assert.deepEqual(output, { req: { url: '/' }, items: [{ keep: 2 }] });
});

test('collapses nodes beyond maxDepth and guards against cycles', () => {
    assert.match(text(tokenizeJson({ a: { b: { c: 1 } } }, { maxDepth: 2 })), /\{… 1 keys\}/);

    const cyclic = { name: 'x' };
    cyclic.self = cyclic;
    assert.match(text(tokenizeJson(cyclic)), /\[Circular\]/);
});

test('escapes strings so rendered text stays valid JSON', () => {
    const value = { html: '<img src=x onerror=alert(1)>', quote: 'say "hi"\n' };
    assert.deepEqual(JSON.parse(text(tokenizeJson(value))), value);
});
