<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CompanyProfile extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'company_profile';
    protected $primaryKey = 'id';
    protected $fillable = [
        'title',
        'logo',
        'banner',
        'storage',
        'key',
        'secret',
        'bucket',
        'region',
        'enable_external_file_view',
        'enable_ad_login',
        'preview_file_extension',
        'send_all_to_gpt',
        'send_all_to_pinecone',
        'set_page_limit',
        'pages_count',
    ];

}
