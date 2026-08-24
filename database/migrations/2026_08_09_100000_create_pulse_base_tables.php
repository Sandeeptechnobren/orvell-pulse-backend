<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Companies Table
        if (!Schema::hasTable('companies')) {
            Schema::create('companies', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
                $table->string('company_name');
                $table->string('company_code')->unique();
                $table->string('currency', 5)->default('GHS');
                $table->string('address')->nullable();
                $table->string('phone', 30)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['client_id', 'is_active']);
            });
        }

        // 2. Countries Table
        if (!Schema::hasTable('countries')) {
            Schema::create('countries', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('country_code', 10)->unique();
                $table->string('country_name');
                $table->string('flag')->nullable();
                $table->timestamps();
            });
        }

        // 3. Products Table (used by StockManagement)
        if (!Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
                $table->foreignId('space_id')->nullable()->constrained('spaces')->nullOnDelete();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->string('name');
                $table->string('slug')->nullable();
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2)->default(0);
                $table->string('currency', 5)->default('GHS');
                $table->string('unit')->default('bale');
                $table->string('type')->default('physical');
                $table->integer('stock')->default(0);
                $table->string('sku')->unique()->nullable();
                $table->unsignedBigInteger('category')->default(0);
                $table->string('image')->nullable();
                $table->json('tags')->nullable();
                $table->boolean('is_featured')->default(false);
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['client_id', 'company_id', 'is_active']);
                $table->index('category');
            });
        }

        // 4. Customers Table
        if (!Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('buyer_id', 30)->unique()->nullable();
                $table->string('name');
                $table->string('whatsapp_number', 50)->nullable()->index();
                $table->string('wa_id', 100)->nullable()->index();
                $table->string('email')->nullable();
                $table->text('address')->nullable();
                $table->string('city', 100)->nullable();
                $table->string('country', 100)->nullable();
                $table->string('zipcode', 20)->nullable();
                $table->text('preferred_categories')->nullable();
                $table->integer('onboarding_status')->default(0);
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 5. Client Customer Pivot Table
        if (!Schema::hasTable('client_customer')) {
            Schema::create('client_customer', function (Blueprint $table) {
                $table->id();
                $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
                $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
                $table->foreignId('space_id')->nullable()->constrained('spaces')->nullOnDelete();
                $table->timestamp('first_interaction_at')->nullable();
                $table->string('source')->default('whatsapp');
                $table->timestamps();

                $table->unique(['client_id', 'customer_id']);
            });
        }

        // 6. AgentDetails Table (Chatterly instance resolution)
        if (!Schema::hasTable('agentDetails')) {
            Schema::create('agentDetails', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('client_id')->nullable();
                $table->string('instance_id')->nullable()->index();
                $table->string('agent_type', 30)->default('customer');
                $table->string('name')->nullable();
                $table->string('ownerId')->nullable();
                $table->text('token')->nullable();
                $table->string('server')->nullable();
                $table->string('status')->default('pending');
                $table->string('projectId')->nullable();
                $table->string('creationTS')->nullable();
                $table->string('activeTill')->nullable();
                $table->boolean('stopped')->default(false);
                $table->boolean('_isPremium')->default(false);
                $table->timestamps();

                $table->index(['client_id', 'agent_type']);
            });
        }

        // 7. AgentPrompt Table
        if (!Schema::hasTable('agentPrompt')) {
            Schema::create('agentPrompt', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('client_id')->nullable();
                $table->text('prompt_description')->nullable();
                $table->timestamps();
            });
        }

        // 8. Email OTPs Table (Native SMTP OTP authentication)
        if (!Schema::hasTable('email_otps')) {
            Schema::create('email_otps', function (Blueprint $table) {
                $table->id();
                $table->string('email')->index();
                $table->string('otp_hash');
                $table->string('type', 30)->default('register');
                $table->timestamp('expires_at');
                $table->timestamp('verified_at')->nullable();
                $table->integer('attempts')->default(0);
                $table->timestamps();

                $table->index(['email', 'type', 'expires_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_otps');
        Schema::dropIfExists('agentPrompt');
        Schema::dropIfExists('agentDetails');
        Schema::dropIfExists('client_customer');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('products');
        Schema::dropIfExists('countries');
        Schema::dropIfExists('companies');
    }
};
