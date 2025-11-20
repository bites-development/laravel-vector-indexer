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
        Schema::create('vector_index_queue', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vector_configuration_id')->constrained()->onDelete('cascade');
            $table->string('model_class')->comment('Model class being indexed');
            $table->unsignedBigInteger('model_id')->comment('Model ID being indexed');
            $table->string('action')->comment('Action: index, update, delete');
            $table->json('related_changes')->nullable()->comment('Which relationships triggered this');
            $table->string('triggered_by')->nullable()->comment('Source: observer, command, manual');
            $table->string('status')->default('pending')->comment('Status: pending, processing, completed, failed');
            $table->integer('attempts')->default(0)->comment('Number of processing attempts');
            $table->text('error_message')->nullable()->comment('Error message if failed');
            $table->timestamp('processed_at')->nullable()->comment('When processing completed');
            $table->timestamps();
            
            // Indexes
            $table->index(['model_class', 'model_id']);
            $table->index('status');
            $table->index('created_at');
            $table->index(['vector_configuration_id', 'status']);
            
            // Unique constraint to prevent duplicate pending jobs
            $table->unique(['model_class', 'model_id', 'action', 'status'], 'unique_pending_job');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vector_index_queue');
    }
};
