<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Love2D\Runtime;

/**
 * Manages the lifecycle of Love2D game processes and project operations.
 *
 * Spawns `love` as a detached background process, tracks it via PID files,
 * and provides start/stop/status/list operations. Handles project scaffolding,
 * archive building, and love.js web export.
 *
 * All runtime files (PID, metadata, logs) are stored in `.workspace/love2d/`.
 * Game projects live in `.workspace/love2d/projects/`.
 */
final class Love2DRunner
{
    private const int STOP_TIMEOUT_MS = 2000;
    private const int STOP_CHECK_INTERVAL_MS = 50;

    private ?string $loveBinaryCache = null;
    private bool $loveBinarySearched = false;

    public function __construct(
        private readonly string $workspacePath,
    ) {}

    // ── Process Management ──────────────────────────────────────────────

    /**
     * Start a Love2D game instance.
     *
     * @param array{project: string, name?: string} $options
     * @return array{success: bool, message: string, name?: string, pid?: int, project?: string}
     */
    public function start(array $options): array
    {
        $project = $options['project'];
        $resolvedProject = $this->resolveProjectPath($project);

        if ($resolvedProject === null) {
            return [
                'success' => false,
                'message' => "Invalid project path: '{$project}' — must be within the workspace directory.",
            ];
        }

        if (!is_dir($resolvedProject)) {
            return [
                'success' => false,
                'message' => "Project directory not found: '{$project}'.",
            ];
        }

        if (!is_file($resolvedProject . '/main.lua')) {
            return [
                'success' => false,
                'message' => "Not a valid Love2D project: '{$project}' — missing main.lua.",
            ];
        }

        $loveBinary = $this->findLoveBinary();
        if ($loveBinary === null) {
            return [
                'success' => false,
                'message' => "Love2D binary not found. Install Love2D (https://love2d.org) and ensure 'love' is in your PATH.",
            ];
        }

        $name = $options['name'] ?? '';
        if ($name === '') {
            $name = $this->generateName($project);
        }

        // Check if already running
        $existingStatus = $this->status($name);
        if ($existingStatus['running']) {
            $pid = $existingStatus['pid'] ?? 0;
            return [
                'success' => false,
                'message' => "Instance '{$name}' is already running (PID {$pid}). Stop it first or use a different name.",
            ];
        }

        // Ensure runtime directory exists
        $runtimeDir = $this->runtimeDir();
        if (!is_dir($runtimeDir)) {
            mkdir($runtimeDir, 0755, true);
        }

        $logFile = $runtimeDir . "/{$name}.log";

        // Spawn Love2D as a detached process
        $command = sprintf(
            'nohup %s %s > %s 2>&1 & echo $!',
            escapeshellarg($loveBinary),
            escapeshellarg($resolvedProject),
            escapeshellarg($logFile),
        );

        $pid = $this->spawnProcess($command);

        if ($pid === null) {
            return ['success' => false, 'message' => 'Failed to start Love2D process.'];
        }

        // Give it a moment to start
        usleep(300_000); // 300ms

        if (!$this->isProcessAlive($pid)) {
            $logContent = is_file($logFile) ? file_get_contents($logFile) : '';
            $this->cleanupFiles($name);

            return [
                'success' => false,
                'message' => "Love2D process exited immediately.\n" . ($logContent ?: 'No log output.'),
            ];
        }

        // Write PID and metadata
        file_put_contents($runtimeDir . "/{$name}.pid", (string) $pid);

        $metadata = [
            'name' => $name,
            'pid' => $pid,
            'project' => $project,
            'project_absolute' => $resolvedProject,
            'started_at' => date('c'),
        ];
        file_put_contents(
            $runtimeDir . "/{$name}.json",
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        return [
            'success' => true,
            'message' => "Love2D instance '{$name}' started.",
            'name' => $name,
            'pid' => $pid,
            'project' => $project,
        ];
    }

    /**
     * Stop a running Love2D instance.
     *
     * @return array{success: bool, message: string}
     */
    public function stop(string $name): array
    {
        if ($name === '') {
            return ['success' => false, 'message' => 'Instance name is required.'];
        }

        $pidFile = $this->runtimeDir() . "/{$name}.pid";

        if (!is_file($pidFile)) {
            return ['success' => false, 'message' => "No instance found with name '{$name}'."];
        }

        $pid = (int) file_get_contents($pidFile);

        if ($pid <= 0) {
            $this->cleanupFiles($name);
            return ['success' => false, 'message' => "Invalid PID for instance '{$name}'. Files cleaned up."];
        }

        if (!$this->isProcessAlive($pid)) {
            $this->cleanupFiles($name);
            return ['success' => true, 'message' => "Instance '{$name}' was not running. Files cleaned up."];
        }

        // Send SIGTERM first
        posix_kill($pid, SIGTERM);

        // Wait for graceful shutdown
        $waited = 0;
        while ($waited < self::STOP_TIMEOUT_MS) {
            usleep(self::STOP_CHECK_INTERVAL_MS * 1000);
            $waited += self::STOP_CHECK_INTERVAL_MS;

            if (!$this->isProcessAlive($pid)) { // @phpstan-ignore booleanNot.alwaysFalse
                $this->cleanupFiles($name);
                return ['success' => true, 'message' => "Instance '{$name}' stopped gracefully."];
            }
        }

        // Force kill
        posix_kill($pid, SIGKILL);
        usleep(100_000); // 100ms grace

        $this->cleanupFiles($name);

        return ['success' => true, 'message' => "Instance '{$name}' killed (did not respond to SIGTERM)."];
    }

    /**
     * Get the status of a Love2D instance.
     *
     * @return array{running: bool, name: string, pid?: int, project?: string, started_at?: string, uptime?: string}
     */
    public function status(string $name): array
    {
        if ($name === '') {
            return ['running' => false, 'name' => ''];
        }

        $metaFile = $this->runtimeDir() . "/{$name}.json";

        if (!is_file($metaFile)) {
            return ['running' => false, 'name' => $name];
        }

        $meta = json_decode(file_get_contents($metaFile) ?: '{}', true);
        if (!is_array($meta)) {
            return ['running' => false, 'name' => $name];
        }

        $pid = (int) ($meta['pid'] ?? 0);
        $running = $pid > 0 && $this->isProcessAlive($pid);

        if (!$running) {
            $this->cleanupFiles($name);
            return ['running' => false, 'name' => $name];
        }

        $startedAt = (string) ($meta['started_at'] ?? '');
        $uptime = $this->calculateUptime($startedAt);

        return [
            'running' => true,
            'name' => $name,
            'pid' => $pid,
            'project' => (string) ($meta['project'] ?? ''),
            'started_at' => $startedAt,
            'uptime' => $uptime,
        ];
    }

    /**
     * List all managed Love2D instances.
     *
     * @return array<int, array{running: bool, name: string, pid?: int, project?: string, started_at?: string, uptime?: string}>
     */
    public function listInstances(): array
    {
        $runtimeDir = $this->runtimeDir();
        if (!is_dir($runtimeDir)) {
            return [];
        }

        $pidFiles = glob($runtimeDir . '/*.pid');
        if ($pidFiles === false) {
            return [];
        }

        $instances = [];
        foreach ($pidFiles as $pidFile) {
            $name = basename($pidFile, '.pid');
            $instances[] = $this->status($name);
        }

        return $instances;
    }

    /**
     * Stop all running Love2D instances.
     *
     * @return array{stopped: int, failed: int, messages: string[]}
     */
    public function stopAll(): array
    {
        $instances = $this->listInstances();
        $stopped = 0;
        $failed = 0;
        $messages = [];

        foreach ($instances as $instance) {
            if (!$instance['running']) {
                continue;
            }

            $result = $this->stop($instance['name']);
            if ($result['success']) {
                $stopped++;
            } else {
                $failed++;
            }
            $messages[] = $result['message'];
        }

        if ($stopped === 0 && $failed === 0) {
            $messages[] = 'No running instances found.';
        }

        return ['stopped' => $stopped, 'failed' => $failed, 'messages' => $messages];
    }

    // ── Project Operations ──────────────────────────────────────────────

    /**
     * Create a new Love2D project directory with initial files.
     *
     * @param array{name: string, title?: string, width?: int, height?: int, template?: string} $options
     * @return array{success: bool, message: string, path?: string}
     */
    public function createProject(array $options): array
    {
        $name = $options['name'];
        if ($name === '') {
            return ['success' => false, 'message' => 'Project name is required.'];
        }

        // Sanitize name for filesystem
        $safeName = (string) preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
        $projectDir = $this->projectsDir() . '/' . $safeName;

        if (is_dir($projectDir)) {
            return [
                'success' => false,
                'message' => "Project '{$safeName}' already exists at love2d/projects/{$safeName}.",
            ];
        }

        if (!mkdir($projectDir, 0755, true)) {
            return ['success' => false, 'message' => "Failed to create project directory."];
        }

        // Create subdirectories
        mkdir($projectDir . '/assets', 0755, true);
        mkdir($projectDir . '/assets/sprites', 0755, true);
        mkdir($projectDir . '/assets/sounds', 0755, true);
        mkdir($projectDir . '/assets/fonts', 0755, true);
        mkdir($projectDir . '/lib', 0755, true);

        $title = $options['title'] ?? ucwords(str_replace(['-', '_'], ' ', $safeName));
        $width = $options['width'] ?? 800;
        $height = $options['height'] ?? 600;

        // Determine template
        $template = $options['template'] ?? 'blank';
        $templateDir = dirname(__DIR__) . '/Resources/templates/' . $template;

        if (is_dir($templateDir)) {
            // Copy template files with placeholder replacement
            $this->applyTemplate($templateDir, $projectDir, $title, $width, $height);
        } else {
            // Fallback: write default conf.lua
            $confLua = $this->generateConfLua($title, $width, $height);
            file_put_contents($projectDir . '/conf.lua', $confLua);

            // Write a minimal main.lua
            file_put_contents($projectDir . '/main.lua', $this->generateDefaultMainLua($title));
        }

        // Copy the Coqui API bridge library
        $bridgeSource = dirname(__DIR__) . '/Resources/coqui_api.lua';
        if (is_file($bridgeSource)) {
            copy($bridgeSource, $projectDir . '/lib/coqui_api.lua');
        }

        // Write .gitignore
        file_put_contents($projectDir . '/.gitignore', "*.love\n/web/\n");

        $relativePath = 'love2d/projects/' . $safeName;

        return [
            'success' => true,
            'message' => "Project '{$safeName}' created at {$relativePath}.",
            'path' => $relativePath,
        ];
    }

    /**
     * Build a .love archive from a project directory.
     *
     * @return array{success: bool, message: string, archive?: string, size?: int}
     */
    public function buildArchive(string $projectPath): array
    {
        $resolved = $this->resolveProjectPath($projectPath);

        if ($resolved === null) {
            return ['success' => false, 'message' => "Invalid project path: '{$projectPath}'."];
        }

        if (!is_dir($resolved)) {
            return ['success' => false, 'message' => "Project directory not found: '{$projectPath}'."];
        }

        if (!is_file($resolved . '/main.lua')) {
            return ['success' => false, 'message' => "Not a valid Love2D project: missing main.lua."];
        }

        $archiveName = basename($resolved) . '.love';
        $archivePath = $resolved . '/' . $archiveName;

        // Remove existing archive
        if (is_file($archivePath)) {
            unlink($archivePath);
        }

        $zip = new \ZipArchive();
        $result = $zip->open($archivePath, \ZipArchive::CREATE);

        if ($result !== true) {
            return ['success' => false, 'message' => 'Failed to create ZIP archive.'];
        }

        $this->addDirectoryToZip($zip, $resolved, $resolved);
        $zip->close();

        $size = filesize($archivePath);

        return [
            'success' => true,
            'message' => "Archive built: {$archiveName}",
            'archive' => $archivePath,
            'size' => $size !== false ? $size : 0,
        ];
    }

    /**
     * Export a Love2D project for web play via love.js.
     *
     * @return array{success: bool, message: string, output?: string}
     */
    public function exportWeb(string $projectPath, string $outputDir = '', bool $compatibility = true): array
    {
        $loveJs = $this->findLoveJs();
        if ($loveJs === null) {
            return [
                'success' => false,
                'message' => "love.js not found. Install it via: npm install -g love.js\n"
                    . "Node.js is required for web export.",
            ];
        }

        // First build the .love archive
        $archiveResult = $this->buildArchive($projectPath);
        if (!$archiveResult['success']) {
            return $archiveResult;
        }

        $archivePath = $archiveResult['archive'] ?? '';
        $resolved = $this->resolveProjectPath($projectPath);

        if ($resolved === null || $archivePath === '') {
            return ['success' => false, 'message' => 'Failed to resolve project path.'];
        }

        if ($outputDir === '') {
            $outputDir = $resolved . '/web';
        } else {
            $resolvedOutput = $this->resolveProjectPath($outputDir);
            if ($resolvedOutput === null) {
                return ['success' => false, 'message' => "Invalid output directory: '{$outputDir}'."];
            }
            $outputDir = $resolvedOutput;
        }

        // Clean output directory
        if (is_dir($outputDir)) {
            $this->removeDirectory($outputDir);
        }

        // Run love.js
        $args = [
            escapeshellarg($loveJs),
            escapeshellarg($archivePath),
            escapeshellarg($outputDir),
        ];

        if ($compatibility) {
            $args[] = '-c';
        }

        $command = implode(' ', $args) . ' 2>&1';
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            return [
                'success' => false,
                'message' => "love.js export failed (exit code {$exitCode}):\n" . implode("\n", $output),
            ];
        }

        // Look for the actual output — love.js creates release/ or debug/ subdirs
        $serveDir = $outputDir;
        if (is_dir($outputDir . '/release')) {
            $serveDir = $outputDir . '/release';
        }

        // Copy custom index.html template if available
        $customIndex = dirname(__DIR__) . '/Resources/index.html';
        if (is_file($customIndex) && is_dir($serveDir)) {
            copy($customIndex, $serveDir . '/index.html');
        }

        // Copy JS bridge for web communication
        $bridgeJs = dirname(__DIR__) . '/Resources/coqui_bridge.js';
        if (is_file($bridgeJs) && is_dir($serveDir)) {
            copy($bridgeJs, $serveDir . '/coqui_bridge.js');
        }

        // Clean up the .love archive
        if (is_file($archivePath)) {
            unlink($archivePath);
        }

        $relativeOutput = str_replace($this->workspacePath . '/', '', $serveDir);

        return [
            'success' => true,
            'message' => "Web export complete. Serve directory: {$relativeOutput}",
            'output' => $relativeOutput,
        ];
    }

