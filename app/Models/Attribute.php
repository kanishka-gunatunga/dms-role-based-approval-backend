<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Attribute extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'attributes';
    protected $primaryKey = 'id';
    protected $fillable = [
        'category',
        'attributes'
    ];

    public function category()
    {
        return $this->belongsTo(Categories::class, 'category', 'id');
    }
}
