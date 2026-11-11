<?php

namespace App\Models\B2B;

use Illuminate\Database\Eloquent\Model;

class B2bBulkImportBatch extends Model
{
    protected $table = 'b2b_bulk_import_batches';

    protected $fillable = [
        'user_id',
        'token',
        'original_filename',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'status',
        'rows',
    ];

    protected $casts = [
        'rows' => 'array',
        'total_rows' => 'integer',
        'valid_rows' => 'integer',
        'invalid_rows' => 'integer',
    ];
}
