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
        Schema::create('cna_product_names', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parsed_record_id')->constrained()->cascadeOnDelete();
            $table->string('cna_vendor')->nullable();
            $table->string('cna_product');
            $table->string('vendor')->nullable();
            $table->string('product');
            $table->timestamps();

            $table->index('parsed_record_id');
            $table->index(['vendor', 'product']);
            $table->index('product');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cna_product_names');
    }
};
