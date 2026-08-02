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
        Schema::create('scan_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_host_id')->constrained('scan_hosts')->cascadeOnDelete();
            $table->string('tenant_id')->nullable();
            $table->unsignedInteger('component_count');
            $table->unsignedInteger('vulnerable_count');
            $table->unsignedInteger('unmatched_count');
            $table->timestamp('scanned_at');
            $table->timestamps();
            $table->index(['tenant_id', 'scanned_at']);
            $table->index('scan_host_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scan_logs');
    }
};
