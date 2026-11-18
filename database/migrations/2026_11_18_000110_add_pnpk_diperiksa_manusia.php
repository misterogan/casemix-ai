<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bedakan persetujuan otomatis dari persetujuan koder/dokter. Yang sudah disetujui sebelum migrasi = diperiksa manusia.
        foreach (['pnpk_butir', 'pnpk_icd'] as $tabel) {
            Schema::table($tabel, fn (Blueprint $t) => $t->boolean('diperiksa_manusia')->default(false)->after('status'));
            DB::table($tabel)->where('status', 'disetujui')->update(['diperiksa_manusia' => true]);
        }
    }

    public function down(): void
    {
        foreach (['pnpk_butir', 'pnpk_icd'] as $tabel) {
            Schema::table($tabel, fn (Blueprint $t) => $t->dropColumn('diperiksa_manusia'));
        }
    }
};
