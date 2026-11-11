<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['detected' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }

    public function claim(): BelongsTo
    {
        return $this->belongsTo(Claim::class);
    }
}
