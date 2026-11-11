@php use App\Enums\Severity; use App\Support\Eklaim; @endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan pemeriksaan · {{ $claim->nama_pasien }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
    <style>
        @page { size: A4; margin: 16mm 14mm; }
        @media print { .no-print { display: none !important; } body { background: #fff; } }
        body { font-size: 12.5px; }
    </style>
</head>
<body class="bg-white">
<div class="mx-auto max-w-[760px] p-8 print:p-0">
    <div class="no-print mb-6 flex justify-end gap-2">
        <button onclick="window.print()" class="btn-primary">Cetak</button>
    </div>

    <header class="flex items-start justify-between border-b-2 border-tinta pb-3">
        <div>
            <p class="text-xs text-tinta-2">{{ config('casemix.hospital_name') }} · Unit Casemix</p>
            <h1 class="text-xl font-bold">Laporan pemeriksaan berkas klaim JKN</h1>
            <p class="text-xs text-tinta-3">Dicetak {{ now()->translatedFormat('d F Y H:i') }} oleh {{ auth()->user()->name }}</p>
        </div>
        @if ($claim->isLocked())
            <x-approval-stamp :claim="$claim"/>
        @else
            <span class="badge {{ $claim->status->tone() }}">{{ $claim->status->label() }}</span>
        @endif
    </header>

    <table class="mt-4 w-full text-sm">
        <tbody>
        @foreach ([
            'Nama pasien' => $claim->nama_pasien, 'No. RM' => $claim->no_rm, 'No. SEP' => $claim->no_sep ?: '—',
            'Jenis rawat' => Eklaim::JENIS_RAWAT[$claim->jenis_rawat] ?? $claim->jenis_rawat,
            'Tanggal' => $claim->tanggal_masuk?->format('d/m/Y') . ' – ' . ($claim->tanggal_keluar?->format('d/m/Y') ?? '…') . ' (LOS ' . ($claim->los() ?? '-') . ' hari)',
            'DPJP' => $claim->dpjp, 'Diagnosis utama' => $claim->diagnosis_utama ?: '—',
            'Diagnosis sekunder' => $claim->diagnosis_sekunder ?: '—', 'Tindakan' => $claim->tindakan ?: '—',
            'Total tarif' => Eklaim::rupiah($claim->tarifTotal()),
        ] as $k => $v)
            <tr class="border-b border-garis"><th class="w-40 py-1.5 pr-3 text-left align-top font-medium text-tinta-2">{{ $k }}</th><td class="num py-1.5 whitespace-pre-line">{{ $v }}</td></tr>
        @endforeach
        </tbody>
    </table>

    <h2 class="mt-6 text-base font-bold">Dokumen ({{ $claim->documents->count() }})</h2>
    <p class="text-sm">{{ $claim->documents->map(fn ($d) => $d->type->label() . ': ' . $d->original_name)->implode('; ') ?: '—' }}</p>

    <h2 class="mt-6 text-base font-bold">Hasil pemeriksaan</h2>
    @if (! $review)
        <p class="text-sm">Belum diperiksa.</p>
    @else
        <p class="text-xs text-tinta-3">{{ $review->finished_at?->format('d/m/Y H:i') }} · model {{ $review->model }}@if($stale) · <strong class="text-waspada">USANG: data berubah setelah pemeriksaan</strong>@endif</p>
        @if ($review->summary)<p class="mt-2 text-sm">{{ $review->summary }}</p>@endif
        <table class="mt-3 w-full border border-garis text-sm">
            <thead class="bg-kertas"><tr><th class="px-2 py-1.5 text-left">Tingkat</th><th class="px-2 py-1.5 text-left">Temuan</th><th class="px-2 py-1.5 text-left">Sumber</th></tr></thead>
            <tbody>
            @foreach ($review->allFindings() as $f)
                <tr class="border-t border-garis align-top">
                    <td class="px-2 py-1.5 whitespace-nowrap">{{ (Severity::tryFrom($f['tingkat']) ?? Severity::Perhatian)->label() }}</td>
                    <td class="px-2 py-1.5"><strong>{{ $f['judul'] }}</strong>@if(! empty($f['uraian']))<br>{{ $f['uraian'] }}@endif</td>
                    <td class="px-2 py-1.5 whitespace-nowrap">{{ $f['sumber'] === 'aturan' ? 'Aturan' : 'AI' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if (! empty($review->suggestions))
            <h3 class="mt-4 font-bold">Kondisi untuk dikonfirmasi ke DPJP</h3>
            <ul class="mt-1 list-disc pl-5 text-sm">
                @foreach ($review->suggestions as $s)
                    <li><strong>{{ $s['kondisi'] }}</strong>: {{ $s['bukti'] }}@if($s['catatan']) — {{ $s['catatan'] }}@endif</li>
                @endforeach
            </ul>
        @endif
    @endif

    <h2 class="mt-6 text-base font-bold">Daftar cek petugas</h2>
    <ul class="mt-1 text-sm">
        @foreach (Eklaim::CHECKLIST as $k => $l)
            <li>{{ ! empty($claim->checklist[$k]) ? '☑' : '☐' }} {{ $l }}</li>
        @endforeach
    </ul>
    @if ($claim->checklistUser)<p class="mt-1 text-xs text-tinta-3">Oleh {{ $claim->checklistUser->name }}, {{ $claim->checklist_at?->format('d/m/Y H:i') }}</p>@endif

    <footer class="mt-8 border-t border-garis pt-3 text-xs text-tinta-3">
        Temuan AI adalah alat bantu pemeriksaan, bukan keputusan klaim dan bukan kode diagnosis. Penetapan koding dan persetujuan berkas menjadi tanggung jawab petugas casemix dan DPJP.
    </footer>
</div>
</body>
</html>
