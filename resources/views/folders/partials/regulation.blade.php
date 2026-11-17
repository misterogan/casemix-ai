{{-- Tautan kecil "Acuan" pada butir rubrik berstatus kurang: judul, pasal, ringkasan, url regulasi terkait. Butuh $acuanPerButir dan $kode. --}}
@php $acuan = $acuanPerButir->get($kode); @endphp
@if ($acuan?->isNotEmpty())
    <details class="relative inline-block text-xs" data-acuan="{{ $kode }}">
        <summary class="cursor-pointer text-url underline">Acuan</summary>
        <div class="absolute left-0 z-20 mt-1 w-80 max-w-[80vw] space-y-3 rounded-md border border-gray-200 bg-white p-3 text-sm shadow-lg" role="note">
            @foreach ($acuan as $r)
                <div>
                    <p class="font-medium text-arang">{{ $r->judul }}@if ($r->pasal) · {{ $r->pasal }}@endif</p>
                    <p class="mt-0.5 whitespace-pre-line text-abu">{{ $r->ringkasan }}</p>
                    @if ($r->url)<a href="{{ $r->url }}" target="_blank" rel="noopener noreferrer" class="text-url underline">Buka sumber</a>@endif
                </div>
            @endforeach
        </div>
    </details>
@endif
