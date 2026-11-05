<?php

namespace NourApi\Framework\Testing;

trait DatabaseMigrations
{
    /**
     * Run the database migrations for the application.
     *
     * @return void
     */
    public function runDatabaseMigrations()
    {
        $status = $this->artisan('migrate:fresh');
        if ($status !== 0) {
            throw new \RuntimeException('Database test setup failed: migrate:fresh exited with status '.$status.'.');
        }

        $this->beforeApplicationDestroyed(function () {
            $status = $this->artisan('migrate:rollback');
            if ($status !== 0) {
                throw new \RuntimeException('Database test cleanup failed: migrate:rollback exited with status '.$status.'.');
            }
        });
    }
}
