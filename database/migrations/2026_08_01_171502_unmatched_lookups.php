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
        Schema::create('unmatched_lookups', function (Blueprint $table) {
            $table->id();
            $table->string('cpe_vendor');
            $table->string('cpe_product');
            $table->unsignedInteger('hit_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['cpe_vendor', 'cpe_product']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unmatched_lookups');
    }
};
