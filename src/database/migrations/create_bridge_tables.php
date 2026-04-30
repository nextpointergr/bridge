<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_batches', function (Blueprint $table) {
            $table->id();
            $table->string('source'); // π.χ. prestashop, pylon, woo
            $table->string('entity'); // π.χ. products, customers
            $table->string('status')->default('pending'); // pending, running, completed, failed
            $table->integer('total')->default(0);
            $table->integer('processed')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source', 'entity', 'status']);
        });

        Schema::create('sync_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('entity');
            $table->string('identifier')->index();
            $table->string('reason');
            $table->json('payload');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'entity', 'identifier'], 'source_entity_remote_unique');
        });

        Schema::create('sync_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('sync_batches')->onDelete('cascade');
            $table->string('source');
            $table->string('entity');
            $table->string('identifier'); // SKU ή ID
            $table->string('action'); // Θα αποθηκεύει 'created', 'updated', 'synced', 'skipped'
            $table->json('changes')->nullable(); // Τι άλλαξε (before/after)
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('staging_products', function (Blueprint $table) {
            $table->string('prestashop_id')->nullable()->unique();
            // Αλλαγή εδώ: προσθήκη ->nullable()
            $table->string('erp_id')->nullable()->unique()->index();
            $table->string('sku')->nullable();
            $table->string('ean')->nullable()->index(); // Εδώ επιτρέπουμε διπλότυπα προσωρινά
            $table->string('mpn')->nullable();
            $table->decimal('wholesale_price', 15, 4)->default(0);
            $table->string('name')->nullable();
            $table->string('weight')->nullable();
            $table->string('vat_id')->nullable();
            $table->string('category_id')->nullable();
            $table->string('category_name')->nullable();
            $table->string('unit_code')->nullable();
            $table->decimal('price', 15, 4)->default(0);
            $table->integer('quantity')->default(0);
            $table->boolean('active')->default(true);
            $table->longText('image')->nullable();
            $table->text('extra')->nullable();
            $table->text('url')->nullable();
            $table->string('hash')->nullable()->index();
            $table->string('source')->index();
            $table->json('payload')->nullable(); // Πρόσθεσε αυτή τη γραμμή
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('staging_customers', function (Blueprint $table) {
            $table->id(); // Staging ID
            table->string('prestashop_id')->nullable()->unique();
            $table->string('company')->nullable();
            $table->string('erp_id')->nullable()->unique()->index();
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('email')->nullable();
            $table->string('vat_number')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('address')->nullable();
            $table->string('zip')->nullable();
            $table->string('city')->nullable();
            $table->string('hash')->nullable()->index();
            $table->boolean('active')->default(true);
            $table->text('address_id')->nullable();
            $table->integer('country_id')->nullable();
            $table->integer('state_id')->nullable();
            $table->string('source')->index();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_activities');
        Schema::dropIfExists('sync_exceptions');
        Schema::dropIfExists('sync_batches');
        Schema::dropIfExists('staging_products');
        Schema::dropIfExists('staging_customers');
    }
};
