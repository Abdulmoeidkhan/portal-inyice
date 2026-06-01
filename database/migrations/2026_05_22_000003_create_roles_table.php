<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('code', 50);
            $table->string('name', 100);
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            // Indexes
            $table->unique(['tenant_id', 'code']);

            // Constraints
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
