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
        Schema::create('gds_parsed_records', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->longText('raw_text');
            $table->string('gds_source', 50);
            $table->string('booking_reference', 50)->nullable();
            $table->json('parsed_json')->nullable();
            $table->unsignedBigInteger('parsed_by_user_id')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('tenant_id');
            $table->index('gds_source');
            $table->index('booking_reference');
            $table->index('parsed_by_user_id');
            $table->unique(['tenant_id', 'uid']);

            // Constraints
            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('parsed_by_user_id')->references('id')->on('users')->onDelete('set null')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gds_parsed_records');
    }
};
