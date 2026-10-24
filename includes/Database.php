<?php
declare(strict_types=1);

/**
 * Thin PDO/MySQL wrapper.
 *
 * Security posture:
 *  - real prepared statements (emulation disabled) so user input is never
 *    interpolated into SQL
 *  - exceptions on by default; no silent failures
 *  - every helper accepts bound parameters only
 *  - identifiers passed to insert()/update() are validated against a strict
 *    pattern, so table/column names can never be attacker controlled
 */
final class Database
{
    private static ?PDO $pdo = null;
    private static string $prefix = '';
    /** @var list<array{sql:string,ms:float}> */
    private static array $log = [];

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $host = (string) Config::get('db.host', 'localhost');
        $port = (int) Config::get('db.port', 3306);
        $name = (string) Config::get('db.name', '');
        $charset = (string) Config::get('db.charset', 'utf8mb4');
        self::$prefix = (string) Config::get('db.prefix', '');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $name, $charset);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_TIMEOUT            => 15,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$charset} COLLATE {$charset}_unicode_ci, sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION', time_zone='+05:30'",
        ];

        try {
            self::$pdo = new PDO($dsn, (string) Config::get('db.user', ''), (string) Config::get('db.pass', ''), $options);
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new RuntimeException('The store database is unavailable. Please try again shortly.', 0, $e);
        }

        return self::$pdo;
    }

    /** Prefix helper: Database::table('products') => "products" (or "smm_products"). */
    public static function table(string $name): string
    {
        if (self::$prefix === '') {
            self::$prefix = (string) Config::get('db.prefix', '');
        }
        return self::$prefix !== '' ? self::$prefix . $name : $name;
    }

    /** @param array<string|int,mixed> $params */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $start = microtime(true);
        $stmt = self::pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $param = is_int($key) ? $key + 1 : (str_starts_with((string) $key, ':') ? (string) $key : ':' . $key);
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_INT,
                is_null($value) => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($param, is_bool($value) ? (int) $value : $value, $type);
        }
        $stmt->execute();

        if (Config::get('app.debug')) {
            self::$log[] = ['sql' => $sql, 'ms' => round((microtime(true) - $start) * 1000, 2)];
            if (count(self::$log) > 200) {
                array_shift(self::$log);
            }
        }

        return $stmt;
    }

    /** @return list<array<string,mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = [], mixed $default = null): mixed
    {
        $value = self::run($sql, $params)->fetchColumn();
        return $value === false ? $default : $value;
    }

    public static function int(string $sql, array $params = []): int
    {
        return (int) self::value($sql, $params, 0);
    }

    public static function float(string $sql, array $params = []): float
    {
        return (float) self::value($sql, $params, 0);
    }

    public static function exists(string $sql, array $params = []): bool
    {
        return self::run($sql, $params)->fetchColumn() !== false;
    }

    /**
     * Insert a row and return its id.
     *
     * @param array<string,mixed> $data column => value
     */
    public static function insert(string $table, array $data): int
    {
        if ($data === []) {
            throw new InvalidArgumentException('Cannot insert an empty row.');
        }
        $columns = array_map([self::class, 'identifier'], array_keys($data));
        $placeholders = array_map(static fn (string $c) => ':' . $c, $columns);
        $sql = 'INSERT INTO ' . self::table($table) . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        self::run($sql, $data);
        return (int) self::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $data */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        if ($data === []) {
            return 0;
        }
        $sets = [];
        $params = [];
        foreach ($data as $column => $value) {
            $identifier = self::identifier($column);
            $sets[] = $identifier . ' = :set_' . $identifier;
            $params['set_' . $identifier] = $value;
        }
        $sql = 'UPDATE ' . self::table($table) . ' SET ' . implode(', ', $sets) . ' WHERE ' . $where;
        $stmt = self::run($sql, array_merge($params, $whereParams));
        return $stmt->rowCount();
    }

    /** @param array<string,mixed> $data */
    public static function insertMany(string $table, array $rows, int $chunk = 250): int
    {
        if ($rows === []) {
            return 0;
        }
        $count = 0;
        $columns = array_map([self::class, 'identifier'], array_keys($rows[0]));
        foreach (array_chunk($rows, $chunk) as $batch) {
            $values = [];
            $params = [];
            foreach (array_values($batch) as $i => $row) {
                $holder = [];
                foreach ($columns as $column) {
                    $key = 'v' . $i . '_' . $column;
                    $holder[] = ':' . $key;
                    $params[$key] = $row[$column] ?? null;
                }
                $values[] = '(' . implode(', ', $holder) . ')';
            }
            $sql = 'INSERT INTO ' . self::table($table) . ' (' . implode(', ', $columns) . ') VALUES ' . implode(', ', $values);
            $count += self::run($sql, $params)->rowCount();
        }
        return $count;
    }

    /** Reject anything that is not a plain snake_case identifier. */
    public static function identifier(string $name): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/', $name)) {
            throw new InvalidArgumentException('Invalid SQL identifier: ' . $name);
        }
        return $name;
    }

    /** Run a callback inside a transaction, rolling back on any exception. */
    public static function tx(callable $callback): mixed
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $callback();
        }
        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array{sql:string,ms:float}> */
    public static function queryLog(): array
    {
        return self::$log;
    }

    public static function disconnect(): void
    {
        self::$pdo = null;
    }

    /** True when the schema has been installed (used by the installer). */
    public static function isInstalled(): bool
    {
        try {
            self::value('SELECT 1 FROM ' . self::table('settings') . ' LIMIT 1');
            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
