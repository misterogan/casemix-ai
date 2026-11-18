<?php

namespace App\Http\Controllers;

use App\Enums\ReviewStatus;
use App\Models\FolderTemuanCek;
use App\Models\FtpFolder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Centang "Sudah dicek" per temuan (JSON, Koordinator saja). */
class FolderTemuanCekController extends Controller
{
    public function store(Request $request, FtpFolder $folder): JsonResponse
    {
        $key = $this->validKey($request, $folder);
        FolderTemuanCek::updateOrCreate(['folder_id' => $folder->id, 'temuan_key' => $key],
            ['user_id' => $request->user()->id, 'dicek_at' => now()]);

        return response()->json(['key' => $key, 'dicek' => true, 'jumlah' => $this->jumlah($folder)]);
    }

    public function destroy(Request $request, FtpFolder $folder): JsonResponse
    {
        $key = $this->validKey($request, $folder);
        FolderTemuanCek::where('folder_id', $folder->id)->where('temuan_key', $key)->delete();

        return response()->json(['key' => $key, 'dicek' => false, 'jumlah' => $this->jumlah($folder)]);
    }

    private function validKey(Request $request, FtpFolder $folder): string
    {
        $key = $request->validate(['key' => ['required', 'string', 'regex:/^[0-9a-f]{40}$/']])['key'];
        abort_unless($this->keys($folder)->contains($key), 404);

        return $key;
    }

    private function keys(FtpFolder $folder)
    {
        $done = $folder->analyses()->where('status', ReviewStatus::Selesai->value)->first();
        $docs = $folder->analyses()->first()?->documents()->get() ?? [];

        return FolderTemuanCek::temuan($done, $docs)->pluck('key');
    }

    private function jumlah(FtpFolder $folder): int
    {
        return FolderTemuanCek::where('folder_id', $folder->id)->whereIn('temuan_key', $this->keys($folder))->count();
    }
}
