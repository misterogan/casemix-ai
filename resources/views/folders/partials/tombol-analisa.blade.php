{{-- Tombol Verifikasi/Analisa ulang: satu sumber untuk kepala utama & kepala sticky. $kecil = versi ringkas (sticky). --}}
@php $kecil = $kecil ?? false; @endphp
<form method="POST" action="{{ route('folders.analyze', $folder) }}" @class(['shrink-0' => $kecil]) data-tombol-analisa="{{ $latest ? 'ulang' : 'baru' }}" @if ($latest) data-konfirmasi="Analisa ulang dengan AI? File yang belum berubah memakai hasil baca sebelumnya; hanya file baru/berubah yang dibaca ulang, lalu periksa silang dijalankan lagi. Memakai biaya AI." @endif>
    @csrf
    <button type="submit" @class(['btn-primary', 'btn-sm' => $kecil]) title="{{ $blocker ?: ($latest ? 'Membaca file baru/berubah saja, lalu periksa silang seluruh berkas diulang. Memakai biaya AI.' : 'Membaca semua file lalu periksa silang. Memakai biaya AI.') }}" @if ($kunci) data-lock @endif @disabled($berjalan || $blocker)>{{ $latest ? ($kecil ? 'Analisa ulang' : 'Analisa ulang dengan AI') : 'Verifikasi dengan AI' }}</button>
</form>
