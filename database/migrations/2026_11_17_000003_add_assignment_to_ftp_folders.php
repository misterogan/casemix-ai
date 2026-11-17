<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ftp_folders', function (Blueprint $t) {
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('assigned_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ftp_folders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('assigned_to');
            $t->dropColumn('assigned_at');
        });
    }
};
