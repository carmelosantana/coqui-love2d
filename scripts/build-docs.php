#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Build script: Generate love2d-api.json from the love2d-community/love-api repo.
 *
 * Requirements:
 *   - `lua` binary on PATH
 *   - `git` binary on PATH (or internet access for raw GitHub download)
 *
 * Usage:
 *   php scripts/build-docs.php
 *
 * This downloads the love-api repo, uses a Lua encoder script to serialize the
 * complete API table to JSON, then normalizes it into a flat indexed structure
 * suitable for FTS5 search.
 *
 * Output: src/Resources/love2d-api.json
 */

const LOVE_API_REPO = 'https://github.com/love2d-community/love-api.git';
const LOVE_API_BRANCH = 'master';

$outputPath = __DIR__ . '/../src/Resources/love2d-api.json';

// ── Verify lua binary ────────────────────────────────────────────────

$luaBin = trim((string) shell_exec('which lua 2>/dev/null'));
if ($luaBin === '') {
    // Check common Homebrew / system locations
    $candidates = [
        '/opt/homebrew/bin/lua',
        '/opt/homebrew/bin/lua-5.5',
        '/opt/homebrew/bin/lua5.4',
        '/usr/local/bin/lua',
        '/usr/bin/lua',
    ];
    foreach ($candidates as $candidate) {
        if (is_executable($candidate)) {
            $luaBin = $candidate;
            break;
        }
    }
}
if ($luaBin === '') {
    fwrite(STDERR, "Error: 'lua' binary not found on PATH or common locations.\n");
    fwrite(STDERR, "Install Lua (e.g. `brew install lua` on macOS) and try again.\n");
    exit(1);
}

echo "Using Lua: {$luaBin}\n";

// ── Clone love-api repo to temp dir ──────────────────────────────────

$tmpDir = sys_get_temp_dir() . '/love-api-build-' . getmypid();

if (is_dir($tmpDir)) {
    exec("rm -rf " . escapeshellarg($tmpDir));
}

echo "Cloning love-api repo...\n";
$cloneCmd = sprintf(
    'git clone --depth 1 --branch %s %s %s 2>&1',
    escapeshellarg(LOVE_API_BRANCH),
    escapeshellarg(LOVE_API_REPO),
    escapeshellarg($tmpDir),
);
exec($cloneCmd, $cloneOutput, $cloneExit);

if ($cloneExit !== 0) {
    fwrite(STDERR, "Failed to clone love-api repo:\n" . implode("\n", $cloneOutput) . "\n");
    exit(1);
}

echo "Cloned to {$tmpDir}\n";

// ── Write Lua JSON encoder script ────────────────────────────────────

$encoderLua = <<<'LUA'
-- Minimal JSON encoder for love-api tables.
-- Outputs the full love_api structure as JSON to stdout.

local function escape_string(s)
    s = s:gsub('\\', '\\\\')
    s = s:gsub('"', '\\"')
    s = s:gsub('\n', '\\n')
    s = s:gsub('\r', '\\r')
    s = s:gsub('\t', '\\t')
    return s
end

local encode

local function is_array(t)
    local i = 0
    for _ in pairs(t) do
        i = i + 1
        if t[i] == nil then return false end
    end
    return true
end

