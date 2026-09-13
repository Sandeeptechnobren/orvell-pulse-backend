<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // invoice_items.container_id referenced the legacy `containers` table;
        // live inventory (batches) uses `tbl_containers`.
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropForeign(['container_id']);
            $table->foreign('container_id')
                ->references('id')
                ->on('tbl_containers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropForeign(['container_id']);
            $table->foreign('container_id')
                ->references('id')
                ->on('containers')
                ->nullOnDelete();
        });
    }
};
