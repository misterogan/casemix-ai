<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VclaimKlaim extends Model
{
    public const STATUS = [1 => 'Proses Verifikasi', 2 => 'Pending Verifikasi', 3 => 'Klaim'];

    protected $table = 'vclaim_klaim';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status_kode' => 'integer',
            'tgl_sep' => 'date', 'tgl_pulang' => 'date',
            'by_pengajuan' => 'decimal:2', 'by_setujui' => 'decimal:2', 'by_tarif_gruper' => 'decimal:2',
            'by_tarif_rs' => 'decimal:2', 'by_topup' => 'decimal:2',
            'first_seen_at' => 'datetime', 'status_changed_at' => 'datetime', 'synced_at' => 'datetime',
        ];
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(VclaimKlaimStatusHistory::class, 'no_sep', 'no_sep')->orderBy('terlihat_at');
    }
}
