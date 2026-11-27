<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bezetting_legacy_nik_maps', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_nik_hash', 64)->unique();
            $table->string('employee_nik', 8)->index();
            $table->foreign('employee_nik')->references('nik')->on('employees')->cascadeOnDelete();
            $table->foreignId('source_run_id')->nullable()->constrained('bezetting_backfill_runs')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bezetting_legacy_nik_maps');
    }
};
