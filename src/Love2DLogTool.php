<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitLove2D;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\CoquiToolkitLove2D\Runtime\Love2DRunner;
use CarmeloSantana\CoquiToolkitLove2D\Storage\Love2DLogStore;

/**
 * Tool for viewing and searching Love2D process logs.
 *
 * Provides tail, search, and clear operations on the structured log store.
 * Automatically imports new entries from the active log file before each query.
 */
final class Love2DLogTool implements ToolInterface
{
    public function __construct(
        private readonly Love2DRunner $runner,
        private readonly Love2DLogStore $logStore,
    ) {}

    public function name(): string
    {
        return 'love2d_log';
    }

    public function description(): string
    {
        return <<<'DESC'
            View and search Love2D process output logs for debugging.

            Available actions:
            - tail: Show the most recent log entries (optionally filtered by level).
            - search: Search log entries by keyword or regex pattern.
            - clear: Clear all stored log entries.
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
            'tail' => $this->tail($input),
            'search' => $this->search($input),
            'clear' => $this->clear(),
            default => ToolResult::error("Unknown love2d_log action: '{$action}'"),
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
                            'description' => 'The log action to perform.',
                            'enum' => ['tail', 'search', 'clear'],
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'Instance name to view logs for. Used with "tail" and "search".',
                        ],
                        'project' => [
                            'type' => 'string',
                            'description' => 'Project directory relative to workspace to filter logs by (for example "projects/my-game").',
                        ],
                        'lines' => [
                            'type' => 'integer',
                            'description' => 'Number of log entries to return for "tail" (default: 50, max: 500).',
                        ],
                        'level' => [
                            'type' => 'string',
                            'description' => 'Filter by log level.',
                            'enum' => ['info', 'warning', 'error', 'debug'],
                        ],
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query string for "search" action.',
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
    private function tail(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        $project = ltrim(trim((string) ($input['project'] ?? '')), '/');
        $lines = min(500, max(1, (int) ($input['lines'] ?? 50)));
        $level = trim((string) ($input['level'] ?? ''));

        $runs = $this->importKnownLogEntries($name, $project);

        $entries = $this->logStore->tail(
            $lines,
            $level !== '' ? $level : null,
            $name !== '' ? $name : null,
            $project !== '' ? $project : null,
        );

        if ($entries === []) {
            $msg = 'No log entries found';
            if ($name !== '') {
                $msg .= " for instance \"{$name}\"";
            }
            if ($project !== '') {
                $msg .= " in project \"{$project}\"";
            }
            if ($level !== '') {
                $msg .= " at level \"{$level}\"";
            }

            if ($runs !== []) {
                $latestLog = $runs[0]['latest_log'] ?? $runs[0]['log_file'] ?? null;
                if (is_string($latestLog) && $latestLog !== '') {
                    $msg .= " — latest known log: {$latestLog}";
                }
            }

            return ToolResult::success($msg . '.');
        }

        $output = $this->formatHeader($name, $project, $level, count($entries), $runs);
        $output .= $this->formatEntries($entries);
        $output .= $this->formatStats();

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function search(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));
        $project = ltrim(trim((string) ($input['project'] ?? '')), '/');
        $query = trim((string) ($input['query'] ?? ''));
        $level = trim((string) ($input['level'] ?? ''));
        $limit = min(500, max(1, (int) ($input['lines'] ?? 100)));

        if ($query === '') {
            return ToolResult::error('The "query" parameter is required for the "search" action.');
        }

        $runs = $this->importKnownLogEntries($name, $project);

        $entries = $this->logStore->search([
            'query' => $query,
            'level' => $level !== '' ? $level : null,
            'instance_name' => $name !== '' ? $name : null,
            'project_path' => $project !== '' ? $project : null,
        ], $limit);

        if ($entries === []) {
            $message = "No log entries matching \"{$query}\".";

            if ($runs !== []) {
                $latestLog = $runs[0]['latest_log'] ?? $runs[0]['log_file'] ?? null;
                if (is_string($latestLog) && $latestLog !== '') {
                    $message .= " Latest known log: {$latestLog}.";
                }
            }

            return ToolResult::success($message);
        }

