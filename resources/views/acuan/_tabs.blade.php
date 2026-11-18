{{-- Tab Acuan regulasi: Regulasi (Admin) | PNPK (Admin & Koordinator) --}}
@php
    $tabs = [];
    if (auth()->user()->can('admin.manage')) {
        $tabs[] = ['label' => 'Regulasi', 'href' => route('admin.regulations.index'), 'active' => request()->routeIs('admin.regulations.*')];
    }
    $tabs[] = ['label' => 'PNPK', 'href' => route('acuan.pnpk.index'), 'active' => request()->routeIs('acuan.pnpk.*')];
@endphp
<x-tabs :items="$tabs" label="Jenis acuan" class="mb-4"/>
