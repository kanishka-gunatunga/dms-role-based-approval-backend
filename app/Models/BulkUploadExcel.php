<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BulkUploadExcel extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'bulk_uploads_excel';
    protected $primaryKey = 'id';
    protected $fillable = [
        'upload_file',
        'category',
        'sector_category',
        'file_path',
        'extension',
        'row_from',
        'row_to',
        'storage',
        'data',
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
