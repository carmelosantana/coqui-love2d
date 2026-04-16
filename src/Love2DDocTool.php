<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitLove2D;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\CoquiToolkitLove2D\Storage\Love2DDocStore;

/**
 * Tool for querying bundled Love2D API documentation.
 *
 * Provides search, lookup, and list_modules actions so the bot can answer
 * Love2D API questions without external network access.
 */
final class Love2DDocTool implements ToolInterface
{
    public function __construct(
        private readonly Love2DDocStore $docStore,
    ) {}

    public function name(): string
    {
        return 'love2d_doc';
    }

    public function description(): string
    {
        return <<<'DESC'
            Query the bundled Love2D API reference documentation.

            Available actions:
            - search: Full-text search across all Love2D functions, types, enums, and callbacks. Returns matching entries ranked by relevance.
            - lookup: Look up a specific API entry by name (e.g. "love.graphics.draw", "Body:applyForce", "DrawMode"). Returns full documentation including all argument/return signatures.
            - list_modules: List all Love2D modules with entry counts.
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
            'search' => $this->search($input),
            'lookup' => $this->lookup($input),
            'list_modules' => $this->listModules(),
            default => ToolResult::error("Unknown love2d_doc action: '{$action}'"),
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
                            'description' => 'The documentation query action to perform.',
                            'enum' => ['search', 'lookup', 'list_modules'],
                        ],
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query string. Used with "search" action. Example: "draw texture sprite".',
                        ],
                        'name' => [
                            'type' => 'string',
                            'description' => 'API entry name for lookup. Examples: "love.graphics.draw", "Body:applyForce", "DrawMode", "love.load". Used with "lookup" action.',
                        ],
                        'module' => [
                            'type' => 'string',
                            'description' => 'Filter results to a specific module. Example: "graphics", "physics". Used with "search" action.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of search results. Default: 10. Used with "search" action.',
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
    private function search(array $input): ToolResult
    {
        $query = trim((string) ($input['query'] ?? ''));

        if ($query === '') {
            return ToolResult::error(
                'The "query" parameter is required for the "search" action. '
                . 'Example: love2d_doc action:"search" query:"draw image texture"',
            );
        }

        $module = isset($input['module']) ? trim((string) $input['module']) : null;
        $limit = isset($input['limit']) ? max(1, min(50, (int) $input['limit'])) : 10;

        $results = $this->docStore->search($query, $module, $limit);

        if ($results === []) {
            $output = "## No Results\n\n";
            $output .= "No Love2D API entries matched **\"{$query}\"**";
            if ($module !== null && $module !== '') {
                $output .= " in module `{$module}`";
            }
            $output .= ".\n\nTry a broader search or use `love2d_doc list_modules` to see available modules.";

            return ToolResult::success($output);
        }

        $version = $this->docStore->version();
        $output = "## Love2D API Search Results (LÖVE {$version})\n\n";
        $output .= "**Query:** \"{$query}\"";
        if ($module !== null && $module !== '') {
            $output .= " | **Module:** {$module}";
        }
        $output .= " | **Results:** " . count($results) . "\n\n";
        $output .= "| Name | Type | Module | Description |\n";
        $output .= "|------|------|--------|-------------|\n";

        foreach ($results as $row) {
            $desc = $this->truncate($row['description'] ?? '', 100);
            $output .= sprintf(
                "| `%s` | %s | %s | %s |\n",
                $row['fullname'] ?? '',
                $row['what'] ?? '',
                $row['module'] ?? '',
                $desc,
            );
        }

        $output .= "\nUse `love2d_doc lookup name:\"<fullname>\"` for full documentation on any entry.";

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function lookup(array $input): ToolResult
    {
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            return ToolResult::error(
                'The "name" parameter is required for the "lookup" action. '
                . 'Example: love2d_doc action:"lookup" name:"love.graphics.draw"',
            );
        }

        $entry = $this->docStore->lookup($name);

        if ($entry === null) {
            return ToolResult::success(
                "## Not Found\n\nNo Love2D API entry found for **\"{$name}\"**.\n\n"
                . "Try `love2d_doc search query:\"{$name}\"` for a fuzzy search.",
            );
        }

        return ToolResult::success($this->formatEntry($entry));
    }

    private function listModules(): ToolResult
    {
        $modules = $this->docStore->listModules();
        $version = $this->docStore->version();
        $totalEntries = $this->docStore->entryCount();

        $output = "## Love2D API Modules (LÖVE {$version})\n\n";
        $output .= "**Total entries:** {$totalEntries}\n\n";
        $output .= "| Module | Entries |\n";
        $output .= "|--------|--------|\n";

        foreach ($modules as $mod) {
            $output .= sprintf(
                "| `%s` | %s |\n",
                $mod['module'],
                $mod['entry_count'],
            );
        }

        $output .= "\nUse `love2d_doc search query:\"<term>\" module:\"<name>\"` to search within a module.\n";
        $output .= "Use `love2d_doc lookup name:\"love.<module>.<function>\"` for full documentation.";

        return ToolResult::success($output);
    }

    // ── Formatting ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $entry
     */
    private function formatEntry(array $entry): string
    {
        $fullname = $entry['fullname'] ?? '';
        $what = $entry['what'] ?? '';
        $module = $entry['module'] ?? '';
        $description = $entry['description'] ?? '';
        $wikiUrl = $entry['wiki_url'] ?? '';

        $output = "## {$fullname}\n\n";
        $output .= "| | |\n|---|---|\n";
        $output .= "| **Type** | {$what} |\n";
        $output .= "| **Module** | {$module} |\n";
        if ($wikiUrl !== '') {
            $output .= "| **Wiki** | {$wikiUrl} |\n";
        }
        $output .= "\n{$description}\n";

        // Type-specific sections
        if ($what === 'type') {
            $output .= $this->formatTypeDetails($entry);
        } elseif ($what === 'enum') {
            $output .= $this->formatEnumDetails($entry);
        } else {
            $output .= $this->formatVariants($entry);
        }

        return $output;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function formatVariants(array $entry): string
    {
        $variants = $entry['variants'] ?? [];
        if ($variants === []) {
            return '';
        }

        $output = "\n### Signatures\n\n";

        foreach ($variants as $i => $variant) {
            if (count($variants) > 1) {
                $output .= "**Variant " . ($i + 1) . "**\n\n";
            }

            if (!empty($variant['description'])) {
                $output .= $variant['description'] . "\n\n";
            }

            // Synopsis line
            $output .= $this->formatSynopsis($entry, $variant);

            // Arguments
            if (!empty($variant['arguments'])) {
                $output .= "\n**Arguments:**\n\n";
                $output .= "| Name | Type | Default | Description |\n";
                $output .= "|------|------|---------|-------------|\n";
                foreach ($variant['arguments'] as $arg) {
                    $output .= sprintf(
                        "| `%s` | `%s` | %s | %s |\n",
                        $arg['name'] ?? '',
                        $arg['type'] ?? 'any',
                        $arg['default'] ?? '-',
                        $this->truncate($arg['description'] ?? '', 120),
                    );
                }
            }

            // Returns
            if (!empty($variant['returns'])) {
                $output .= "\n**Returns:**\n\n";
                $output .= "| Name | Type | Description |\n";
                $output .= "|------|------|-------------|\n";
                foreach ($variant['returns'] as $ret) {
                    $output .= sprintf(
                        "| `%s` | `%s` | %s |\n",
                        $ret['name'] ?? '',
                        $ret['type'] ?? 'any',
                        $this->truncate($ret['description'] ?? '', 120),
                    );
                }
            }

            $output .= "\n";
        }

        return $output;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $variant
     */
    private function formatSynopsis(array $entry, array $variant): string
    {
        $fullname = $entry['fullname'] ?? '';
        $args = [];
        foreach ($variant['arguments'] ?? [] as $arg) {
            $name = $arg['name'] ?? '';
            if (isset($arg['default'])) {
                $name .= ' [' . $arg['default'] . ']';
            }
            $args[] = $name;
        }

        $returns = [];
        foreach ($variant['returns'] ?? [] as $ret) {
            $returns[] = $ret['name'] ?? '';
        }

        $synopsis = '```lua\n';
        if ($returns !== []) {
            $synopsis .= implode(', ', $returns) . ' = ';
        }
        $synopsis .= $fullname . '(' . implode(', ', $args) . ")\n";
        $synopsis .= "```\n";

        return $synopsis;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function formatTypeDetails(array $entry): string
    {
        $output = '';

        $supertypes = $entry['supertypes'] ?? [];
        if ($supertypes !== []) {
            $output .= "\n**Supertypes:** " . implode(', ', array_map(fn(string $s): string => "`{$s}`", $supertypes)) . "\n";
        }

        $constructors = $entry['constructors'] ?? [];
        if ($constructors !== []) {
            $output .= "\n**Constructors:** " . implode(', ', array_map(fn(string $c): string => "`{$c}`", $constructors)) . "\n";
        }

        return $output;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function formatEnumDetails(array $entry): string
    {
        $constants = $entry['constants'] ?? [];
        if ($constants === []) {
            return '';
        }

        $output = "\n### Constants\n\n";
        $output .= "| Name | Description |\n";
        $output .= "|------|-------------|\n";

        foreach ($constants as $constant) {
            $output .= sprintf(
                "| `%s` | %s |\n",
                $constant['name'] ?? '',
                $this->truncate($constant['description'] ?? '', 120),
            );
        }

        return $output;
    }

    private function truncate(string $text, int $maxLen): string
    {
        // Replace newlines with spaces for table cells
        $text = str_replace(["\n", "\r"], ' ', $text);

        if (mb_strlen($text) <= $maxLen) {
            return $text;
        }

        return mb_substr($text, 0, $maxLen - 3) . '...';
    }
}
