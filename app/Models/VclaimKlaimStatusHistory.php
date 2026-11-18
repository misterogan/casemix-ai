<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VclaimKlaimStatusHistory extends Model
{
    protected $table = 'vclaim_klaim_status_history';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status_lama' => 'integer', 'status_baru' => 'integer', 'terlihat_at' => 'datetime'];
    }
}
