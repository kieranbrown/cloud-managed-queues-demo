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
        Schema::table('worker_heartbeats', function (Blueprint $table) {
            $table->boolean('active')->default(false)->after('queue');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('worker_heartbeats', function (Blueprint $table) {
            $table->dropColumn('active');
        });
    }
};
