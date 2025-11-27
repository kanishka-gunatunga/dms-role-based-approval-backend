<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use MongoDB\Laravel\Eloquent\Model;
use Laravel\Scout\Searchable;


class MongoDocuments extends Model
{
    use HasFactory, Searchable;

    protected $connection = 'mongodb';
    protected $collection = 'documents';
    protected $fillable = ['sql_doc_id', 'content'];
    
    // public function searchableAs()
    // {
    //     return 'mongo_documents_index';
    // }

    public function toSearchableArray()
    {
        return [
            'id' => $this->id,
            'sql_doc_id' => $this->sql_doc_id,
            'content' => $this->content,
        ];
    }
    public function sqlDocument()
    {
        return $this->belongsTo(Documents::class, 'sql_doc_id');
    }
}

?>