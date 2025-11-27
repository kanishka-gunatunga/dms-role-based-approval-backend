<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BulkUploadExcelConfirmed extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'bulk_uploads_excel_confirmed';
    protected $primaryKey = 'id';
    protected $fillable = [
        'excel_id',
        'name',
        'type',
        'category',
        'sector_category',
        'storage',
        'description',
        'meta_tags',
        'file_path',
        'attributes',
    ];
    public function category()
    {
        return $this->belongsTo(Categories::class, 'category', 'id');
    }
    public function sector()
    {
        return $this->belongsTo(Sectors::class, 'sector_category', 'id');
    }
}
