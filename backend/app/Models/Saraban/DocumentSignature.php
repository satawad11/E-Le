<?php

namespace App\Models\Saraban;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentSignature extends Model
{
    use HasFactory;

    protected $table = 'saraban_document_signatures';

    protected $fillable = [
        'document_id',
        'signer_id',
        'signature_hash',
        'ip_address',
        'user_agent',
        'mode',
        'signed_at',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function signer()
    {
        return $this->belongsTo(User::class, 'signer_id');
    }
}
