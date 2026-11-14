<?php

namespace App\Http\Controllers;

use App\Enums\ClaimStatus;
use App\Enums\DocumentType;
use App\Models\Claim;
use App\Services\Rules\ConsistencyChecker;
use App\Support\Audit;
use App\Support\Eklaim;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClaimController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Claim::class);

        $claims = $this->filtered($request)
            ->with('latestReview')
            ->withCount('documents')
            ->latest('updated_at')
            ->paginate(25)->withQueryString();

        return view('claims.index', [
            'claims' => $claims,
            'statuses' => ClaimStatus::cases(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        Gate::authorize('viewAny', Claim::class);
        Audit::log('laporan.ekspor', null, 'Mengekspor daftar berkas (CSV)', $request->only('q', 'status', 'jenis_rawat', 'dari', 'sampai'));

        $query = $this->filtered($request)->with(['latestReview', 'approver'])->orderBy('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8
            fputcsv($out, ['No. RM', 'Nama pasien', 'No. SEP', 'Jenis rawat', 'Tgl masuk', 'Tgl keluar', 'LOS', 'DPJP',
                'Diagnosis utama', 'Status', 'Temuan kritis', 'Temuan perhatian', 'Saran diagnosis', 'Disetujui oleh', 'Disetujui pada'], ';');
            $query->chunk(500, function ($rows) use ($out) {
                foreach ($rows as $c) {
                    $r = $c->latestReview;
                    fputcsv($out, [
                        $c->no_rm, $c->nama_pasien, $c->no_sep, Eklaim::JENIS_RAWAT[$c->jenis_rawat] ?? $c->jenis_rawat,
                        $c->tanggal_masuk?->format('d/m/Y'), $c->tanggal_keluar?->format('d/m/Y'), $c->los(), $c->dpjp,
                        $c->diagnosis_utama, $c->status->label(),
                        $r?->countBy(\App\Enums\Severity::Kritis) ?? '', $r?->countBy(\App\Enums\Severity::Perhatian) ?? '',
                        $r ? count($r->suggestions ?? []) : '',
                        $c->approver?->name, $c->approved_at?->format('d/m/Y H:i'),
                    ], ';');
                }
            });
            fclose($out);
        }, 'berkas-casemix-' . now()->format('Ymd-His') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function create()
    {
        Gate::authorize('create', Claim::class);

        return view('claims.create', ['claim' => new Claim(['jenis_rawat' => 'inap', 'tanggal_masuk' => today()])]);
    }

    public function store(Request $request)
    {
        Gate::authorize('create', Claim::class);
        $data = $this->validated($request);

        $claim = Claim::create($data + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id, 'content_updated_at' => now()]);
        $claim->refresh()->refreshStatus();
        Audit::log('berkas.buat', $claim, "Membuat berkas {$claim->nama_pasien} ({$claim->no_rm})");

        return redirect()->route('claims.show', $claim)->with('ok', 'Berkas dibuat. Unggah dokumen wajib untuk melanjutkan.');
    }

    public function show(Claim $claim)
    {
        Gate::authorize('view', $claim);
        $claim->load(['documents.uploader', 'reviews.requester', 'reviews.provider', 'approver', 'checklistUser', 'creator']);
        $extraction = $claim->extractions()->first();

        $review = $claim->reviews->first();
        $completed = $claim->reviews->first(fn ($r) => $r->status === \App\Enums\ReviewStatus::Selesai);

        return view('claims.show', [
            'claim' => $claim,
            'extraction' => $extraction,
            'aiBlocked' => app(\App\Services\Ai\AiBudget::class)->startBlocker(),
            'review' => $review,
            'completed' => $completed,
            'stale' => $completed ? $claim->reviewIsStale($completed) : false,
            'blockers' => $claim->approvalBlockers(auth()->user()->can('claims.approve') ? auth()->user() : null),
            'documentTypes' => DocumentType::cases(),
            'activity' => $claim->auditLogs()->with('user')->limit(30)->get(),
            // Aturan E-Klaim dihitung langsung (tanpa AI) setiap halaman dibuka
            'liveRules' => app(ConsistencyChecker::class)->check($claim),
        ]);
    }

    public function edit(Claim $claim)
    {
        Gate::authorize('update', $claim);

        return view('claims.edit', ['claim' => $claim]);
    }

    public function update(Request $request, Claim $claim)
    {
        Gate::authorize('update', $claim);
        $data = $this->validated($request, $claim);

        $claim->fill($data);
        // Simpan nilai lama dan baru, supaya setiap koreksi bisa ditelusuri saat audit klaim.
        $diff = $claim->contentDiff();
        $changed = array_keys($diff);

        $claim->updated_by = $request->user()->id;
        $claim->save();

        if ($changed) {
            $claim->markContentChanged();
            Audit::log('berkas.ubah', $claim, 'Mengubah data klaim: ' . implode(', ', $changed), [
                'field' => $changed,
                'perubahan' => $diff,
            ]);
        }
        $claim->refresh()->refreshStatus();

        return redirect()->route('claims.show', $claim)->with('ok', $changed
            ? 'Data klaim disimpan. Hasil pemeriksaan AI perlu diperbarui dan daftar cek dikosongkan.'
            : 'Tidak ada perubahan.');
    }

    private function filtered(Request $request)
    {
        return Claim::query()
            ->search($request->string('q')->trim()->value())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('jenis_rawat'), fn ($q) => $q->where('jenis_rawat', $request->jenis_rawat))
            ->when($request->filled('dari'), fn ($q) => $q->whereDate('tanggal_keluar', '>=', $request->dari))
            ->when($request->filled('sampai'), fn ($q) => $q->whereDate('tanggal_keluar', '<=', $request->sampai));
    }

    private function validated(Request $request, ?Claim $claim = null): array
    {
        $data = $request->validate([
            'nama_pasien' => ['required', 'string', 'max:150'],
            'no_rm' => ['required', 'string', 'max:30'],
            'no_sep' => ['nullable', 'string', 'max:30', Rule::unique('claims', 'no_sep')->ignore($claim?->id)],
            'no_peserta' => ['nullable', 'string', 'max:30'],
            'tanggal_lahir' => ['nullable', 'date', 'before_or_equal:today'],
            'jenis_kelamin' => ['nullable', Rule::in(['L', 'P'])],
            'jenis_rawat' => ['required', Rule::in(array_keys(Eklaim::JENIS_RAWAT))],
            'kelas_hak' => ['nullable', Rule::in(array_keys(Eklaim::KELAS))],
            'kelas_rawat' => ['nullable', Rule::in(array_keys(Eklaim::KELAS_RAWAT))],
            'naik_kelas' => ['boolean'],
            'eksekutif' => ['boolean'],
            'tanggal_masuk' => ['required', 'date'],
            'tanggal_keluar' => ['nullable', 'date', 'after_or_equal:tanggal_masuk'],
            'cara_masuk' => ['nullable', Rule::in(array_keys(Eklaim::CARA_MASUK))],
            'cara_pulang' => ['nullable', Rule::in(array_keys(Eklaim::CARA_PULANG))],
            'berat_lahir' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'rawat_intensif_hari' => ['nullable', 'integer', 'min:0', 'max:999'],
            'ventilator_jam' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'adl_sub_acute' => ['nullable', 'integer', 'min:12', 'max:60'],
            'adl_chronic' => ['nullable', 'integer', 'min:12', 'max:60'],
            'pasien_tb' => ['boolean'],
            'tekanan_darah' => ['nullable', 'string', 'max:15', 'regex:/^\d{2,3}\/\d{2,3}$/'],
            'dpjp' => ['required', 'string', 'max:150'],
            'diagnosis_utama' => ['nullable', 'string', 'max:2000'],
            'diagnosis_sekunder' => ['nullable', 'string', 'max:4000'],
            'tindakan' => ['nullable', 'string', 'max:4000'],
            'tarif' => ['nullable', 'array'],
            'tarif.*' => ['nullable', 'string', 'max:20'],
        ], [
            'no_sep.unique' => 'No. SEP ini sudah dipakai berkas lain.',
            'tekanan_darah.regex' => 'Tulis tekanan darah seperti 120/80.',
        ]);

        foreach (['naik_kelas', 'eksekutif', 'pasien_tb'] as $b) {
            $data[$b] = $request->boolean($b);
        }

        // Rupiah ditulis "1.250.000" → 1250000
        $data['tarif'] = collect(Eklaim::TARIF)->keys()
            ->mapWithKeys(fn ($k) => [$k => (int) preg_replace('/\D/', '', (string) ($data['tarif'][$k] ?? ''))])
            ->all();

        return $data;
    }
}
