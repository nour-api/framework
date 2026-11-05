<?php

namespace NourApi\Framework\Testing;

trait DatabaseTransactions
{
    /**
     * Handle database transactions on the specified connections.
     *
     * @return void
     */
    public function beginDatabaseTransaction()
    {
        $database = $this->app->make('db');

        $transactions = [];

        try {
            foreach ($this->connectionsToTransact() as $name) {
                $connection = $database->connection($name);
                $level = $connection->transactionLevel();
                $transactions[] = [$connection, $level];
                $connection->beginTransaction();
            }
        } catch (\Throwable $e) {
            $this->rollbackTestTransactions($transactions, false);
            throw $e;
        }

        $this->beforeApplicationDestroyed(function () use ($transactions) {
            $this->rollbackTestTransactions($transactions, true);
        });
    }

    /** Restore every started connection, including when another connection fails cleanup. */
    private function rollbackTestTransactions(array $transactions, bool $disconnect): void
    {
        $failure = null;
        foreach (array_reverse($transactions) as [$connection, $level]) {
            try {
                $connection->rollBack($level);
            } catch (\Throwable $e) {
                $failure ??= $e;
            } finally {
                if ($disconnect && $level === 0) {
                    try {
                        $connection->disconnect();
                    } catch (\Throwable $e) {
                        $failure ??= $e;
                    }
                }
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * The database connections that should have transactions.
     *
     * @return array
     */
    protected function connectionsToTransact()
    {
        return property_exists($this, 'connectionsToTransact')
            ? $this->connectionsToTransact : [null];
    }
}
