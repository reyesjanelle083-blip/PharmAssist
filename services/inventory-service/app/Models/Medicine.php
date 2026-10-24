<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medicine extends Model
{
    // The plan's medicines table has no created_at / updated_at.
    public $timestamps = false;

    protected $fillable = [
        'generic_name',
        'brand_name',
        'category',
        'strength',
        'dosage_form',
        'prescription_required',
        'image_path',
        'counseling_summary',
    ];

    protected function casts(): array
    {
        return [
            'prescription_required' => 'boolean',
        ];
    }

    public function inventory(): HasMany
    {
        return $this->hasMany(BranchInventory::class);
    }
}
