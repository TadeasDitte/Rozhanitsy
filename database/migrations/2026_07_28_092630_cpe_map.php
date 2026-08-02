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
        Schema::create('cpe_map', function (Blueprint $table) {
            $table->id();
            $table->string('cpe_vendor');
            $table->string('cpe_product');
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->enum('match_type', ['exact', 'fuzzy'])->default('exact');
            $table->timestamps();
            $table->unique(['cpe_vendor', 'cpe_product']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cpe_map');
    }
};
