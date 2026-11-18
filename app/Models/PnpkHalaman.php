<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Teks satu halaman PDF PNPK (hasil pdftotext atau OCR). */
class PnpkHalaman extends Model
{
    protected $table = 'pnpk_halaman';

    public $timestamps = false;

    protected $fillable = ['dokumen_id', 'halaman', 'teks'];
}
