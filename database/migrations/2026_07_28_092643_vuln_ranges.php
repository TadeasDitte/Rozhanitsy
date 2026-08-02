<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vulnerability_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vulnerability_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->enum('match_confidence', ['exact', 'fuzzy', 'unmatched'])->default('unmatched');
            $table->string('version_start')->nullable();
            $table->boolean('version_start_incl')->default(true);
            $table->string('version_end')->nullable();
            $table->boolean('version_end_incl')->default(false);
            $table->string('raw_cpe')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'match_confidence']);
            $table->index('vulnerability_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vulnerability_ranges');
    }
};
