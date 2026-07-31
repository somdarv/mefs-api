<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'address',
        'phone',
        'order_number_prefix',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'bool'];
    }

    /**
     * What gets copied onto an order.
     *
     * Snapshot, don't join (brief §3.2). A branch that renames itself or moves must not
     * rewrite last month's receipts — the customer's copy has to keep saying what they saw.
     *
     * @return array{name: string, address: string, phone: string}
     */
    public function toOrderSnapshot(): array
    {
        return [
            'name' => $this->name,
            'address' => $this->address,
            'phone' => $this->phone,
        ];
    }
}
