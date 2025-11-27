<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Roles extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'roles';
    protected $primaryKey = 'id';
    protected $fillable = [
        'role_name',
        'permissions',
    ];


    public function sharedRoles()
    {
        return $this->hasMany(DocumentSharedRoles::class, 'role');
    }

    public function users()
{
    return $this->hasMany(User::class, 'role');
}
}
