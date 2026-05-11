<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('batch_id')->index();
            $table->string('queue')->default('default');
            $table->unsignedInteger('job_number');
            $table->decimal('dispatched_at', 16, 4);
            $table->decimal('picked_up_at', 16, 4)->nullable();
            $table->decimal('completed_at', 16, 4)->nullable();
            $table->string('worker_id')->nullable();
            $table->boolean('failed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_metrics');
    }
};
