<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Teks satu halaman PDF PNPK (hasil pdftotext atau OCR). */
class RegulasiHalaman extends Model
{
    protected $table = 'regulasi_halaman';

    public $timestamps = false;

    protected $fillable = ['dokumen_id', 'halaman', 'teks'];
}
