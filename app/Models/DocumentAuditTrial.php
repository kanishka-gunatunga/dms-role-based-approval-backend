<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;


class DocumentAuditTrial extends Model
{
    use HasFactory;

    protected $table = 'document_audit_trial';
    protected $primaryKey = 'id';
    protected $fillable = [
        'operation',
        'type',
        'user',
        'changed_source',
        'date_time',
        'assigned_roles',
        'assigned_users'
    ];
    // public function document()
    // {
    //     return $this->belongsTo(Documents::class, 'document');
    // }

    public function user()
    {
        return $this->belongsTo(User::class, 'user');
    }
}
