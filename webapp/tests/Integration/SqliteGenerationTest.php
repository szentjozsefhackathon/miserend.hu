<?php

use PHPUnit\Framework\TestCase;

final class SqliteGenerationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/miserend-sqlite-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testSuccessfulGenerationAtomicallyReplacesPublishedFile(): void
    {
        $target = $this->directory . '/miserend_v4.sqlite3';
        file_put_contents($target, 'previous database');
        $sqlite = $this->generator($target, false);

        $this->assertTrue($sqlite->generateSqlite());
        $database = new PDO('sqlite:' . $target);
        $tables = $database->query("SELECT name FROM sqlite_master WHERE type = 'table'")
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->assertEqualsCanonicalizing(['templomok', 'misek', 'kepek'], $tables);
        $this->assertSame([], glob($this->directory . '/.miserend_v4.sqlite3.*') ?: []);
    }

    public function testFailedGenerationPreservesPublishedFile(): void
    {
        $target = $this->directory . '/miserend_v4.sqlite3';
        file_put_contents($target, 'previous database');
        $sqlite = $this->generator($target, true);

        try {
            $sqlite->generateSqlite();
            $this->fail('A hibás generálásnak kivételt kell dobnia.');
        } catch (RuntimeException $e) {
            $this->assertSame('test failure', $e->getMessage());
        }

        $this->assertSame('previous database', file_get_contents($target));
        $this->assertSame([], glob($this->directory . '/.miserend_v4.sqlite3.*') ?: []);
    }

    private function generator(string $target, bool $fail): \Api\Sqlite
    {
        return new class($target, $fail) extends \Api\Sqlite {
            public function __construct(string $target, private bool $fail)
            {
                $this->version = 4;
                $this->sqliteFileName = basename($target);
                $this->sqliteFilePath = $target;
            }

            public function insertData(): void
            {
                if ($this->fail) {
                    throw new RuntimeException('test failure');
                }
            }
        };
    }
}
