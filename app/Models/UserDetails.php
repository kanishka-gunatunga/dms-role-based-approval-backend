<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class UserDetails extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'user_details';
    protected $primaryKey = 'id';
    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'mobile_no',
        'sector'
    ];
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
