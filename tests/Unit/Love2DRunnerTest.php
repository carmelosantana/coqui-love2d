<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitLove2D\Runtime\Love2DRunner;

test('projectsDir is within workspace', function () {
    $runner = new Love2DRunner(workspacePath: '/tmp/test-workspace');

    expect($runner->projectsDir())->toBe('/tmp/test-workspace/projects');
});

test('projectsDir strips trailing slash', function () {
    $runner = new Love2DRunner(workspacePath: '/tmp/test-workspace/');

    expect($runner->projectsDir())->toBe('/tmp/test-workspace/projects');
});

test('resolveProjectPath blocks path traversal', function () {
    $tmpDir = sys_get_temp_dir() . '/love2d-test-' . uniqid();
    mkdir($tmpDir, 0755, true);

    $runner = new Love2DRunner(workspacePath: $tmpDir);

    expect($runner->resolveProjectPath('../../../etc'))->toBeNull();
    expect($runner->resolveProjectPath('../../etc/passwd'))->toBeNull();

    rmdir($tmpDir);
});

test('resolveProjectPath accepts valid names', function () {
    $tmpDir = sys_get_temp_dir() . '/love2d-test-' . uniqid();
    mkdir($tmpDir . '/my-game', 0755, true);

    $runner = new Love2DRunner(workspacePath: $tmpDir);
    $resolved = $runner->resolveProjectPath('my-game');

    expect($resolved)->toBe(realpath($tmpDir . '/my-game'));

    rmdir($tmpDir . '/my-game');
    rmdir($tmpDir);
});

test('createProject scaffolds directory structure', function () {
    $tmpDir = sys_get_temp_dir() . '/love2d-test-' . uniqid();
    mkdir($tmpDir, 0755, true);

    $runner = new Love2DRunner(workspacePath: $tmpDir);
    $result = $runner->createProject([
        'name' => 'test-project',
        'title' => 'Test Project',
        'width' => 800,
        'height' => 600,
    ]);

    expect($result['success'])->toBeTrue();

    $projectPath = $tmpDir . '/projects/test-project';
    expect(is_dir($projectPath))->toBeTrue();
    expect(is_file($projectPath . '/main.lua'))->toBeTrue();
    expect(is_file($projectPath . '/conf.lua'))->toBeTrue();
    expect(is_dir($projectPath . '/assets'))->toBeTrue();
    expect(is_dir($projectPath . '/lib'))->toBeTrue();
    expect(is_file($projectPath . '/lib/coqui_api.lua'))->toBeTrue();
    expect($result['path'])->toBe('projects/test-project');
    expect($result['debug_directory'])->toBe('projects/test-project/.coqui/love2d/logs');

    // Cleanup
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
    rmdir($tmpDir);
});

test('createProject rejects duplicate names', function () {
    $tmpDir = sys_get_temp_dir() . '/love2d-test-' . uniqid();
    mkdir($tmpDir, 0755, true);

    $runner = new Love2DRunner(workspacePath: $tmpDir);

    $first = $runner->createProject(['name' => 'my-game']);
    expect($first['success'])->toBeTrue();

    $second = $runner->createProject(['name' => 'my-game']);
    expect($second['success'])->toBeFalse();
    expect($second['message'])->toContain('already exists');

    // Cleanup
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
    rmdir($tmpDir);
});

test('createProject with template applies template files', function () {
    $tmpDir = sys_get_temp_dir() . '/love2d-test-' . uniqid();
    mkdir($tmpDir, 0755, true);

    $runner = new Love2DRunner(workspacePath: $tmpDir);
    $result = $runner->createProject([
        'name' => 'platformer-game',
        'template' => 'platformer',
        'title' => 'My Platformer',
    ]);

    expect($result['success'])->toBeTrue();

    $mainLua = file_get_contents($tmpDir . '/projects/platformer-game/main.lua');
    expect($mainLua)->toContain('Platformer Template');

    $confLua = file_get_contents($tmpDir . '/projects/platformer-game/conf.lua');
    expect($confLua)->toContain("t.version = '11.5'");
    expect($confLua)->toContain('t.console = true');

    // Cleanup
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
    rmdir($tmpDir);
});

test('listInstances returns empty when no instances running', function () {
    $tmpDir = sys_get_temp_dir() . '/love2d-test-' . uniqid();
    mkdir($tmpDir, 0755, true);

    $runner = new Love2DRunner(workspacePath: $tmpDir);
    $instances = $runner->listInstances();

    expect($instances)->toBe([]);

    rmdir($tmpDir);
});

test('createProject can scaffold into an explicit project directory', function () {
    $tmpDir = sys_get_temp_dir() . '/love2d-test-' . uniqid();
    mkdir($tmpDir . '/projects/existing-root', 0755, true);

    $runner = new Love2DRunner(workspacePath: $tmpDir);
    $result = $runner->createProject([
        'name' => 'test-project',
        'project' => 'projects/existing-root',
    ]);

    expect($result['success'])->toBeTrue();
    expect($result['path'])->toBe('projects/existing-root');
    expect(is_file($tmpDir . '/projects/existing-root/main.lua'))->toBeTrue();

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
    rmdir($tmpDir);
});
