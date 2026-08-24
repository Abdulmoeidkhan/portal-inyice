<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receivings', function (Blueprint $table): void {
            if (!Schema::hasColumn('receivings', 'receiving_number')) {
                $table->string('receiving_number', 50)->nullable()->after('uid');
            }

            if (!Schema::hasColumn('receivings', 'status')) {
                $table->string('status', 20)->default('received')->after('amount');
            }

            if (!Schema::hasColumn('receivings', 'created_by_user_id')) {
                $table->unsignedBigInteger('created_by_user_id')->nullable()->after('received_by_user_id');
            }

            if (!Schema::hasColumn('receivings', 'updated_by_user_id')) {
                $table->unsignedBigInteger('updated_by_user_id')->nullable()->after('created_by_user_id');
            }
        });

        $sequences = [];

        DB::table('receivings')
            ->orderBy('company_id')
            ->orderBy('id')
            ->get(['id', 'company_id', 'receiving_number', 'status', 'received_by_user_id', 'created_by_user_id'])
            ->each(function ($receiving) use (&$sequences): void {
                $sequence = $sequences[$receiving->company_id] ?? 1;
                $updates = [];

                if (!$receiving->receiving_number) {
                    $updates['receiving_number'] = 'REC' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
                }

                if (!$receiving->status) {
                    $updates['status'] = 'received';
                }

                if (!$receiving->created_by_user_id) {
                    $updates['created_by_user_id'] = $receiving->received_by_user_id;
                }

                if ($updates) {
                    DB::table('receivings')->where('id', $receiving->id)->update($updates);
                }

                $sequences[$receiving->company_id] = $sequence + 1;
            });

        Schema::table('receivings', function (Blueprint $table): void {
            $table->unique(['company_id', 'receiving_number'], 'receivings_company_receiving_number_unique');
            $table->index('status', 'receivings_status_index');
            $table->index('created_by_user_id', 'receivings_created_by_user_id_index');
            $table->index('updated_by_user_id', 'receivings_updated_by_user_id_index');
            $table->foreign('created_by_user_id', 'receivings_created_by_user_id_foreign')->references('id')->on('users')->onDelete('restrict');
            $table->foreign('updated_by_user_id', 'receivings_updated_by_user_id_foreign')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('receivings', function (Blueprint $table): void {
            $table->dropForeign('receivings_created_by_user_id_foreign');
            $table->dropForeign('receivings_updated_by_user_id_foreign');
            $table->dropUnique('receivings_company_receiving_number_unique');
            $table->dropIndex('receivings_status_index');
            $table->dropIndex('receivings_created_by_user_id_index');
            $table->dropIndex('receivings_updated_by_user_id_index');
            $table->dropColumn(['receiving_number', 'status', 'created_by_user_id', 'updated_by_user_id']);
        });
    }
};