    // ── Path Helpers ────────────────────────────────────────────────────

    /**
     * Get the Love2D runtime data directory within the workspace.
     */
    public function runtimeDir(): string
    {
        return rtrim($this->workspacePath, '/') . '/love2d';
    }

    /**
     * Get the Love2D projects directory within the workspace.
     */
    public function projectsDir(): string
    {
        return rtrim($this->workspacePath, '/') . '/love2d/projects';
    }

    /**
     * Get the log file path for an instance.
     */
    public function logPath(string $name): string
    {
        return $this->runtimeDir() . "/{$name}.log";
    }

    /**
     * Resolve a project path relative to the workspace.
     *
     * Returns null if the resolved path escapes the workspace root (path traversal protection).
     */
    public function resolveProjectPath(string $relativePath): ?string
    {
        // Strip leading slashes to prevent absolute path injection
        $relativePath = ltrim($relativePath, '/');

        $path = rtrim($this->workspacePath, '/') . '/' . $relativePath;
        $realWorkspace = realpath($this->workspacePath);

        if ($realWorkspace === false) {
            return null;
        }

        // If the directory exists, verify its real path
        $realPath = realpath($path);
        if ($realPath !== false) {
            if (!str_starts_with($realPath, $realWorkspace)) {
                return null;
            }
            return $realPath;
        }

        // Directory doesn't exist yet — verify the parent path
        $parentDir = dirname($path);
        $realParent = realpath($parentDir);
        if ($realParent === false || !str_starts_with($realParent, $realWorkspace)) {
            return null;
        }

        return $path;
    }

