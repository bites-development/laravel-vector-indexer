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
        Schema::create('vector_relationship_watchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vector_configuration_id')->constrained()->onDelete('cascade');
            $table->string('parent_model')->comment('Parent model class (e.g., EmailCache)');
            $table->string('related_model')->comment('Related model class (e.g., Attachment)');
            $table->string('relationship_name')->comment('Relationship method name');
            $table->string('relationship_type')->comment('Type: hasMany, hasOne, belongsTo, etc.');
            $table->string('relationship_path')->comment('Full path: attachments.comments');
            $table->integer('depth')->default(1)->comment('Depth level in relationship tree');
            $table->json('watch_fields')->nullable()->comment('Fields to watch for changes');
            $table->string('on_change_action')->default('reindex_parent')->comment('Action on change: reindex_parent, append, ignore');
            $table->boolean('enabled')->default(true)->comment('Enable/disable this watcher');
            $table->timestamps();
            
            // Indexes
            $table->index(['parent_model', 'related_model'], 'idx_parent_related');
            $table->index('enabled');
            $table->index(['vector_configuration_id', 'enabled'], 'idx_config_enabled');
            $table->index('relationship_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vector_relationship_watchers');
    }
};
