<?php
// app/Models/ScanHost.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class ScanHost extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'hostname',
        'customer_id',
        'last_seen_at',
        'is_active',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'is_active' => 'boolean',
    ];
}
