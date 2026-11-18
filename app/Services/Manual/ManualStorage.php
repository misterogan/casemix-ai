<?php

namespace App\Services\Manual;

use App\Models\FtpFolder;
use App\Models\ManualFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Validasi dan penyimpanan dokumen unggahan manual (storage/app/private/manual/{folder}/{uuid}.{ext}).
 * Isi file dicek dari magic bytes; gambar diputar sesuai EXIF, metadata dibuang, disimpan ulang sebagai JPEG.
 */
class ManualStorage
{
    public const DISK = 'local';

    public const MAX_FILE = 25 * 1024 * 1024;

    public const MAX_FILES = 20;

    public const MAX_FOLDER = 150 * 1024 * 1024;

    private const MAX_SIDE = 3000;

    public static function disk()
    {
        return Storage::disk(self::DISK);
    }

    /** Jenis isi dari magic bytes: pdf | jpeg | png | webp | heic | null. */
    public static function sniff(string $head): ?string
    {
        return match (true) {
            str_starts_with($head, '%PDF-') => 'pdf',
            str_starts_with($head, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'webp',
            substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1', 'avif'], true) => 'heic',
            default => null,
        };
    }

    /** Simpan satu file ke folder; melempar RuntimeException dengan pesan untuk pengguna bila ditolak. */
    public function store(UploadedFile $file, FtpFolder $folder, ?string $jenis, int $userId): ManualFile
    {
        $size = (int) $file->getSize();
        if ($size > self::MAX_FILE) {
            throw new RuntimeException('Ukuran file melebihi 25 MB.');
        }
        $raw = (string) file_get_contents($file->getRealPath());
        $kind = self::sniff(substr($raw, 0, 16));
        if ($kind === 'heic' || in_array(Str::lower($file->getClientOriginalExtension()), ['heic', 'heif'], true)) {
            throw new RuntimeException('Format HEIC belum didukung. Ubah foto ke JPG dulu.');
        }
        if (! $kind) {
            throw new RuntimeException('Isi file bukan PDF, JPG, PNG, atau WEBP.');
        }
        $sha = hash('sha256', $raw);
        $aktif = $folder->manualFiles();
        if ((clone $aktif)->where('sha256', $sha)->exists()) {
            throw new RuntimeException('File yang sama sudah ada di folder ini.');
        }
        if ((clone $aktif)->sum('ukuran') + $size > self::MAX_FOLDER) {
            throw new RuntimeException('Total dokumen manual di folder ini melebihi 150 MB.');
        }

        if ($kind === 'pdf') {
            if (preg_match('#/Encrypt\b#', $raw)) {
                throw new RuntimeException('PDF terkunci kata sandi (terenkripsi). Buka kuncinya dulu lalu unggah ulang.');
            }
            [$data, $mime, $ext] = [$raw, 'application/pdf', 'pdf'];
        } else {
            [$data, $mime, $ext] = [self::cleanImage($raw, $kind), 'image/jpeg', 'jpg'];
        }

        $path = 'manual/' . $folder->id . '/' . Str::uuid() . '.' . $ext;
        if (! self::disk()->put($path, $data)) {
            throw new RuntimeException('File gagal disimpan.');
        }

        return ManualFile::create([
            'ftp_folder_id' => $folder->id,
            'nama_asli' => mb_substr(str_replace(["\r", "\n", '"'], '', $file->getClientOriginalName()), 0, 255),
            'path' => $path,
            'mime' => $mime,
            'ukuran' => strlen($data),
            'sha256' => $sha,
            'jenis_dokumen' => array_key_exists((string) $jenis, ManualFile::JENIS) ? $jenis : null,
            'diunggah_oleh' => $userId,
        ]);
    }

    public static function contents(ManualFile $m): ?string
    {
        return self::disk()->exists($m->path) ? self::disk()->get($m->path) : null;
    }

    public static function deletePhysical(ManualFile $m): void
    {
        if ($m->path && self::disk()->exists($m->path)) {
            self::disk()->delete($m->path);
        }
    }

    /** Putar sesuai EXIF, kecilkan ke sisi terpanjang 3000 px, simpan ulang JPEG 85 (seluruh metadata terbuang). */
    public static function cleanImage(string $raw, string $kind): string
    {
        $img = @imagecreatefromstring($raw);
        if (! $img) {
            throw new RuntimeException('Gambar rusak atau tidak bisa dibaca.');
        }
        $img = match ($kind === 'jpeg' ? self::orientation($raw) : 1) {
            2 => self::flip($img, IMG_FLIP_HORIZONTAL),
            3 => imagerotate($img, 180, 0),
            4 => self::flip($img, IMG_FLIP_VERTICAL),
            5 => self::flip(imagerotate($img, 270, 0), IMG_FLIP_HORIZONTAL),
            6 => imagerotate($img, 270, 0),
            7 => self::flip(imagerotate($img, 90, 0), IMG_FLIP_HORIZONTAL),
            8 => imagerotate($img, 90, 0),
            default => $img,
        };
        $w = imagesx($img);
        $h = imagesy($img);
        $scale = min(1, self::MAX_SIDE / max($w, $h));
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255)); // latar putih untuk PNG/WEBP transparan
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        imagejpeg($out, null, 85);

        return (string) ob_get_clean();
    }

    private static function flip($img, int $mode)
    {
        imageflip($img, $mode);

        return $img;
    }

    /** Nilai Orientation (0x0112) dari segmen APP1 Exif pada JPEG; 1 bila tidak ada. */
    public static function orientation(string $jpeg): int
    {
        $pos = 2;
        $len = strlen($jpeg);
        while ($pos + 4 <= $len && $jpeg[$pos] === "\xFF") {
            $marker = ord($jpeg[$pos + 1]);
            $size = unpack('n', substr($jpeg, $pos + 2, 2))[1];
            if ($marker === 0xE1 && substr($jpeg, $pos + 4, 6) === "Exif\0\0") {
                $tiff = substr($jpeg, $pos + 10, $size - 8);
                $le = substr($tiff, 0, 2) === 'II';
                $u16 = fn ($o) => strlen($tiff) >= $o + 2 ? unpack($le ? 'v' : 'n', substr($tiff, $o, 2))[1] : 0;
                $u32 = fn ($o) => strlen($tiff) >= $o + 4 ? unpack($le ? 'V' : 'N', substr($tiff, $o, 4))[1] : 0;
                $ifd = $u32(4);
                $n = $u16($ifd);
                for ($i = 0; $i < $n; $i++) {
                    $e = $ifd + 2 + $i * 12;
                    if ($u16($e) === 0x0112) {
                        $v = $u16($e + 8);

                        return $v >= 1 && $v <= 8 ? $v : 1;
                    }
                }

                return 1;
            }
            if ($marker === 0xDA) {
                break;
            }
            $pos += 2 + $size;
        }

        return 1;
    }
}
