<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('worker_heartbeats');

        Schema::create('workers', function (Blueprint $table) {
            $table->string('worker_id')->primary();
            $table->string('queue')->nullable();
            $table->timestamp('started_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workers');

        Schema::create('worker_heartbeats', function (Blueprint $table) {
            $table->string('worker_id')->primary();
            $table->string('queue')->nullable();
            $table->boolean('active')->default(false);
            $table->timestamp('last_seen_at')->nullable();

            $table->index('last_seen_at');
        });
    }
};
