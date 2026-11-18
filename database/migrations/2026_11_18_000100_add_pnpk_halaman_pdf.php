<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // halaman = label halaman dari AI; halaman_pdf = halaman PDF tempat kutipan benar-benar ditemukan.
        Schema::table('pnpk_butir', function (Blueprint $t) {
            $t->unsignedInteger('halaman_pdf')->nullable()->after('halaman');
        });
    }

    public function down(): void
    {
        Schema::table('pnpk_butir', function (Blueprint $t) {
            $t->dropColumn('halaman_pdf');
        });
    }
};
