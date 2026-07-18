<?php

namespace App\Models\Saraban;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentCircularRecipient extends Model
{
    use HasFactory;

    protected $table = 'saraban_document_circular_recipients';

    protected $fillable = [
        'circular_id',
        'user_id',
        'is_acknowledged',
        'acknowledged_at',
    ];

    protected $casts = [
        'is_acknowledged' => 'boolean',
        'acknowledged_at' => 'datetime',
    ];

    public function circular()
    {
        return $this->belongsTo(DocumentCircular::class, 'circular_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