function encode(val)
    local t = type(val)
    if val == nil then
        return 'null'
    elseif t == 'boolean' then
        return val and 'true' or 'false'
    elseif t == 'number' then
        return tostring(val)
    elseif t == 'string' then
        return '"' .. escape_string(val) .. '"'
    elseif t == 'table' then
        if is_array(val) then
            local parts = {}
            for i = 1, #val do
                parts[i] = encode(val[i])
            end
            return '[' .. table.concat(parts, ',') .. ']'
        else
            local parts = {}
            for k, v in pairs(val) do
                if type(k) == 'string' then
                    parts[#parts + 1] = '"' .. escape_string(k) .. '":' .. encode(v)
                end
            end
            return '{' .. table.concat(parts, ',') .. '}'
        end
    elseif t == 'function' then
        return 'null'
    else
        return 'null'
    end
end

-- Load the love API
-- The repo root is passed as arg[1]; love_api.lua lives there directly.
-- We need the parent directory of the repo on the path so that
-- require('love-api.love_api') resolves (love-api is the directory name).
local repo = arg[1]
local parent = repo:match('(.+)/[^/]+$') or '.'
package.path = parent .. '/?.lua;' .. parent .. '/?/init.lua;' .. repo .. '/?.lua;' .. repo .. '/?/init.lua;' .. package.path

-- love_api.lua uses `local path = (...):match('(.-)[^%./]+$')` to find its
-- own location relative to the package path root. We must require it using
-- the module name that matches the cloned directory name.
local dirName = repo:match('([^/]+)$')
local api = require(dirName .. '.love_api')

io.write(encode(api))
LUA;

$encoderPath = $tmpDir . '/encode.lua';
file_put_contents($encoderPath, $encoderLua);

// ── Run Lua encoder ──────────────────────────────────────────────────

echo "Running Lua encoder...\n";
$rawJsonPath = $tmpDir . '/raw-api.json';
$luaCmd = sprintf(
    '%s %s %s > %s 2>&1',
    escapeshellarg($luaBin),
    escapeshellarg($encoderPath),
    escapeshellarg($tmpDir),
    escapeshellarg($rawJsonPath),
);
exec($luaCmd, $luaOutput, $luaExit);

if ($luaExit !== 0) {
    fwrite(STDERR, "Lua encoder failed:\n" . implode("\n", $luaOutput) . "\n");
    // Check if stderr was captured in stdout redirect
    $errorContent = is_file($rawJsonPath) ? file_get_contents($rawJsonPath) : '';
    if ($errorContent !== '' && $errorContent !== false) {
        fwrite(STDERR, $errorContent . "\n");
    }
    exec("rm -rf " . escapeshellarg($tmpDir));
    exit(1);
}

$rawJson = file_get_contents($rawJsonPath);
if ($rawJson === false || $rawJson === '') {
    fwrite(STDERR, "Lua encoder produced no output.\n");
    exec("rm -rf " . escapeshellarg($tmpDir));
    exit(1);
}

// ── Parse and normalize ──────────────────────────────────────────────

echo "Normalizing API data...\n";

/** @var array<string, mixed> $api */
$api = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);

$entries = [];

// Top-level functions (love.getVersion, etc.)
foreach ($api['functions'] ?? [] as $func) {
    $entries[] = normalizeFunction($func, 'love', 'love.', 'function');
}

// Callbacks (love.load, love.update, love.draw, etc.)
foreach ($api['callbacks'] ?? [] as $cb) {
    $entries[] = normalizeFunction($cb, 'love', 'love.', 'callback');
}

// Top-level types (Object, Data)
foreach ($api['types'] ?? [] as $type) {
    $entries[] = normalizeType($type, 'love');
    foreach ($type['functions'] ?? [] as $method) {
        $entries[] = normalizeFunction($method, 'love', $type['name'] . ':', 'method');
    }
}

// Modules
foreach ($api['modules'] ?? [] as $module) {
    $moduleName = $module['name'] ?? '';

    // Module-level functions
    foreach ($module['functions'] ?? [] as $func) {
        $entries[] = normalizeFunction($func, $moduleName, "love.{$moduleName}.", 'function');
    }

    // Module types
    foreach ($module['types'] ?? [] as $type) {
        $entries[] = normalizeType($type, $moduleName);
        foreach ($type['functions'] ?? [] as $method) {
            $entries[] = normalizeFunction($method, $moduleName, $type['name'] . ':', 'method');
        }
    }

    // Module enums
    foreach ($module['enums'] ?? [] as $enum) {
        $entries[] = normalizeEnum($enum, $moduleName);
    }
}

// Sort by fullname for stable output
usort($entries, fn(array $a, array $b): int => strcasecmp($a['fullname'], $b['fullname']));

// ── Write output ─────────────────────────────────────────────────────

$output = [
    'version' => $api['version'] ?? 'unknown',
    'generated_at' => date('c'),
    'source' => 'https://github.com/love2d-community/love-api',
    'entry_count' => count($entries),
    'entries' => $entries,
];

