<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentSharedRoles extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'document_shared_roles';
    protected $primaryKey = 'id';
    protected $fillable = [
        'document_id',
        'role',
        'is_time_limited',
        'start_date',
        'end_date',
        'is_downloadable'
    ];

    public function document()
    {
        return $this->belongsTo(Documents::class, 'document_id');
    }


    public function role()
    {
        return $this->belongsTo(Roles::class, 'role'); 
    }
}
