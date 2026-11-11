<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Import\SimrsFtp;
use App\Support\Audit;

class SettingController extends Controller
{
    public function index(SimrsFtp $ftp)
    {
        $c = config('casemix.ftp');

        return view('admin.settings.index', [
            'ftp' => [
                'Host' => $c['host'] ?: '—',
                'Port' => $c['port'],
                'Pengguna' => $c['username'] ?: '—',
                'Kata sandi' => $c['password'] ? 'tersimpan di .env' : 'belum diisi',
                'Mode' => ($c['ssl'] ? 'FTPS' : 'FTP biasa') . ($c['passive'] ? ', pasif' : ', aktif'),
                'Folder data' => $c['path'],
                'Folder riwayat pasien' => $c['path_riwayat_pasien'],
                'Maks. file per impor' => $c['max_files_per_import'],
            ],
            'insecure' => $ftp->configured() && ! $c['ssl'],
            'app' => [
                'Nama rumah sakit' => config('casemix.hospital_name'),
                'Disk penyimpanan' => config('casemix.storage_disk'),
                'Antrean AI' => config('casemix.ai.queue') . ' (' . config('queue.default') . ')',
                'Batas gambar per pemeriksaan' => config('casemix.ai.max_images_total'),
                'Halaman scan yang dibaca per dokumen' => config('casemix.ai.max_pages_per_scan'),
            ],
        ]);
    }

    public function testFtp(SimrsFtp $ftp)
    {
        $r = $ftp->test();
        Audit::log('admin.ftp_uji', null, 'Menguji koneksi FTP SIMRS: ' . ($r['ok'] ? 'berhasil' : 'gagal'));

        return back()->with($r['ok'] ? 'ok' : 'warn', $r['message']);
    }
}
