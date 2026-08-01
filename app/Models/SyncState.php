<?php
// app/Models/SyncState.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncState extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'last_synced_at',
        'last_index',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];
}
