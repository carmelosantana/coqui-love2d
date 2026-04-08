<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitLove2D\Storage;

/**
 * SQLite-backed storage for Love2D process output logs.
 *
 * Each Love2D instance has its own log file (stdout/stderr). This store
 * provides structured access to parsed log entries for debugging and monitoring.
 *
 * @phpstan-type LogEntry array{
 *     id: int,
 *     timestamp: string,
 *     level: string,
 *     message: string,
 *     source: string,
 *     instance_name?: string|null,
 *     project_path?: string|null,
 *     log_file?: string|null,
 *     line_number?: int|null
 * }
 * @phpstan-type LogSearchFilters array{
 *     query?: string,
 *     level?: string|null,
 *     instance_name?: string|null,
 *     project_path?: string|null
 * }
 */
final class Love2DLogStore
{
    private ?\PDO $db = null;

    public function __construct(
        private readonly string $dbPath,
    ) {}

    /**
     * Log an entry from Love2D process output.
     */
    public function log(string $level, string $message, string $source = 'stdout'): void
    {
        $db = $this->connect();

        $stmt = $db->prepare(
            'INSERT INTO output_log (timestamp, level, message, source) VALUES (?, ?, ?, ?)',
        );
        $stmt->execute([
            date('c'),
            $level,
            $message,
            $source,
        ]);
    }

