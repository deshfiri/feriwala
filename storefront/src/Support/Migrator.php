<?php

declare(strict_types=1);

namespace Storefront\Support;

/**
 * Applies `database/migrations/*.sql` once each, in name order.
 *
 * A statement per `;` at the end of a line. Applied migrations are recorded, so
 * running it again is a no-op and a new file is picked up on the next run.
 */
final class Migrator
{
    /**
     * @return list<string> the migrations applied by this run
     */
    public static function run(string $directory): array
    {
        $pdo = Db::connect();

        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            name       VARCHAR(190) NOT NULL PRIMARY KEY,
            applied_at DATETIME     NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        /** @var list<string> $done */
        $done = array_column(Db::all('SELECT name FROM schema_migrations'), 'name');
        $files = glob(rtrim($directory, '/').'/*.sql') ?: [];
        sort($files);

        $applied = [];

        foreach ($files as $file) {
            $name = basename($file);

            if (in_array($name, $done, true)) {
                continue;
            }

            foreach (self::statements((string) file_get_contents($file)) as $statement) {
                $pdo->exec($statement);
            }

            Db::exec('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)', [$name, Db::now()]);
            $applied[] = $name;
        }

        return $applied;
    }

    /**
     * @return list<string>
     */
    public static function statements(string $sql): array
    {
        $lines = [];

        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '--')) {
                continue;
            }

            $lines[] = $line;
        }

        $statements = [];

        foreach (explode(";\n", implode("\n", $lines)."\n") as $statement) {
            $statement = trim($statement);

            if ($statement !== '' && $statement !== ';') {
                $statements[] = rtrim($statement, ';');
            }
        }

        return $statements;
    }
}
