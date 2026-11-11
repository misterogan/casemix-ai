<x-app-layout title="Peran dan hak akses">
    <x-page-header title="Peran dan hak akses" subtitle="Atur apa yang boleh dilakukan setiap peran. Perubahan berlaku segera."/>
    <form method="POST" action="{{ route('admin.roles.update') }}" class="panel">
        @csrf @method('PUT')
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr>
                    <th>Hak akses</th>
                    @foreach ($labels as $k => $l)<th class="text-center">{{ $l }}</th>@endforeach
                </tr></thead>
                <tbody>
                @foreach ($permissions as $perm => $desc)
                    <tr>
                        <td><p class="font-medium">{{ $desc }}</p><p class="num text-xs text-tinta-3">{{ $perm }}</p></td>
                        @foreach ($labels as $role => $l)
                            @php $locked = $role === 'admin' && $perm === 'admin.manage'; @endphp
                            <td class="text-center">
                                <input type="checkbox" name="matrix[{{ $role }}][{{ $perm }}]" value="1"
                                       @checked(optional($roles[$role] ?? null)?->permissions?->contains('name', $perm)) @disabled($locked)
                                       class="size-4 rounded border-garis-tua accent-giok" aria-label="{{ $l }}: {{ $desc }}">
                                @if ($locked)<input type="hidden" name="matrix[{{ $role }}][{{ $perm }}]" value="1">@endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="flex justify-end border-t border-garis px-5 py-3">
            <button type="submit" class="btn-primary">Simpan hak akses</button>
        </div>
    </form>
</x-app-layout>
