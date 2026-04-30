<?php

namespace Nextpointer\Bridge\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncActivity extends Model
{
    // Ορίζουμε το table αν το πακέτο χρησιμοποιεί δικό του prefix
    protected $table = 'sync_activities';

    protected $fillable = [
        'batch_id',
        'source',
        'entity',
        'identifier',
        'action',
        'changes'
    ];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Απενεργοποιούμε το updated_at αφού το migration σου
     * έχει μόνο created_at για ιστορικότητα.
     */
    public $timestamps = false;

    public function batch(): BelongsTo
    {
        // Εδώ θεωρούμε ότι και το SyncBatch ανήκει στο πακέτο
        return $this->belongsTo(SyncBatch::class, 'batch_id');
    }
}
