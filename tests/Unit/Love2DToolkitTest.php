<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitLove2D\Love2DToolkit;

test('toolkit implements ToolkitInterface', function () {
    $toolkit = new Love2DToolkit(workspacePath: sys_get_temp_dir());

    expect($toolkit)->toBeInstanceOf(\CarmeloSantana\PHPAgents\Contract\ToolkitInterface::class);
});

test('tools returns all three love2d tools', function () {
    $toolkit = new Love2DToolkit(workspacePath: sys_get_temp_dir());
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(3);

    $names = array_map(fn($tool) => $tool->name(), $tools);
    expect($names)->toBe(['love2d', 'love2d_template', 'love2d_log']);
});

test('each tool implements ToolInterface', function () {
    $toolkit = new Love2DToolkit(workspacePath: sys_get_temp_dir());
    $tools = $toolkit->tools();

    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(\CarmeloSantana\PHPAgents\Contract\ToolInterface::class);
    }
});

test('guidelines returns non-empty string with XML tag', function () {
    $toolkit = new Love2DToolkit(workspacePath: sys_get_temp_dir());
    $guidelines = $toolkit->guidelines();

    expect($guidelines)->toBeString();
    expect($guidelines === '')->toBeFalse();
    expect($guidelines)->toContain('LOVE2D-TOOLKIT-GUIDELINES');
});

test('fromEnv creates instance', function () {
    $toolkit = Love2DToolkit::fromEnv();

    expect($toolkit)->toBeInstanceOf(Love2DToolkit::class);
});