$json = json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($json === false) {
    fwrite(STDERR, "JSON encoding failed: " . json_last_error_msg() . "\n");
    exec("rm -rf " . escapeshellarg($tmpDir));
    exit(1);
}

file_put_contents($outputPath, $json . "\n");

// ── Clean up ─────────────────────────────────────────────────────────

exec("rm -rf " . escapeshellarg($tmpDir));

echo "Done! Generated {$outputPath}\n";
echo "  Version: {$output['version']}\n";
echo "  Entries: {$output['entry_count']}\n";

// ── Normalizer functions ─────────────────────────────────────────────

/**
 * @param array<string, mixed> $func
 * @return array<string, mixed>
 */
function normalizeFunction(array $func, string $module, string $prefix, string $what): array
{
    $name = $func['name'] ?? '';
    $fullname = $prefix . $name;

    $variants = [];
    foreach ($func['variants'] ?? [] as $variant) {
        $args = [];
        foreach ($variant['arguments'] ?? [] as $arg) {
            $argEntry = [
                'name' => $arg['name'] ?? '',
                'type' => $arg['type'] ?? 'any',
                'description' => $arg['description'] ?? '',
            ];
            if (isset($arg['default'])) {
                $argEntry['default'] = $arg['default'];
            }
            if (!empty($arg['table'])) {
                $argEntry['table'] = normalizeTableFields($arg['table']);
            }
            $args[] = $argEntry;
        }

        $returns = [];
        foreach ($variant['returns'] ?? [] as $ret) {
            $retEntry = [
                'name' => $ret['name'] ?? '',
                'type' => $ret['type'] ?? 'any',
                'description' => $ret['description'] ?? '',
            ];
            if (!empty($ret['table'])) {
                $retEntry['table'] = normalizeTableFields($ret['table']);
            }
            $returns[] = $retEntry;
        }

        $v = [];
        if (!empty($variant['description'])) {
            $v['description'] = $variant['description'];
        }
        if ($args !== []) {
            $v['arguments'] = $args;
        }
        if ($returns !== []) {
            $v['returns'] = $returns;
        }
        $variants[] = $v;
    }

    return [
        'fullname' => $fullname,
        'name' => $name,
        'module' => $module,
        'what' => $what,
        'description' => $func['description'] ?? '',
        'variants' => $variants,
        'wiki_url' => 'https://love2d.org/wiki/' . str_replace(':', '.', $fullname),
    ];
}

/**
 * @param array<string, mixed> $type
 * @return array<string, mixed>
 */
function normalizeType(array $type, string $module): array
{
    $name = $type['name'] ?? '';

    return [
        'fullname' => $name,
        'name' => $name,
        'module' => $module,
        'what' => 'type',
        'description' => $type['description'] ?? '',
        'constructors' => $type['constructors'] ?? [],
        'supertypes' => $type['supertypes'] ?? [],
        'wiki_url' => 'https://love2d.org/wiki/' . $name,
    ];
}

/**
 * @param array<string, mixed> $enum
 * @return array<string, mixed>
 */
function normalizeEnum(array $enum, string $module): array
{
    $name = $enum['name'] ?? '';
    $constants = [];

    foreach ($enum['constants'] ?? [] as $constant) {
        $constants[] = [
            'name' => $constant['name'] ?? '',
            'description' => $constant['description'] ?? '',
        ];
    }

    return [
        'fullname' => $name,
        'name' => $name,
        'module' => $module,
        'what' => 'enum',
        'description' => $enum['description'] ?? '',
        'constants' => $constants,
        'wiki_url' => 'https://love2d.org/wiki/' . $name,
    ];
}

/**
 * @param array<int, array<string, mixed>> $fields
 * @return array<int, array<string, mixed>>
 */
function normalizeTableFields(array $fields): array
{
    $result = [];
    foreach ($fields as $field) {
        $entry = [
            'name' => $field['name'] ?? '',
            'type' => $field['type'] ?? 'any',
            'description' => $field['description'] ?? '',
        ];
        if (isset($field['default'])) {
            $entry['default'] = $field['default'];
        }
        if (!empty($field['table'])) {
            $entry['table'] = normalizeTableFields($field['table']);
        }
        $result[] = $entry;
    }
    return $result;
}
