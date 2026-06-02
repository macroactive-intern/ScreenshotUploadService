<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Screenshot extends Model
{
    protected $keyType    = 'string';
    public    $incrementing = false;

    protected $fillable = ['id', 'path', 'mime_type'];
}
