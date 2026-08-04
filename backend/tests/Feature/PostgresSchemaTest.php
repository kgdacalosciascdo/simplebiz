<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PostgresSchemaTest extends TestCase
{
    public function test_postgresql_schema_contract_when_running_against_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This assertion runs only when the test database driver is PostgreSQL.');
        }

        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertTrue(Schema::hasTable('companies'));
        $this->assertTrue(Schema::hasColumn('companies', 'principal_address'));
        $this->assertTrue(Schema::hasColumn('companies', 'setup_status'));
        $this->assertTrue(Schema::hasTable('user_invitations'));
        $this->assertTrue(Schema::hasTable('membership_access_history'));
    }
}
