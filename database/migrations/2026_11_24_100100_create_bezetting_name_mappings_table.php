<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keputusan admin SDM atas nama unit, teks jabatan, dan frasa status pada berkas Bezetting (DOMAIN.md §5.5 butir 5–6).
 * Satu keputusan per nama ternormalisasi, dipakai ulang lintas sesi peninjauan. Tanpa data pribadi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bezetting_name_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20)->comment('unit, jabatan, atau status');
            $table->string('source_text')->comment('Teks dari berkas, ternormalisasi');
            $table->string('decision', 20)->comment('map, keep, atau skip');
            $table->string('target_value')->nullable()->comment('Nama Master Formasi atau status baku bila decision = map');
            $table->text('note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['kind', 'source_text']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bezetting_name_mappings');
    }
};
