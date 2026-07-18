<?php

namespace App\Models\Saraban;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentCircular extends Model
{
    use HasFactory;

    protected $table = 'saraban_document_circulars';

    protected $fillable = [
        'document_id',
        'sender_id',
        'message',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipients()
    {
        return $this->hasMany(DocumentCircularRecipient::class, 'circular_id');
    }
}
