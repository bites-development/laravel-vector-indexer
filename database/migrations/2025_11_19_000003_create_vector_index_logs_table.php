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
        Schema::create('vector_index_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vector_configuration_id')->constrained()->onDelete('cascade');
            $table->string('model_class')->comment('Model class that was indexed');
            $table->unsignedBigInteger('model_id')->nullable()->comment('Model ID (null for batch operations)');
            $table->string('action')->comment('Action: index, update, delete, batch');
            $table->integer('records_processed')->default(1)->comment('Number of records processed');
            $table->integer('chunks_created')->nullable()->comment('Number of text chunks created');
            $table->integer('embeddings_generated')->nullable()->comment('Number of embeddings generated');
            $table->float('duration_seconds')->nullable()->comment('Processing duration in seconds');
            $table->string('status')->comment('Status: success, failed');
            $table->text('error_message')->nullable()->comment('Error message if failed');
            $table->json('metadata')->nullable()->comment('Additional context and debugging info');
            $table->timestamps();
            
            // Indexes
            $table->index(['model_class', 'created_at']);
            $table->index('status');
            $table->index(['vector_configuration_id', 'created_at']);
            $table->index('action');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vector_index_logs');
    }
};
