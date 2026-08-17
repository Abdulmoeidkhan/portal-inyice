<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_discounts', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 26)->unique();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('invoice_id');
            $table->unsignedBigInteger('invoice_line_id')->nullable();
            $table->string('discount_type', 20)->default('amount');
            $table->decimal('percentage', 8, 4)->nullable();
            $table->decimal('amount', 18, 4);
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('company_id');
            $table->index('invoice_id');
            $table->unique(['tenant_id', 'uid']);

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('invoice_id')->references('id')->on('invoices')->onDelete('cascade');
            $table->foreign('invoice_line_id')->references('id')->on('invoice_lines')->nullOnDelete();
            $table->foreign('created_by_user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by_user_id')->references('id')->on('users')->nullOnDelete();
        });

        DB::table('invoice_lines')
            ->join('invoices', 'invoice_lines.invoice_id', '=', 'invoices.id')
            ->where('invoice_lines.total_price', '<', 0)
            ->select([
                'invoice_lines.id as invoice_line_id',
                'invoice_lines.tenant_id',
                'invoice_lines.invoice_id',
                'invoice_lines.description',
                'invoice_lines.total_price',
                'invoices.company_id',
                'invoice_lines.created_at',
                'invoice_lines.updated_at',
            ])
            ->orderBy('invoice_lines.id')
            ->get()
            ->each(function ($line): void {
                $description = (string) $line->description;
                $reason = preg_match('/^Discount:\s*(.+)$/i', $description, $match) ? $match[1] : null;

                DB::table('invoice_discounts')->insert([
                    'uid' => (string) Str::ulid(),
                    'tenant_id' => $line->tenant_id,
                    'company_id' => $line->company_id,
                    'invoice_id' => $line->invoice_id,
                    'invoice_line_id' => $line->invoice_line_id,
                    'discount_type' => 'amount',
                    'percentage' => null,
                    'amount' => abs((float) $line->total_price),
                    'reason' => $reason,
                    'created_at' => $line->created_at,
                    'updated_at' => $line->updated_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_discounts');
    }
};
