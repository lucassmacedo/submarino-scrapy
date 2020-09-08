<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $guarded = ['id'];
    protected $casts
        = [
            'attributes' => 'json',
            'images'     => 'json'
        ];

    public function images()
    {
        return $this->hasMany(ProductImage::class, 'code', 'product_code');
    }

    public function attributes()
    {
        return $this->hasMany(ProductAttribute::class, 'product_id', 'code');
    }

    public function category()
    {
        return $this->belongsTo(Category::class, 'code', 'product_code');
    }
}
