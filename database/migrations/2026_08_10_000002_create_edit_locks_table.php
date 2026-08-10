<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edit_locks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_id');
            $table->string('lockable_type', 30);
            $table->string('lockable_uid', 64);
            $table->unsignedBigInteger('user_id');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'company_id', 'lockable_type', 'lockable_uid'], 'edit_locks_resource_unique');
            $table->index(['user_id', 'expires_at'], 'edit_locks_user_expires_index');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edit_locks');
    }
};
