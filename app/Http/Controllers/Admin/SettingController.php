<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\Import\SimrsFtp;
use App\Support\Audit;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /** Saran "kondisi yang belum tertulis sebagai diagnosis" di verifikasi folder. Bawaan: mati. */
    public const KEY_CONDITION_SUGGESTIONS = 'ai_condition_suggestions';

    public static function conditionSuggestionsOn(): bool
    {
        return filter_var(AppSetting::get(self::KEY_CONDITION_SUGGESTIONS, false), FILTER_VALIDATE_BOOLEAN);
    }

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
            'conditionSuggestions' => self::conditionSuggestionsOn(),
            'app' => [
                'Nama rumah sakit' => config('casemix.hospital_name'),
                'Disk penyimpanan' => config('casemix.storage_disk'),
                'Antrean AI' => config('casemix.ai.queue') . ' (' . config('queue.default') . ')',
                'Batas gambar per pemeriksaan' => config('casemix.ai.max_images_total'),
                'Halaman scan yang dibaca per dokumen' => config('casemix.ai.max_pages_per_scan'),
            ],
        ]);
    }

    public function updateAnalysis(Request $request)
    {
        $lama = self::conditionSuggestionsOn();
        $baru = $request->boolean('saran_kondisi');
        AppSetting::put(self::KEY_CONDITION_SUGGESTIONS, $baru, $request->user()->id);

        if ($lama !== $baru) {
            Audit::log('admin.saran_kondisi', null, 'Saran kondisi ' . ($baru ? 'dinyalakan' : 'dimatikan'),
                ['perubahan' => ['saran_kondisi' => ['lama' => $lama, 'baru' => $baru]]]);
        }

        return back()->with('ok', 'Pengaturan analisa AI disimpan.');
    }

    public function testFtp(SimrsFtp $ftp)
    {
        $r = $ftp->test();
        Audit::log('admin.ftp_uji', null, 'Menguji koneksi FTP SIMRS: ' . ($r['ok'] ? 'berhasil' : 'gagal'));

        return back()->with($r['ok'] ? 'ok' : 'warn', $r['message']);
    }
}
