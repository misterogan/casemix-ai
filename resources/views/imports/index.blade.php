@php
    $statusInfo = [
        'baru' => ['Baru', 'tone-ok'],
        'tambahan' => ['Ada file baru', 'tone-warn'],
        'sudah' => ['Sudah diimpor', 'tone-neutral'],
        'kosong' => ['Tanpa dokumen', 'tone-neutral'],
    ];
    $siap = $folders->whereIn('status', ['baru', 'tambahan']);
@endphp
<x-app-layout title="Impor dari SIMRS">
    <x-page-header title="Impor dari FTP SIMRS"
                   subtitle="Satu folder pasien menjadi satu berkas. Identitas dibaca dari nama folder, jenis dokumen dari nama file."/>

    @if ($error)
        <section class="panel panel-body mb-6 text-sm text-kritis">{{ $error }}</section>
    @else
        <form method="POST" action="{{ route('imports.store') }}" class="panel mb-6">
            @csrf
            <div class="panel-head">
                <div>
                    <h2 class="panel-title">Folder pasien di <span class="num">{{ $root }}</span></h2>
                    <p class="text-xs text-tinta-3">
                        {{ $folders->count() }} folder · {{ $siap->count() }} siap diimpor · maks. {{ $max }} file per impor, satu folder tidak dipecah
                    </p>
                </div>
                <button type="submit" class="btn-primary" @disabled($siap->isEmpty())>Impor folder terpilih</button>
            </div>

            @if ($folders->isEmpty())
                <x-empty-state title="Belum ada folder pasien"
                    text="Folder dengan pola NoRM_NAMA_NoSEP di {{ $root }} akan muncul di sini."/>
            @else
                <div class="overflow-x-auto">
                    <table class="table">
                        <thead>
                        <tr>
                            <th class="w-10">
                                <input type="checkbox" data-check-all="folders" checked aria-label="Pilih semua" class="size-4 rounded border-garis-tua accent-giok">
                            </th>
                            <th>Pasien</th>
                            <th>No. SEP</th>
                            <th>Rawat</th>
                            <th>Dokumen</th>
                            <th>Status</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($folders as $f)
                            @php [$label, $tone] = $statusInfo[$f['status']]; $bisa = in_array($f['status'], ['baru', 'tambahan'], true); @endphp
                            <tr @class(['opacity-55' => ! $bisa])>
                                <td>
                                    <input type="checkbox" name="folders[]" value="{{ $f['path'] }}" @checked($bisa) @disabled(! $bisa)
                                           class="size-4 rounded border-garis-tua accent-giok" aria-label="Pilih {{ $f['folder'] }}">
                                </td>
                                <td>
                                    @if ($f['dikenali'])
                                        <p class="font-semibold">{{ $f['nama'] }}</p>
                                        <p class="num text-xs text-tinta-3">RM {{ $f['no_rm'] }}</p>
                                    @else
                                        <p class="font-semibold text-waspada">{{ $f['folder'] }}</p>
                                        <p class="text-xs text-waspada">Nama folder tidak sesuai pola NoRM_NAMA_NoSEP</p>
                                    @endif
                                </td>
                                <td class="num">{{ $f['no_sep'] ?: '—' }}</td>
                                <td>{{ ['jalan' => 'Rawat jalan', 'inap' => 'Rawat inap'][$f['jenis_rawat']] ?? '—' }}</td>
                                <td>
                                    <span class="num">{{ count($f['new_files']) }}/{{ count($f['files']) }}</span>
                                    <details class="mt-0.5">
                                        <summary class="cursor-pointer text-xs text-giok">lihat</summary>
                                        <ul class="mt-1 space-y-0.5 text-xs text-tinta-2">
                                            @foreach ($f['files'] as $file)
                                                @php $type = \App\Services\Import\DocumentGuesser::type($file['name']); @endphp
                                                <li>{{ $file['name'] }} <span class="text-tinta-3">→ {{ $type->label() }}</span></li>
                                            @endforeach
                                        </ul>
                                    </details>
                                </td>
                                <td><span class="badge {{ $tone }}">{{ $label }}</span></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </form>
    @endif

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
