<?php

declare(strict_types=1);

namespace PeopleSuite;

use LogicException;
use PDO;
use PDOStatement;
use Throwable;

final class Database
{
    private function __construct(private readonly PDO $connection)
    {
    }

    public static function connect(): self
    {
        $dsn = getenv('HCM_DSN') ?: '';
        if (!str_starts_with($dsn, 'mysql:') || !preg_match('/(?:^mysql:|;)charset=utf8mb4(?:;|$)/i', $dsn)) {
            throw new LogicException('HCM_DSN must be a MySQL DSN with charset=utf8mb4.');
        }
        $connection = new PDO($dsn, getenv('HCM_DB_USER') ?: '', (string) getenv('HCM_DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
        ]);
        $connection->exec("SET time_zone = '+00:00'");
        $connection->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        return new self($connection);
    }

    public function run(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    public function row(string $sql, array $parameters = []): ?array
    {
        $row = $this->run($sql, $parameters)->fetch();
        return $row === false ? null : $row;
    }

    public function rows(string $sql, array $parameters = []): array
    {
        return $this->run($sql, $parameters)->fetchAll();
    }

    public function insert(string $sql, array $parameters): int
    {
        $this->run($sql, $parameters);
        return (int) $this->connection->lastInsertId();
    }

    public function transaction(callable $work, bool $repeatableRead = false): mixed
    {
        if ($repeatableRead) {
            $this->connection->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $this->connection->beginTransaction();
        try {
            $result = $work();
            $this->connection->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $error;
        }
    }
}
