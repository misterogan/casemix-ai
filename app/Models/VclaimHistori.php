<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VclaimHistori extends Model
{
    protected $table = 'vclaim_histori';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tgl_sep' => 'date', 'tgl_plg_sep' => 'date', 'fetched_at' => 'datetime'];
    }
}
