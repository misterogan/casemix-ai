<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * Klien FTP ringan memakai ekstensi ftp PHP secara langsung.
 * Dipakai sebagai pengganti Flysystem karena parser daftar folder Flysystem
 * melewatkan folder yang namanya mengandung titik (mis. "010120_Tn. NAMA_...").
 * Urutan cara membaca folder: MLSD (standar, ada jenis dan ukuran) lalu NLST sebagai cadangan.
 */
class FtpClient
{
    /** @var \FTP\Connection|null */
    private $conn = null;

    public function __construct(private array $cfg) {}

    public function __destruct()
    {
        $this->close();
    }

    public function connect(): void
    {
        if ($this->conn) {
            return;
        }
        $host = (string) ($this->cfg['host'] ?? '');
        $port = (int) ($this->cfg['port'] ?? 21);
        $timeout = (int) ($this->cfg['timeout'] ?? 30);

        $c = ! empty($this->cfg['ssl']) ? @ftp_ssl_connect($host, $port, $timeout) : @ftp_connect($host, $port, $timeout);
        if (! $c) {
            throw new RuntimeException("Tidak bisa terhubung ke FTP $host:$port.");
        }
        if (! @ftp_login($c, (string) ($this->cfg['username'] ?? ''), (string) ($this->cfg['password'] ?? ''))) {
            @ftp_close($c);
            throw new RuntimeException('Login FTP ditolak. Periksa SIMRS_FTP_USERNAME dan SIMRS_FTP_PASSWORD.');
        }
        ftp_pasv($c, (bool) ($this->cfg['passive'] ?? true));
        $this->conn = $c;
    }

    public function close(): void
    {
        if ($this->conn) {
            @ftp_close($this->conn);
            $this->conn = null;
        }
    }

    /**
     * Isi satu folder (tidak rekursif).
     *
     * @return array<int, array{name: string, path: string, type: string, size: int, modified: int}>
     */
    public function list(string $dir): array
    {
        $this->connect();
        $dir = '/' . trim($dir, '/');

        $mlsd = @ftp_mlsd($this->conn, $dir);
        if (is_array($mlsd)) {
            $out = [];
            foreach ($mlsd as $e) {
                $type = strtolower($e['type'] ?? '');
                if (in_array($type, ['cdir', 'pdir'], true) || in_array($e['name'] ?? '', ['.', '..'], true)) {
                    continue;
                }
                $out[] = [
                    'name' => $e['name'],
                    'path' => rtrim($dir, '/') . '/' . $e['name'],
                    'type' => $type === 'dir' ? 'dir' : 'file',
                    'size' => (int) ($e['size'] ?? $e['sizd'] ?? 0),
                    'modified' => $this->parseMlsdTime($e['modify'] ?? null),
                ];
            }

            return $this->sorted($out);
        }

        // Cadangan bila server tidak mendukung MLSD
        $names = @ftp_nlist($this->conn, $dir);
        if ($names === false) {
            throw new RuntimeException("Tidak bisa membaca folder FTP $dir (tidak ada atau tidak ada izin).");
        }
        $out = [];
        foreach ($names as $n) {
            $name = basename(str_replace('\\', '/', $n));
            if (in_array($name, ['.', '..', ''], true)) {
                continue;
            }
            $path = rtrim($dir, '/') . '/' . $name;
            $size = @ftp_size($this->conn, $path);
            $isDir = $size < 0 && $this->isDir($path);
            $out[] = [
                'name' => $name,
                'path' => $path,
                'type' => $isDir ? 'dir' : 'file',
                'size' => max(0, (int) $size),
                'modified' => $isDir ? 0 : max(0, (int) @ftp_mdtm($this->conn, $path)),
            ];
        }

        return $this->sorted($out);
    }

    public function isDir(string $path): bool
    {
        $this->connect();
        $cwd = @ftp_pwd($this->conn) ?: '/';
        if (@ftp_chdir($this->conn, $path)) {
            @ftp_chdir($this->conn, $cwd);

            return true;
        }

        return false;
    }

    public function download(string $remotePath, string $localAbsolute): void
    {
        $this->connect();
        if (! is_dir(dirname($localAbsolute))) {
            mkdir(dirname($localAbsolute), 0775, true);
        }
        if (! @ftp_get($this->conn, $localAbsolute, $remotePath, FTP_BINARY)) {
            @unlink($localAbsolute);
            throw new RuntimeException("Gagal mengunduh $remotePath dari FTP.");
        }
    }

    private function parseMlsdTime(?string $v): int
    {
        if (! $v || strlen($v) < 14) {
            return 0;
        }
        $dt = \DateTimeImmutable::createFromFormat('YmdHis', substr($v, 0, 14), new \DateTimeZone('UTC'));

        return $dt ? $dt->getTimestamp() : 0;
    }

    private function sorted(array $items): array
    {
        usort($items, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $items;
    }
}