        $output = "## Log Search Results\n\n";
        $output .= "**Query:** `{$query}`";
        if ($name !== '') {
            $output .= " | **Instance:** `{$name}`";
        }
        if ($project !== '') {
            $output .= " | **Project:** `{$project}`";
        }
        if ($level !== '') {
            $output .= " | **Level:** `{$level}`";
        }
        $output .= " | **Found:** " . count($entries) . "\n\n";
        $output .= $this->formatEntries($entries);

        return ToolResult::success($output);
    }

    private function clear(): ToolResult
    {
        $this->logStore->clear();

        return ToolResult::success('All log entries cleared.');
    }

    // ── Formatting ──────────────────────────────────────────────────────

    /**
     * @param array<int, array<string, mixed>> $runs
     */
    private function formatHeader(string $name, string $project, string $level, int $count, array $runs): string
    {
        $output = "## Love2D Logs";
        if ($name !== '') {
            $output .= " — {$name}";
        } elseif ($project !== '') {
            $output .= " — {$project}";
        }
        $output .= "\n\n";

        if ($level !== '') {
            $output .= "**Level filter:** `{$level}` | ";
        }
        $output .= "**Showing:** {$count} entries\n\n";

        if ($runs !== []) {
            $latestRun = $runs[0];
            $logFile = $latestRun['log_file'] ?? '';
            $latestLog = $latestRun['latest_log'] ?? '';
            $debugDirectory = $latestRun['debug_directory'] ?? '';

            if (is_string($logFile) && $logFile !== '') {
                $output .= "**Latest run log:** `{$logFile}`\n\n";
            }
            if (is_string($latestLog) && $latestLog !== '') {
                $output .= "**Stable log path:** `{$latestLog}`\n\n";
            }
            if (is_string($debugDirectory) && $debugDirectory !== '') {
                $output .= "**Debug directory:** `{$debugDirectory}`\n\n";
            }
        }

        return $output;
    }

    /**
     * @param array<int, array{id?: int, timestamp: string, level: string, message: string, source: string, instance_name?: string|null, project_path?: string|null, log_file?: string|null, line_number?: int|null}> $entries
     */
    private function formatEntries(array $entries): string
    {
        $output = "```log\n";

        foreach ($entries as $entry) {
            $time = substr($entry['timestamp'], 11, 8); // HH:MM:SS
            $level = strtoupper($entry['level']);
            $prefix = '';
            $instanceName = $entry['instance_name'] ?? null;
            if ($instanceName !== null && $instanceName !== '') {
                $prefix .= '[' . $instanceName . '] ';
            }
            $lineNumber = $entry['line_number'] ?? null;
            if ($lineNumber !== null) {
                $prefix .= '#L' . $lineNumber . ' ';
            }
            $output .= "[{$time}] [{$level}] {$prefix}{$entry['message']}\n";
        }

        $output .= "```\n\n";

        return $output;
    }

    private function formatStats(): string
    {
        $stats = $this->logStore->stats();

        if ($stats['total'] === 0) {
            return '';
        }

        $output = "### Log Summary\n\n";
        $output .= "| Level | Count |\n|-------|-------|\n";

        foreach ($stats['levels'] as $level => $count) {
            $output .= "| {$level} | {$count} |\n";
        }

        return $output;
    }

    // ── Log Import ──────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function importKnownLogEntries(string $name, string $project): array
    {
        $runs = $this->runner->listKnownRuns(
            $name !== '' ? $name : null,
            $project !== '' ? $project : null,
        );

        foreach ($runs as $run) {
            $logFile = $run['log_file'] ?? $run['log_file_absolute'] ?? null;
            if (!is_string($logFile) || $logFile === '' || !is_file($logFile)) {
                continue;
            }

            $this->logStore->importFromFile(
                $logFile,
                isset($run['name']) && is_string($run['name']) ? $run['name'] : null,
                isset($run['project']) && is_string($run['project']) ? $run['project'] : null,
            );
        }

        return $runs;
    }
}
