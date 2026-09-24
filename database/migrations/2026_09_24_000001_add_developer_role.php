<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('tenants')->orderBy('id')->each(function ($tenant): void {
            if (! DB::table('roles')->where('tenant_id', $tenant->id)->where('code', 'dev')->exists()) {
                DB::table('roles')->insert([
                    'uid' => (string) Str::ulid(), 'tenant_id' => $tenant->id,
                    'code' => 'dev', 'name' => 'Developer (read-only)', 'is_system' => false,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Assigned roles must survive rollback; removing them would orphan users.
        DB::table('roles')->where('code', 'dev')->where('is_system', false)
            ->whereNotIn('id', DB::table('users')->select('role_id')->whereNotNull('role_id'))->delete();
    }
};
