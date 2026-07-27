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
        Schema::create('programs', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('components', function (Blueprint $t) {
            $t->id();
            $t->foreignId('program_id')->constrained();
            $t->string('slug');
            $t->enum('type', ['core', 'plugin', 'theme', 'extension', 'module']);
            $t->unique(['program_id', 'slug']);
            $t->timestamps();
        });

        Schema::create('program_cpe_map', function (Blueprint $t) {
            $t->id();
            $t->foreignId('component_id')->constrained();
            $t->string('cpe_vendor');
            $t->string('cpe_product');
            $t->enum('match_type', ['exact', 'fuzzy'])->default('exact');
            $t->timestamps();
            $t->index(['cpe_vendor', 'cpe_product']);
        });

        Schema::create('vulnerabilities', function (Blueprint $t) {
            $t->id();
            $t->string('cve_id')->unique();
            $t->foreignId('component_id')->nullable()->constrained();
            $t->decimal('cvss_score', 3, 1)->nullable();
            $t->string('cvss_vector')->nullable();
            $t->string('cvss_version')->nullable();
            $t->string('version_start')->nullable();
            $t->boolean('version_start_incl')->default(true);
            $t->string('version_end')->nullable();
            $t->boolean('version_end_incl')->default(false);
            $t->text('description')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamp('last_modified_at')->nullable();
            $t->string('source')->default('nvd');
            $t->json('raw_cpe_config')->nullable();
            $t->timestamps();
            $t->index('cvss_score');
            $t->index('last_modified_at');
        });

        Schema::create('sync_states', function (Blueprint $t) {
            $t->id();
            $t->string('source')->unique();
            $t->timestamp('last_synced_at');
            $t->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
