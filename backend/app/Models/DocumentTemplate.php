<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'module',
        'paper_size',
        'orientation',
        'margin_top',
        'margin_bottom',
        'margin_left',
        'margin_right',
        'content_html',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'margin_top' => 'float',
        'margin_bottom' => 'float',
        'margin_left' => 'float',
        'margin_right' => 'float',
    ];

    protected static function booted(): void
    {
        static::created(fn (DocumentTemplate $template) => $template->createVersion());

        static::updated(function (DocumentTemplate $template): void {
            if ($template->wasChanged(['paper_size', 'orientation', 'margin_top', 'margin_bottom', 'margin_left', 'margin_right', 'content_html', 'is_active'])) {
                $template->currentVersion?->update(['effective_to' => now()->subDay()->toDateString(), 'is_active' => false]);
                $template->createVersion();
            }
        });
    }

    public function versions()
    {
        return $this->hasMany(DocumentTemplateVersion::class);
    }

    public function currentVersion()
    {
        return $this->hasOne(DocumentTemplateVersion::class)->whereNull('effective_to')->latestOfMany('version');
    }

    private function createVersion(): void
    {
        $this->versions()->create([
            'version' => ($this->versions()->max('version') ?? 0) + 1,
            'paper_size' => $this->paper_size ?? 'A4',
            'orientation' => $this->orientation ?? 'portrait',
            'margin_top' => $this->margin_top ?? 8.0,
            'margin_bottom' => $this->margin_bottom ?? 8.0,
            'margin_left' => $this->margin_left ?? 12.0,
            'margin_right' => $this->margin_right ?? 12.0,
            'content_html' => $this->content_html,
            'effective_from' => now()->toDateString(),
            'is_active' => $this->is_active ?? true,
        ]);
    }
}
