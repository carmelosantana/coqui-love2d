<?php

declare(strict_types=1);

namespace CarmeloSantana\CoquiToolkitLove2D\Storage;

/**
 * SQLite FTS5-backed store for bundled Love2D API documentation.
 *
 * Loads entries from the pre-built love2d-api.json and caches them in a
 * SQLite database with full-text search for fast keyword queries.
 *
 * @phpstan-type DocEntry array{
 *     fullname: string,
 *     name: string,
 *     module: string,
 *     what: string,
 *     description: string,
 *     wiki_url: string,
 *     variants?: list<array<string, mixed>>,
 *     constants?: list<array{name: string, description: string}>,
 *     constructors?: list<string>,
 *     supertypes?: list<string>
 * }
 * @phpstan-type ModuleRow array{module: string, entry_count: int}
 * @phpstan-type ApiData array{version?: string, source?: string, generated_at?: string, entries?: list<array<string, mixed>>}
 */
final class Love2DDocStore
{
    private ?\PDO $db = null;

    public function __construct(
        private readonly string $jsonPath,
        private readonly string $dbCachePath,
    ) {}

    /**
     * Full-text search across API entries.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query, ?string $module = null, int $limit = 10): array
    {
        $db = $this->connect();

        // Sanitize query for FTS5: remove special chars, add prefix matching
        $ftsQuery = $this->buildFtsQuery($query);
        if ($ftsQuery === '') {
            return [];
        }

        $sql = 'SELECT d.fullname, d.name, d.module, d.what, d.description, d.wiki_url '
            . 'FROM docs_fts f '
            . 'JOIN docs d ON d.id = f.rowid '
            . 'WHERE docs_fts MATCH :query';
        $params = [':query' => $ftsQuery];

        if ($module !== null && $module !== '') {
            $sql .= ' AND d.module = :module';
            $params[':module'] = $module;
        }

        $sql .= ' ORDER BY rank LIMIT :limit';

        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Look up an entry by exact or prefix fullname match.
     *
     * @return array<string, mixed>|null
     */
    public function lookup(string $name): ?array
    {
        $db = $this->connect();

        // Try exact match first
        $stmt = $db->prepare(
            'SELECT * FROM docs WHERE fullname = :name LIMIT 1',
        );
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row !== false) {
            return $this->hydrateRow($row);
        }

