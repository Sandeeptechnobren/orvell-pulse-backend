<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_suppliers', function (Blueprint $table) {
            $table->string('phone_no')->nullable()->after('name');
            $table->text('address_1')->nullable()->after('email');
            $table->text('address_2')->nullable()->after('address_1');
            $table->string('district')->nullable()->after('address_2');
            $table->string('state')->nullable()->after('district');
            $table->string('zip_code')->nullable()->after('state');

            $table->dropColumn([
                'phone',
                'address',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('tbl_suppliers', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('name');
            $table->text('address')->nullable()->after('email');

            $table->dropColumn([
                'uuid',
                'phone_no',
                'address_1',
                'address_2',
                'district',
                'state',
                'zip_code',
            ]);
        });
    }
};