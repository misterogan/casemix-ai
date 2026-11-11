<x-app-layout title="Riwayat pasien">
    <x-page-header title="Riwayat pasien di FTP SIMRS"
                   :subtitle="$claim->nama_pasien . ' · RM ' . $claim->no_rm . ' · folder ' . config('casemix.ftp.path_riwayat_pasien')"
                   :back="route('claims.show', $claim)"/>

    <section class="panel">
        @if ($error)
            <p class="px-5 py-4 text-sm text-kritis">{{ $error }}</p>
        @elseif ($files->isEmpty())
            <x-empty-state title="Tidak ada file yang cocok"
                text="Aplikasi mencari file yang nama atau foldernya memuat No. RM {{ $claim->no_rm }}. Periksa penamaan file di folder riwayat pasien."/>
        @else
            <form method="POST" action="{{ route('claims.history.attach', $claim) }}">
                @csrf
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead><tr>
                            <th class="w-10"><input type="checkbox" data-check-all="paths" aria-label="Pilih semua" class="size-4 rounded border-garis-tua accent-giok"></th>
                            <th>File</th><th>Ukuran</th><th>Diubah</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($files as $f)
                            @php $already = in_array($f['path'], $attached, true); @endphp
                            <tr>
                                <td><input type="checkbox" name="paths[]" value="{{ $f['path'] }}" @disabled($already) class="size-4 rounded border-garis-tua accent-giok" aria-label="Pilih {{ $f['name'] }}"></td>
                                <td>
                                    <p class="font-medium">{{ $f['name'] }}</p>
                                    <p class="text-xs text-tinta-3">{{ $f['path'] }}@if($already) · sudah dilampirkan @endif</p>
                                </td>
                                <td class="num">{{ number_format($f['size'] / 1024, 0, ',', '.') }} KB</td>
                                <td class="num">{{ \Illuminate\Support\Carbon::createFromTimestamp($f['modified'])->format('d/m/Y H:i') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="flex justify-end border-t border-garis px-5 py-3">
                    <button type="submit" class="btn-primary">Lampirkan sebagai dokumen tambahan</button>
                </div>
            </form>
        @endif
    </section>
</x-app-layout>
