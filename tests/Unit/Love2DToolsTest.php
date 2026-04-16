<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\CoquiToolkitLove2D\Love2DTool;
use CarmeloSantana\CoquiToolkitLove2D\Love2DTemplateTool;
use CarmeloSantana\CoquiToolkitLove2D\Love2DLogTool;
use CarmeloSantana\CoquiToolkitLove2D\Love2DDocTool;
use CarmeloSantana\CoquiToolkitLove2D\Runtime\Love2DRunner;
use CarmeloSantana\CoquiToolkitLove2D\Storage\Love2DLogStore;
use CarmeloSantana\CoquiToolkitLove2D\Storage\Love2DDocStore;

// ── Love2DTool ──────────────────────────────────────────────────────

test('love2d tool has correct name', function () {
    $runner = new Love2DRunner(workspacePath: sys_get_temp_dir());
    $tool = new Love2DTool($runner);

    expect($tool->name())->toBe('love2d');
});

test('love2d tool has description', function () {
    $runner = new Love2DRunner(workspacePath: sys_get_temp_dir());
    $tool = new Love2DTool($runner);
    $description = $tool->description();

    expect($description)->toBeString();
    expect($description === '')->toBeFalse();
});

test('love2d tool has valid function schema', function () {
    $runner = new Love2DRunner(workspacePath: sys_get_temp_dir());
    $tool = new Love2DTool($runner);
    $schema = $tool->toFunctionSchema();

    expect($schema)->toBeArray();
    expect($schema['type'])->toBe('function');
    expect($schema['function']['name'])->toBe('love2d');
    expect($schema['function']['parameters']['properties'])->toHaveKey('action');
    expect($schema['function']['parameters']['required'])->toContain('action');
});

