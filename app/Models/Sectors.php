<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sectors extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'sectors';
    protected $primaryKey = 'id';
    protected $fillable = [
        'parent_sector',
        'sector_name'
    ];

    // public function documents()
    // {
    //     return $this->hasMany(Documents::class, 'category', 'id');
    // }
}
