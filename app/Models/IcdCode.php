<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Master kode ICD-10 / ICD-9-CM (DHealth). Dipakai untuk nama kode & lencana kaidah; tidak membuang saran. */
class IcdCode extends Model
{
    protected $fillable = ['system', 'code', 'title', 'valid', 'accpdx', 'asterisk', 'valid_idrg'];

    protected function casts(): array
    {
        return ['valid' => 'boolean', 'accpdx' => 'boolean', 'asterisk' => 'boolean', 'valid_idrg' => 'boolean'];
    }

    /** Trim + huruf besar saja; angka dan titik tidak diubah ("0.450" tetap). */
    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    public static function lookup(string $system, string $code): ?self
    {
        return self::where('system', strtolower(trim($system)))->where('code', self::normalize($code))->first();
    }
}
