<?php

namespace App\Support;

use stdClass;

/**
 * Validation and templates for log parser preset configs.
 *
 * Parsing happens in the browser (resources/js/log-parser/core.js). This class
 * only guards what gets stored, so the client never receives a malformed
 * preset. Unknown keys are tolerated to keep configs forward compatible.
 */
final class LogParserConfig
{
    public const LEVELS = ['error', 'warning', 'debug', 'info'];

    public const MAX_CONFIG_BYTES = 65536;

    public const MAX_HOOK_BYTES = 16384;

    /**
     * @return array<string, array{label: string, config: string}>
     */
    public static function templates(): array
    {
        return [
            'pino-pretty' => [
                'label' => 'pino-pretty style',
                'config' => self::encode([
                    'version' => 1,
                    'format' => 'regex',
                    'pattern' => '^\[(?<time>[^\]]+)\]\s+(?<level>[A-Za-z]+)(?:\s*\((?<pid>\d+)\))?:\s*(?<msg>.*?)\s*(?<json>\{.*\})?$',
                    'levels' => [
                        'TRACE' => 'debug',
                        'DEBUG' => 'debug',
                        'INFO' => 'info',
                        'WARN' => 'warning',
                        'ERROR' => 'error',
                        'FATAL' => 'error',
                    ],
                    'unmatched' => 'inherit',
                    'json' => ['prettify' => true, 'collapsed' => true, 'maxDepth' => 8, 'hiddenKeys' => []],
                    'display' => ['showTime' => true, 'levelBadge' => true, 'extraFields' => true],
                ]),
            ],
            'pino-json' => [
                'label' => 'pino / bunyan JSON lines',
                'config' => self::encode([
                    'version' => 1,
                    'format' => 'json',
                    'levelKey' => 'level',
                    'messageKey' => 'msg',
                    'timeKey' => 'time',
                    'levels' => ['10' => 'debug', '20' => 'debug', '30' => 'info', '40' => 'warning', '50' => 'error', '60' => 'error'],
                    'unmatched' => 'inherit',
                    'json' => ['prettify' => true, 'collapsed' => true, 'maxDepth' => 8, 'hiddenKeys' => ['pid', 'hostname', 'v']],
                    'display' => ['showTime' => true, 'timeFormat' => 'hms', 'levelBadge' => true, 'extraFields' => true],
                ]),
            ],
            'blank' => [
                'label' => 'Blank',
                'config' => self::encode([
                    'version' => 1,
                    'format' => 'regex',
                    'pattern' => '^(?<level>[A-Z]+)\s+(?<msg>.*)$',
                ]),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function errors(mixed $json): array
    {
        if (! is_string($json) || trim($json) === '') {
            return ['The config must be a non-empty JSON object.'];
        }

        if (strlen($json) > self::MAX_CONFIG_BYTES) {
            return ['The config must not exceed 64 KB.'];
        }

        try {
            $config = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['The config is not valid JSON: '.$e->getMessage().'.'];
        }

        if (! $config instanceof stdClass) {
            return ['The config must be a JSON object.'];
        }

        $errors = [];

        if (($config->version ?? 1) !== 1) {
            $errors[] = 'version must be 1.';
        }

        $format = $config->format ?? 'regex';
        if (! in_array($format, ['regex', 'json'], true)) {
            $errors[] = 'format must be "regex" or "json".';
        }

        if ($format === 'regex') {
            array_push($errors, ...self::patternErrors($config->pattern ?? null, $config->flags ?? ''));
        }

        foreach (['levelKey', 'messageKey', 'timeKey'] as $key) {
            if (isset($config->{$key}) && (! is_string($config->{$key}) || $config->{$key} === '')) {
                $errors[] = "{$key} must be a non-empty string.";
            }
        }

        if (isset($config->levels)) {
            if (! $config->levels instanceof stdClass) {
                $errors[] = 'levels must be an object mapping raw levels to one of: '.implode(', ', self::LEVELS).'.';
            } else {
                foreach (get_object_vars($config->levels) as $raw => $level) {
                    if (! in_array($level, self::LEVELS, true)) {
                        $errors[] = "levels.{$raw} must be one of: ".implode(', ', self::LEVELS).'.';
                    }
                }
            }
        }

        if (isset($config->defaultLevel) && ! in_array($config->defaultLevel, self::LEVELS, true)) {
            $errors[] = 'defaultLevel must be one of: '.implode(', ', self::LEVELS).'.';
        }

        if (isset($config->unmatched) && ! in_array($config->unmatched, ['raw', 'inherit'], true)) {
            $errors[] = 'unmatched must be "raw" or "inherit".';
        }

        if (isset($config->hook)) {
            if (! is_string($config->hook)) {
                $errors[] = 'hook must be a string containing a JavaScript function.';
            } elseif (strlen($config->hook) > self::MAX_HOOK_BYTES) {
                $errors[] = 'hook must not exceed 16 KB.';
            }
        }

        if (isset($config->json)) {
            array_push($errors, ...self::jsonOptionErrors($config->json));
        }

        if (isset($config->display)) {
            array_push($errors, ...self::displayOptionErrors($config->display));
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function patternErrors(mixed $pattern, mixed $flags): array
    {
        if (! is_string($pattern) || $pattern === '') {
            return ['pattern is required when format is "regex".'];
        }

        $errors = [];

        if (! preg_match('/\(\?<[A-Za-z_$][\w$]*>/', $pattern)) {
            $errors[] = 'pattern must contain at least one named group, e.g. (?<msg>.*).';
        }

        if (! is_string($flags) || ! preg_match('/^[isu]*$/', $flags)) {
            $errors[] = 'flags may only contain the letters i, s and u.';
        }

        // JS and PCRE share the syntax used by log patterns; compiling with
        // PCRE catches typos before the preset reaches anyone's browser.
        $compileError = null;
        set_error_handler(function (int $errno, string $message) use (&$compileError): bool {
            $compileError = preg_replace('/^preg_match\(\):\s*/', '', $message);

            return true;
        });
        try {
            $result = preg_match("\x01{$pattern}\x01u", '');
        } finally {
            restore_error_handler();
        }

        if ($result === false) {
            $errors[] = 'pattern does not compile'.($compileError ? ': '.$compileError : '').'.';
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function jsonOptionErrors(mixed $options): array
    {
        if (! $options instanceof stdClass) {
            return ['json must be an object.'];
        }

        $errors = [];

        foreach (['prettify', 'collapsed'] as $key) {
            if (isset($options->{$key}) && ! is_bool($options->{$key})) {
                $errors[] = "json.{$key} must be true or false.";
            }
        }

        if (isset($options->maxDepth) && (! is_int($options->maxDepth) || $options->maxDepth < 1 || $options->maxDepth > 20)) {
            $errors[] = 'json.maxDepth must be an integer between 1 and 20.';
        }

        if (isset($options->previewChars) && (! is_int($options->previewChars) || $options->previewChars < 0 || $options->previewChars > 1000)) {
            $errors[] = 'json.previewChars must be an integer between 0 and 1000.';
        }

        if (isset($options->hiddenKeys)) {
            $hiddenKeys = $options->hiddenKeys;
            if (! is_array($hiddenKeys) || collect($hiddenKeys)->contains(fn ($key) => ! is_string($key) || $key === '')) {
                $errors[] = 'json.hiddenKeys must be a list of key names (dotted paths allowed).';
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private static function displayOptionErrors(mixed $options): array
    {
        if (! $options instanceof stdClass) {
            return ['display must be an object.'];
        }

        $errors = [];

        foreach (['showTime', 'levelBadge', 'extraFields'] as $key) {
            if (isset($options->{$key}) && ! is_bool($options->{$key})) {
                $errors[] = "display.{$key} must be true or false.";
            }
        }

        if (isset($options->timeFormat) && ! in_array($options->timeFormat, ['raw', 'hms'], true)) {
            $errors[] = 'display.timeFormat must be "raw" or "hms".';
        }

        return $errors;
    }

    private static function encode(array $config): string
    {
        return json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
