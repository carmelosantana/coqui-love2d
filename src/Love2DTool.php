<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitLove2D;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\CoquiToolkitLove2D\Runtime\Love2DRunner;

/**
 * Tool for managing Love2D game projects and instances.
 *
 * Provides create, run, stop, status, list, build, export_web, and stop_all
 * actions for Love2D game development lifecycle management.
 */
final class Love2DTool implements ToolInterface
{
    public function __construct(
        private readonly Love2DRunner $runner,
    ) {}

    public function name(): string
    {
        return 'love2d';
    }

    public function description(): string
    {
        return <<<'DESC'
            Manage Love2D game projects and instances.

            Available actions:
            - create: Scaffold a new Love2D project with main.lua, conf.lua, and directory structure.
            - run: Launch a Love2D game in a native window (requires `love` binary).
            - stop: Stop a running Love2D instance.
            - status: Check if a Love2D instance is running.
            - list: List all managed Love2D instances.
            - build: Create a .love archive from a project directory.
            - export_web: Export a project for browser play via love.js (requires Node.js + love.js).
            - stop_all: Stop all running Love2D instances.
            DESC;
    }

    public function parameters(): array
    {
        return [];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        return match ($action) {
            'create' => $this->createProject($input),
            'run' => $this->runProject($input),
            'stop' => $this->stopInstance($input),
            'status' => $this->instanceStatus($input),
            'list' => $this->listInstances(),
            'build' => $this->buildArchive($input),
            'export_web' => $this->exportWeb($input),
            'stop_all' => $this->stopAll(),
            default => ToolResult::error("Unknown love2d action: '{$action}'"),
        };
    }

    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'description' => 'The Love2D management action to perform.',
                            'enum' => ['create', 'run', 'stop', 'status', 'list', 'build', 'export_web', 'stop_all'],
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'Project name (for "create") or instance name (for "run", "stop", "status"). For "create", this becomes the default directory name when no explicit project path is given.',
                        ],
                        'project' => [
                            'type' => 'string',
                            'description' => 'Project directory path relative to workspace. For "create", this is an optional target scaffold directory (for example "projects/my-game" or an existing Coqui project directory). For "run", "build", and "export_web", this is the Love2D project root.',
                        ],
                        'title' => [
                            'type' => 'string',
                            'description' => 'Game window title. Defaults to the project name. Used with "create".',
                        ],
                        'width' => [
                            'type' => 'integer',
                            'description' => 'Window width in pixels. Default: 800. Used with "create".',
                        ],
                        'height' => [
                            'type' => 'integer',
                            'description' => 'Window height in pixels. Default: 600. Used with "create".',
                        ],
                        'output' => [
                            'type' => 'string',
                            'description' => 'Output directory for web export, relative to workspace. Defaults to "{project}/web". Used with "export_web".',
                        ],
                        'compatibility' => [
                            'type' => 'boolean',
                            'description' => 'Use love.js compatibility mode (no SharedArrayBuffer, wider browser support). Default: true. Used with "export_web".',
                        ],
                    ],
                    'required' => ['action'],
                ],
            ],
        ];
    }

    // ── Action Handlers ─────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     */
    private function createProject(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            return ToolResult::error(
                'The "name" parameter is required for the "create" action. '
                . 'Specify a name for your Love2D project (e.g. "my-platformer").',
            );
        }

        $options = ['name' => $name];

        if (isset($input['title'])) {
            $options['title'] = (string) $input['title'];
        }
        if (isset($input['width'])) {
            $options['width'] = (int) $input['width'];
        }
        if (isset($input['height'])) {
            $options['height'] = (int) $input['height'];
        }
        if (isset($input['project'])) {
            $options['project'] = (string) $input['project'];
        }

        $result = $this->runner->createProject($options);

        if (!$result['success']) {
            return ToolResult::error("## Project Creation Failed\n\n" . $result['message']);
        }

        $path = $result['path'] ?? '';

        $output = "## Project Created\n\n";
        $output .= "| Setting | Value |\n|---------|-------|\n";
        $output .= "| **Name** | `{$name}` |\n";
        $output .= "| **Path** | {$path} |\n";
        $output .= "| **Debug Logs** | " . ($result['debug_directory'] ?? '-') . " |\n";
        $output .= "| **Title** | " . ($options['title'] ?? ucwords(str_replace(['-', '_'], ' ', $name))) . " |\n";
        $output .= "| **Size** | " . ($options['width'] ?? 800) . "×" . ($options['height'] ?? 600) . " |\n";
        $output .= "| **Toolkit Target** | " . ($result['supported_love_version'] ?? $this->runner->supportedLoveVersion()) . " |\n";
        $output .= "| **Detected Runtime** | " . (($result['detected_love_version'] ?? null) ?: 'Not detected') . " |\n";
        $output .= "\n### Project Structure\n\n";
        $output .= "```\n";
        $output .= "{$name}/\n";
        $output .= "├── main.lua          ← Write your game code here\n";
        $output .= "├── conf.lua          ← Window and module configuration\n";
        $output .= "├── lib/\n";
        $output .= "│   └── coqui_api.lua ← Bot communication bridge\n";
        $output .= "├── assets/\n";
        $output .= "│   ├── sprites/\n";
        $output .= "│   ├── sounds/\n";
        $output .= "│   └── fonts/\n";
        $output .= "└── .gitignore\n";
        $output .= "```\n";
        $output .= "\n### Next Steps\n\n";
        $output .= "1. Write game code in `main.lua` (implement `love.load()`, `love.update(dt)`, `love.draw()`)\n";
        $output .= "2. Native test: `love2d` action `run` with `project: \"{$path}\"`\n";
        $output .= "3. Web export: `love2d` action `export_web` with `project: \"{$path}\"`\n";
        $output .= "4. Serve web build: `webserver` action `start` with `docroot: \"{$path}/web/release\"`\n";
        $output .= "5. Startup logs live in `{$result['debug_directory']}` and each run updates `latest.log` there\n";

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runProject(array $input): ToolResult
    {
        $project = trim((string) ($input['project'] ?? ''));

        if ($project === '') {
            return ToolResult::error(
                'The "project" parameter is required for the "run" action. '
                . 'Specify the project directory relative to workspace (e.g. "love2d/projects/my-game").',
            );
        }

        $options = ['project' => $project];

        if (isset($input['name'])) {
            $options['name'] = (string) $input['name'];
        }

        $result = $this->runner->start($options);

        if (!$result['success']) {
            return ToolResult::error("## Run Failed\n\n" . $result['message']);
        }

        $output = "## Love2D Running\n\n";
        $output .= "| Setting | Value |\n|---------|-------|\n";
        $output .= "| **Instance** | `" . ($result['name'] ?? '') . "` |\n";
        $output .= "| **PID** | " . ($result['pid'] ?? '') . " |\n";
        $output .= "| **Project** | " . ($result['project'] ?? '') . " |\n";
        $output .= "| **Run Log** | " . ($result['log_file'] ?? '-') . " |\n";
        $output .= "| **Stable Log** | " . ($result['latest_log'] ?? '-') . " |\n";
        $output .= "| **Debug Directory** | " . ($result['debug_directory'] ?? '-') . " |\n";
        $output .= "| **Love2D Binary** | " . ($result['love_binary'] ?? '-') . " |\n";
        $output .= "| **Detected Version** | " . (($result['love_version'] ?? null) ?: 'Unknown') . " |\n";
        $output .= "| **Toolkit Target** | " . ($result['supported_love_version'] ?? $this->runner->supportedLoveVersion()) . " |\n";
        $output .= "\nThe game window should now be visible. If startup looks wrong, inspect `" . ($result['latest_log'] ?? ($result['log_file'] ?? 'the debug log')) . "` or run `love2d_log` for this instance.";

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function stopInstance(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            return ToolResult::error('The "name" parameter is required for the "stop" action.');
        }

        $result = $this->runner->stop($name);

        if (!$result['success']) {
            return ToolResult::error("## Stop Failed\n\n" . $result['message']);
        }

        return ToolResult::success("## Instance Stopped\n\n" . $result['message']);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function instanceStatus(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            return ToolResult::error('The "name" parameter is required for the "status" action.');
        }

        $status = $this->runner->status($name);

        $output = "## Instance Status: {$name}\n\n";

        if (!$status['running']) {
            $output .= "**Status:** Not running\n";
            if (isset($status['latest_log']) && $status['latest_log'] !== '') {
                $output .= "**Latest log:** " . $status['latest_log'] . "\n";
            }
            return ToolResult::success($output);
        }

        $output .= "| Setting | Value |\n|---------|-------|\n";
        $output .= "| **Status** | Running |\n";
        $output .= "| **PID** | " . ($status['pid'] ?? '-') . " |\n";
        $output .= "| **Project** | " . ($status['project'] ?? '-') . " |\n";
        $output .= "| **Uptime** | " . ($status['uptime'] ?? '-') . " |\n";
        $output .= "| **Started** | " . ($status['started_at'] ?? '-') . " |\n";
        $output .= "| **Run Log** | " . ($status['log_file'] ?? '-') . " |\n";
        $output .= "| **Stable Log** | " . ($status['latest_log'] ?? '-') . " |\n";
        $output .= "| **Debug Directory** | " . ($status['debug_directory'] ?? '-') . " |\n";
        $output .= "| **Love2D Binary** | " . ($status['love_binary'] ?? '-') . " |\n";
        $output .= "| **Detected Version** | " . (($status['love_version'] ?? null) ?: 'Unknown') . " |\n";
        $output .= "| **Toolkit Target** | " . ($status['supported_love_version'] ?? $this->runner->supportedLoveVersion()) . " |\n";

        return ToolResult::success($output);
    }

    private function listInstances(): ToolResult
    {
        $instances = $this->runner->listInstances();

        if ($instances === []) {
            return ToolResult::success("## Love2D Instances\n\nNo managed instances found.");
        }

        $output = "## Love2D Instances\n\n";
        $output .= "| Name | Status | Project | Uptime | Latest Log |\n";
        $output .= "|------|--------|---------|--------|------------|\n";

        foreach ($instances as $instance) {
            $status = $instance['running'] ? 'Running' : 'Stopped';
            $project = $instance['project'] ?? '-';
            $uptime = $instance['uptime'] ?? '-';
            $latestLog = $instance['latest_log'] ?? $instance['log_file'] ?? '-';
            $output .= "| `{$instance['name']}` | {$status} | {$project} | {$uptime} | {$latestLog} |\n";
        }

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function buildArchive(array $input): ToolResult
    {
        $project = trim((string) ($input['project'] ?? ''));

        if ($project === '') {
            return ToolResult::error(
                'The "project" parameter is required for the "build" action.',
            );
        }

        $result = $this->runner->buildArchive($project);

        if (!$result['success']) {
            return ToolResult::error("## Build Failed\n\n" . $result['message']);
        }

        $size = $result['size'] ?? 0;
        $sizeFormatted = $this->formatBytes($size);

        $output = "## Archive Built\n\n";
        $output .= "| Setting | Value |\n|---------|-------|\n";
        $output .= "| **Archive** | " . ($result['archive'] ?? '') . " |\n";
        $output .= "| **Size** | {$sizeFormatted} |\n";

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function exportWeb(array $input): ToolResult
    {
        $project = trim((string) ($input['project'] ?? ''));

        if ($project === '') {
            return ToolResult::error(
                'The "project" parameter is required for the "export_web" action.',
            );
        }

        $outputDir = trim((string) ($input['output'] ?? ''));
        $compatibility = (bool) ($input['compatibility'] ?? true);

        $result = $this->runner->exportWeb($project, $outputDir, $compatibility);

        if (!$result['success']) {
            return ToolResult::error("## Web Export Failed\n\n" . $result['message']);
        }

        $outputPath = $result['output'] ?? '';

        $output = "## Web Export Complete\n\n";
        $output .= "| Setting | Value |\n|---------|-------|\n";
        $output .= "| **Output** | {$outputPath} |\n";
        $output .= "| **Mode** | " . ($compatibility ? 'Compatibility (broad browser support)' : 'Standard (pthreads, needs COOP/COEP headers)') . " |\n";
        $output .= "\n### Serve the Game\n\n";
        $output .= "Use the `webserver` tool to serve the exported directory:\n";
        $output .= "```\nwebserver action: start, docroot: \"{$outputPath}\"\n```\n";

        return ToolResult::success($output);
    }

    private function stopAll(): ToolResult
    {
        $result = $this->runner->stopAll();

        $output = "## Stop All Instances\n\n";

        foreach ($result['messages'] as $msg) {
            $output .= "- {$msg}\n";
        }

        if ($result['stopped'] > 0 || $result['failed'] > 0) {
            $output .= "\n**Stopped:** {$result['stopped']} | **Failed:** {$result['failed']}";
        }

        return ToolResult::success($output);
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 1) . ' MB';
    }
}
