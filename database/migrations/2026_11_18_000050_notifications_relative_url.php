<?php

use App\Notifications\BpjsTindakLanjut;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }
        DB::table('notifications')->where('data', 'like', '%"url":"http%')->orderBy('id')
            ->each(function ($n) {
                $data = json_decode($n->data, true);
                if (! is_array($data) || ! is_string($data['url'] ?? null)) {
                    return;
                }
                $data['url'] = BpjsTindakLanjut::relatif($data['url']);
                DB::table('notifications')->where('id', $n->id)->update(['data' => json_encode($data)]);
            });
    }

    public function down(): void {}
};
