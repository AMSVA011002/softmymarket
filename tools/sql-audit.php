<?php
/**
 * Static SQL safety audit.
 *
 *   php tools/sql-audit.php            # scan the whole release
 *   php tools/sql-audit.php admin      # only files whose path contains "admin"
 *
 * Why this exists: MySQL/MariaDB reserve words like `condition`, `rank` or
 * `system`. A hand-written query such as
 *
 *     UPDATE products SET condition = :c WHERE id = :id
 *
 * is a 1064 syntax error even though it looks fine — the column has to be
 * quoted (`` `condition` ``). Database::insert()/update()/insertMany() quote
 * every identifier automatically; this audit covers the SQL that is written by
 * hand and catches the mistake before a customer meets it.
 *
 * Exit code 0 = clean, 1 = at least one finding. Read-only.
 */
declare(strict_types=1);

/**
 * Reserved words that are almost never part of SQL grammar, so an unquoted
 * appearance inside a statement means a column of that name.
 *
 * @return list<string>
 */
function smm_sql_audit_words(): array
{
    return [
        'condition', 'rank', 'system', 'window', 'range', 'usage',
        'lock', 'lines', 'over', 'recursive', 'preceding', 'following', 'partition',
        'persistent', 'virtual', 'stored', 'empty', 'describe', 'signal', 'resignal',
        'outfile', 'infile', 'separator', 'terminated', 'enclosed', 'escaped',
        'zerofill', 'straight_join', 'fulltext', 'spatial', 'longtext', 'mediumtext',
        'tinytext', 'blob', 'binary', 'release', 'reset', 'purge',
    ];
}

/**
 * Words that are reserved but also ordinary grammar in the contexts we use, so
 * only flag them when they sit in column position (``word = …`` / ``word,``).
 *
 * @return list<string>
 */
function smm_sql_audit_contextual_words(): array
{
    return ['key', 'interval', 'read', 'match', 'option', 'options', 'level', 'general', 'row', 'rows'];
}

/**
 * Does this string literal look like a real SQL statement?
 *
 * Kept deliberately narrow: HTML such as <select …> or <textarea rows="3">
 * lives in the same files and must not be mistaken for a query.
 */
function smm_sql_audit_is_sql(string $text): bool
{
    $patterns = [
        '/\bSELECT\b[^;]*\bFROM\b/is',          // SELECT … FROM …
        '/\bINSERT\s+INTO\s+[`\w]/i',
        '/\bUPDATE\s+[`\w]+\s+SET\b/i',         // UPDATE table SET …
        '/\bDELETE\s+FROM\s+[`\w]/i',
        '/\b(?:CREATE|ALTER|DROP)\s+TABLE\b/i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text) === 1) {
            return true;
        }
    }
    return false;
}

/**
 * Strip the parts of a statement where a reserved word is harmless: quoted
 * identifiers, string literals and bound placeholders.
 */
function smm_sql_audit_clean(string $sql): string
{
    $sql = (string) preg_replace('/`[^`]*`/', ' ', $sql);
    $sql = (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", ' ', $sql);
    $sql = (string) preg_replace('/:\w+/', ' ', $sql);
    // ON DUPLICATE KEY UPDATE / DATE_SUB(NOW(), INTERVAL 30 DAY) are grammar.
    $sql = (string) preg_replace('/ON\s+DUPLICATE\s+KEY/i', ' ', $sql);
    $sql = (string) preg_replace('/INTERVAL\s+[0-9:\'\-]+/i', ' ', $sql);
    $sql = (string) preg_replace('/INTERVAL\s+:\w+/i', ' ', $sql);
    return $sql;
}

/**
 * Scan every PHP file below $root for unquoted reserved words in SQL literals.
 *
 * Each finding: ['file' => relative path, 'line' => int, 'word' => string,
 *               'sql' => short statement excerpt]
 *
 * @return list<array{file:string,line:int,word:string,sql:string}>
 */
function smm_sql_reserved_audit(string $root, string $filter = ''): array
{
    $findings = [];
    $plain = smm_sql_audit_words();
    $contextual = smm_sql_audit_contextual_words();

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $relative = ltrim(str_replace($root, '', $path), '/');
        if (str_contains($relative, '/vendor/') || str_starts_with($relative, 'vendor/')) {
            continue;
        }
        if ($filter !== '' && stripos($relative, $filter) === false) {
            continue;
        }

        $source = (string) file_get_contents($path);
        $tokens = @token_get_all($source);
        if ($tokens === []) {
            continue;
        }

        $line = 0;
        foreach ($tokens as $token) {
            if (is_array($token)) {
                $line = (int) $token[2];
                if ($token[0] !== T_CONSTANT_ENCAPSED_STRING && $token[0] !== T_ENCAPSED_AND_WHITESPACE && $token[0] !== T_INLINE_HTML) {
                    continue;
                }
                $text = $token[1];
            } else {
                continue;
            }

            // Keep the full SQL for reporting, drop one pair of PHP quotes.
            $sql = $text;
            if (strlen($sql) > 1) {
                $sql = substr($sql, 1, -1);
            }
            if (!smm_sql_audit_is_sql($sql)) {
                continue;
            }

            $cleaned = smm_sql_audit_clean($sql);
            $excerpt = trim((string) preg_replace('/\s+/', ' ', $sql));

            foreach ($plain as $word) {
                if (preg_match('/(?<![\w`.])' . preg_quote($word, '/') . '(?![\w`])/i', $cleaned) === 1) {
                    $findings[] = ['file' => $relative, 'line' => $line, 'word' => $word, 'sql' => substr($excerpt, 0, 140)];
                }
            }
            foreach ($contextual as $word) {
                // Only column position: `word = …`, `word,`, `word )`.
                if (preg_match('/(?<![\w`.])' . preg_quote($word, '/') . '\s*(=|,|\))/i', $cleaned) === 1) {
                    $findings[] = ['file' => $relative, 'line' => $line, 'word' => $word, 'sql' => substr($excerpt, 0, 140)];
                }
            }
        }
    }

    return $findings;
}

/* ------------------------------------------------------------------- direct */
if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    $rootPath = dirname(__DIR__);
    $filter = (string) ($argv[1] ?? '');
    echo "SoftMyMarket SQL audit · " . date('d M Y H:i') . "\n\n";

    $findings = smm_sql_reserved_audit($rootPath, $filter);
    if ($findings === []) {
        echo "  No unquoted reserved words found in hand-written SQL.\n";
        echo "  Database::insert()/update()/insertMany() quote identifiers themselves.\n";
        exit(0);
    }

    foreach ($findings as $finding) {
        printf("  %s:%d  %s\n      %s\n", $finding['file'], $finding['line'], $finding['word'], $finding['sql']);
        $hint = 'Backtick the column: `' . $finding['word'] . '`.';
        echo '      → ' . $hint . "\n";
    }
    printf("\n  %d finding(s). MySQL/MariaDB reserved words must be quoted with backticks.\n", count($findings));
    exit(1);
}
