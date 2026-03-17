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
    /** @var array<string, int> Tracks the last imported line per log file */
    private array $importCursors = [];

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
        $lines = min(500, max(1, (int) ($input['lines'] ?? 50)));
        $level = trim((string) ($input['level'] ?? ''));

        // Import new entries from active log files
        $this->importActiveLogEntries($name);

        $entries = $this->logStore->tail($lines, $level !== '' ? $level : null);

        if ($entries === []) {
            $msg = 'No log entries found';
            if ($name !== '') {
                $msg .= " for instance \"{$name}\"";
            }
            if ($level !== '') {
                $msg .= " at level \"{$level}\"";
            }
            return ToolResult::success($msg . '.');
        }

        $output = $this->formatHeader($name, $level, count($entries));
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
        $query = trim((string) ($input['query'] ?? ''));
        $level = trim((string) ($input['level'] ?? ''));
        $limit = min(500, max(1, (int) ($input['lines'] ?? 100)));

        if ($query === '') {
            return ToolResult::error('The "query" parameter is required for the "search" action.');
        }

        // Import new entries first
        $this->importActiveLogEntries($name);

        $entries = $this->logStore->search($query, $level !== '' ? $level : null, $limit);

        if ($entries === []) {
            return ToolResult::success("No log entries matching \"{$query}\".");
        }

        $output = "## Log Search Results\n\n";
        $output .= "**Query:** `{$query}`";
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
        $this->importCursors = [];

        return ToolResult::success('All log entries cleared.');
    }

    // ── Formatting ──────────────────────────────────────────────────────

    private function formatHeader(string $name, string $level, int $count): string
    {
        $output = "## Love2D Logs";
        if ($name !== '') {
            $output .= " — {$name}";
        }
        $output .= "\n\n";

        if ($level !== '') {
            $output .= "**Level filter:** `{$level}` | ";
        }
        $output .= "**Showing:** {$count} entries\n\n";

        return $output;
    }

    /**
     * @param array<int, array{id?: int, timestamp: string, level: string, message: string, source: string}> $entries
     */
    private function formatEntries(array $entries): string
    {
        $output = "```log\n";

        foreach ($entries as $entry) {
            $time = substr($entry['timestamp'], 11, 8); // HH:MM:SS
            $level = strtoupper($entry['level']);
            $output .= "[{$time}] [{$level}] {$entry['message']}\n";
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

    private function importActiveLogEntries(string $name): void
    {
        $instances = $this->runner->listInstances();

        foreach ($instances as $instance) {
            // If a specific name is given, only import from that instance
            if ($name !== '' && $instance['name'] !== $name) {
                continue;
            }

            $logFile = $this->runner->logPath($instance['name']);
            if (!is_file($logFile)) {
                continue;
            }

            $cursor = $this->importCursors[$logFile] ?? 0;
            $imported = $this->logStore->importFromFile($logFile, $cursor);

            if ($imported > 0) {
                $this->importCursors[$logFile] = $cursor + $imported;
            }
        }
    }
}
