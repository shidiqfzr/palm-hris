<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asal-usul baris riwayat yang direkonstruksi dari Bezetting bulanan (sumber `bezetting`, DOMAIN.md §5.5).
 * Seluruh kolom nullable atau berbawaan, sehingga baris lama dan sumber lain tidak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_histories', function (Blueprint $table) {
            $table->foreignId('import_log_id')->nullable()->after('sumber')
                ->constrained('employee_import_logs')->nullOnDelete();
            $table->boolean('tmt_awal_diperkirakan')->default(false)->after('tmt_awal');
            $table->string('jenis_perubahan_terdeteksi', 30)->nullable()->after('jenis_mutasi')
                ->comment('Deskripsi hasil perbandingan Bezetting, bukan jenis mutasi');
            $table->text('teks_sumber')->nullable()->after('catatan')
                ->comment('Teks unit dan jabatan apa adanya dari berkas Bezetting');
        });
    }

    public function down(): void
    {
        Schema::table('job_histories', function (Blueprint $table) {
            $table->dropForeign(['import_log_id']);
            $table->dropColumn(['import_log_id', 'tmt_awal_diperkirakan', 'jenis_perubahan_terdeteksi', 'teks_sumber']);
        });
    }
};
