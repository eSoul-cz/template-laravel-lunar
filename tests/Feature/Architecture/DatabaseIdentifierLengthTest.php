<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use ArrayObject;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * MariaDB and MySQL reject identifiers longer than 64 characters and PostgreSQL silently truncates them at 63 bytes;
 * SQLite accepts any length. Laravel generates index and foreign key names from the table and column names, so a
 * migration can pass the suite on SQLite and fail (or be cut) in production. This test records every name the
 * migrations really use (explicit or generated) while they run and checks it against the limit of the project's engine
 * (64 for MariaDB and MySQL, 63 for PostgreSQL). Fix a hit by passing a short name to index(), unique(), foreign() or
 * constrained(indexName: ...).
 *
 * It extends Laravel's TestCase directly, not Tests\TestCase: it runs migrate:fresh itself, which a
 * test wrapped in the shared base class's transaction cannot do.
 */
final class DatabaseIdentifierLengthTest extends TestCase
{
    private const int MARIADB_LENGTH = 64;

    private const int POSTGRES_LENGTH = 63;

    public function test_every_identifier_the_migrations_create_fits_the_production_engine(): void
    {
        $names = $this->recordIdentifiers();

        self::assertSame(0, Artisan::call('migrate:fresh'));

        self::assertSame([], $this->tooLong($names), 'Identifiers longer than the limit of the engine.');
    }

    public function test_the_check_detects_a_generated_name_that_is_too_long(): void
    {
        $names = $this->recordIdentifiers();
        $table = 'identifier_length_probe_with_a_long_table_name';

        try {
            Schema::create($table, static function (Blueprint $blueprint): void {
                $blueprint->string('a_rather_long_column_name_for_the_probe')->index();
            });
        } catch (QueryException) {
            // MariaDB and MySQL reject the index themselves; its name was recorded before.
        } finally {
            Schema::dropIfExists($table);
        }

        self::assertNotSame([], $this->tooLong($names));
    }

    /**
     * Swaps in a blueprint that records the table, column, index and key names it creates.
     *
     * @return ArrayObject<int, string>
     */
    private function recordIdentifiers(): ArrayObject
    {
        /** @var ArrayObject<int, string> $names */
        $names = new ArrayObject();

        $this->app->bind(Blueprint::class, static function (mixed $app, array $parameters) use ($names): Blueprint {
            $connection = $parameters['connection'] ?? null;
            $table = $parameters['table'] ?? null;
            $callback = $parameters['callback'] ?? null;
            if ( ! $connection instanceof Connection || ! is_string($table) || ($callback !== null && ! $callback instanceof Closure)) {
                throw new LogicException('Unexpected Blueprint constructor parameters.');
            }

            $blueprint = new class($connection, $table, $callback) extends Blueprint
            {
                /** @var ArrayObject<int, string>|null */
                public ?ArrayObject $names = null;

                public function build(): void
                {
                    $statements = $this->toSql();
                    $this->record($this->getTable());
                    foreach ($this->getAddedColumns() as $column) {
                        $this->record($column->get('name'));
                    }
                    foreach ($this->getCommands() as $command) {
                        $name = $command->get('name');
                        // MariaDB and MySQL ignore the primary key name; it is always PRIMARY.
                        if ( ! is_string($name) || $name === 'primary' || str_starts_with($name, 'drop')) {
                            continue;
                        }
                        $this->record($command->get('index'));
                        $this->record($command->get('to'));
                    }
                    foreach ($statements as $statement) {
                        if (is_string($statement)) {
                            $this->connection->statement($statement);
                        }
                    }
                }

                private function record(mixed $identifier): void
                {
                    if (is_string($identifier)) {
                        $this->names?->append($identifier);
                    }
                }
            };
            $blueprint->names = $names;

            return $blueprint;
        });

        return $names;
    }

    /**
     * @param  ArrayObject<int, string>  $names
     * @return list<string>
     */
    private function tooLong(ArrayObject $names): array
    {
        $postgres = $this->isPostgres();
        $limit = $postgres ? self::POSTGRES_LENGTH : self::MARIADB_LENGTH;
        $tooLong = [];
        foreach ($names as $identifier) {
            $length = $postgres ? strlen($identifier) : mb_strlen($identifier);
            if ($length > $limit) {
                $tooLong[] = sprintf('%s (%d)', $identifier, $length);
            }
        }

        return array_values(array_unique($tooLong));
    }

    /**
     * The engine the application runs on in production: PostgreSQL when the run itself is on it or when the project selects
     * the `package/postgres` guideline layer; the tests use SQLite, so the layer is the usual source.
     */
    private function isPostgres(): bool
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            return true;
        }

        $file = base_path('esoul-guidelines.json');
        $config = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $layers = is_array($config) && is_array($config['layers'] ?? null) ? $config['layers'] : [];

        return in_array('package/postgres', $layers, true);
    }
}
