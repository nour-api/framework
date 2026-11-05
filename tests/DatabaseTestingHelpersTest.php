<?php

use NourApi\Framework\Application;
use NourApi\Framework\Testing\Concerns\RestoresPhpHandlers;
use NourApi\Framework\Testing\DatabaseMigrations;
use NourApi\Framework\Testing\DatabaseTransactions;
use PHPUnit\Framework\TestCase;

class DatabaseTestingHelpersTest extends TestCase
{
    use RestoresPhpHandlers;

    public function testFailedMigrationCannotSilentlyStartATest(): void
    {
        $harness = $this->migrationHarness([1]);
        $failure = null;
        try {
            $harness->runDatabaseMigrations();
        } catch (RuntimeException $e) {
            $failure = $e;
        }
        self::assertInstanceOf(RuntimeException::class, $failure);
        self::assertStringContainsString('migrate:fresh', $failure->getMessage());
        self::assertSame([], $harness->callbacks);
    }

    public function testFailedMigrationRollbackCannotSilentlyPass(): void
    {
        $harness = $this->migrationHarness([0, 1]);
        $harness->runDatabaseMigrations();
        self::assertCount(1, $harness->callbacks);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('migrate:rollback');
        $harness->callbacks[0]();
    }

    private function migrationHarness(array $statuses): object
    {
        return new class($statuses) {
            use DatabaseMigrations;
            public array $callbacks = [];
            public function __construct(private array $statuses) {}
            public function artisan($command) { return array_shift($this->statuses); }
            protected function beforeApplicationDestroyed(callable $callback) { $this->callbacks[] = $callback; }
        };
    }

    public function testPartialTransactionSetupRollsBackConnectionsAlreadyStarted(): void
    {
        $app = new Application;
        $app->configure('database');
        $app['config']->set('database.connections.first', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app['config']->set('database.connections.broken', ['driver' => 'unsupported-test-driver', 'database' => ':memory:']);
        $connection = $app['db']->connection('first');
        $pdo = $connection->getPdo();
        $harness = new class($app) {
            use DatabaseTransactions;
            public array $callbacks = [];
            protected $connectionsToTransact = ['first', 'broken'];
            public function __construct(public Application $app) {}
            protected function beforeApplicationDestroyed(callable $callback) { $this->callbacks[] = $callback; }
        };
        try {
            try {
                $harness->beginDatabaseTransaction();
                self::fail('The second connection must fail.');
            } catch (InvalidArgumentException $e) {
                self::assertStringContainsString('unsupported-test-driver', $e->getMessage());
            }
            self::assertFalse($pdo->inTransaction(), 'The first connection must not retain a transaction after setup fails.');
            self::assertSame(0, $connection->transactionLevel());
        } finally {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
        }
    }
    public function testNestedTransactionCleanupPreservesExistingTransaction(): void
    {
        $app = new Application;
        $app->configure('database');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);
        $connection = $app['db']->connection();
        $pdo = $connection->getPdo();
        $pdo->exec('create table test_records (id integer)');
        $connection->beginTransaction();
        try {
            $pdo->exec('insert into test_records values (1)');
            $harness = new class($app) {
                use DatabaseTransactions;
                public array $callbacks = [];
                public function __construct(public Application $app) {}
                protected function beforeApplicationDestroyed(callable $callback) { $this->callbacks[] = $callback; }
            };
            $harness->beginDatabaseTransaction();
            $pdo->exec('insert into test_records values (2)');
            $harness->callbacks[0]();
            self::assertSame(1, $connection->transactionLevel());
            self::assertSame($pdo, $connection->getPdo());
            self::assertTrue($pdo->inTransaction());
            self::assertSame(1, (int) $pdo->query('select count(*) from test_records')->fetchColumn());
        } finally {
            $connection->rollBack();
        }
    }

    public function testCleanupStillAttemptsEveryConnectionWhenOneRollbackFails(): void
    {
        $app = new Application;
        $first = $this->createMock(\Illuminate\Database\Connection::class);
        $second = $this->createMock(\Illuminate\Database\Connection::class);
        foreach ([$first, $second] as $connection) {
            $connection->method('transactionLevel')->willReturn(0);
            $connection->expects(self::once())->method('beginTransaction');
            $connection->expects(self::once())->method('disconnect');
        }
        $first->expects(self::once())->method('rollBack')->with(0);
        $second->expects(self::once())->method('rollBack')->with(0)->willThrowException(new RuntimeException('rollback failed'));
        $app->instance('db', new class($first, $second) {
            public function __construct(private $first, private $second) {}
            public function connection($name) { return $name === 'first' ? $this->first : $this->second; }
        });
        $harness = new class($app) {
            use DatabaseTransactions;
            public array $callbacks = [];
            protected $connectionsToTransact = ['first', 'second'];
            public function __construct(public Application $app) {}
            protected function beforeApplicationDestroyed(callable $callback) { $this->callbacks[] = $callback; }
        };
        $harness->beginDatabaseTransaction();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rollback failed');
        $harness->callbacks[0]();
    }

    public function testTransactionListenerFailureDoesNotLeaveAnOpenTransaction(): void
    {
        $app = new Application;
        $app->configure('database');
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);
        $connection = $app['db']->connection();
        $pdo = $connection->getPdo();
        $app['events']->listen(\Illuminate\Database\Events\TransactionBeginning::class, static function () {
            throw new RuntimeException('transaction listener failed');
        });
        $harness = new class($app) {
            use DatabaseTransactions;
            public function __construct(public Application $app) {}
            protected function beforeApplicationDestroyed(callable $callback) {}
        };
        $failure = null;
        try {
            try { $harness->beginDatabaseTransaction(); } catch (RuntimeException $e) { $failure = $e; }
            self::assertInstanceOf(RuntimeException::class, $failure);
            self::assertSame('transaction listener failed', $failure->getMessage());
            self::assertFalse($pdo->inTransaction());
            self::assertSame(0, $connection->transactionLevel());
        } finally {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
        }
    }

}
