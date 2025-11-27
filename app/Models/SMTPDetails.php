<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SMTPDetails extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'smtp_details';
    protected $primaryKey = 'id';
    protected $fillable = [
        'host',
        'port',
        'user_name',
        'password',
        'from_name',
        'encryption',
        'is_default'
    ];

    // public function documents()
    // {
    //     return $this->hasMany(Documents::class, 'category', 'id');
    // }
}
