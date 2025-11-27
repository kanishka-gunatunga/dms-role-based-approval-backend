<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ADCredential extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'ad_credential';
    protected $primaryKey = 'id';
    protected $fillable = [
        'tenant_id',
        'client_id',
        'client_secret'
    ];


}