test('love2d tool returns error for unknown action', function () {
    $runner = new Love2DRunner(workspacePath: sys_get_temp_dir());
    $tool = new Love2DTool($runner);

    $result = $tool->execute(['action' => 'invalid_action']);
    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('love2d tool list action works with no instances', function () {
    $tmpDir = sys_get_temp_dir() . '/love2d-test-' . uniqid();
    mkdir($tmpDir, 0755, true);

    $runner = new Love2DRunner(workspacePath: $tmpDir);
    $tool = new Love2DTool($runner);

    $result = $tool->execute(['action' => 'list']);
    expect($result->status === ToolResultStatus::Error)->toBeFalse();

    rmdir($tmpDir);
});

// ── Love2DTemplateTool ──────────────────────────────────────────────

test('template tool has correct name', function () {
    $tool = new Love2DTemplateTool();

    expect($tool->name())->toBe('love2d_template');
});

test('template tool list_components returns all types', function () {
    $tool = new Love2DTemplateTool();

    $result = $tool->execute(['action' => 'list_components']);
    expect($result->status)->not->toBe(ToolResultStatus::Error);

    expect($result->content)->toContain('player');
    expect($result->content)->toContain('enemy');
    expect($result->content)->toContain('camera');
    expect($result->content)->toContain('menu');
    expect($result->content)->toContain('gameplay');
});

test('template tool generates player component', function () {
    $tool = new Love2DTemplateTool();

    $result = $tool->execute(['action' => 'generate_component', 'type' => 'player']);
    expect($result->status)->not->toBe(ToolResultStatus::Error);

    expect($result->content)->toContain('player.lua');
    expect($result->content)->toContain('function player');
    expect($result->content)->toContain('love.keyboard.isDown');
});

test('template tool generates all component types', function () {
    $tool = new Love2DTemplateTool();

    $types = [
        'player', 'enemy', 'tilemap', 'camera', 'ui-hud', 'particle-system',
        'state-machine', 'collision', 'animation', 'save-load',
        'audio-manager', 'level-loader',
    ];

    foreach ($types as $type) {
        $result = $tool->execute(['action' => 'generate_component', 'type' => $type]);
        expect($result->status)->not->toBe(ToolResultStatus::Error, "Component type '{$type}' should generate successfully");
    }
});

test('template tool generates all scene types', function () {
    $tool = new Love2DTemplateTool();

    $types = ['menu', 'gameplay', 'pause', 'game-over', 'settings', 'level-select'];

    foreach ($types as $type) {
        $result = $tool->execute(['action' => 'generate_scene', 'type' => $type]);
        expect($result->status)->not->toBe(ToolResultStatus::Error, "Scene type '{$type}' should generate successfully");
    }
});

test('template tool rejects unknown component type', function () {
    $tool = new Love2DTemplateTool();

    $result = $tool->execute(['action' => 'generate_component', 'type' => 'nonexistent']);
    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('template tool requires type for generate_component', function () {
    $tool = new Love2DTemplateTool();

    $result = $tool->execute(['action' => 'generate_component']);
    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('template tool uses custom name', function () {
    $tool = new Love2DTemplateTool();

    $result = $tool->execute([
        'action' => 'generate_component',
        'type' => 'player',
        'name' => 'hero',
    ]);
    expect($result->status)->not->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('hero.lua');
});

test('template tool has valid function schema', function () {
    $tool = new Love2DTemplateTool();
    $schema = $tool->toFunctionSchema();

    expect($schema['type'])->toBe('function');
    expect($schema['function']['name'])->toBe('love2d_template');
    expect($schema['function']['parameters']['properties'])->toHaveKey('action');
    expect($schema['function']['parameters']['properties'])->toHaveKey('type');
});

// ── Love2DLogTool ───────────────────────────────────────────────────

test('log tool has correct name', function () {
    $tmpDir = sys_get_temp_dir() . '/love2d-test-' . uniqid();
    mkdir($tmpDir, 0755, true);

    $runner = new Love2DRunner(workspacePath: $tmpDir);
    $logStore = new Love2DLogStore($tmpDir . '/test-log.db');
    $tool = new Love2DLogTool($runner, $logStore);

    expect($tool->name())->toBe('love2d_log');

    rmdir($tmpDir);
});

test('log tool tail returns empty when no entries', function () {
    $tmpDir = sys_get_temp_dir() . '/love2d-test-' . uniqid();
    mkdir($tmpDir, 0755, true);
    $dbPath = $tmpDir . '/test-log.db';

    $runner = new Love2DRunner(workspacePath: $tmpDir);
    $logStore = new Love2DLogStore($dbPath);
    $tool = new Love2DLogTool($runner, $logStore);

    $result = $tool->execute(['action' => 'tail']);
    expect($result->status)->not->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('No log entries');

    // Cleanup all files (db, WAL, SHM, etc.)
    array_map('unlink', glob($tmpDir . '/*') ?: []);
    rmdir($tmpDir);
});

test('log tool clear works', function () {
    $tmpDir = sys_get_temp_dir() . '/love2d-test-' . uniqid();
    mkdir($tmpDir, 0755, true);
    $dbPath = $tmpDir . '/test-log.db';

    $runner = new Love2DRunner(workspacePath: $tmpDir);
    $logStore = new Love2DLogStore($dbPath);
    $tool = new Love2DLogTool($runner, $logStore);

    $logStore->log('info', 'Test message');
    $result = $tool->execute(['action' => 'clear']);
    expect($result->status)->not->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('cleared');

    // Cleanup all files (db, WAL, SHM, etc.)
    array_map('unlink', glob($tmpDir . '/*') ?: []);
    rmdir($tmpDir);
});

test('log tool search requires query', function () {
    $tmpDir = sys_get_temp_dir() . '/love2d-test-' . uniqid();
    mkdir($tmpDir, 0755, true);
    $dbPath = $tmpDir . '/test-log.db';

    $runner = new Love2DRunner(workspacePath: $tmpDir);
    $logStore = new Love2DLogStore($dbPath);
    $tool = new Love2DLogTool($runner, $logStore);

    $result = $tool->execute(['action' => 'search']);
    expect($result->status)->toBe(ToolResultStatus::Error);

    if (is_file($dbPath)) {
        unlink($dbPath);
    }
    rmdir($tmpDir);
});

test('log tool returns error for unknown action', function () {
    $tmpDir = sys_get_temp_dir() . '/love2d-test-' . uniqid();
    mkdir($tmpDir, 0755, true);

    $runner = new Love2DRunner(workspacePath: $tmpDir);
    $logStore = new Love2DLogStore($tmpDir . '/test-log.db');
    $tool = new Love2DLogTool($runner, $logStore);

    $result = $tool->execute(['action' => 'invalid']);
    expect($result->status)->toBe(ToolResultStatus::Error);

    rmdir($tmpDir);
});

// ── Love2DDocTool ───────────────────────────────────────────────────

function createDocTool(): Love2DDocTool
{
    $jsonPath = __DIR__ . '/../../src/Resources/love2d-api.json';
    $dbPath = sys_get_temp_dir() . '/love2d-doc-tool-test-' . uniqid() . '.db';

    return new Love2DDocTool(new Love2DDocStore($jsonPath, $dbPath));
}

test('doc tool has correct name', function () {
    $tool = createDocTool();
    expect($tool->name())->toBe('love2d_doc');
});

test('doc tool has description', function () {
    $tool = createDocTool();
    $description = $tool->description();

    expect($description)->toBeString();
    expect($description === '')->toBeFalse();
});

test('doc tool has valid function schema', function () {
    $tool = createDocTool();
    $schema = $tool->toFunctionSchema();

    expect($schema['type'])->toBe('function');
    expect($schema['function']['name'])->toBe('love2d_doc');
    expect($schema['function']['parameters']['properties'])->toHaveKey('action');
    expect($schema['function']['parameters']['properties'])->toHaveKey('query');
    expect($schema['function']['parameters']['properties'])->toHaveKey('name');
    expect($schema['function']['parameters']['properties'])->toHaveKey('module');
    expect($schema['function']['parameters']['required'])->toContain('action');
});

test('doc tool returns error for unknown action', function () {
    $tool = createDocTool();

    $result = $tool->execute(['action' => 'invalid_action']);
    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('doc tool search returns results', function () {
    $tool = createDocTool();

    $result = $tool->execute(['action' => 'search', 'query' => 'draw']);
    expect($result->status)->not->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('love.graphics.draw');
    expect($result->content)->toContain('Search Results');
});

test('doc tool search requires query', function () {
    $tool = createDocTool();

    $result = $tool->execute(['action' => 'search']);
    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('doc tool search with module filter', function () {
    $tool = createDocTool();

    $result = $tool->execute(['action' => 'search', 'query' => 'new', 'module' => 'graphics']);
    expect($result->status)->not->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('graphics');
});

test('doc tool lookup returns full entry', function () {
    $tool = createDocTool();

    $result = $tool->execute(['action' => 'lookup', 'name' => 'love.graphics.draw']);
    expect($result->status)->not->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('love.graphics.draw');
    expect($result->content)->toContain('function');
    expect($result->content)->toContain('Signatures');
});

test('doc tool lookup requires name', function () {
    $tool = createDocTool();

    $result = $tool->execute(['action' => 'lookup']);
    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('doc tool lookup returns not found gracefully', function () {
    $tool = createDocTool();

    $result = $tool->execute(['action' => 'lookup', 'name' => 'nonexistent.thing']);
    expect($result->status)->not->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('Not Found');
});

test('doc tool list_modules returns module table', function () {
    $tool = createDocTool();

    $result = $tool->execute(['action' => 'list_modules']);
    expect($result->status)->not->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('graphics');
    expect($result->content)->toContain('audio');
    expect($result->content)->toContain('physics');
    expect($result->content)->toContain('Modules');
});
