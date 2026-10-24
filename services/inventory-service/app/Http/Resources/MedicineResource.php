<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Medicine as defined in docs/openapi.yaml. An empty brand_name (unbranded) is exposed as null. */
class MedicineResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'generic_name' => $this->generic_name,
            'brand_name' => $this->brand_name === '' ? null : $this->brand_name,
            'category' => $this->category,
            'strength' => $this->strength,
            'dosage_form' => $this->dosage_form,
            'prescription_required' => $this->prescription_required,
            'image_url' => $this->image_path,
        ];
    }
}
