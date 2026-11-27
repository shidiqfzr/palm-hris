<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Satu proses rekonstruksi riwayat karir dari Bezetting bulanan (DOMAIN.md §5.5): pengaju, penyetuju, sidik berkas,
 * dan ringkasan angka. Terpisah dari log Impor Karyawan agar daftar dan pembatalan impor karyawan tidak menyentuhnya.
 * `payload` (data terbaca dari berkas, terenkripsi) dihapus setelah masa pembatalan atau 30 hari (SECURITY.md §4.4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bezetting_backfill_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status', 30)->index();
            $table->foreignId('maker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('maker_name')->nullable();
            $table->foreignId('checker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('checker_name')->nullable();
            $table->json('files')->comment('Nama berkas, periode, SHA-256, jumlah baris; tanpa data pribadi');
            $table->string('period_from', 7);
            $table->string('period_to', 7);
            $table->longText('payload')->nullable()->comment('Data terbaca dari berkas, terenkripsi');
            $table->string('plan_digest', 64)->nullable();
            $table->json('summary')->nullable()->comment('Angka ringkasan saja');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->unsignedInteger('rows_written')->default(0);
            $table->timestamp('rolled_back_at')->nullable();
            $table->foreignId('rolled_back_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rollback_reason')->nullable();
            $table->timestamp('payload_purged_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bezetting_backfill_runs');
    }
};
