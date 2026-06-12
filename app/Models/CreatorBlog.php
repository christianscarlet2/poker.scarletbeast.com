<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreatorBlog extends Model
{
    protected $fillable = ['user_id', 'slug', 'port', 'status', 'url', 'note'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
