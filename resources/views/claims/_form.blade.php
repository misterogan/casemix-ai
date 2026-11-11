@php
    use App\Support\Eklaim;
    $v = fn ($f, $d = null) => old($f, $claim->$f ?? $d);
    $date = fn ($f) => old($f, $claim->$f?->format('Y-m-d'));
@endphp

<div class="grid gap-6 xl:grid-cols-[1fr_360px]">
<div class="space-y-6">
    <section class="panel">
        <div class="panel-head"><h2 class="panel-title">Identitas pasien</h2></div>
        <div class="panel-body grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-field name="nama_pasien" label="Nama pasien" required class="sm:col-span-2">
                <input id="nama_pasien" name="nama_pasien" value="{{ $v('nama_pasien') }}" required class="field">
            </x-field>
            <x-field name="no_rm" label="No. RM" required>
                <input id="no_rm" name="no_rm" value="{{ $v('no_rm') }}" required class="field num">
            </x-field>
            <x-field name="no_sep" label="No. SEP" hint="Dari V-Claim. Kosongkan bila belum terbit.">
                <input id="no_sep" name="no_sep" value="{{ $v('no_sep') }}" class="field num" maxlength="30">
            </x-field>
            <x-field name="no_peserta" label="No. peserta JKN">
                <input id="no_peserta" name="no_peserta" value="{{ $v('no_peserta') }}" class="field num">
            </x-field>
            <x-field name="tanggal_lahir" label="Tanggal lahir">
                <input id="tanggal_lahir" type="date" name="tanggal_lahir" value="{{ $date('tanggal_lahir') }}" class="field">
            </x-field>
            <x-field name="jenis_kelamin" label="Jenis kelamin">
                <select id="jenis_kelamin" name="jenis_kelamin" class="field">
                    <option value="">—</option>
                    <option value="L" @selected($v('jenis_kelamin') === 'L')>Laki-laki</option>
                    <option value="P" @selected($v('jenis_kelamin') === 'P')>Perempuan</option>
                </select>
            </x-field>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><h2 class="panel-title">Pelayanan</h2><span class="text-xs text-tinta-3">Mengikuti formulir E-Klaim</span></div>
        <div class="panel-body grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <x-field name="jenis_rawat" label="Jenis rawat" required>
                <select id="jenis_rawat" name="jenis_rawat" class="field" required>
                    @foreach (Eklaim::JENIS_RAWAT as $k => $l)<option value="{{ $k }}" @selected($v('jenis_rawat') === $k)>{{ $l }}</option>@endforeach
                </select>
            </x-field>
            <x-field name="tanggal_masuk" label="Tanggal masuk" required>
                <input id="tanggal_masuk" type="date" name="tanggal_masuk" value="{{ $date('tanggal_masuk') }}" required class="field">
            </x-field>
            <x-field name="tanggal_keluar" label="Tanggal keluar">
                <input id="tanggal_keluar" type="date" name="tanggal_keluar" value="{{ $date('tanggal_keluar') }}" class="field">
            </x-field>
            <x-field name="cara_masuk" label="Cara masuk">
                <select id="cara_masuk" name="cara_masuk" class="field">
                    <option value="">—</option>
                    @foreach (Eklaim::CARA_MASUK as $k => $l)<option value="{{ $k }}" @selected($v('cara_masuk') === $k)>{{ $l }}</option>@endforeach
                </select>
            </x-field>
            <x-field name="cara_pulang" label="Cara pulang">
                <select id="cara_pulang" name="cara_pulang" class="field">
                    <option value="">—</option>
                    @foreach (Eklaim::CARA_PULANG as $k => $l)<option value="{{ $k }}" @selected($v('cara_pulang') === $k)>{{ $l }}</option>@endforeach
                </select>
            </x-field>
            <x-field name="dpjp" label="DPJP" required>
                <input id="dpjp" name="dpjp" value="{{ $v('dpjp') }}" required class="field">
            </x-field>

            <div data-show-when="jenis_rawat=inap" class="contents">
                <x-field name="kelas_hak" label="Hak kelas">
                    <select id="kelas_hak" name="kelas_hak" class="field">
                        <option value="">—</option>
                        @foreach (Eklaim::KELAS as $k => $l)<option value="{{ $k }}" @selected((string) $v('kelas_hak') === (string) $k)>{{ $l }}</option>@endforeach
                    </select>
                </x-field>
                <x-field name="kelas_rawat" label="Kelas rawat">
                    <select id="kelas_rawat" name="kelas_rawat" class="field">
                        <option value="">—</option>
                        @foreach (Eklaim::KELAS_RAWAT as $k => $l)<option value="{{ $k }}" @selected((string) $v('kelas_rawat') === (string) $k)>{{ $l }}</option>@endforeach
                    </select>
                </x-field>
                <x-field name="rawat_intensif_hari" label="Rawat intensif (hari)">
                    <input id="rawat_intensif_hari" type="number" min="0" name="rawat_intensif_hari" value="{{ $v('rawat_intensif_hari') }}" class="field num">
                </x-field>
                <x-field name="ventilator_jam" label="Ventilator (jam)">
                    <input id="ventilator_jam" type="number" min="0" name="ventilator_jam" value="{{ $v('ventilator_jam') }}" class="field num">
                </x-field>
            </div>

            <x-field name="berat_lahir" label="Berat lahir (gram)" hint="Wajib untuk pasien umur ≤ 28 hari.">
                <input id="berat_lahir" type="number" min="0" name="berat_lahir" value="{{ $v('berat_lahir') }}" class="field num">
            </x-field>
            <x-field name="tekanan_darah" label="Tekanan darah" hint="Contoh 120/80">
                <input id="tekanan_darah" name="tekanan_darah" value="{{ $v('tekanan_darah') }}" class="field num" placeholder="120/80">
            </x-field>
            <x-field name="adl_sub_acute" label="ADL sub-acute" hint="12–60, bila ada">
                <input id="adl_sub_acute" type="number" min="12" max="60" name="adl_sub_acute" value="{{ $v('adl_sub_acute') }}" class="field num">
            </x-field>
            <x-field name="adl_chronic" label="ADL chronic" hint="12–60, bila ada">
                <input id="adl_chronic" type="number" min="12" max="60" name="adl_chronic" value="{{ $v('adl_chronic') }}" class="field num">
            </x-field>

            <div class="flex flex-wrap gap-x-6 gap-y-2 sm:col-span-2 lg:col-span-3">
                @foreach (['naik_kelas' => 'Naik kelas', 'eksekutif' => 'Eksekutif', 'pasien_tb' => 'Pasien TB'] as $f => $l)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="hidden" name="{{ $f }}" value="0">
                        <input type="checkbox" name="{{ $f }}" value="1" @checked($v($f)) class="size-4 rounded border-garis-tua accent-giok"> {{ $l }}
                    </label>
                @endforeach
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Diagnosis dan tindakan</h2>
            <span class="text-xs text-tinta-3">Tulis dalam kata-kata sesuai resume medis. Koding ICD dilakukan petugas di E-Klaim.</span>
        </div>
        <div class="panel-body grid gap-4">
            <x-field name="diagnosis_utama" label="Diagnosis utama">
                <textarea id="diagnosis_utama" name="diagnosis_utama" rows="2" class="field">{{ $v('diagnosis_utama') }}</textarea>
            </x-field>
            <x-field name="diagnosis_sekunder" label="Diagnosis sekunder" hint="Satu diagnosis per baris.">
                <textarea id="diagnosis_sekunder" name="diagnosis_sekunder" rows="4" class="field">{{ $v('diagnosis_sekunder') }}</textarea>
            </x-field>
            <x-field name="tindakan" label="Tindakan / prosedur" hint="Satu tindakan per baris.">
                <textarea id="tindakan" name="tindakan" rows="3" class="field">{{ $v('tindakan') }}</textarea>
            </x-field>
        </div>
    </section>
</div>

<aside class="xl:sticky xl:top-6 xl:self-start">
    <section class="panel">
        <div class="panel-head">
            <h2 class="panel-title">Komponen tarif</h2>
            <span class="text-xs text-tinta-3">Rupiah</span>
        </div>
        <div class="panel-body grid grid-cols-1 gap-2.5">
            @foreach (Eklaim::TARIF as $k => $l)
                @php $val = old("tarif.$k", ($claim->tarif[$k] ?? 0) ?: null); @endphp
                <div class="grid grid-cols-[1fr_140px] items-center gap-3">
                    <label for="tarif_{{ $k }}" class="text-sm text-tinta-2">{{ $l }}</label>
                    <input id="tarif_{{ $k }}" name="tarif[{{ $k }}]" inputmode="numeric" data-rupiah
                           value="{{ $val ? number_format((float) preg_replace('/\D/', '', (string) $val), 0, ',', '.') : '' }}"
                           class="field num py-1.5 text-right" placeholder="0">
                </div>
            @endforeach
        </div>
    </section>
</aside>
</div>
