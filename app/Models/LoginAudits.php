<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoginAudits extends Model
{
    use HasFactory;

    protected $table = 'login_audits';

    protected $primaryKey = 'id';

    protected $fillable = [
        'email',
        'date_time',
        'ip_address',
        'status',
        'latitude',
        'longitude',
    ];
}
