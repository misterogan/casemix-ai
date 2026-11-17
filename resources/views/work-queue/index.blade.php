<x-app-layout title="Antrean kerja">
<div class="space-y-4">
    <section class="panel px-5 py-4">
        <h1 class="text-xl font-bold text-arang">Antrean kerja</h1>
        <p class="text-sm text-abu">Folder perlu ditinjau atau terblokir, urut prioritas. <span data-total>{{ $rows->total() }}</span> folder.</p>
        <form method="GET" action="{{ route('work-queue.index') }}" class="mt-4 flex flex-wrap items-end gap-3">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="saya" value="1" @checked($filters['saya'])> Tugas saya saja</label>
            <div>
                <label for="vonis" class="block text-xs text-abu">Vonis</label>
                <select id="vonis" name="vonis" class="field">
                    <option value="">Tinjau &amp; Blokir</option>
                    <option value="tinjau" @selected($filters['vonis'] === 'tinjau')>Tinjau</option>
                    <option value="blokir" @selected($filters['vonis'] === 'blokir')>Blokir</option>
                </select>
            </div>
            <div>
                <label for="periode" class="block text-xs text-abu">Periode</label>
                <select id="periode" name="periode" class="field">
                    @foreach (\App\Services\Dashboard\DashboardStats::PERIODS as $k => $l)
                        <option value="{{ $k }}" @selected($filters['periode'] === (string) $k)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn-primary">Terapkan</button>
        </form>
    </section>

    <section class="panel overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-left text-xs text-abu">
                <tr>
                    <th class="px-4 py-2">Prioritas</th><th class="px-4 py-2">SEP</th><th class="px-4 py-2">Dx</th><th class="px-4 py-2">Vonis</th>
                    <th class="px-4 py-2">Tenggat</th><th class="px-4 py-2">Butir kurang</th><th class="px-4 py-2">Ditugaskan ke</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($rows as $r)
                @php
                    $f = $r['folder'];
                    $sisa = $r['tenggat'] ? (int) now()->startOfDay()->diffInDays($r['tenggat']->copy()->startOfDay(), false) : null;
                @endphp
                <tr class="border-t border-abu-muda" data-folder="{{ $f->id }}">
                    <td class="px-4 py-2">
                        <div class="flex items-center gap-2">
                            <div class="h-2 w-16 overflow-hidden rounded-full bg-abu-muda" aria-hidden="true"><div class="h-full {{ $r['vonis'] === 'blokir' ? 'bg-kritis' : 'bg-waspada' }}" style="width: {{ $r['prioritas'] }}%"></div></div>
                            <span class="num" data-prioritas>{{ $r['prioritas'] }}</span>
                        </div>
                    </td>
                    <td class="px-4 py-2"><a href="{{ route('folders.show', $f) }}" class="text-biru hover:underline">{{ $f->no_sep ?: $f->name }}</a></td>
                    <td class="px-4 py-2 font-mono">{{ $r['dx'] ?? '-' }}</td>
                    <td class="px-4 py-2">
                        @if ($r['vonis'] === 'blokir')<span class="chip chip-kritis">Blokir</span>@else<span class="chip chip-waspada">Tinjau</span>@endif
                    </td>
                    <td class="px-4 py-2 whitespace-nowrap">
                        @if ($r['tenggat'])
                            {{ $r['tenggat']->format('d/m/Y') }}
                            @if ($sisa < 0)<span class="block text-xs text-kritis">terlambat {{ -$sisa }} hari</span>@else<span class="block text-xs text-abu">sisa {{ $sisa }} hari</span>@endif
                        @else — @endif
                    </td>
                    <td class="px-4 py-2 num">{{ (int) $f->rubric_kurang }}</td>
                    <td class="px-4 py-2">
                        <form method="POST" action="{{ route('folders.assign', $f) }}">
                            @csrf @method('PUT')
                            <select name="user_id" class="field" aria-label="Tugaskan folder {{ $f->no_sep ?: $f->name }}" onchange="this.form.submit()">
                                <option value="">— Belum ditugaskan —</option>
                                @foreach ($petugas as $u)
                                    <option value="{{ $u->id }}" @selected($f->assigned_to === $u->id)>{{ $u->name }}</option>
                                @endforeach
                            </select>
                            <noscript><button class="btn-ghost">Simpan</button></noscript>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-6 text-center text-abu">Tidak ada folder dalam antrean.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
    {{ $rows->links() }}
</div>
</x-app-layout>
