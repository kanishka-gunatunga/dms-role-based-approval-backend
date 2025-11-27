<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Categories extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'categories';
    protected $primaryKey = 'id';
    protected $fillable = [
        'parent_category',
        'category_name',
        'description',
        'template',
        'ftp_account',
        'status'
    ];

    protected $casts = [
        'approver_ids' => 'array',
    ];

    public function documents()
    {
        return $this->hasMany(Documents::class, 'category', 'id');
    }
}
