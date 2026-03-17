<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitLove2D\Storage\Love2DLogStore;

test('creates table on connect', function () {
    $dbPath = sys_get_temp_dir() . '/love2d-log-test-' . uniqid() . '.db';

    $store = new Love2DLogStore($dbPath);
    $entries = $store->tail();
    expect($entries)->toBe([]);

    $db = new PDO("sqlite:{$dbPath}");
    $result = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='output_log'");
    expect($result->fetchColumn())->toBe('output_log');

    unlink($dbPath);
});

test('log and tail round-trip', function () {
    $dbPath = sys_get_temp_dir() . '/love2d-log-test-' . uniqid() . '.db';
    $store = new Love2DLogStore($dbPath);

    $store->log('info', 'Game started');
    $store->log('error', 'Texture not found: player.png', 'stderr');
    $store->log('debug', 'Player position: 100, 200');

    $entries = $store->tail(10);
    expect($entries)->toHaveCount(3);

    // tail() returns in chronological order (oldest first)
    expect($entries[0]['level'])->toBe('info');
    expect($entries[0]['message'])->toBe('Game started');

    expect($entries[1]['level'])->toBe('error');
    expect($entries[1]['source'])->toBe('stderr');

    expect($entries[2]['level'])->toBe('debug');
    expect($entries[2]['message'])->toBe('Player position: 100, 200');

    unlink($dbPath);
});

test('tail respects limit', function () {
    $dbPath = sys_get_temp_dir() . '/love2d-log-test-' . uniqid() . '.db';
    $store = new Love2DLogStore($dbPath);

    for ($i = 0; $i < 20; $i++) {
        $store->log('info', "Message {$i}");
    }

    $entries = $store->tail(5);
    expect($entries)->toHaveCount(5);

    unlink($dbPath);
});

test('search filters by level', function () {
    $dbPath = sys_get_temp_dir() . '/love2d-log-test-' . uniqid() . '.db';
    $store = new Love2DLogStore($dbPath);

    $store->log('info', 'Info message');
    $store->log('error', 'Error message');
    $store->log('warning', 'Warning message');
    $store->log('error', 'Another error');

    $entries = $store->search(['level' => 'error']);
    expect($entries)->toHaveCount(2);

    foreach ($entries as $entry) {
        expect($entry['level'])->toBe('error');
    }

    unlink($dbPath);
});

test('search finds matching messages', function () {
    $dbPath = sys_get_temp_dir() . '/love2d-log-test-' . uniqid() . '.db';
    $store = new Love2DLogStore($dbPath);

    $store->log('info', 'Loading texture: player.png');
    $store->log('error', 'Failed to load texture: enemy.png');
    $store->log('info', 'Game loop started');

    $results = $store->search(['query' => 'texture']);
    expect($results)->toHaveCount(2);

    unlink($dbPath);
});

test('search filters by query and level', function () {
    $dbPath = sys_get_temp_dir() . '/love2d-log-test-' . uniqid() . '.db';
    $store = new Love2DLogStore($dbPath);

    $store->log('info', 'Loading texture: player.png');
    $store->log('error', 'Failed to load texture: enemy.png');

    $results = $store->search(['query' => 'texture', 'level' => 'error']);
    expect($results)->toHaveCount(1);
    expect($results[0]['level'])->toBe('error');

    unlink($dbPath);
});

test('stats returns level counts', function () {
    $dbPath = sys_get_temp_dir() . '/love2d-log-test-' . uniqid() . '.db';
    $store = new Love2DLogStore($dbPath);

    $store->log('info', 'One');
    $store->log('info', 'Two');
    $store->log('error', 'Three');
    $store->log('warning', 'Four');

    $stats = $store->stats();
    expect($stats)->toBeArray()->not->toBeEmpty();
    expect($stats['total'])->toBe(4);
    expect($stats['levels'])->toBeArray();
    expect($stats['levels']['info'])->toBe(2);
    expect($stats['levels']['error'])->toBe(1);
    expect($stats['levels']['warning'])->toBe(1);

    unlink($dbPath);
});

test('clear removes all entries', function () {
    $dbPath = sys_get_temp_dir() . '/love2d-log-test-' . uniqid() . '.db';
    $store = new Love2DLogStore($dbPath);

    $store->log('info', 'Message 1');
    $store->log('info', 'Message 2');
    $store->clear();

    $entries = $store->tail();
    expect($entries)->toBe([]);

    unlink($dbPath);
});

test('hasEntries returns correct state', function () {
    $dbPath = sys_get_temp_dir() . '/love2d-log-test-' . uniqid() . '.db';
    $store = new Love2DLogStore($dbPath);

    expect($store->hasEntries())->toBeFalse();

    $store->log('info', 'First entry');
    expect($store->hasEntries())->toBeTrue();

    $store->clear();
    expect($store->hasEntries())->toBeFalse();

    unlink($dbPath);
});

test('importFromFile parses log lines', function () {
    $dbPath = sys_get_temp_dir() . '/love2d-log-test-' . uniqid() . '.db';
    $logFile = sys_get_temp_dir() . '/love2d-output-test-' . uniqid() . '.log';
    $store = new Love2DLogStore($dbPath);

    file_put_contents($logFile, implode("\n", [
        'Game started successfully',
        'Error: main.lua:45: attempt to index nil value',
        'Warning: Audio subsystem not available',
        'Debug: FPS: 60',
    ]));

    $imported = $store->importFromFile($logFile);
    expect($imported)->toBe(4);

    $entries = $store->tail(10);
    expect($entries)->toHaveCount(4);

    unlink($dbPath);
    unlink($logFile);
});
