<x-app-layout title="Riwayat aktivitas">
    <x-page-header title="Riwayat aktivitas" subtitle="Semua aksi pengguna dan sistem tercatat dan tidak bisa diubah dari aplikasi."/>

    <section class="panel">
        <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-garis px-4 py-3">
            <div class="min-w-56 flex-1">
                <label for="q" class="label text-xs">Cari keterangan</label>
                <input id="q" name="q" value="{{ request('q') }}" class="field" placeholder="Nama pasien, nama file, alasan…">
            </div>
            <div>
                <label for="user" class="label text-xs">Pengguna</label>
                <select id="user" name="user" class="field">
                    <option value="">Semua</option>
                    @foreach ($users as $u)<option value="{{ $u->id }}" @selected(request('user') == $u->id)>{{ $u->name }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="aksi" class="label text-xs">Jenis aksi</label>
                <select id="aksi" name="aksi" class="field">
                    <option value="">Semua</option>
                    @foreach ($actions as $k => $l)<option value="{{ $k }}" @selected(request('aksi') === $k)>{{ $l }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="tanggal" class="label text-xs">Tanggal</label>
                <input id="tanggal" type="date" name="tanggal" value="{{ request('tanggal') }}" class="field">
            </div>
            <button class="btn-secondary">Terapkan</button>
        </form>

        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th class="w-40">Waktu</th><th class="w-48">Pengguna</th><th>Aktivitas</th><th class="w-28">IP</th></tr></thead>
                <tbody>
                @forelse ($logs as $log)
                    <tr>
                        <td class="num whitespace-nowrap text-tinta-2">{{ $log->created_at->format('d/m/Y H:i:s') }}</td>
                        <td>{{ $log->user?->name ?? 'Sistem' }}</td>
                        <td>
                            {{ $log->description }}
                            @if ($log->subject_type === \App\Models\Claim::class && $log->subject_id)
                                <a href="{{ route('claims.show', $log->subject_id) }}" class="ml-1 text-xs font-medium text-giok hover:underline">buka berkas</a>
                            @endif
                            @if (! empty($log->properties['perubahan']))
                                @php $fmt = fn ($v) => $v === null || $v === '' ? '—' : (is_array($v) ? collect($v)->filter()->map(fn ($x, $k) => "$k: $x")->implode(', ') : $v); @endphp
                                <ul class="mt-1 space-y-0.5 text-xs text-tinta-2">
                                    @foreach ($log->properties['perubahan'] as $field => $p)
                                        <li><span class="font-medium">{{ $field }}</span>: {{ $fmt($p['lama'] ?? null) }} → {{ $fmt($p['baru'] ?? null) }}</li>
                                    @endforeach
                                </ul>
                            @endif
                            <span class="block text-xs text-tinta-3">{{ $log->action }}</span>
                        </td>
                        <td class="num text-xs text-tinta-3">{{ $log->ip }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-8 text-center text-tinta-3">Tidak ada aktivitas yang cocok.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $logs->links() }}
    </section>
</x-app-layout>
