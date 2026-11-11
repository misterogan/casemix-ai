<x-app-layout title="Impor dari SIMRS">
    <x-page-header title="Impor dari FTP SIMRS"
                   subtitle="AI memilah jenis dokumen dan identitas pasien tiap file, lalu Anda memeriksa pengelompokannya sebelum disimpan."/>

    <section class="panel mb-6">
        <div class="panel-body flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm text-tinta-2">Folder <span class="num font-semibold text-tinta">{{ $folder }}</span></p>
                @if ($error)
                    <p class="mt-1 text-sm text-kritis">{{ $error }}</p>
                @else
                    <p class="mt-1 text-2xl font-bold"><span class="num">{{ $pending->count() }}</span> <span class="text-base font-medium text-tinta-2">file baru belum diimpor</span></p>
                    @if ($pending->count() > $max)
                        <p class="text-xs text-tinta-3">Satu kali impor maksimal {{ $max }} file. Sisanya bisa diimpor berikutnya.</p>
                    @endif
                @endif
            </div>
            <form method="POST" action="{{ route('imports.store') }}">
                @csrf
                <button type="submit" class="btn-primary" @disabled($error || $pending->isEmpty())>
                    Impor {{ min($pending->count(), $max) ?: '' }} file
                </button>
            </form>
        </div>
        @if (! $error && $pending->isNotEmpty())
            <details class="border-t border-garis">
                <summary class="cursor-pointer px-5 py-2.5 text-sm text-tinta-2">Lihat daftar file</summary>
                <ul class="max-h-64 overflow-y-auto px-5 pb-3 text-sm">
                    @foreach ($pending->take(200) as $f)
                        <li class="flex justify-between gap-3 py-0.5"><span class="truncate">{{ $f['name'] }}</span><span class="num text-tinta-3">{{ number_format($f['size'] / 1024, 0, ',', '.') }} KB</span></li>
                    @endforeach
                </ul>
            </details>
        @endif
    </section>

    <section class="panel">
        <div class="panel-head"><h2 class="panel-title">Riwayat impor</h2></div>
        @if ($batches->isEmpty())
            <p class="px-5 py-6 text-sm text-tinta-3">Belum pernah mengimpor.</p>
        @else
            <div class="overflow-x-auto">
                <table class="table">
                    <thead><tr><th>Waktu</th><th>Oleh</th><th>File</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($batches as $b)
                        <tr>
                            <td class="num">{{ $b->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $b->creator?->name }}</td>
                            <td class="num">{{ $b->processed }}/{{ $b->total }}</td>
                            <td><span @class(['badge', 'tone-ok' => $b->status === 'disimpan', 'tone-warn' => $b->status === 'siap_ditinjau', 'tone-crit' => $b->status === 'gagal', 'tone-neutral' => $b->isProcessing()])>{{ $b->statusLabel() }}</span></td>
                            <td class="text-right"><a href="{{ route('imports.show', $b) }}" class="text-sm font-medium text-giok hover:underline">{{ $b->status === 'siap_ditinjau' ? 'Tinjau' : 'Buka' }}</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $batches->links() }}
        @endif
    </section>
</x-app-layout>
