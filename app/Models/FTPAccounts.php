<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FTPAccounts extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ftp_accounts';
    protected $primaryKey = 'id';
    protected $fillable = [
        'name',
        'host',
        'port',
        'username',
        'password',
        'root_path',
        'is_default',
    ];

}
