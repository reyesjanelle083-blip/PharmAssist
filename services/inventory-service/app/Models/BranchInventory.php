<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BranchInventory extends Model
{
    public $timestamps = false;

    protected $table = 'branch_inventory';

    protected $fillable = [
        'branch_id',
        'medicine_id',
        'price',
        'stock_quantity',
        'reserved_quantity',
        'min_threshold',
        'batch_no',
        'expiration_date',
        'status',
        'version',
    ];

    // Derived value, never stored (plan 10.3). Included whenever the model is serialised.
    protected $appends = ['available_quantity'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'reserved_quantity' => 'integer',
            'min_threshold' => 'integer',
            'expiration_date' => 'date',
            'version' => 'integer',
        ];
    }

    /** available = stock_quantity - reserved_quantity */
    protected function availableQuantity(): Attribute
    {
        return Attribute::get(fn () => $this->stock_quantity - $this->reserved_quantity);
    }

    /** 0 stock is out_of_stock, below the threshold is low_stock, otherwise available. */
    public static function statusFor(int $stockQuantity, int $minThreshold): string
    {
        return match (true) {
            $stockQuantity === 0 => 'out_of_stock',
            $stockQuantity < $minThreshold => 'low_stock',
            default => 'available',
        };
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    public function holds(): HasMany
    {
        return $this->hasMany(StockHold::class, 'branch_inventory_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'branch_inventory_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(StockAlert::class, 'branch_inventory_id');
    }
}
