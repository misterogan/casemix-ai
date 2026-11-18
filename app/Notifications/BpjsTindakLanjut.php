<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Satu notifikasi per run penerapan VClaim: jumlah klaim yang perlu tindak lanjut (tanpa nama pasien). */
class BpjsTindakLanjut extends Notification
{
    public function __construct(public int $jumlah, public string $url) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['pesan' => "{$this->jumlah} klaim perlu tindak lanjut BPJS", 'jumlah' => $this->jumlah, 'url' => $this->url];
    }
}
