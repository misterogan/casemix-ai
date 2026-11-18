<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Satu notifikasi per run penerapan VClaim: jumlah klaim yang perlu tindak lanjut (tanpa nama pasien). */
class BpjsTindakLanjut extends Notification
{
    public function __construct(public int $jumlah, public string $url)
    {
        $this->url = self::relatif($url);
    }

    /** Simpan path relatif agar tautan berlaku dari alamat mana pun (lokal HTTP / Tailscale HTTPS). */
    public static function relatif(string $url): string
    {
        $p = parse_url($url);
        if (! isset($p['host'])) {
            return $url;
        }

        return ($p['path'] ?? '/').(isset($p['query']) ? '?'.$p['query'] : '').(isset($p['fragment']) ? '#'.$p['fragment'] : '');
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['pesan' => "{$this->jumlah} klaim perlu tindak lanjut BPJS", 'jumlah' => $this->jumlah, 'url' => $this->url];
    }
}
