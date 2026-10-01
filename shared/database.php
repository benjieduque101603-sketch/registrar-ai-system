<?php
// shared/database.php - Fixed version

if (defined('DATABASE_LOADED')) {
    return;
}
define('DATABASE_LOADED', true);

require_once __DIR__ . '/config.php';

class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT
                 . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASSWORD, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            // Log the real reason server-side; never echo it to the browser.
            error_log('[db] Connection failed: ' . $e->getMessage());
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
            exit;
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->connection;
    }

    public function query($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchAll($sql, $params = []) {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchOne($sql, $params = []) {
        return $this->query($sql, $params)->fetch();
    }

    public function fetchColumn($sql, $params = []) {
        return $this->query($sql, $params)->fetchColumn();
    }

    /**
     * D3 — reject identifiers that are not plain column names.
     *
     * $table and the column keys in $data cannot be bound as PDO
     * placeholders (PDO only parameterises VALUES), so they are
     * interpolated. Every current call site passes a string literal, so
     * this is defence in depth rather than a live hole — but it converts a
     * future mistake from silent SQL injection into an immediate, obvious
     * exception.
     *
     * A backtick-quoted identifier (`users`) is also accepted, because some
     * schema scripts and SHOW COLUMNS output use that form.
     */
    private function assertSafeIdentifier($identifier): string
    {
        $name = trim((string) $identifier);
        if (strpos($name, '`') === 0 && substr($name, -1) === '`' && strlen($name) > 2) {
            $name = substr($name, 1, -1);
        }
        // A column name: letters, digits and underscores only. Anything
        // containing a space, quote, comma, parenthesis or semicolon is a
        // fragment of a larger statement, not an identifier.
        if ($name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new InvalidArgumentException(
                'Unsafe SQL identifier rejected: ' . var_export($identifier, true)
            );
        }
        return '`' . $name . '`';
    }

    public function insert($table, $data) {
        $table = $this->assertSafeIdentifier($table);
        $columns = [];
        foreach (array_keys($data) as $column) {
            $columns[] = $this->assertSafeIdentifier($column);
        }
        $placeholders = array_fill(0, count($columns), '?');
        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $this->query($sql, array_values($data));
        return $this->connection->lastInsertId();
    }

    public function update($table, $data, $where, $whereParams = []) {
        $table = $this->assertSafeIdentifier($table);
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = $this->assertSafeIdentifier($column) . ' = ?';
        }
        // $where is a small fixed predicate (always 'id = ?' or a literal
        // column comparison with placeholders), so it is not passed through
        // assertSafeIdentifier — it is intentionally a WHERE fragment.
        $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE {$where}";
        $params = array_merge(array_values($data), $whereParams);
        return $this->query($sql, $params)->rowCount();
    }

    public function delete($table, $where, $params = []) {
        $table = $this->assertSafeIdentifier($table);
        $sql = "DELETE FROM {$table} WHERE {$where}";
        return $this->query($sql, $params)->rowCount();
    }

    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }

    public function beginTransaction() {
        return $this->connection->beginTransaction();
    }

    public function commit() {
        return $this->connection->commit();
    }

    public function rollBack() {
        return $this->connection->rollBack();
    }

    public function inTransaction() {
        return $this->connection->inTransaction();
    }
}
?>