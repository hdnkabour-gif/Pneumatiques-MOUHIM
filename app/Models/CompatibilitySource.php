<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompatibilitySource extends Model
{
    protected $fillable = ['external_id', 'publisher', 'title', 'source_type', 'url', 'source_date', 'scope', 'reliability', 'notes'];

    public function label(): string
    {
        return trim(($this->external_id ? $this->external_id.' – ' : '').$this->publisher.' : '.$this->title);
    }
}
