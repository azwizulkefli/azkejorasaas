<?php

declare(strict_types=1);

/**
 * Supabase PostgreSQL Singleton Database Connection Class (PDO)
 * 
 * PHP Version: 8.2+
 * Designed for Supabase PostgreSQL direct & pooled connections.
 */
class Database
{
    /**
     * @var Database|null Singleton instance
     */
    private static ?Database $instance = null;

    /**
     * @var PDO PDO instance
     */
    private PDO $pdo;

    /**
     * Private constructor to prevent direct instantiation.
     * Initializes the PDO connection to Supabase PostgreSQL.
     *
     * @param array<string, mixed>|null $config Optional custom configuration override
     * @throws PDOException If the connection attempt fails
     */
    private function __construct(?array $config = null)
    {
        // Default configuration sourced from environment variables with fallback defaults
        $host = $config['host'] 
            ?? $_ENV['DB_HOST'] 
            ?? getenv('DB_HOST') 
            ?? 'aws-0-us-east-1.pooler.supabase.com';

        $port = (int) ($config['port'] 
            ?? $_ENV['DB_PORT'] 
            ?? getenv('DB_PORT') 
            ?? 6543);

        $dbName = $config['dbname'] 
            ?? $_ENV['DB_NAME'] 
            ?? getenv('DB_NAME') 
            ?? 'postgres';

        $user = $config['user'] 
            ?? $_ENV['DB_USER'] 
            ?? getenv('DB_USER') 
            ?? 'postgres.jfdpnbkacxnlsquqypsy';

        $password = $config['password'] 
            ?? $_ENV['DB_PASSWORD'] 
            ?? getenv('DB_PASSWORD') 
            ?? '';

        $sslMode = $config['sslmode'] 
            ?? $_ENV['DB_SSLMODE'] 
            ?? getenv('DB_SSLMODE') 
            ?? 'require';

        // Construct PostgreSQL DSN string
        $dsn = sprintf(
            "pgsql:host=%s;port=%d;dbname=%s;sslmode=%s",
            $host,
            $port,
            $dbName,
            $sslMode
        );

        // Robust PDO options for production reliability & security
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_PERSISTENT         => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $password, $options);
        } catch (PDOException $e) {
            // Re-throw with descriptive error message without leaking sensitive credentials
            throw new PDOException(
                sprintf("Database connection error: %s (Host: %s, Port: %d, DB: %s)", $e->getMessage(), $host, $port),
                (int) $e->getCode()
            );
        }
    }

    /**
     * Prevent cloning of the singleton instance.
     */
    private function __clone() {}

    /**
     * Prevent unserializing of the singleton instance.
     *
     * @throws \Exception
     */
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton class " . static::class);
    }

    /**
     * Get the singleton Database instance.
     *
     * @param array<string, mixed>|null $config Optional configuration override on initial call
     * @return Database
     */
    public static function getInstance(?array $config = null): Database
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }

        return self::$instance;
    }

    /**
     * Get the underlying raw PDO instance.
     *
     * @return PDO
     */
    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    /**
     * Execute a prepared SQL statement with parameters.
     *
     * @param string $sql SQL query string with parameter placeholders
     * @param array<string|int, mixed> $params Query parameter values
     * @return PDOStatement
     * @throws PDOException
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Fetch a single row matching the query.
     *
     * @param string $sql SQL query string
     * @param array<string|int, mixed> $params Query parameter values
     * @return array<string, mixed>|null Returns array of row data, or null if no match found
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $result = $this->query($sql, $params)->fetch();
        return $result !== false ? $result : null;
    }

    /**
     * Fetch all rows matching the query.
     *
     * @param string $sql SQL query string
     * @param array<string|int, mixed> $params Query parameter values
     * @return array<int, array<string, mixed>> Array of result rows
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Fetch a single column scalar value from the query result.
     *
     * @param string $sql SQL query string
     * @param array<string|int, mixed> $params Query parameter values
     * @param int $column Zero-indexed column position
     * @return mixed
     */
    public function fetchColumn(string $sql, array $params = [], int $column = 0): mixed
    {
        return $this->query($sql, $params)->fetchColumn($column);
    }

    /**
     * Helper method to insert a record into a specified table.
     * Supports PostgreSQL RETURNING id for instant primary key retrieval.
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Associative array of column => value
     * @param string $primaryKey Primary key column name for RETURNING clause
     * @return mixed Inserted primary key ID or lastInsertId value
     */
    public function insert(string $table, array $data, string $primaryKey = 'id'): mixed
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn($col) => ':' . $col, $columns);

        $sql = sprintf(
            "INSERT INTO %s (%s) VALUES (%s) RETURNING %s",
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders),
            $primaryKey
        );

        return $this->fetchColumn($sql, $data);
    }

    /**
     * Helper method to update records in a specified table.
     *
     * @param string $table Table name
     * @param array<string, mixed> $data Associative array of column => new_value
     * @param string $where WHERE clause condition (e.g. "id = :id")
     * @param array<string|int, mixed> $whereParams Parameters for the WHERE clause
     * @return int Number of affected rows
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $setParts = [];
        $params = [];

        foreach ($data as $column => $value) {
            $paramName = 'set_' . $column;
            $setParts[] = sprintf("%s = :%s", $column, $paramName);
            $params[$paramName] = $value;
        }

        $params = array_merge($params, $whereParams);

        $sql = sprintf(
            "UPDATE %s SET %s WHERE %s",
            $table,
            implode(', ', $setParts),
            $where
        );

        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Helper method to delete records from a specified table.
     *
     * @param string $table Table name
     * @param string $where WHERE clause condition (e.g. "id = :id")
     * @param array<string|int, mixed> $params Parameters for the WHERE clause
     * @return int Number of affected rows
     */
    public function delete(string $table, string $where, array $params = []): int
    {
        $sql = sprintf("DELETE FROM %s WHERE %s", $table, $where);
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Execute a callback function inside an atomic database transaction.
     * Automatically rolls back on exception and commits on success.
     *
     * @template T
     * @param callable(Database): T $callback
     * @return T
     * @throws Throwable
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Begin a database transaction.
     *
     * @return bool
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit the current transaction.
     *
     * @return bool
     */
    public function commit(): bool
    {
        return $this->pdo->commit();
    }

    /**
     * Roll back the current transaction.
     *
     * @return bool
     */
    public function rollBack(): bool
    {
        return $this->pdo->rollBack();
    }

    /**
     * Check if the database connection is alive.
     *
     * @return bool
     */
    public function ping(): bool
    {
        try {
            return (bool) $this->pdo->query('SELECT 1');
        } catch (PDOException $e) {
            return false;
        }
    }
}

/*
 ============================================================================
 USAGE EXAMPLES:
 ============================================================================

 // 1. Get Singleton Instance
 $db = Database::getInstance();

 // 2. Select Single Record
 $user = $db->fetch("SELECT * FROM users WHERE id = :id", ['id' => 1]);

 // 3. Select All Records
 $users = $db->fetchAll("SELECT id, email, created_at FROM users WHERE active = :active", ['active' => true]);

 // 4. Insert Record (Returns generated primary key ID via RETURNING id)
 $newUserId = $db->insert('users', [
     'email' => 'user@example.com',
     'name'  => 'John Doe',
 ]);

 // 5. Update Record
 $updatedRows = $db->update(
     'users',
     ['name' => 'Jane Doe'],
     'id = :id',
     ['id' => $newUserId]
 );

 // 6. Transaction Example
 $db->transaction(function (Database $db) {
     $db->insert('orders', ['user_id' => 1, 'amount' => 99.99]);
     $db->update('users', ['balance' => 0], 'id = :id', ['id' => 1]);
 });
*/
