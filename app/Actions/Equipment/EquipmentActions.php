<?php

namespace App\Actions\Equipment;

use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\Equipment;
use App\Models\EquipmentEvent;
use App\Models\User;
use App\Services\SequenceService;
use Filament\Notifications\Notification;

/** Equipment register and maintenance (§15). */
class EquipmentActions
{
    public function __construct(private SequenceService $sequences) {}

    public function create(array $data, User $user): Equipment
    {
        $this->authorize($user, Permission::EquipmentManage, (int) $data['site_id']);
        $equipment = new Equipment($data);
        $equipment->code = $this->sequences->nextSku('EQ');
        if ($equipment->maintenance_every_days && ! $equipment->next_maintenance_on) {
            $equipment->next_maintenance_on = today()->addDays($equipment->maintenance_every_days);
        }
        $equipment->save();

        return $equipment;
    }

    /** Anyone on the site can report a breakdown; the owner and managers are alerted. */
    public function reportBreakdown(Equipment $equipment, string $description, User $user): EquipmentEvent
    {
        $this->authorize($user, Permission::EquipmentReport, $equipment->site_id);
        if (trim($description) === '') {
            throw BusinessRuleException::make('approvals.errors.reason_required');
        }
        $event = $equipment->events()->create(['type' => 'breakdown', 'description' => trim($description), 'occurred_on' => today(), 'user_id' => $user->id]);
        $equipment->update(['status' => 'broken']);

        foreach (User::query()->where('is_active', true)->get()->filter(fn (User $u) => $u->can(Permission::EquipmentManage->value) && $u->canAccessSite($equipment->site_id) && ! $u->is($user)) as $manager) {
            Notification::make()->danger()->title(__('equipment.broken_alert', ['name' => $equipment->name, 'site' => $equipment->site->code]))
                ->body($event->description)->sendToDatabase($manager);
        }

        return $event;
    }

    /** Repair or preventive maintenance: back in service, next date planned. */
    public function service(Equipment $equipment, string $type, string $description, int $costCents, ?string $performedBy, User $user): EquipmentEvent
    {
        $this->authorize($user, Permission::EquipmentManage, $equipment->site_id);
        if (! in_array($type, ['repair', 'maintenance'], true) || trim($description) === '' || $costCents < 0) {
            throw BusinessRuleException::make('equipment.errors.event');
        }
        $event = $equipment->events()->create(['type' => $type, 'description' => trim($description), 'cost_cents' => $costCents,
            'performed_by' => $performedBy, 'occurred_on' => today(), 'user_id' => $user->id]);
        $equipment->update([
            'status' => 'in_service',
            'next_maintenance_on' => $equipment->maintenance_every_days ? today()->addDays($equipment->maintenance_every_days) : $equipment->next_maintenance_on,
        ]);
        activity('equipment')->causedBy($user)->performedOn($equipment)->event('equipment_'.$type)
            ->withProperties(['cost_cents' => $costCents])->tap(fn ($a) => $a->site_id = $equipment->site_id)->log('equipment_'.$type);

        return $event;
    }

    private function authorize(User $user, Permission $permission, int $siteId): void
    {
        if (! $user->can($permission->value) || ! $user->canAccessSite($siteId)) {
            throw BusinessRuleException::make('app.errors.forbidden');
        }
    }
}
