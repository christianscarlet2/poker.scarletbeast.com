<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreatorProfile extends Model
{
    protected $fillable = ['user_id', 'slug', 'headline', 'bio', 'location', 'banner',
        'skills', 'links', 'open_to', 'resume', 'public', 'views'];

    protected $casts = [
        'skills' => 'array', 'links' => 'array', 'open_to' => 'array',
        'resume' => 'array', 'public' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
