<?php

namespace App\Filament\Pages;

use App\Actions\WorkOrders\WorkOrderActions;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Enums\WorkOrderStatus;
use App\Exceptions\BusinessRuleException;
use App\Filament\Support\PageHub;
use App\Models\Site;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\SettingsService;
use App\Support\Normalize;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Workshop board (§14, D-53): vehicles by status, big buttons. The seller
 * moves the work orders for the technicians, who need no device.
 */
class Workshop extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.workshop';

    protected Width|string|null $maxContentWidth = Width::Full;

    public ?int $siteId = null;

    /** @var array<int, array{notes: ?string, next_oil_km: ?string}> */
    public array $edit = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(Permission::WorkOrdersManage->value) ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.sales');
    }

    public static function getNavigationLabel(): string
    {
        return __('work_orders.title');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = WorkOrder::query()->visibleTo(auth()->user())
            ->whereIn('status', [WorkOrderStatus::Pending->value, WorkOrderStatus::InProgress->value, WorkOrderStatus::Paused->value])->count();

        return $count ? (string) $count : null;
    }

    public function getTitle(): string
    {
        return __('work_orders.title');
    }

    public function mount(): void
    {
        $user = auth()->user();
        $this->siteId = $user->default_site_id && $user->canAccessSite($user->default_site_id) ? $user->default_site_id : null;
    }

    public function move(int $id, string $status, WorkOrderActions $actions): void
    {
        try {
            $actions->move($this->find($id), WorkOrderStatus::from($status), auth()->user());
        } catch (BusinessRuleException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }

    public function toggleTechnician(int $id, int $technicianId, WorkOrderActions $actions): void
    {
        $order = $this->find($id);
        $ids = $order->technicians->modelKeys();
        $ids = in_array($technicianId, $ids, true) ? array_values(array_diff($ids, [$technicianId])) : [...$ids, $technicianId];
        $actions->assign($order, $ids, auth()->user());
    }

    public function saveNote(int $id, WorkOrderActions $actions): void
    {
        $data = $this->edit[$id] ?? [];
        $km = filled($data['next_oil_km'] ?? null) ? (int) preg_replace('/\D/', '', $data['next_oil_km']) : null;
        $actions->note($this->find($id), $data['notes'] ?? null, auth()->user(), $km ?: null);
        Notification::make()->success()->title(__('work_orders.saved'))->send();
    }

    /** @return Collection<string, Collection<int, WorkOrder>> */
    public function columns(): Collection
    {
        $orders = WorkOrder::query()->visibleTo(auth()->user())
            ->when($this->siteId, fn ($q) => $q->where('site_id', $this->siteId))
            ->where(fn ($q) => $q->whereIn('status', [WorkOrderStatus::Pending->value, WorkOrderStatus::InProgress->value, WorkOrderStatus::Paused->value, WorkOrderStatus::Done->value])
                ->orWhere(fn ($d) => $d->where('status', WorkOrderStatus::Delivered->value)->where('delivered_at', '>=', today())))
            ->with(['vehicle.vehicleModel.make', 'customer', 'technicians', 'site', 'sale.lines'])
            ->orderBy('created_at')->get();

        foreach ($orders as $o) {
            $this->edit[$o->id] ??= ['notes' => $o->notes, 'next_oil_km' => $o->vehicle?->next_oil_change_km ? (string) $o->vehicle->next_oil_change_km : null];
        }

        return collect(['pending', 'in_progress', 'paused', 'done', 'delivered'])
            ->mapWithKeys(fn ($s) => [$s => $orders->filter(fn (WorkOrder $o) => $o->status->value === $s)->values()]);
    }

    /** @return Collection<int, User> */
    public function techniciansFor(int $siteId): Collection
    {
        return User::query()->role(RoleName::Technician->value)->where('is_active', true)
            ->whereHas('sites', fn ($q) => $q->whereKey($siteId))->orderBy('name')->get();
    }

    /** @return Collection<int, Site> */
    public function sites(): Collection
    {
        return Site::query()->visibleTo(auth()->user())->where('is_active', true)->orderBy('code')->get();
    }

    public function canCancel(): bool
    {
        return auth()->user()->can(Permission::ApprovalsDecide->value);
    }

    /** "Votre véhicule est prêt" (§17) by WhatsApp. */
    public function readyMessageUrl(WorkOrder $order): ?string
    {
        return Normalize::whatsappUrl($order->customer?->phone, __('work_orders.whatsapp_ready', [
            'name' => $order->customer?->name,
            'vehicle' => $order->vehicle?->label() ?? '',
            'company' => app(SettingsService::class)->get('company.name'),
        ], 'fr'));
    }

    private function find(int $id): WorkOrder
    {
        $order = WorkOrder::query()->visibleTo(auth()->user())->with('technicians')->findOrFail($id);
        abort_unless(auth()->user()->can('update', $order), 403);

        return $order;
    }

    protected function getHeaderActions(): array
    {
        return PageHub::actions(static::class);
    }
}
