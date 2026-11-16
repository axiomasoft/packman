<?php

declare(strict_types=1);

namespace Packman\Filament\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FilamentTestOrder extends Model
{
    use SoftDeletes;

    protected $table = 'orders';

    protected $guarded = [];

    protected $casts = [
        'secret' => 'bool',
        'locked' => 'bool',
    ];

    /**
     * @return BelongsToMany<FilamentTestProduct, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(FilamentTestProduct::class, 'order_product', 'order_id', 'product_id');
    }
}
