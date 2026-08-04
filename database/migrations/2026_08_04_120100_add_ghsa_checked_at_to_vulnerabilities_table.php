<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vulnerabilities', function (Blueprint $table) {
            $table->timestamp('ghsa_checked_at')->nullable()->after('raw_data');
            $table->boolean('ghsa_ecosystem_mismatch')->default(false)->after('ghsa_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('vulnerabilities', function (Blueprint $table) {
            $table->dropColumn(['ghsa_checked_at', 'ghsa_ecosystem_mismatch']);
        });
    }
};
