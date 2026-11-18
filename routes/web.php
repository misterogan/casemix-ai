<?php

use App\Http\Controllers\AccountPasswordController;
use App\Http\Controllers\Admin\AiBudgetController;
use App\Http\Controllers\Admin\AiProviderController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\ClaimApprovalController;
use App\Http\Controllers\ClaimChecklistController;
use App\Http\Controllers\ClaimController;
use App\Http\Controllers\ClaimDocumentController;
use App\Http\Controllers\ClaimExtractionController;
use App\Http\Controllers\ClaimReportController;
use App\Http\Controllers\ClaimReviewController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FolderController;
use App\Http\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/masuk', [LoginController::class, 'show'])->name('login');
    Route::post('/masuk', [LoginController::class, 'login'])->middleware('throttle:10,1');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/keluar', [LoginController::class, 'logout'])->name('logout');
    Route::get('/akun/password', [AccountPasswordController::class, 'edit'])->name('account.password.edit');
    Route::put('/akun/password', [AccountPasswordController::class, 'update'])->middleware('throttle:6,1')->name('account.password.update');
    // Halaman awal menurut peran: Koordinator ke Dashboard, peran lain ke verifikasi, Admin ke pengaturan.
    Route::get('/', function () {
        $u = auth()->user();

        return match (true) {
            $u->hasRole('koordinator') => redirect()->route('control.index'),
            $u->can('folders.view') => redirect()->route('folders.index'),
            $u->can('admin.manage') => redirect()->route('admin.budget.index'),
            default => abort(403),
        };
    })->name('home');

    // Pusat Kendali Klaim: Koordinator saja (Admin tidak, privasi)
    Route::get('/dashboard', \App\Http\Controllers\Dashboard\DashboardController::class)->middleware('role:koordinator')->name('control.index');
    Route::get('/antrean', \App\Http\Controllers\WorkQueueController::class)->middleware('role:koordinator')->name('work-queue.index');
    Route::middleware('role:koordinator')->group(function () {
        Route::get('/bpjs', [\App\Http\Controllers\BpjsController::class, 'index'])->name('bpjs.index');
        Route::get('/bpjs/unduh', [\App\Http\Controllers\BpjsController::class, 'csv'])->name('bpjs.csv');
        Route::get('/bpjs/klaim/{noSep}', [\App\Http\Controllers\BpjsController::class, 'show'])->name('bpjs.klaim.show');
        Route::post('/bpjs/klaim/{noSep}/histori-pelayanan', [\App\Http\Controllers\BpjsController::class, 'histori'])->middleware('throttle:30,1')->name('bpjs.klaim.histori');
        Route::post('/folders/{folder}/histori-pelayanan', [FolderController::class, 'histori'])->middleware('throttle:30,1')->name('folders.histori');
        // Unggah dokumen manual (dianalisa dengan pipeline folder yang sama)
        Route::get('/unggah', [\App\Http\Controllers\ManualUploadController::class, 'create'])->name('manual.create');
        Route::get('/unggah/cek-sep', [\App\Http\Controllers\ManualUploadController::class, 'lookup'])->name('manual.lookup');
        Route::post('/unggah/file', [\App\Http\Controllers\ManualUploadController::class, 'store'])->middleware('throttle:120,1')->name('manual.store');
        Route::post('/unggah/{folder}/analisa', [\App\Http\Controllers\ManualUploadController::class, 'analyze'])->name('manual.analyze');
        Route::get('/folders/{folder}/manual/{file}', [\App\Http\Controllers\ManualUploadController::class, 'show'])->whereNumber('file')->name('manual.show');
        Route::delete('/folders/{folder}/manual/{file}', [\App\Http\Controllers\ManualUploadController::class, 'destroy'])->whereNumber('file')->name('manual.destroy');
        // Lonceng notifikasi: buka (tandai dibaca lalu ke tautan) dan tandai semua dibaca.
        Route::post('/notifikasi/{id}/baca', function (string $id) {
            $n = auth()->user()->notifications()->findOrFail($id);
            $n->markAsRead();
            $url = (string) ($n->data['url'] ?? '');

            return redirect(str_starts_with($url, '/') && ! str_starts_with($url, '//') ? $url : route('home'));
        })->name('notifications.open');
        Route::post('/notifikasi/baca-semua', function () {
            auth()->user()->unreadNotifications->markAsRead();

            return back();
        })->name('notifications.read-all');
    });

    // Verifikasi langsung dari folder FTP
    Route::middleware('permission:folders.view')->group(function () {
        Route::get('/verifikasi', [FolderController::class, 'index'])->name('folders.index');
        Route::get('/verifikasi/{folder}', [FolderController::class, 'show'])->name('folders.show');
        Route::get('/verifikasi/{folder}/status', [FolderController::class, 'status'])->name('folders.status');
        Route::get('/folders/{folder}/status-analisa', [FolderController::class, 'statusAnalisa'])->name('folders.status_analisa');
        Route::get('/verifikasi/{folder}/dokumen/{index}', [FolderController::class, 'file'])->whereNumber('index')->name('folders.file');
        Route::post('/verifikasi/{folder}/analisa', [FolderController::class, 'analyze'])->middleware('permission:folders.analyze')->name('folders.analyze');
        Route::post('/verifikasi/{folder}/lanjutkan', [FolderController::class, 'resume'])->middleware('permission:folders.analyze')->name('folders.resume');
        Route::post('/verifikasi/{folder}/rekomendasi', [FolderController::class, 'resynthesize'])->middleware('permission:folders.analyze')->name('folders.resynthesize');
        Route::post('/verifikasi/{folder}/proses-semua', [FolderController::class, 'processAll'])->middleware('permission:folders.analyze')->name('folders.process_all');
        Route::post('/verifikasi/{folder}/dokumen/{document}/proses', [FolderController::class, 'processDocument'])->whereNumber('document')->middleware('permission:folders.analyze')->name('folders.process_document');
        Route::post('/verifikasi/{folder}/rubrik/{kode}/terima', [FolderController::class, 'acceptRubric'])->middleware('permission:folders.analyze')->name('folders.rubric.accept');
        Route::delete('/verifikasi/{folder}/rubrik/{kode}/terima', [FolderController::class, 'unacceptRubric'])->middleware('permission:folders.analyze')->name('folders.rubric.unaccept');
        Route::post('/verifikasi/{folder}/saran/{suggestion}/status', [FolderController::class, 'suggestionStatus'])->whereNumber('suggestion')->middleware('permission:folders.analyze')->name('folders.suggestion.status');
        Route::post('/verifikasi/{folder}/kode/{code}/status', [FolderController::class, 'codeStatus'])->whereNumber('code')->middleware('permission:folders.analyze')->name('folders.code.status');
        Route::put('/verifikasi/{folder}/penugasan', [FolderController::class, 'assign'])->middleware('role:koordinator')->name('folders.assign');
    });

    // Modul lama: berkas klaim, impor, unggah. Mati kecuali CASEMIX_LEGACY_CLAIMS=true.
    Route::middleware('legacy')->group(function () {
        Route::get('/ringkasan', DashboardController::class)->name('dashboard');

        // Berkas klaim
        Route::get('/berkas', [ClaimController::class, 'index'])->name('claims.index');
        Route::get('/berkas/ekspor', [ClaimController::class, 'export'])->name('claims.export')->middleware('permission:reports.download');
        Route::get('/berkas/baru', [ClaimController::class, 'create'])->name('claims.create');
        Route::post('/berkas', [ClaimController::class, 'store'])->name('claims.store');
        Route::get('/berkas/{claim}', [ClaimController::class, 'show'])->name('claims.show');
        Route::get('/berkas/{claim}/ubah', [ClaimController::class, 'edit'])->name('claims.edit');
        Route::put('/berkas/{claim}', [ClaimController::class, 'update'])->name('claims.update');

        Route::post('/berkas/{claim}/dokumen', [ClaimDocumentController::class, 'store'])->name('claims.documents.store');
        Route::get('/berkas/{claim}/dokumen/{document}', [ClaimDocumentController::class, 'show'])->name('claims.documents.show')->scopeBindings();
        Route::patch('/berkas/{claim}/dokumen/{document}', [ClaimDocumentController::class, 'update'])->name('claims.documents.update')->scopeBindings();
        Route::delete('/berkas/{claim}/dokumen/{document}', [ClaimDocumentController::class, 'destroy'])->name('claims.documents.destroy')->scopeBindings();
        Route::get('/berkas/{claim}/riwayat-pasien', [ClaimDocumentController::class, 'history'])->name('claims.history');
        Route::post('/berkas/{claim}/riwayat-pasien', [ClaimDocumentController::class, 'attachHistory'])->name('claims.history.attach');

        Route::post('/berkas/{claim}/isi-otomatis', [ClaimExtractionController::class, 'store'])->name('claims.extract');
        Route::get('/berkas/{claim}/isi-otomatis/status', [ClaimExtractionController::class, 'status'])->name('claims.extract.status');
        Route::post('/berkas/{claim}/isi-otomatis/{extraction}/terapkan', [ClaimExtractionController::class, 'apply'])->name('claims.extract.apply');

        Route::post('/berkas/{claim}/periksa', [ClaimReviewController::class, 'store'])->name('claims.review');
        Route::get('/berkas/{claim}/periksa/status', [ClaimReviewController::class, 'status'])->name('claims.review.status');
        Route::put('/berkas/{claim}/daftar-cek', [ClaimChecklistController::class, 'update'])->name('claims.checklist');
        Route::post('/berkas/{claim}/setujui', [ClaimApprovalController::class, 'approve'])->name('claims.approve');
        Route::post('/berkas/{claim}/buka-kembali', [ClaimApprovalController::class, 'reopen'])->name('claims.reopen');
        Route::get('/berkas/{claim}/laporan', ClaimReportController::class)->name('claims.report')->middleware('permission:reports.download');

        // Impor dari FTP SIMRS
        Route::middleware('permission:claims.import')->group(function () {
            Route::get('/impor', [ImportController::class, 'index'])->name('imports.index');
            Route::post('/impor', [ImportController::class, 'store'])->name('imports.store');
            Route::get('/impor/{batch}', [ImportController::class, 'show'])->name('imports.show');
            Route::post('/impor/{batch}/simpan', [ImportController::class, 'commit'])->name('imports.commit');
        });
    });

    // Acuan regulasi → PNPK. Kelola dokumen: Admin & Koordinator (bukan data pasien). Tinjau butir/ICD: Koordinator.
    Route::prefix('acuan/pnpk')->name('acuan.pnpk.')->middleware('role:admin|koordinator')->group(function () {
        $c = \App\Http\Controllers\PnpkController::class;
        Route::get('/', [$c, 'index'])->name('index');
        Route::get('/baru', [$c, 'create'])->name('create');
        Route::post('/', [$c, 'store'])->middleware('throttle:20,1')->name('store');
        Route::get('/{pnpk}', [$c, 'show'])->whereNumber('pnpk')->name('show');
        Route::get('/{pnpk}/pdf', [$c, 'pdf'])->whereNumber('pnpk')->name('pdf');
        Route::get('/{pnpk}/ubah', [$c, 'edit'])->whereNumber('pnpk')->name('edit');
        Route::put('/{pnpk}', [$c, 'update'])->whereNumber('pnpk')->name('update');
        Route::delete('/{pnpk}', [$c, 'destroy'])->whereNumber('pnpk')->name('destroy');
        Route::middleware('role:koordinator')->whereNumber('pnpk')->group(function () use ($c) {
            Route::post('/{pnpk}/butir/{butir}', [$c, 'butir'])->whereNumber('butir')->name('butir');
            Route::post('/{pnpk}/butir-massal', [$c, 'butirMassal'])->name('butir.massal');
            Route::post('/{pnpk}/icd/{icd}', [$c, 'icd'])->whereNumber('icd')->name('icd');
            Route::post('/{pnpk}/icd', [$c, 'icdTambah'])->name('icd.tambah');
            Route::post('/{pnpk}/selesai', [$c, 'selesai'])->name('selesai');
        });
    });

    // Pemakaian AI (token & biaya, tanpa data pasien): Koordinator & Admin
    Route::get('/sistem/pemakaian-ai', \App\Http\Controllers\AiUsageController::class)->middleware('role:admin|koordinator')->name('sistem.pemakaian_ai');
    Route::get('/riwayat-aktivitas', [AuditLogController::class, 'index'])->name('audit.index')->middleware('permission:audit.view');

    // Admin
    Route::prefix('admin')->name('admin.')->middleware('permission:admin.manage')->group(function () {
        Route::resource('pengguna', UserController::class)->except(['show', 'destroy'])->parameters(['pengguna' => 'user'])->names('users');
        Route::get('peran', [RoleController::class, 'index'])->name('roles.index');
        Route::put('peran', [RoleController::class, 'update'])->name('roles.update');
        Route::resource('penyedia-ai', AiProviderController::class)->except(['show'])->parameters(['penyedia-ai' => 'provider'])->names('providers');
        Route::post('penyedia-ai/{provider}/uji', [AiProviderController::class, 'test'])->name('providers.test');
        Route::get('anggaran-ai', [AiBudgetController::class, 'index'])->name('budget.index');
        Route::put('anggaran-ai', [AiBudgetController::class, 'update'])->name('budget.update');
        Route::get('pengaturan', [SettingController::class, 'index'])->name('settings.index');
        Route::post('pengaturan/uji-ftp', [SettingController::class, 'testFtp'])->name('settings.ftp');
        Route::put('pengaturan/analisa', [SettingController::class, 'updateAnalysis'])->name('settings.analysis');
        Route::resource('pengaturan/acuan-regulasi', \App\Http\Controllers\Admin\RegulationController::class)->except(['show'])
            ->parameters(['acuan-regulasi' => 'regulation'])->names('regulations');
    });
});
