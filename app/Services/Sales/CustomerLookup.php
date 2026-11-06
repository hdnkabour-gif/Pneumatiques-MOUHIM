<?php

namespace App\Services\Sales;

use App\Models\Customer;
use App\Support\Normalize;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One box for customers (§5): phone in any form (06…, +212…, 00212…),
 * number plate with or without separators, name, ICE.
 */
class CustomerLookup
{
    /** @return Collection<int, Customer> */
    public function search(string $term, int $limit = 8): Collection
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return collect();
        }

        $query = Customer::query()->where('is_active', true)->with('vehicles.vehicleModel.make');
        self::apply($query, $term);

        return $query->orderBy('name')->limit($limit)->get();
    }

    public static function apply(Builder $query, string $term): Builder
    {
        $digits = preg_replace('/\D/', '', $term);
        $plate = Normalize::plate($term);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($term)).'%';

        return $query->where(function (Builder $q) use ($digits, $plate, $like, $term) {
            $q->whereRaw('lower(customers.name) like ?', [$like]);
            if (strlen($digits) >= 4) {
                $phone = Normalize::phone($term);
                $q->orWhere('customers.phone_normalized', 'like', '%'.(strlen($digits) >= 9 ? substr($phone, -9) : $digits).'%')
                    ->orWhere('customers.ice', 'like', $digits.'%');
            }
            if (mb_strlen($plate) >= 3) {
                $q->orWhereHas('vehicles', fn (Builder $v) => $v->where('plate_normalized', 'like', '%'.$plate.'%'));
            }
        });
    }
}
