<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Transforms an Asset model into a consistent API response shape.
 * Includes computed fields (attachment_url, current assignment) and
 * related category / custodian summaries.
 */
class AssetResource extends JsonResource
{
    /**
     * @param  Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentAssignment = $this->relationLoaded('currentAssignment')
            ? $this->currentAssignment->first()
            : null;

        return [
            'id'                    => $this->id,
            'category_id'           => $this->category_id,
            'category'              => $this->whenLoaded('category', function () {
                return [
                    'id'   => $this->category->id,
                    'name' => $this->category->name,
                    'icon' => $this->category->icon,
                ];
            }),
            'name'                  => $this->name,
            'asset_code'            => $this->asset_code,
            'brand'                 => $this->brand,
            'model'                 => $this->model,
            'serial_number'         => $this->serial_number,
            'description'           => $this->description,
            'status'                => $this->status,
            'condition'             => $this->condition,
            'purchase_price'        => $this->purchase_price,
            'purchase_date'         => $this->purchase_date ? $this->purchase_date->toDateString() : null,
            'vendor'                => $this->vendor,
            'warranty_expiry'       => $this->warranty_expiry,
            'location'              => $this->location,
            'custodian_employee_id' => $this->custodian_employee_id,
            'assigned_date'         => $currentAssignment ? $currentAssignment->assigned_date->toDateString() : null,
            'return_date'           => $currentAssignment && $currentAssignment->return_date ? $currentAssignment->return_date->toDateString() : null,
            'current_assignment'    => $currentAssignment ? [
                'id' => $currentAssignment->id,
                'assigned_date' => $currentAssignment->assigned_date ? $currentAssignment->assigned_date->toDateString() : null,
                'return_date' => $currentAssignment->return_date ? $currentAssignment->return_date->toDateString() : null,
                'condition_at_assign' => $currentAssignment->condition_at_assign,
                'condition_at_return' => $currentAssignment->condition_at_return,
                'notes' => $currentAssignment->notes,
            ] : null,
            'custodian'             => $this->whenLoaded('custodian', function () {
                return $this->custodian ? [
                    'id'   => $this->custodian->id,
                    'name' => trim(($this->custodian->first_name ?? '') . ' ' . ($this->custodian->last_name ?? '')),
                    'employee_code' => $this->custodian->employee_code,
                ] : null;
            }),
            'has_attachment'        => (bool) $this->attachment_path,
            'attachment_url'        => $this->attachment_url,
            'attachment_name'       => $this->attachment_name,
            'assignments'           => $this->whenLoaded('assignments'),
            'maintenance'           => $this->whenLoaded('maintenance'),
            'created_by'            => $this->created_by,
            'created_at'            => $this->created_at ? $this->created_at->toDateTimeString() : null,
            'updated_at'            => $this->updated_at ? $this->updated_at->toDateTimeString() : null,
        ];
    }
}