        // Try case-insensitive exact match
        $stmt = $db->prepare(
            'SELECT * FROM docs WHERE fullname COLLATE NOCASE = :name LIMIT 1',
        );
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row !== false) {
            return $this->hydrateRow($row);
        }

        // Try suffix match (e.g. "draw" → "love.graphics.draw")
        $stmt = $db->prepare(
            'SELECT * FROM docs WHERE fullname LIKE :pattern ORDER BY LENGTH(fullname) ASC LIMIT 1',
        );
        $stmt->execute([':pattern' => '%.' . $name]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row !== false) {
            return $this->hydrateRow($row);
        }

        // Try prefix match for methods (e.g. "Body:applyForce")
        $stmt = $db->prepare(
            'SELECT * FROM docs WHERE fullname LIKE :pattern ORDER BY LENGTH(fullname) ASC LIMIT 1',
        );
        $stmt->execute([':pattern' => '%' . $name . '%']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row !== false) {
            return $this->hydrateRow($row);
        }

        return null;
    }

    /**
     * List all modules with their descriptions.
     *
     * @return list<array{module: string, entry_count: int}>
     */
    public function listModules(): array
    {
        $db = $this->connect();

        $stmt = $db->query(
            "SELECT module, COUNT(*) as entry_count "
            . "FROM docs WHERE module != '' "
            . "GROUP BY module ORDER BY module",
        );

        if ($stmt === false) {
            return [];
        }

        /** @var list<array{module: string, entry_count: int}> */
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get the API version from the bundled data.
     */
    public function version(): string
    {
        $db = $this->connect();
        $stmt = $db->query("SELECT value FROM meta WHERE key = 'version'");

        if ($stmt === false) {
            return 'unknown';
        }

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row !== false ? $row['value'] : 'unknown';
    }

    /**
     * Total number of documentation entries.
     */
    public function entryCount(): int
    {
        $db = $this->connect();
        $stmt = $db->query('SELECT COUNT(*) FROM docs');

        if ($stmt === false) {
            return 0;
        }

        return (int) $stmt->fetchColumn();
    }

    private function connect(): \PDO
    {
        if ($this->db !== null) {
            return $this->db;
        }

        $needsImport = !is_file($this->dbCachePath) || $this->isStale();

        $db = new \PDO('sqlite:' . $this->dbCachePath);
        $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode = WAL');

        $this->db = $db;

        if ($needsImport) {
            $this->createSchema();
            $this->importFromJson();
        }

        return $db;
    }

    private function isStale(): bool
    {
        if (!is_file($this->dbCachePath) || !is_file($this->jsonPath)) {
            return true;
        }

        return filemtime($this->jsonPath) > filemtime($this->dbCachePath);
    }

    private function createSchema(): void
    {
        $db = $this->db;
        assert($db !== null);

        $db->exec('DROP TABLE IF EXISTS docs_fts');
        $db->exec('DROP TABLE IF EXISTS docs');
        $db->exec('DROP TABLE IF EXISTS meta');

        $db->exec(<<<'SQL'
            CREATE TABLE docs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                fullname TEXT NOT NULL,
                name TEXT NOT NULL,
                module TEXT NOT NULL DEFAULT '',
                what TEXT NOT NULL DEFAULT '',
                description TEXT NOT NULL DEFAULT '',
                wiki_url TEXT NOT NULL DEFAULT '',
                extra_json TEXT NOT NULL DEFAULT '{}'
            )
        SQL);

        $db->exec(<<<'SQL'
            CREATE VIRTUAL TABLE docs_fts USING fts5(
                fullname,
                name,
                description,
                content=docs,
                content_rowid=id,
                tokenize='porter unicode61'
            )
        SQL);

        $db->exec(<<<'SQL'
            CREATE TABLE meta (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL DEFAULT ''
            )
        SQL);

        $db->exec("CREATE INDEX idx_docs_fullname ON docs(fullname)");
        $db->exec("CREATE INDEX idx_docs_module ON docs(module)");
        $db->exec("CREATE INDEX idx_docs_what ON docs(what)");
    }

    private function importFromJson(): void
    {
        $db = $this->db;
        assert($db !== null);

        $raw = file_get_contents($this->jsonPath);
        if ($raw === false) {
            return;
        }

        /** @var ApiData $data */
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $db->beginTransaction();

        // Store metadata
        $metaStmt = $db->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)');
        $metaStmt->execute(['version', $data['version'] ?? 'unknown']);
        $metaStmt->execute(['source', $data['source'] ?? '']);
        $metaStmt->execute(['generated_at', $data['generated_at'] ?? '']);
        $metaStmt->execute(['json_path', $this->jsonPath]);

        // Import entries
        $stmt = $db->prepare(
            'INSERT INTO docs (fullname, name, module, what, description, wiki_url, extra_json) '
            . 'VALUES (:fullname, :name, :module, :what, :description, :wiki_url, :extra_json)',
        );

        $ftsStmt = $db->prepare(
            'INSERT INTO docs_fts (rowid, fullname, name, description) '
            . 'VALUES (:rowid, :fullname, :name, :description)',
        );

        foreach ($data['entries'] ?? [] as $entry) {
            $fullname = $entry['fullname'] ?? '';
            $name = $entry['name'] ?? '';
            $module = $entry['module'] ?? '';
            $what = $entry['what'] ?? '';
            $description = $entry['description'] ?? '';
            $wikiUrl = $entry['wiki_url'] ?? '';

            // Store variant/type-specific data as JSON blob
            $extra = [];
            if (isset($entry['variants'])) {
                $extra['variants'] = $entry['variants'];
            }
            if (isset($entry['constants'])) {
                $extra['constants'] = $entry['constants'];
            }
            if (isset($entry['constructors'])) {
                $extra['constructors'] = $entry['constructors'];
            }
            if (isset($entry['supertypes'])) {
                $extra['supertypes'] = $entry['supertypes'];
            }

            $stmt->execute([
                ':fullname' => $fullname,
                ':name' => $name,
                ':module' => $module,
                ':what' => $what,
                ':description' => $description,
                ':wiki_url' => $wikiUrl,
                ':extra_json' => json_encode($extra, JSON_UNESCAPED_SLASHES),
            ]);

            $rowId = (int) $db->lastInsertId();

            $ftsStmt->execute([
                ':rowid' => $rowId,
                ':fullname' => $fullname,
                ':name' => $name,
                ':description' => $description,
            ]);
        }

        $db->commit();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateRow(array $row): array
    {
        $extra = json_decode((string) ($row['extra_json'] ?? '{}'), true) ?: [];
        unset($row['extra_json'], $row['id']);

        return array_merge($row, $extra);
    }

    /**
     * Build an FTS5 query string from user input.
     */
    private function buildFtsQuery(string $input): string
    {
        // Remove FTS5 special characters
        $cleaned = preg_replace('/[{}()\[\]^"~*:]/u', ' ', $input) ?? $input;
        $tokens = preg_split('/\s+/', trim($cleaned), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === [] || $tokens === false) {
            return '';
        }

        // Add prefix matching to each token for partial matches
        $terms = array_map(fn(string $t): string => '"' . $t . '"*', $tokens);

        return implode(' ', $terms);
    }
}
