<x-app-layout title="Acuan regulasi">
    <x-page-header title="Acuan regulasi" subtitle="Rujukan untuk butir Daftar periksa yang kurang; tampil sebagai tautan Acuan di verifikasi folder." :back="route('admin.settings.index')">
        <x-slot:actions><a href="{{ route('admin.regulations.create') }}" class="btn-primary">Tambah acuan</a></x-slot:actions>
    </x-page-header>

    <section class="panel">
        @if ($regulations->isEmpty())
            <x-empty-state title="Belum ada acuan regulasi" text="Tambahkan regulasi (mis. Permenkes 24/2022 Rekam Medis) lalu pilih butir Daftar periksa yang terkait."/>
        @else
            <ul class="divide-y divide-garis">
                @foreach ($regulations as $r)
                    <li class="flex flex-wrap items-start justify-between gap-3 px-5 py-3 text-sm" data-regulasi="{{ $r->id }}">
                        <div class="min-w-0">
                            <p class="font-medium text-arang">{{ $r->judul }}@if ($r->pasal) · {{ $r->pasal }}@endif
                                @unless ($r->aktif)<span class="badge tone-neutral">Nonaktif</span>@endunless</p>
                            <p class="text-xs text-tinta-3">Butir: {{ collect($r->rubricCodes())->map(fn ($k) => \App\Support\VerificationRubric::label($k))->implode(', ') ?: '—' }}</p>
                        </div>
                        <div class="flex gap-2">
                            <a href="{{ route('admin.regulations.edit', $r) }}" class="btn-secondary">Ubah</a>
                            <form method="POST" action="{{ route('admin.regulations.destroy', $r) }}" onsubmit="return confirm('Hapus acuan ini?')">
                                @csrf @method('DELETE')<button class="btn-ghost">Hapus</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</x-app-layout>
