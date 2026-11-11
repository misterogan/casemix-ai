<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $logs = AuditLog::query()->with('user')
            ->when($request->filled('user'), fn ($q) => $q->where('user_id', $request->user))
            ->when($request->filled('aksi'), fn ($q) => $q->where('action', 'like', $request->aksi . '%'))
            ->when($request->filled('q'), fn ($q) => $q->where('description', 'like', '%' . $request->q . '%'))
            ->when($request->filled('tanggal'), fn ($q) => $q->whereDate('created_at', $request->tanggal))
            ->latest('id')->paginate(50)->withQueryString();

        return view('audit.index', [
            'logs' => $logs,
            'users' => User::orderBy('name')->get(['id', 'name']),
            'actions' => ['berkas' => 'Berkas', 'dokumen' => 'Dokumen', 'ai' => 'Pemeriksaan AI', 'daftar_cek' => 'Daftar cek',
                'impor' => 'Impor', 'laporan' => 'Laporan', 'auth' => 'Masuk/keluar', 'admin' => 'Admin'],
        ]);
    }
}
