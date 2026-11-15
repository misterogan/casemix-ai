<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Saran kondisi untuk dikonfirmasi ke DPJP. Bukan bagian rubrik; tidak memengaruhi status folder. */
class ConditionSuggestion extends Model
{
    protected $guarded = ['id'];

    public const STATUSES = [
        'baru' => 'Baru',
        'ditanyakan' => 'Sudah ditanyakan ke DPJP',
        'disetujui' => 'DPJP setuju',
        'tidak_disetujui' => 'DPJP tidak setuju',
        'tidak_relevan' => 'Tidak relevan',
    ];

    /** Sudah dijawab: tidak diusulkan ulang selama dokumen sumbernya tidak berubah. */
    public const ANSWERED = ['disetujui', 'tidak_disetujui', 'tidak_relevan'];

    protected function casts(): array
    {
        return ['source_fingerprints' => 'array', 'responded_at' => 'datetime', 'halaman' => 'integer'];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(FtpFolder::class, 'ftp_folder_id');
    }

    public function answered(): bool
    {
        return in_array($this->status, self::ANSWERED, true);
    }

    /** Kondisi dianggap serupa bila sama setelah dinormalkan, salah satu memuat yang lain, atau sangat mirip. */
    public static function similar(string $a, string $b): bool
    {
        $n = fn ($s) => trim(preg_replace('/[^a-z0-9 ]+/', ' ', mb_strtolower($s)));
        [$a, $b] = [$n($a), $n($b)];
        if ($a === '' || $b === '') {
            return false;
        }
        similar_text($a, $b, $pct);

        return $a === $b || str_contains($a, $b) || str_contains($b, $a) || $pct >= 80;
    }

    /** Teks yang disalin untuk dikirim ke DPJP. */
    public function dpjpMessage(FtpFolder $folder): string
    {
        $dok = $this->dokumen ?: 'dokumen';
        $hal = $this->halaman ?: '-';

        return sprintf(
            'Dok, mohon konfirmasi untuk pasien %s (RM %s, SEP %s). Di %s hal. %s tercatat: %s. Apakah kondisi %s perlu dicantumkan sebagai diagnosis di resume medis? Bila ya, mohon dilengkapi di DHealth. Bila tidak, tidak perlu ada perubahan. Terima kasih.',
            $folder->nama_pasien ?: $folder->name, $folder->no_rm ?: '-', $folder->no_sep ?: '-', $dok, $hal, rtrim((string) $this->bukti, '. '), $this->kondisi,
        );
    }
}
