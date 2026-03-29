<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VariantAttribute extends Model
{
    protected $fillable = ['product_variant_id', 'name', 'value'];

    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
