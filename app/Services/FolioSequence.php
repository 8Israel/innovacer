<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * Consecutive folios without gaps (V-000001, D-000001…), assigned only when a
 * document becomes final.
 */
class FolioSequence
{
    /**
     * Next folio for a model with a "folio" column. Locks the latest folio row,
     * so call it inside the caller's transaction.
     *
     * @param  class-string<Model>  $model
     */
    public function next(string $model, string $prefix): string
    {
        $lastFolio = $model::query()
            ->where('folio', 'like', $prefix.'-%')
            ->orderByDesc('folio')
            ->lockForUpdate()
            ->value('folio');

        $nextNumber = $lastFolio === null ? 1 : ((int) substr($lastFolio, strlen($prefix) + 1)) + 1;

        return sprintf('%s-%06d', $prefix, $nextNumber);
    }
}
