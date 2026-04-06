<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Batches (Τα παλιά Runs) - Παρακολουθεί την πρόοδο κάθε συγχρονισμού
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

        // 2. Exceptions (Η παλιά Καραντίνα) - Εδώ μπαίνουν τα records που απέτυχαν στο validation
        Schema::create('sync_exceptions', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('entity');
            $table->string('remote_id'); // Το ID από το εξωτερικό σύστημα
            $table->string('reason'); // Γιατί απέτυχε (π.χ. missing_sku)
            $table->json('payload'); // Τα δεδομένα που απορρίφθηκαν για να τα δούμε
            $table->timestamp('resolved_at')->nullable(); // Αν το διορθώσαμε και το ξανατρέξαμε
            $table->timestamps();

            $table->unique(['source', 'entity', 'remote_id'], 'source_entity_remote_unique');
        });

        // 3. Activities (Τα παλιά Logs) - Πλήρες ιστορικό αλλαγών (Created/Updated)
        Schema::create('sync_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('sync_batches')->onDelete('cascade');
            $table->string('source');
            $table->string('entity');
            $table->string('identifier'); // SKU ή ID
            $table->enum('action', ['created', 'updated', 'deleted', 'restored']);
            $table->json('changes')->nullable(); // Τι άλλαξε (before/after)
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_activities');
        Schema::dropIfExists('sync_exceptions');
        Schema::dropIfExists('sync_batches');
    }
};