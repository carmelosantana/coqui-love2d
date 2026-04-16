<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitLove2D\Storage\Love2DDocStore;

test('creates database on first connect', function () {
    $jsonPath = __DIR__ . '/../../src/Resources/love2d-api.json';
    $dbPath = sys_get_temp_dir() . '/love2d-doc-test-' . uniqid() . '.db';

    $store = new Love2DDocStore($jsonPath, $dbPath);
    $count = $store->entryCount();

    expect($count)->toBeGreaterThan(500);
    expect(is_file($dbPath))->toBeTrue();

    unlink($dbPath);
});

test('version returns 11.5', function () {
    $jsonPath = __DIR__ . '/../../src/Resources/love2d-api.json';
    $dbPath = sys_get_temp_dir() . '/love2d-doc-test-' . uniqid() . '.db';

    $store = new Love2DDocStore($jsonPath, $dbPath);

    expect($store->version())->toBe('11.5');

    unlink($dbPath);
});

test('search returns results for common terms', function () {
    $jsonPath = __DIR__ . '/../../src/Resources/love2d-api.json';
    $dbPath = sys_get_temp_dir() . '/love2d-doc-test-' . uniqid() . '.db';

    $store = new Love2DDocStore($jsonPath, $dbPath);

    $results = $store->search('draw');
    expect($results)->not->toBeEmpty();

    // Should include drawing-related entries
    $fullnames = array_column($results, 'fullname');
    $hasDrawRelated = false;
    foreach ($fullnames as $name) {
        if (str_contains(strtolower($name), 'draw')) {
            $hasDrawRelated = true;
            break;
        }
    }
    expect($hasDrawRelated)->toBeTrue();

    unlink($dbPath);
});

test('search filters by module', function () {
    $jsonPath = __DIR__ . '/../../src/Resources/love2d-api.json';
    $dbPath = sys_get_temp_dir() . '/love2d-doc-test-' . uniqid() . '.db';

    $store = new Love2DDocStore($jsonPath, $dbPath);

    $results = $store->search('new', 'graphics');
    expect($results)->not->toBeEmpty();

    foreach ($results as $row) {
        expect($row['module'])->toBe('graphics');
    }

    unlink($dbPath);
});

test('search respects limit', function () {
    $jsonPath = __DIR__ . '/../../src/Resources/love2d-api.json';
    $dbPath = sys_get_temp_dir() . '/love2d-doc-test-' . uniqid() . '.db';

    $store = new Love2DDocStore($jsonPath, $dbPath);

    $results = $store->search('set', limit: 3);
    expect($results)->toHaveCount(3);

    unlink($dbPath);
});

test('search returns empty for nonsense query', function () {
    $jsonPath = __DIR__ . '/../../src/Resources/love2d-api.json';
    $dbPath = sys_get_temp_dir() . '/love2d-doc-test-' . uniqid() . '.db';

    $store = new Love2DDocStore($jsonPath, $dbPath);

    $results = $store->search('xyzqwerty999');
    expect($results)->toBeEmpty();

    unlink($dbPath);
});

test('lookup finds exact fullname', function () {
    $jsonPath = __DIR__ . '/../../src/Resources/love2d-api.json';
    $dbPath = sys_get_temp_dir() . '/love2d-doc-test-' . uniqid() . '.db';

    $store = new Love2DDocStore($jsonPath, $dbPath);

    $entry = $store->lookup('love.graphics.draw');
    expect($entry)->not->toBeNull();
    expect($entry['fullname'])->toBe('love.graphics.draw');
    expect($entry['what'])->toBe('function');
    expect($entry['module'])->toBe('graphics');
    expect($entry['variants'])->toBeArray();
    expect($entry['variants'])->not->toBeEmpty();

    unlink($dbPath);
});

test('lookup finds case-insensitive match', function () {
    $jsonPath = __DIR__ . '/../../src/Resources/love2d-api.json';
    $dbPath = sys_get_temp_dir() . '/love2d-doc-test-' . uniqid() . '.db';

    $store = new Love2DDocStore($jsonPath, $dbPath);

    $entry = $store->lookup('love.graphics.Draw');
    expect($entry)->not->toBeNull();
    expect($entry['fullname'])->toBe('love.graphics.draw');

    unlink($dbPath);
});

test('lookup finds by short name', function () {
    $jsonPath = __DIR__ . '/../../src/Resources/love2d-api.json';
    $dbPath = sys_get_temp_dir() . '/love2d-doc-test-' . uniqid() . '.db';

    $store = new Love2DDocStore($jsonPath, $dbPath);

    // "DrawMode" is an enum — exact match without module prefix
    $entry = $store->lookup('DrawMode');
    expect($entry)->not->toBeNull();
    expect($entry['what'])->toBe('enum');

    unlink($dbPath);
});

test('lookup returns null for unknown name', function () {
    $jsonPath = __DIR__ . '/../../src/Resources/love2d-api.json';
    $dbPath = sys_get_temp_dir() . '/love2d-doc-test-' . uniqid() . '.db';

    $store = new Love2DDocStore($jsonPath, $dbPath);

    $entry = $store->lookup('nonexistent.function.name');
    expect($entry)->toBeNull();

    unlink($dbPath);
});

test('lookup returns enum with constants', function () {
    $jsonPath = __DIR__ . '/../../src/Resources/love2d-api.json';
    $dbPath = sys_get_temp_dir() . '/love2d-doc-test-' . uniqid() . '.db';

    $store = new Love2DDocStore($jsonPath, $dbPath);

    $entry = $store->lookup('DrawMode');
    expect($entry)->not->toBeNull();
    expect($entry['constants'])->toBeArray();
    expect($entry['constants'])->not->toBeEmpty();

    $names = array_column($entry['constants'], 'name');
    expect($names)->toContain('fill');
    expect($names)->toContain('line');

    unlink($dbPath);
});

test('lookup returns type with constructors', function () {
    $jsonPath = __DIR__ . '/../../src/Resources/love2d-api.json';
    $dbPath = sys_get_temp_dir() . '/love2d-doc-test-' . uniqid() . '.db';

    $store = new Love2DDocStore($jsonPath, $dbPath);

    $entry = $store->lookup('Canvas');
    expect($entry)->not->toBeNull();
    expect($entry['what'])->toBe('type');
    expect($entry['constructors'])->toBeArray();

    unlink($dbPath);
});

test('listModules returns all modules', function () {
    $jsonPath = __DIR__ . '/../../src/Resources/love2d-api.json';
    $dbPath = sys_get_temp_dir() . '/love2d-doc-test-' . uniqid() . '.db';

    $store = new Love2DDocStore($jsonPath, $dbPath);

    $modules = $store->listModules();
    expect($modules)->not->toBeEmpty();

    $names = array_column($modules, 'module');
    expect($names)->toContain('graphics');
    expect($names)->toContain('audio');
    expect($names)->toContain('physics');
    expect($names)->toContain('love');

    unlink($dbPath);
});

test('reuses cached database on second instantiation', function () {
    $jsonPath = __DIR__ . '/../../src/Resources/love2d-api.json';
    $dbPath = sys_get_temp_dir() . '/love2d-doc-test-' . uniqid() . '.db';

    // First: create and populate
    $store1 = new Love2DDocStore($jsonPath, $dbPath);
    $count1 = $store1->entryCount();

    // Second: reuse cache (should not re-import if not stale)
    $store2 = new Love2DDocStore($jsonPath, $dbPath);
    $count2 = $store2->entryCount();

    expect($count1)->toBe($count2);

    unlink($dbPath);
});
