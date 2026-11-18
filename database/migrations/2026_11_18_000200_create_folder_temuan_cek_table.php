<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tanda "Sudah dicek" per temuan; kunci stabil dari isi temuan sehingga bertahan setelah analisa ulang.
        Schema::create('folder_temuan_cek', function (Blueprint $t) {
            $t->id();
            $t->foreignId('folder_id')->constrained('ftp_folders')->cascadeOnDelete();
            $t->string('temuan_key', 40);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('dicek_at');
            $t->unique(['folder_id', 'temuan_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_temuan_cek');
    }
};
