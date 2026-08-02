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
        Schema::create('vulnerabilities', function (Blueprint $table) {
            $table->id();
            $table->string('cve_id')->unique();
            $table->decimal('cvss_score', 3, 1)->nullable();
            $table->string('cvss_vector')->nullable();
            $table->string('cvss_version')->nullable();
            $table->string('cvss_severity')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('last_modified_at')->nullable();
            $table->foreignId('source_id')->constrained('sources');
            $table->json('raw_data')->nullable();
            $table->timestamps();
            $table->index('cvss_score');
            $table->index('last_modified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vulnerabilities');
    }
};
