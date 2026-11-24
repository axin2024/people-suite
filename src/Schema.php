<?php

declare(strict_types=1);

namespace PeopleSuite;

use RuntimeException;

final class Schema
{
    private const LOCK_SQL = "CONCAT('people-suite-schema:', LEFT(SHA2(DATABASE(), 256), 24))";

    public function __construct(private readonly Database $db)
    {
    }

    public function migrate(): array
    {
        $lock = $this->db->row('SELECT GET_LOCK(' . self::LOCK_SQL . ', 15) AS acquired');
        if ((int) $lock['acquired'] !== 1) {
            throw new RuntimeException('Could not acquire the schema migration lock.');
        }

        try {
            $this->db->run('CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
                digest CHAR(64) CHARACTER SET ascii NOT NULL,
                installed_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
            ) ENGINE=InnoDB');
            [$files, $installed] = $this->history();
            $applied = [];
            foreach ($files as $version => $sql) {
                if (isset($installed[$version])) {
                    continue;
                }
                foreach (preg_split('/;\s*(?:\r?\n|\z)/', $sql, -1, PREG_SPLIT_NO_EMPTY) as $statement) {
                    $this->db->run($statement);
                }
                $this->db->run('INSERT INTO schema_migrations (version, digest) VALUES (?, ?)', [
                    $version, hash('sha256', $sql),
                ]);
                $applied[] = $version;
            }
            return $applied;
        } finally {
            $this->db->run('SELECT RELEASE_LOCK(' . self::LOCK_SQL . ')');
        }
    }

    public function ready(): bool
    {
        [$files, $installed] = $this->history();
        return count($files) === count($installed);
    }

    private function history(): array
    {
        $files = [];
        foreach (glob(__DIR__ . '/../database/migrations/*.sql') ?: [] as $path) {
            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new RuntimeException('Could not read migration ' . basename($path));
            }
            $files[basename($path, '.sql')] = str_replace("\r\n", "\n", $sql);
        }
        ksort($files);
        $installed = array_column(
            $this->db->rows('SELECT version, digest FROM schema_migrations ORDER BY version'),
            'digest',
            'version',
        );
        if ($files === [] || array_keys($installed) !== array_slice(array_keys($files), 0, count($installed))) {
            throw new RuntimeException('Database migration history does not match the application.');
        }
        foreach ($installed as $version => $digest) {
            if (!hash_equals($digest, hash('sha256', $files[$version]))) {
                throw new RuntimeException('Installed migration has changed: ' . $version);
            }
        }
        return [$files, $installed];
    }
}