    /**
     * Generate a deterministic instance name from a project path.
     */
    public function generateName(string $project): string
    {
        $base = basename($project);
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '-', $base);

        return 'love-' . ($safe !== '' && $safe !== null ? $safe : substr(md5($project), 0, 8));
    }

    // ── Binary Detection ────────────────────────────────────────────────

    /**
     * Find the Love2D binary in the system PATH.
     */
    public function findLoveBinary(): ?string
    {
        if ($this->loveBinarySearched) {
            return $this->loveBinaryCache;
        }

        $this->loveBinarySearched = true;

        foreach (['love', 'love2d'] as $candidate) {
            $path = $this->whichBinary($candidate);
            if ($path !== null) {
                $this->loveBinaryCache = $path;
                return $path;
            }
        }

        return null;
    }

    /**
     * Find the love.js binary (npm package).
     */
    public function findLoveJs(): ?string
    {
        // Check for globally installed love.js
        $path = $this->whichBinary('love.js');
        if ($path !== null) {
            return $path;
        }

        // Check for npx availability
        $npx = $this->whichBinary('npx');
        if ($npx !== null) {
            return $npx . ' love.js';
        }

        return null;
    }

    // ── Private Helpers ─────────────────────────────────────────────────

    /**
     * Apply a template directory to a project, replacing placeholders.
     */
    private function applyTemplate(string $templateDir, string $projectDir, string $title, int $width, int $height): void
    {
        $replacements = [
            '{{TITLE}}' => $title,
            '{{WIDTH}}' => (string) $width,
            '{{HEIGHT}}' => (string) $height,
        ];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templateDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }

            $relativePath = substr((string) $item->getPathname(), strlen($templateDir) + 1);
            $targetPath = $projectDir . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                $content = file_get_contents($item->getPathname());
                if ($content !== false) {
                    $content = str_replace(
                        array_keys($replacements),
                        array_values($replacements),
                        $content,
                    );
                    file_put_contents($targetPath, $content);
                }
            }
        }
    }

    /**
     * Generate a minimal default main.lua when no template is available.
     */
    private function generateDefaultMainLua(string $title): string
    {
        return <<<LUA
            -- {$title} — main.lua

            local coqui = require('lib.coqui_api')

            function love.load()
                love.graphics.setBackgroundColor(0.15, 0.15, 0.2)
            end

            function love.update(dt)
                local response = coqui.poll()
            end

            function love.draw()
                love.graphics.setColor(1, 1, 1)
                love.graphics.printf(
                    '{$title}\\n\\nEdit main.lua to start building your game!',
                    0, love.graphics.getHeight() / 2 - 30,
                    love.graphics.getWidth(), 'center'
                )
            end

            function love.keypressed(key)
                if key == 'escape' then
                    love.event.quit()
                end
            end
            LUA;
    }

    private function generateConfLua(string $title, int $width, int $height): string
    {
        return <<<LUA
            function love.conf(t)
                t.identity = "{$title}"
                t.version = "11.5"

                t.window.title = "{$title}"
                t.window.width = {$width}
                t.window.height = {$height}
                t.window.resizable = true
                t.window.minwidth = 400
                t.window.minheight = 300

                -- Required for love.js Firefox compatibility
                t.window.depth = 16

                -- Disable unused modules for smaller web builds
                t.modules.joystick = false
                t.modules.video = false
            end
            LUA;
    }

    /**
     * Recursively add a directory to a ZipArchive, excluding build artifacts.
     */
    private function addDirectoryToZip(\ZipArchive $zip, string $dir, string $baseDir): void
    {
        $excludes = ['.git', 'node_modules', 'web', '.love', '.gitignore'];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            // Skip excluded paths
            $relativePath = substr($filePath, strlen($baseDir) + 1);
            $shouldExclude = false;
            foreach ($excludes as $exclude) {
                if (str_starts_with($relativePath, $exclude) || str_ends_with($relativePath, '.love')) {
                    $shouldExclude = true;
                    break;
                }
            }

            if (!$shouldExclude) {
                $zip->addFile($filePath, $relativePath);
            }
        }
    }

    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        return posix_kill($pid, 0);
    }

    private function spawnProcess(string $command): ?int
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->workspacePath);

        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        proc_close($process);

        $pid = (int) trim($output ?: '');

        return $pid > 0 ? $pid : null;
    }

    private function cleanupFiles(string $name): void
    {
        $runtimeDir = $this->runtimeDir();

        $files = [
            "{$runtimeDir}/{$name}.pid",
            "{$runtimeDir}/{$name}.json",
        ];

        foreach ($files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    private function calculateUptime(string $startedAt): string
    {
        if ($startedAt === '') {
            return 'unknown';
        }

        try {
            $start = new \DateTimeImmutable($startedAt);
            $now = new \DateTimeImmutable();
            $diff = $now->diff($start);
            $parts = [];
            if ($diff->d > 0) {
                $parts[] = "{$diff->d}d";
            }
            if ($diff->h > 0) {
                $parts[] = "{$diff->h}h";
            }
            $parts[] = "{$diff->i}m";
            return implode(' ', $parts);
        } catch (\Exception) {
            return 'unknown';
        }
    }

    private function whichBinary(string $name): ?string
    {
        $output = [];
        $exitCode = 0;
        exec('which ' . escapeshellarg($name) . ' 2>/dev/null', $output, $exitCode);

        if ($exitCode === 0 && isset($output[0]) && $output[0] !== '') {
            return $output[0];
        }

        return null;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            if ($file->isDir()) {
                @rmdir($file->getRealPath() ?: $file->getPathname());
            } else {
                @unlink($file->getRealPath() ?: $file->getPathname());
            }
        }

        @rmdir($dir);
    }
}
