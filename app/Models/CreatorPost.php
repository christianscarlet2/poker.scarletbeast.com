<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreatorPost extends Model
{
    protected $fillable = ['user_id', 'kind', 'title', 'body', 'media_id', 'like_count', 'comment_count'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function media()
    {
        return $this->belongsTo(CreatorMedia::class, 'media_id');
    }

    public function comments()
    {
        return $this->hasMany(CreatorComment::class, 'post_id');
    }
}
