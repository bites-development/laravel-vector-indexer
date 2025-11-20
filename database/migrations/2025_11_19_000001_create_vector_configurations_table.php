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
        Schema::create('vector_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('model_class')->unique()->comment('Fully qualified model class name');
            $table->string('collection_name')->comment('Vector DB collection name');
            $table->string('driver')->default('qdrant')->comment('Vector DB driver: qdrant, pinecone, weaviate');
            $table->boolean('enabled')->default(true)->comment('Enable/disable indexing');
            
            // Field configuration
            $table->json('fields')->comment('Fields to embed with weights and chunking config');
            $table->json('metadata_fields')->nullable()->comment('Fields to store but not embed');
            $table->json('filters')->nullable()->comment('Qdrant filter fields configuration');
            
            // Relationship configuration
            $table->json('relationships')->nullable()->comment('Relationships to watch');
            $table->json('eager_load_map')->nullable()->comment('Eager loading paths for N+1 prevention');
            $table->integer('max_relationship_depth')->default(3)->comment('Maximum depth for relationship traversal');
            
            // Embedding options
            $table->json('options')->nullable()->comment('Embedding model, dimensions, chunking settings');
            
            // Statistics
            $table->integer('indexed_count')->default(0)->comment('Number of successfully indexed records');
            $table->integer('pending_count')->default(0)->comment('Number of records pending indexing');
            $table->integer('failed_count')->default(0)->comment('Number of failed indexing attempts');
            $table->timestamp('last_indexed_at')->nullable()->comment('Last successful indexing timestamp');
            
            $table->timestamps();
            
            // Indexes
            $table->index('model_class');
            $table->index('enabled');
            $table->index('last_indexed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vector_configurations');
    }
};
