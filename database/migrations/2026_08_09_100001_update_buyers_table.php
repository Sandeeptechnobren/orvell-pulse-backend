<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            if (!Schema::hasColumn('buyers', 'uuid')) {
                $table->uuid('uuid')->nullable()->unique()->after('id');
            }
            if (!Schema::hasColumn('buyers', 'buyer_id')) {
                $table->string('buyer_id', 30)->nullable()->unique()->after('uuid');
            }
            if (!Schema::hasColumn('buyers', 'name')) {
                $table->string('name')->nullable()->after('buyer_id');
            }
            if (!Schema::hasColumn('buyers', 'whatsapp_number')) {
                $table->string('whatsapp_number', 50)->nullable()->index()->after('name');
            }
            if (!Schema::hasColumn('buyers', 'wa_id')) {
                $table->string('wa_id', 100)->nullable()->index()->after('whatsapp_number');
            }
            if (!Schema::hasColumn('buyers', 'email')) {
                $table->string('email')->nullable()->after('wa_id');
            }
            if (!Schema::hasColumn('buyers', 'category')) {
                $table->string('category', 30)->default('REGULAR')->after('email'); // REGULAR, OCCASIONAL
            }
            if (!Schema::hasColumn('buyers', 'region')) {
                $table->string('region', 100)->nullable()->after('category');
            }
            if (!Schema::hasColumn('buyers', 'priority_level')) {
                $table->string('priority_level', 20)->default('MEDIUM')->after('region'); // HIGH, MEDIUM, LOW
            }
            if (!Schema::hasColumn('buyers', 'preferred_categories')) {
                $table->json('preferred_categories')->nullable()->after('priority_level');
            }
            if (!Schema::hasColumn('buyers', 'address')) {
                $table->text('address')->nullable()->after('preferred_categories');
            }
            if (!Schema::hasColumn('buyers', 'city')) {
                $table->string('city', 100)->nullable()->after('address');
            }
            if (!Schema::hasColumn('buyers', 'country')) {
                $table->string('country', 100)->nullable()->after('city');
            }
            if (!Schema::hasColumn('buyers', 'zipcode')) {
                $table->string('zipcode', 20)->nullable()->after('country');
            }
            if (!Schema::hasColumn('buyers', 'onboarding_status')) {
                $table->integer('onboarding_status')->default(0)->after('zipcode');
            }
            if (!Schema::hasColumn('buyers', 'company_id')) {
                $table->foreignId('company_id')->nullable()->after('onboarding_status')->constrained('companies')->nullOnDelete();
            }
            if (!Schema::hasColumn('buyers', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('company_id')->constrained('clients')->nullOnDelete();
            }
            if (!Schema::hasColumn('buyers', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('client_id');
            }
            if (!Schema::hasColumn('buyers', 'deleted_at')) {
                $table->softDeletes()->after('updated_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('buyers', function (Blueprint $table) {
            $columns = [
                'uuid', 'buyer_id', 'name', 'whatsapp_number', 'wa_id', 'email',
                'category', 'region', 'priority_level', 'preferred_categories',
                'address', 'city', 'country', 'zipcode', 'onboarding_status',
                'company_id', 'client_id', 'is_active', 'deleted_at'
            ];
            foreach ($columns as $column) {
                if (Schema::hasColumn('buyers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
