<x-app-layout :title="$user->exists ? 'Ubah pengguna' : 'Tambah pengguna'">
    <x-page-header :title="$user->exists ? 'Ubah pengguna' : 'Tambah pengguna'" :back="route('admin.users.index')"/>
    <form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}" class="panel max-w-2xl">
        @csrf
        @if ($user->exists) @method('PUT') @endif
        <div class="panel-body grid gap-4 sm:grid-cols-2">
            <x-field name="name" label="Nama lengkap" required class="sm:col-span-2">
                <input id="name" name="name" value="{{ old('name', $user->name) }}" required class="field">
            </x-field>
            <x-field name="email" label="Email" required>
                <input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" required class="field">
            </x-field>
            <x-field name="nip" label="NIP / NIK pegawai">
                <input id="nip" name="nip" value="{{ old('nip', $user->nip) }}" class="field num">
            </x-field>
            <x-field name="role" label="Peran" required class="sm:col-span-2">
                <div class="grid gap-2 sm:grid-cols-2">
                    @php $current = old('role', $user->roles->first()?->name ?? 'petugas'); @endphp
                    @foreach ($roles as $k => $l)
                        <label class="flex cursor-pointer items-start gap-2.5 rounded-md border border-garis px-3 py-2.5 has-checked:border-giok has-checked:bg-giok-muda">
                            <input type="radio" name="role" value="{{ $k }}" @checked($current === $k) class="mt-0.5 accent-giok">
                            <span class="text-sm font-medium">{{ $l }}</span>
                        </label>
                    @endforeach
                </div>
            </x-field>
            <x-field name="password" :label="$user->exists ? 'Kata sandi baru' : 'Kata sandi'" :required="! $user->exists"
                     :hint="$user->exists ? 'Kosongkan bila tidak diganti. Minimal 10 karakter, huruf dan angka.' : 'Minimal 10 karakter, huruf dan angka.'">
                <input id="password" type="password" name="password" autocomplete="new-password" class="field" @required(! $user->exists)>
            </x-field>
            <x-field name="password_confirmation" label="Ulangi kata sandi">
                <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" class="field">
            </x-field>
            <label class="flex items-center gap-2 text-sm sm:col-span-2">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active)) class="size-4 rounded border-garis-tua accent-giok">
                Akun aktif
            </label>
        </div>
        <div class="flex justify-end gap-2 border-t border-garis px-5 py-3">
            <a href="{{ route('admin.users.index') }}" class="btn-ghost">Batal</a>
            <button type="submit" class="btn-primary">{{ $user->exists ? 'Simpan perubahan' : 'Tambah pengguna' }}</button>
        </div>
    </form>
</x-app-layout>
