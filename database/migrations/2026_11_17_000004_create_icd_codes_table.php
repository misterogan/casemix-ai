<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Master kode ICD dari DHealth: hanya untuk nama kode & kaidah, bukan untuk membuang saran.
        Schema::create('icd_codes', function (Blueprint $t) {
            $t->id();
            $t->string('system', 10); // icd10|icd9cm
            $t->string('code', 12);
            $t->string('title', 500);
            $t->boolean('valid')->default(true);
            $t->boolean('accpdx')->default(true);
            $t->boolean('asterisk')->default(false);
            $t->boolean('valid_idrg')->default(true);
            $t->timestamps();
            $t->unique(['system', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('icd_codes');
    }
};
