<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\SoftDeletes;

abstract class CatalogEntity extends Base
{
    use HasUuids, SoftDeletes;

    const FIELDS = ['id', 'name', 'description', 'todb_id', 'musicbrainz_id', 'image_url', 'cover_id', 'user_id', 'version', 'metadata_updated_at', 'created_at', 'updated_at', 'deleted_at'];

    protected $attributes = ['description' => '', 'version' => 1];

    protected $hidden = ['source_data'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    protected function casts(): array
    {
        return ['source_data' => 'array', 'metadata_updated_at' => 'datetime', 'version' => 'integer'];
    }
}
