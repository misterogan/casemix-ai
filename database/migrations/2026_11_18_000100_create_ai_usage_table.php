<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu baris per panggilan AI; tanpa data pasien (hanya jenis tugas, id rujukan, token, biaya).
        Schema::create('ai_usage', function (Blueprint $t) {
            $t->id();
            $t->string('jenis', 30)->index(); // analisa | usulan_regulasi | draf_sanggahan
            $t->unsignedBigInteger('ref_id')->nullable();
            $t->string('model', 100)->nullable();
            $t->unsignedInteger('input_tokens')->default(0);
            $t->unsignedInteger('output_tokens')->default(0);
            $t->unsignedInteger('cache_read_tokens')->default(0);
            $t->decimal('biaya_usd', 10, 6)->default(0);
            $t->unsignedInteger('durasi_ms')->default(0);
            $t->timestamp('created_at')->nullable()->index();
        });
        // Analisa tidak diulang bila hash masukan sama; "Analisa ulang" memaksa.
        Schema::table('folder_analyses', function (Blueprint $t) {
            $t->string('hash_masukan', 64)->nullable();
            $t->boolean('paksa')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
        Schema::table('folder_analyses', fn (Blueprint $t) => $t->dropColumn(['hash_masukan', 'paksa']));
    }
};
