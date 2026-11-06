<?php

namespace App\Actions\Customers;

use App\Enums\CustomerType;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\CustomerVehicle;
use App\Models\User;
use App\Support\Normalize;
use Illuminate\Support\Facades\DB;

/**
 * Quick customer from the sale screen (§10.1): phone + name, optionally the
 * plate and the vehicle model. The same phone and name gives back the
 * existing customer instead of a duplicate.
 */
class CreateCustomerAction
{
    /** @param  array{name: string, phone: string, plate?: ?string, vehicle_model_id?: ?int}  $data */
    public function execute(array $data, User $user): Customer
    {
        if (! $user->can(Permission::CustomersManage->value)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }

        $name = trim((string) ($data['name'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        if ($name === '' || strlen(preg_replace('/\D/', '', $phone)) < 9) {
            throw BusinessRuleException::make('customers.errors.quick_required');
        }

        return DB::transaction(function () use ($data, $user, $name, $phone) {
            $customer = Customer::query()->where('phone_normalized', Normalize::phone($phone))
                ->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
                ?? Customer::query()->create([
                    'type' => CustomerType::Individual,
                    'name' => $name,
                    'phone' => $phone,
                    'created_by' => $user->id,
                ]);

            if (filled($data['plate'] ?? null)) {
                $this->addVehicle($customer, (string) $data['plate'], $data['vehicle_model_id'] ?? null);
            }

            return $customer->load('vehicles');
        });
    }

    public function addVehicle(Customer $customer, string $plate, ?int $modelId = null): CustomerVehicle
    {
        $existing = $customer->vehicles()->where('plate_normalized', Normalize::plate($plate))->first();

        return $existing ?? $customer->vehicles()->create([
            'plate' => mb_strtoupper(trim($plate)),
            'vehicle_model_id' => $modelId,
        ]);
    }
}
