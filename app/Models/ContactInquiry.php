<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactInquiry extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'phone', 'email', 'message', 'read_at'];

    protected $casts = [
        'read_at' => 'datetime',
    ];
}
