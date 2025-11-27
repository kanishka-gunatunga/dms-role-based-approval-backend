<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BulkUpload extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'bulk_uploads';
    protected $primaryKey = 'id';
    protected $fillable = [
        'type',
        'name',
        'file_path',
    ];

}
