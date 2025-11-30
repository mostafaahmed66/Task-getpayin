<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    protected $primaryKey = 'key';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = ['key', 'response_body', 'status_code'];

    protected $casts = [
        'response_body' => 'array',
        'status_code' => 'integer',
    ];
}
