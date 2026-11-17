<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Baris dokumen "diarsipkan" (arsip otomatis saat sinkron) belum milik analisa mana pun. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folder_documents', function (Blueprint $t) {
            $t->unsignedBigInteger('folder_analysis_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('folder_documents', function (Blueprint $t) {
            $t->unsignedBigInteger('folder_analysis_id')->nullable(false)->change();
        });
    }
};
