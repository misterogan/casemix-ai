<x-app-layout title="Pengguna">
    <x-page-header title="Pengguna">
        <x-slot:actions><a href="{{ route('admin.users.create') }}" class="btn-primary">Tambah pengguna</a></x-slot:actions>
    </x-page-header>
    <section class="panel">
        <form method="GET" class="border-b border-garis px-4 py-3">
            <input name="q" value="{{ request('q') }}" class="field max-w-sm" placeholder="Cari nama atau email" aria-label="Cari pengguna">
        </form>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Nama</th><th>Peran</th><th>Status</th><th>Terakhir masuk</th><th></th></tr></thead>
                <tbody>
                @foreach ($users as $u)
                    <tr>
                        <td><p class="font-semibold">{{ $u->name }}</p><p class="text-xs text-tinta-3">{{ $u->email }}@if($u->nip) · NIP {{ $u->nip }}@endif</p></td>
                        <td>{{ $u->roleLabel() }}</td>
                        <td><span @class(['badge', 'tone-ok' => $u->is_active, 'tone-neutral' => ! $u->is_active])>{{ $u->is_active ? 'Aktif' : 'Nonaktif' }}</span></td>
                        <td class="num text-tinta-2">{{ $u->last_login_at?->format('d/m/Y H:i') ?? '—' }}</td>
                        <td class="text-right"><a href="{{ route('admin.users.edit', $u) }}" class="text-sm font-medium text-giok hover:underline">Ubah</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $users->links() }}
    </section>
</x-app-layout>
