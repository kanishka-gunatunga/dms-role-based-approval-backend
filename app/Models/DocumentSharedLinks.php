<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentSharedLinks extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'document_shared_links';
    protected $primaryKey = 'id';
    protected $fillable = [
        'document_id',
        'has_expire_date',
        'expire_date_time',
        'has_password',
        'password',
        'allow_download',
        'link'
    ];

    public function document()
    {
        return $this->belongsTo(Documents::class, 'document_id');
    }


}
