<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentSharedUsers extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'document_shared_users';
    protected $primaryKey = 'id';
    protected $fillable = [
        'document_id',
        'user',
        'is_time_limited',
        'start_date',
        'end_date',
        'is_downloadable'
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
