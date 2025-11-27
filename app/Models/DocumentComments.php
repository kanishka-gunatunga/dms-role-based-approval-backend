<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class DocumentComments extends Model
{
    use HasFactory;

    protected $table = 'document_comments';
    protected $primaryKey = 'id';
    protected $fillable = [
        'document_id',
        'comment',
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
