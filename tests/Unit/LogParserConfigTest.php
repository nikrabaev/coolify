<?php

use App\Support\LogParserConfig;

it('accepts every bundled template', function (string $template) {
    expect(LogParserConfig::errors(LogParserConfig::templates()[$template]['config']))->toBe([]);
})->with(['pino-pretty', 'pino-json', 'blank']);

it('accepts patterns containing slashes and tildes', function () {
    expect(LogParserConfig::errors('{"pattern":"^(?<path>/api/~[a-z]+) (?<msg>.*)$"}'))->toBe([]);
});

it('rejects invalid configs with a helpful message', function (mixed $config, string $expected) {
    expect(implode("\n", LogParserConfig::errors($config)))->toContain($expected);
})->with([
    'empty' => ['', 'non-empty JSON object'],
    'not a string' => [null, 'non-empty JSON object'],
    'invalid json' => ['{', 'not valid JSON'],
    'json array' => ['[1,2]', 'must be a JSON object'],
    'unsupported version' => ['{"version":2,"pattern":"(?<msg>.*)"}', 'version must be 1'],
    'unknown format' => ['{"format":"xml"}', 'format must be'],
    'missing pattern' => ['{"format":"regex"}', 'pattern is required'],
    'no named group' => ['{"pattern":"^(.*)$"}', 'named group'],
    'pattern does not compile' => ['{"pattern":"(?<msg>.*"}', 'does not compile'],
    'unsupported flag' => ['{"pattern":"(?<msg>.*)","flags":"g"}', 'flags may only'],
    'unknown level' => ['{"pattern":"(?<msg>.*)","levels":{"INFO":"notice"}}', 'levels.INFO must be one of'],
    'levels not an object' => ['{"pattern":"(?<msg>.*)","levels":["info"]}', 'levels must be an object'],
    'unknown default level' => ['{"pattern":"(?<msg>.*)","defaultLevel":"loud"}', 'defaultLevel'],
    'unknown unmatched mode' => ['{"pattern":"(?<msg>.*)","unmatched":"drop"}', 'unmatched must be'],
    'empty message key' => ['{"format":"json","messageKey":""}', 'messageKey must be'],
    'hook not a string' => ['{"pattern":"(?<msg>.*)","hook":42}', 'hook must be a string'],
    'hook too large' => [json_encode(['pattern' => '(?<msg>.*)', 'hook' => str_repeat('a', 16385)]), 'hook must not exceed'],
    'maxDepth out of range' => ['{"pattern":"(?<msg>.*)","json":{"maxDepth":99}}', 'json.maxDepth'],
    'hiddenKeys not strings' => ['{"pattern":"(?<msg>.*)","json":{"hiddenKeys":[1]}}', 'json.hiddenKeys'],
    'prettify not boolean' => ['{"pattern":"(?<msg>.*)","json":{"prettify":"yes"}}', 'json.prettify'],
    'unknown time format' => ['{"pattern":"(?<msg>.*)","display":{"timeFormat":"iso"}}', 'display.timeFormat'],
    'config too large' => ['{"pattern":"(?<msg>.*)","note":"'.str_repeat('a', 65536).'"}', 'must not exceed 64 KB'],
]);
