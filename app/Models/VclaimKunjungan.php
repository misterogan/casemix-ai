<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VclaimKunjungan extends Model
{
    protected $table = 'vclaim_kunjungan';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tgl_sep' => 'date', 'tgl_plg_sep' => 'date', 'synced_at' => 'datetime'];
    }
}
