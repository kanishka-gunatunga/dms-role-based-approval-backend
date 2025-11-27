<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reminders extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'reminders';
    protected $primaryKey = 'id';
    protected $fillable = [
        'document_id',
        'subject',
        'message',
        'date_time',
        'is_repeat',
        'send_email',
        'frequency',
        'end_date_time',
        'start_date_time',
        'frequency_details',
        'users',
        'roles'
    ];
    public function document()
    {
        return $this->belongsTo(Documents::class, 'document_id');
    }

}
