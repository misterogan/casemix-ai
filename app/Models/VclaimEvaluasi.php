<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Folder "Siap diajukan" menurut CERMAT tetapi pending/tidak layak di BPJS: bahan evaluasi Acuan regulasi. */
class VclaimEvaluasi extends Model
{
    protected $table = 'vclaim_evaluasi';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['dicatat_at' => 'datetime'];
    }
}
