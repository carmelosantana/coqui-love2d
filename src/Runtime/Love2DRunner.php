<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitLove2D\Runtime;

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
    private const int STARTUP_CHECK_DELAY_US = 500_000;
    private const string SUPPORTED_LOVE_VERSION = '11.5';

    private ?string $loveBinaryCache = null;
    private bool $loveBinarySearched = false;
    private ?string $loveVersionCache = null;
    private bool $loveVersionSearched = false;

    public function __construct(
        private readonly string $workspacePath,
    ) {}

    // ── Process Management ──────────────────────────────────────────────

    /**
     * Start a Love2D game instance.
     *
     * @param array{project: string, name?: string} $options
     * @return array<string, mixed>
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

        $loveVersion = $this->detectLoveVersion();

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

        $projectRelative = $this->relativeToWorkspace($resolvedProject);
        $paths = $this->prepareProjectDebugPaths($resolvedProject, $name);

        $metadata = [
            'name' => $name,
            'pid' => 0,
            'project' => $projectRelative,
            'project_absolute' => $resolvedProject,
            'started_at' => date('c'),
            'state' => 'launching',
            'debug_directory' => $paths['debug_directory'],
            'debug_directory_absolute' => $paths['debug_directory_absolute'],
            'log_file' => $paths['log_file'],
            'log_file_absolute' => $paths['log_file_absolute'],
            'latest_log' => $paths['latest_log'],
            'latest_log_absolute' => $paths['latest_log_absolute'],
            'love_binary' => $loveBinary,
            'love_version' => $loveVersion,
            'supported_love_version' => self::SUPPORTED_LOVE_VERSION,
        ];

        // Spawn Love2D as a detached process
        $command = sprintf(
            'nohup %s %s > %s 2>&1 & echo $!',
            escapeshellarg($loveBinary),
            escapeshellarg($resolvedProject),
            escapeshellarg($paths['log_file_absolute']),
        );

        $pid = $this->spawnProcess($command);

        if ($pid === null) {
            return [
                'success' => false,
                'message' => 'Failed to start Love2D process.',
                'project' => $projectRelative,
                'debug_directory' => $paths['debug_directory'],
                'log_file' => $paths['log_file'],
            ];
        }

        file_put_contents($this->pidPath($name), (string) $pid);
        $metadata['pid'] = $pid;
        $this->writeRunMetadata($name, $metadata);

        // Give it a moment to start
        usleep(self::STARTUP_CHECK_DELAY_US);

        if (!$this->isProcessAlive($pid)) {
            $excerpt = $this->readLogExcerpt($paths['log_file_absolute']);
            $this->removePidFile($name);
            $this->updateRunMetadata($name, [
                'state' => 'failed',
                'stopped_at' => date('c'),
            ]);

            return [
                'success' => false,
                'message' => $this->buildLaunchFailureMessage(
                    name: $name,
                    project: $projectRelative,
                    logFile: $paths['log_file'],
                    debugDirectory: $paths['debug_directory'],
                    loveBinary: $loveBinary,
                    loveVersion: $loveVersion,
                    excerpt: $excerpt,
                ),
                'name' => $name,
                'pid' => $pid,
                'project' => $projectRelative,
                'log_file' => $paths['log_file'],
                'debug_directory' => $paths['debug_directory'],
                'love_binary' => $loveBinary,
                'love_version' => $loveVersion,
            ];
        }

        $this->updateRunMetadata($name, [
            'state' => 'running',
            'pid' => $pid,
        ]);

        return [
            'success' => true,
            'message' => "Love2D instance '{$name}' started.",
            'name' => $name,
            'pid' => $pid,
            'project' => $projectRelative,
            'project_absolute' => $resolvedProject,
            'log_file' => $paths['log_file'],
            'debug_directory' => $paths['debug_directory'],
            'latest_log' => $paths['latest_log'],
            'love_binary' => $loveBinary,
            'love_version' => $loveVersion,
            'supported_love_version' => self::SUPPORTED_LOVE_VERSION,
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

        $pidFile = $this->pidPath($name);

        if (!is_file($pidFile)) {
            $meta = $this->loadRunMetadata($name);

            if ($meta !== null) {
                return [
                    'success' => true,
                    'message' => "Instance '{$name}' is not currently running. Latest logs remain available at " . ($meta['latest_log'] ?? 'the project debug directory') . '.',
                ];
            }

            return ['success' => false, 'message' => "No instance found with name '{$name}'."];
        }

        $pid = (int) file_get_contents($pidFile);

        if ($pid <= 0) {
            $this->removePidFile($name);
            $this->updateRunMetadata($name, [
                'state' => 'invalid-pid',
                'stopped_at' => date('c'),
            ]);

            return ['success' => false, 'message' => "Invalid PID for instance '{$name}'. Runtime state cleaned up."];
        }

        if (!$this->isProcessAlive($pid)) {
            $this->removePidFile($name);
            $this->updateRunMetadata($name, [
                'state' => 'stopped',
                'stopped_at' => date('c'),
            ]);

            return ['success' => true, 'message' => "Instance '{$name}' was not running. Runtime state cleaned up, logs preserved."];
        }

        // Send SIGTERM first
        posix_kill($pid, SIGTERM);

        // Wait for graceful shutdown
        $waited = 0;
        while ($waited < self::STOP_TIMEOUT_MS) {
            usleep(self::STOP_CHECK_INTERVAL_MS * 1000);
            $waited += self::STOP_CHECK_INTERVAL_MS;

            if (!$this->isProcessAlive($pid)) { // @phpstan-ignore booleanNot.alwaysFalse
                $this->removePidFile($name);
                $this->updateRunMetadata($name, [
                    'state' => 'stopped',
                    'stopped_at' => date('c'),
                ]);

                return ['success' => true, 'message' => "Instance '{$name}' stopped gracefully."];
            }
        }

        // Force kill
        posix_kill($pid, SIGKILL);
        usleep(100_000); // 100ms grace

        $this->removePidFile($name);
        $this->updateRunMetadata($name, [
            'state' => 'killed',
            'stopped_at' => date('c'),
        ]);

        return ['success' => true, 'message' => "Instance '{$name}' killed (did not respond to SIGTERM)."];
    }

    /**
     * Get the status of a Love2D instance.
     *
     * @return array<string, mixed>
     */
    public function status(string $name): array
    {
        if ($name === '') {
            return ['running' => false, 'name' => ''];
        }

        $meta = $this->loadRunMetadata($name);

        if ($meta === null) {
            return ['running' => false, 'name' => $name];
        }

        $pid = $this->readPid($name) ?? (int) ($meta['pid'] ?? 0);
        $running = $pid > 0 && $this->isProcessAlive($pid);

        if (!$running) {
            $this->removePidFile($name);

            return [
                'running' => false,
                'name' => $name,
                'pid' => $pid,
                'project' => (string) ($meta['project'] ?? ''),
                'project_absolute' => (string) ($meta['project_absolute'] ?? ''),
                'started_at' => (string) ($meta['started_at'] ?? ''),
                'uptime' => 'stopped',
                'state' => (string) ($meta['state'] ?? 'stopped'),
                'log_file' => (string) ($meta['log_file'] ?? ''),
                'log_file_absolute' => (string) ($meta['log_file_absolute'] ?? ''),
                'latest_log' => (string) ($meta['latest_log'] ?? ''),
                'latest_log_absolute' => (string) ($meta['latest_log_absolute'] ?? ''),
                'debug_directory' => (string) ($meta['debug_directory'] ?? ''),
                'debug_directory_absolute' => (string) ($meta['debug_directory_absolute'] ?? ''),
                'love_binary' => (string) ($meta['love_binary'] ?? ''),
                'love_version' => (string) ($meta['love_version'] ?? ''),
                'supported_love_version' => (string) ($meta['supported_love_version'] ?? self::SUPPORTED_LOVE_VERSION),
            ];
        }

        $startedAt = (string) ($meta['started_at'] ?? '');
        $uptime = $this->calculateUptime($startedAt);

        return [
            'running' => true,
            'name' => $name,
            'pid' => $pid,
            'project' => (string) ($meta['project'] ?? ''),
            'project_absolute' => (string) ($meta['project_absolute'] ?? ''),
            'started_at' => $startedAt,
            'uptime' => $uptime,
            'state' => 'running',
            'log_file' => (string) ($meta['log_file'] ?? ''),
            'log_file_absolute' => (string) ($meta['log_file_absolute'] ?? ''),
            'latest_log' => (string) ($meta['latest_log'] ?? ''),
            'latest_log_absolute' => (string) ($meta['latest_log_absolute'] ?? ''),
            'debug_directory' => (string) ($meta['debug_directory'] ?? ''),
            'debug_directory_absolute' => (string) ($meta['debug_directory_absolute'] ?? ''),
            'love_binary' => (string) ($meta['love_binary'] ?? ''),
            'love_version' => (string) ($meta['love_version'] ?? ''),
            'supported_love_version' => (string) ($meta['supported_love_version'] ?? self::SUPPORTED_LOVE_VERSION),
        ];
    }

    /**
     * List all managed Love2D instances.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listInstances(): array
    {
        $runtimeDir = $this->runtimeDir();
        if (!is_dir($runtimeDir)) {
            return [];
        }

        $metaFiles = glob($runtimeDir . '/*.json');
        if ($metaFiles === false) {
            return [];
        }

        $instances = [];
        sort($metaFiles);

        foreach ($metaFiles as $metaFile) {
            $name = basename($metaFile, '.json');
            $instances[] = $this->status($name);
        }

        return $instances;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listKnownRuns(?string $name = null, ?string $project = null): array
    {
        $runs = $this->listInstances();

        return array_values(array_filter(
            $runs,
            static function (array $run) use ($name, $project): bool {
                if ($name !== null && $name !== '' && ($run['name'] ?? '') !== $name) {
                    return false;
                }

                if ($project !== null && $project !== '' && ($run['project'] ?? '') !== ltrim($project, '/')) {
                    return false;
                }

                return true;
            },
        ));
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
     * @param array{name: string, title?: string, width?: int, height?: int, template?: string, project?: string, path?: string} $options
     * @return array<string, mixed>
     */
    public function createProject(array $options): array
    {
        $name = $options['name'];
        if ($name === '') {
            return ['success' => false, 'message' => 'Project name is required.'];
        }

        // Sanitize name for filesystem
        $safeName = (string) preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
        $targetPath = trim((string) ($options['project'] ?? $options['path'] ?? ''));

        if ($targetPath !== '') {
            $projectDir = $this->resolveProjectPath($targetPath);
            if ($projectDir === null) {
                return ['success' => false, 'message' => "Invalid project path: '{$targetPath}'."];
            }
        } else {
            $projectDir = $this->projectsDir() . '/' . $safeName;
            $targetPath = $this->relativeToWorkspace($projectDir);
        }

        if (is_dir($projectDir) && $this->directoryHasFiles($projectDir)) {
            return [
                'success' => false,
                'message' => "Project '{$safeName}' already exists at {$this->relativeToWorkspace($projectDir)}.",
            ];
        }

        if (!is_dir($projectDir) && !mkdir($projectDir, 0755, true)) {
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
        $detectedLoveVersion = $this->detectLoveVersion();

        // Determine template
        $template = $options['template'] ?? 'blank';
        $templateDir = dirname(__DIR__) . '/Resources/templates/' . $template;

        if (is_dir($templateDir)) {
            // Copy template files with placeholder replacement
            $this->applyTemplate(
                $templateDir,
                $projectDir,
                $title,
                $width,
                $height,
                self::SUPPORTED_LOVE_VERSION,
            );
        } else {
            // Fallback: write default conf.lua
            $confLua = $this->generateConfLua($title, $width, $height, self::SUPPORTED_LOVE_VERSION);
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
        file_put_contents($projectDir . '/.gitignore', "*.love\n/web/\n/.coqui/\n");

        $relativePath = $this->relativeToWorkspace($projectDir);
        $debugDirectory = $this->relativeToWorkspace($projectDir . '/.coqui/love2d/logs');

        return [
            'success' => true,
            'message' => "Project '{$safeName}' created at {$relativePath}.",
            'path' => $relativePath,
            'debug_directory' => $debugDirectory,
            'supported_love_version' => self::SUPPORTED_LOVE_VERSION,
            'detected_love_version' => $detectedLoveVersion,
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
        return rtrim($this->workspacePath, '/') . '/projects';
    }

    /**
     * Get the log file path for an instance.
     */
    public function logPath(string $name): string
    {
        $meta = $this->loadRunMetadata($name);

        if ($meta !== null && isset($meta['log_file_absolute']) && is_string($meta['log_file_absolute'])) {
            return $meta['log_file_absolute'];
        }

        return $this->runtimeDir() . "/{$name}.log";
    }

    public function supportedLoveVersion(): string
    {
        return self::SUPPORTED_LOVE_VERSION;
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

    public function detectLoveVersion(): ?string
    {
        if ($this->loveVersionSearched) {
            return $this->loveVersionCache;
        }

        $this->loveVersionSearched = true;
        $loveBinary = $this->findLoveBinary();
        if ($loveBinary === null) {
            return null;
        }

        $output = [];
        $exitCode = 0;
        exec(escapeshellarg($loveBinary) . ' --version 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            return null;
        }

        $versionText = trim(implode(' ', $output));
        if ($versionText === '') {
            return null;
        }

        if (preg_match('/(?:love|l[öo]ve)\s+(\d+\.\d+(?:\.\d+)?)/iu', $versionText, $matches) === 1) {
            $this->loveVersionCache = $matches[1];
        }

        return $this->loveVersionCache;
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
    private function applyTemplate(string $templateDir, string $projectDir, string $title, int $width, int $height, string $loveVersion): void
    {
        $replacements = [
            '{{TITLE}}' => $title,
            '{{WIDTH}}' => (string) $width,
            '{{HEIGHT}}' => (string) $height,
            '{{LOVE_VERSION}}' => $loveVersion,
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

    private function generateConfLua(string $title, int $width, int $height, string $loveVersion): string
    {
        return <<<LUA
            function love.conf(t)
                t.identity = "{$title}"
                t.version = "{$loveVersion}"
                t.console = true

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

    private function pidPath(string $name): string
    {
        return $this->runtimeDir() . "/{$name}.pid";
    }

    private function metadataPath(string $name): string
    {
        return $this->runtimeDir() . "/{$name}.json";
    }

    private function removePidFile(string $name): void
    {
        $pidFile = $this->pidPath($name);
        if (is_file($pidFile)) {
            @unlink($pidFile);
        }
    }

    private function readPid(string $name): ?int
    {
        $pidFile = $this->pidPath($name);
        if (!is_file($pidFile)) {
            return null;
        }

        $pid = (int) (file_get_contents($pidFile) ?: '0');

        return $pid > 0 ? $pid : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadRunMetadata(string $name): ?array
    {
        $metaFile = $this->metadataPath($name);
        if (!is_file($metaFile)) {
            return null;
        }

        $meta = json_decode(file_get_contents($metaFile) ?: '{}', true);

        return is_array($meta) ? $meta : null;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function writeRunMetadata(string $name, array $metadata): void
    {
        file_put_contents(
            $this->metadataPath($name),
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function updateRunMetadata(string $name, array $changes): void
    {
        $metadata = $this->loadRunMetadata($name) ?? ['name' => $name];
        $this->writeRunMetadata($name, [...$metadata, ...$changes]);
    }

    /**
     * @return array{debug_directory: string, debug_directory_absolute: string, log_file: string, log_file_absolute: string, latest_log: string, latest_log_absolute: string}
     */
    private function prepareProjectDebugPaths(string $projectDir, string $name): array
    {
        $debugDirectoryAbsolute = $projectDir . '/.coqui/love2d/logs';
        if (!is_dir($debugDirectoryAbsolute)) {
            mkdir($debugDirectoryAbsolute, 0755, true);
        }

        $safeName = (string) preg_replace('/[^a-zA-Z0-9_-]/', '-', $name);
        $timestamp = (new \DateTimeImmutable())->format('Ymd-His');
        $logFileAbsolute = $debugDirectoryAbsolute . '/' . $timestamp . '-' . $safeName . '.log';
        $latestLogAbsolute = $debugDirectoryAbsolute . '/latest.log';

        if (!is_file($logFileAbsolute)) {
            touch($logFileAbsolute);
        }

        $this->refreshLatestLogPointer($logFileAbsolute, $latestLogAbsolute);

        return [
            'debug_directory' => $this->relativeToWorkspace($debugDirectoryAbsolute),
            'debug_directory_absolute' => $debugDirectoryAbsolute,
            'log_file' => $this->relativeToWorkspace($logFileAbsolute),
            'log_file_absolute' => $logFileAbsolute,
            'latest_log' => $this->relativeToWorkspace($latestLogAbsolute),
            'latest_log_absolute' => $latestLogAbsolute,
        ];
    }

    private function refreshLatestLogPointer(string $logFileAbsolute, string $latestLogAbsolute): void
    {
        if (is_link($latestLogAbsolute) || is_file($latestLogAbsolute)) {
            @unlink($latestLogAbsolute);
        }

        $target = basename($logFileAbsolute);
        if (function_exists('symlink') && @symlink($target, $latestLogAbsolute)) {
            return;
        }

        if (function_exists('link') && @link($logFileAbsolute, $latestLogAbsolute)) {
            return;
        }

        @copy($logFileAbsolute, $latestLogAbsolute);
    }

    private function readLogExcerpt(string $logFile, int $maxLines = 20): string
    {
        if (!is_file($logFile)) {
            return '';
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        if ($lines === false || $lines === []) {
            return '';
        }

        $excerpt = array_slice($lines, -$maxLines);

        return trim(implode("\n", $excerpt));
    }

    private function buildLaunchFailureMessage(
        string $name,
        string $project,
        string $logFile,
        string $debugDirectory,
        string $loveBinary,
        ?string $loveVersion,
        string $excerpt,
    ): string {
        $message = "Love2D exited during startup.\n\n";
        $message .= "- Instance: {$name}\n";
        $message .= "- Project: {$project}\n";
        $message .= "- Log file: {$logFile}\n";
        $message .= "- Debug directory: {$debugDirectory}\n";
        $message .= "- Love2D binary: {$loveBinary}\n";
        $message .= '- Detected Love2D version: ' . ($loveVersion ?? 'unknown') . "\n";
        $message .= '- Toolkit baseline: ' . self::SUPPORTED_LOVE_VERSION . "\n\n";

        if ($excerpt !== '') {
            $message .= "Startup log excerpt:\n```log\n{$excerpt}\n```\n\n";
        } else {
            $message .= "No log output was captured before the process exited.\n\n";
        }

        $message .= 'Open the log file directly or use `love2d_log` with this instance name to inspect the failure.';

        return $message;
    }

    private function relativeToWorkspace(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        $resolvedPath = realpath($path);
        $candidates = [rtrim(str_replace('\\', '/', $this->workspacePath), '/')];

        $resolvedWorkspace = realpath($this->workspacePath);
        if ($resolvedWorkspace !== false) {
            $candidates[] = rtrim(str_replace('\\', '/', $resolvedWorkspace), '/');
        }

        $pathsToCheck = [$normalized];
        if ($resolvedPath !== false) {
            $pathsToCheck[] = rtrim(str_replace('\\', '/', $resolvedPath), '/');
        }

        foreach ($pathsToCheck as $candidatePath) {
            foreach ($candidates as $workspace) {
                if (str_starts_with($candidatePath, $workspace . '/')) {
                    return substr($candidatePath, strlen($workspace) + 1);
                }
            }
        }

        return ltrim($normalized, '/');
    }

    private function directoryHasFiles(string $directory): bool
    {
        $items = scandir($directory);
        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            return true;
        }

        return false;
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