    /**
     * Import lines from a log file, parsing level from content.
     *
     * @return int Number of entries imported
     */
    public function importFromFile(string $logFile, ?string $instanceName = null, ?string $projectPath = null): int
    {
        if (!is_file($logFile)) {
            return 0;
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return 0;
        }

        $afterLine = $this->getImportOffset($logFile);
        $imported = 0;
        $db = $this->connect();
        $db->beginTransaction();
        $stmt = $db->prepare(
            'INSERT INTO output_log (timestamp, level, message, source, instance_name, project_path, log_file, line_number) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );

        try {
            foreach ($lines as $i => $line) {
                if ($i < $afterLine) {
                    continue;
                }

                $level = $this->detectLevel($line);
                $source = str_contains($line, 'Error') || str_contains($line, 'error') ? 'stderr' : 'stdout';

                $stmt->execute([
                    date('c'),
                    $level,
                    $line,
                    $source,
                    $instanceName,
                    $projectPath,
                    $logFile,
                    $i + 1,
                ]);
                $imported++;
            }

            $this->setImportOffset($logFile, count($lines));
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        return $imported;
    }

    /**
     * Get the most recent log entries, optionally filtered by level.
     *
        * @return list<LogEntry>
     */
    public function tail(int $limit = 20, ?string $level = null, ?string $instanceName = null, ?string $projectPath = null): array
    {
        $db = $this->connect();
        $limit = max(1, min($limit, 500));

        $where = [];
        $params = [];

        if ($level !== null && $level !== '') {
            $where[] = 'level = ?';
            $params[] = $level;
        }

        if ($instanceName !== null && $instanceName !== '') {
            $where[] = 'instance_name = ?';
            $params[] = $instanceName;
        }

        if ($projectPath !== null && $projectPath !== '') {
            $where[] = 'project_path = ?';
            $params[] = $projectPath;
        }

        $sql = 'SELECT * FROM output_log';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT ?';
        $params[] = $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        /** @var list<LogEntry> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_reverse($rows);
    }

    /**
     * Search log entries by content and/or level.
     *
     * Accepts either an array of filters or positional string arguments:
     * - search(['query' => '...', 'level' => '...'], $limit)
     * - search($query, $level, $limit)
     *
    * @param LogSearchFilters|string $filters Query string or filter array
     * @param string|int|null $levelOrLimit Level string (when $filters is string) or limit (when $filters is array)
    * @return list<LogEntry>
     */
    public function search(array|string $filters, string|int|null $levelOrLimit = null, int $limit = 50): array
    {
        $db = $this->connect();

        // Normalize positional arguments to filter array
        if (is_string($filters)) {
            $query = $filters;
            $level = is_string($levelOrLimit) ? $levelOrLimit : null;
            if (is_int($levelOrLimit)) {
                $limit = $levelOrLimit;
            }
            $filters = ['query' => $query];
            if ($level !== null) {
                $filters['level'] = $level;
            }
        } elseif (is_int($levelOrLimit)) {
            $limit = $levelOrLimit;
        }

        $limit = max(1, min($limit, 500));

        $where = [];
        $params = [];

        if (isset($filters['query']) && $filters['query'] !== '') {
            $where[] = 'message LIKE ?';
            $params[] = '%' . $filters['query'] . '%';
        }

        if (isset($filters['level']) && $filters['level'] !== '') {
            $where[] = 'level = ?';
            $params[] = $filters['level'];
        }

        if (isset($filters['instance_name']) && $filters['instance_name'] !== '') {
            $where[] = 'instance_name = ?';
            $params[] = $filters['instance_name'];
        }

        if (isset($filters['project_path']) && $filters['project_path'] !== '') {
            $where[] = 'project_path = ?';
            $params[] = $filters['project_path'];
        }

        $sql = 'SELECT * FROM output_log';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT ?';
        $params[] = $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        /** @var list<LogEntry> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_reverse($rows);
    }

    /**
     * Get aggregate statistics about log entries.
     *
     * @return array{total: int, levels: array<string, int>, sources: array<string, int>, first_entry: string, last_entry: string}
     */
    public function stats(): array
    {
        $db = $this->connect();

        // Total count
        $totalStmt = $db->query('SELECT COUNT(*) FROM output_log');
        $total = $totalStmt !== false ? (int) $totalStmt->fetchColumn() : 0;

        if ($total === 0) {
            return [
                'total' => 0,
                'levels' => [],
                'sources' => [],
                'first_entry' => '',
                'last_entry' => '',
            ];
        }

        // Level distribution
        $levels = [];
        $levelStmt = $db->query('SELECT level, COUNT(*) as count FROM output_log GROUP BY level ORDER BY count DESC');
        if ($levelStmt !== false) {
            foreach ($levelStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $levels[(string) $row['level']] = (int) $row['count'];
            }
        }

        // Source distribution
        $sources = [];
        $sourceStmt = $db->query('SELECT source, COUNT(*) as count FROM output_log GROUP BY source ORDER BY count DESC');
        if ($sourceStmt !== false) {
            foreach ($sourceStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $sources[(string) $row['source']] = (int) $row['count'];
            }
        }

        // Time range
        $firstStmt = $db->query('SELECT timestamp FROM output_log ORDER BY id ASC LIMIT 1');
        $first = $firstStmt !== false ? (string) $firstStmt->fetchColumn() : '';

        $lastStmt = $db->query('SELECT timestamp FROM output_log ORDER BY id DESC LIMIT 1');
        $last = $lastStmt !== false ? (string) $lastStmt->fetchColumn() : '';

        return [
            'total' => $total,
            'levels' => $levels,
            'sources' => $sources,
            'first_entry' => $first,
            'last_entry' => $last,
        ];
    }

    /**
     * Delete all log entries.
     *
     * @return int Number of entries deleted
     */
    public function clear(): int
    {
        $db = $this->connect();

        $countStmt = $db->query('SELECT COUNT(*) FROM output_log');
        $count = $countStmt !== false ? (int) $countStmt->fetchColumn() : 0;
        if ($countStmt !== false) {
            $countStmt->closeCursor();
        }
        unset($countStmt);
        $db->exec('DELETE FROM output_log');
        $db->exec('DELETE FROM log_import_state');
        $db->exec('VACUUM');

        return $count;
    }

    /**
     * Check if the store has any entries.
     */
    public function hasEntries(): bool
    {
        if (!is_file($this->dbPath)) {
            return false;
        }

        try {
            $db = $this->connect();
            $stmt = $db->query('SELECT COUNT(*) FROM output_log');

            return $stmt !== false && (int) $stmt->fetchColumn() > 0;
        } catch (\PDOException) {
            return false;
        }
    }

    private function connect(): \PDO
    {
        if ($this->db !== null) {
            return $this->db;
        }

        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $db = new \PDO('sqlite:' . $this->dbPath);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');

        $this->db = $db;
        $this->createTable();

        return $db;
    }

    private function createTable(): void
    {
        $this->db?->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS output_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp TEXT NOT NULL,
                level TEXT NOT NULL DEFAULT 'info',
                message TEXT NOT NULL,
                source TEXT NOT NULL DEFAULT 'stdout',
                instance_name TEXT DEFAULT NULL,
                project_path TEXT DEFAULT NULL,
                log_file TEXT DEFAULT NULL,
                line_number INTEGER DEFAULT NULL
            )
            SQL);

        $this->db?->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS log_import_state (
                log_file TEXT PRIMARY KEY,
                last_line INTEGER NOT NULL DEFAULT 0,
                updated_at TEXT NOT NULL
            )
            SQL);

        $this->migrateColumn('output_log', 'instance_name', 'TEXT DEFAULT NULL');
        $this->migrateColumn('output_log', 'project_path', 'TEXT DEFAULT NULL');
        $this->migrateColumn('output_log', 'log_file', 'TEXT DEFAULT NULL');
        $this->migrateColumn('output_log', 'line_number', 'INTEGER DEFAULT NULL');

        $this->db?->exec('CREATE INDEX IF NOT EXISTS idx_level ON output_log (level)');
        $this->db?->exec('CREATE INDEX IF NOT EXISTS idx_source ON output_log (source)');
        $this->db?->exec('CREATE INDEX IF NOT EXISTS idx_instance_name ON output_log (instance_name)');
        $this->db?->exec('CREATE INDEX IF NOT EXISTS idx_project_path ON output_log (project_path)');
        $this->db?->exec('CREATE INDEX IF NOT EXISTS idx_log_file ON output_log (log_file)');
    }

    private function migrateColumn(string $table, string $column, string $definition): void
    {
        $db = $this->db;
        if ($db === null) {
            return;
        }

        $stmt = $db->query('PRAGMA table_info(' . $table . ')');
        if ($stmt === false) {
            return;
        }

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? null) === $column) {
                return;
            }
        }

        $db->exec(sprintf('ALTER TABLE %s ADD COLUMN %s %s', $table, $column, $definition));
    }

    private function getImportOffset(string $logFile): int
    {
        $db = $this->connect();
        $stmt = $db->prepare('SELECT last_line FROM log_import_state WHERE log_file = ?');
        $stmt->execute([$logFile]);
        $value = $stmt->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    private function setImportOffset(string $logFile, int $lastLine): void
    {
        $db = $this->connect();
        $stmt = $db->prepare(
            'INSERT INTO log_import_state (log_file, last_line, updated_at) VALUES (?, ?, ?) '
            . 'ON CONFLICT(log_file) DO UPDATE SET last_line = excluded.last_line, updated_at = excluded.updated_at',
        );
        $stmt->execute([$logFile, $lastLine, date('c')]);
    }

    private function detectLevel(string $line): string
    {
        $lower = strtolower($line);

        if (str_contains($lower, 'error') || str_contains($lower, 'fatal') || str_contains($lower, 'stack traceback')) {
            return 'error';
        }

        if (str_contains($lower, 'warning') || str_contains($lower, 'warn')) {
            return 'warning';
        }

        if (str_contains($lower, 'debug')) {
            return 'debug';
        }

        return 'info';
    }
}
