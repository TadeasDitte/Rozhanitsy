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
        Schema::create('vulnerability_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vulnerability_id')->constrained()->cascadeOnDelete();
            $table->foreignId('component_id')->nullable()->constrained();
            $table->enum('match_confidence', ['exact', 'fuzzy', 'unmatched'])->default('unmatched');
            $table->string('version_start')->nullable();
            $table->boolean('version_start_incl')->default(true);
            $table->string('version_end')->nullable();
            $table->boolean('version_end_incl')->default(false);
            $table->timestamps();
            $table->index('component_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vulnerability_ranges');
    }
};
