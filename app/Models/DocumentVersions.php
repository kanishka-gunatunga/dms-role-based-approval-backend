<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentVersions extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'document_versions';
    protected $primaryKey = 'id';
    protected $fillable = [
        'document_id',
        'type',
        'file_path',
        'date_time',
        'user'
    ];

    public function document()
    {
        return $this->belongsTo(Documents::class, 'document_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user');
    }
}
