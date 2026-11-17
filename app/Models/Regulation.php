<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Acuan regulasi (diisi Admin) untuk butir Daftar periksa. Dipakai sebagai rujukan pelengkap dokumentasi. */
class Regulation extends Model
{
    protected $fillable = ['judul', 'pasal', 'ringkasan', 'url', 'aktif'];

    protected function casts(): array
    {
        return ['aktif' => 'boolean'];
    }

    /** @return string[] kode butir rubrik terkait */
    public function rubricCodes(): array
    {
        return DB::table('regulation_rubric')->where('regulation_id', $this->id)->orderBy('rubric_code')->pluck('rubric_code')->all();
    }

    public function syncRubric(array $codes): void
    {
        DB::table('regulation_rubric')->where('regulation_id', $this->id)->delete();
        DB::table('regulation_rubric')->insert(array_map(fn ($k) => ['regulation_id' => $this->id, 'rubric_code' => $k], array_values(array_unique($codes))));
    }

    /** Acuan aktif dikelompokkan per kode butir rubrik. */
    public static function byRubric(): Collection
    {
        return self::query()->where('aktif', true)
            ->join('regulation_rubric', 'regulation_rubric.regulation_id', '=', 'regulations.id')
            ->orderBy('regulations.judul')->get(['regulations.*', 'regulation_rubric.rubric_code'])
            ->groupBy('rubric_code');
    }
}
