<?php

namespace App\Filament\Resources\UsedTyres;

use App\Enums\UsedTyreCheck;
use App\Enums\UsedTyreOrigin;
use App\Enums\UsedTyreStatus;
use App\Filament\Actions\StockActions;
use App\Filament\Resources\UsedTyres\Pages\CreateUsedTyre;
use App\Filament\Resources\UsedTyres\Pages\EditUsedTyre;
use App\Filament\Resources\UsedTyres\Pages\ListUsedTyres;
use App\Filament\Resources\UsedTyres\Pages\UsedTyreSets;
use App\Filament\Resources\UsedTyres\Pages\ViewUsedTyre;
use App\Filament\Support\Fields;
use App\Models\Site;
use App\Models\UsedTyre;
use App\Models\UsedTyreInspection;
use App\Support\Money;
use App\Support\TyreSize;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Individually tracked used tyres (§5.4): inspection, QR label, matching sets. */
class UsedTyreResource extends Resource
{
    protected static ?string $model = UsedTyre::class;

    /** French labels keep their own capitalisation ("Pneus d'occasion"). */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?int $navigationSort = 35;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getModelLabel(): string
    {
        return __('used_tyres.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('used_tyres.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.stock');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', UsedTyreStatus::PendingInspection->value)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->with(['brand', 'site'])
            ->when(! $user->canSeeAllSites(), fn (Builder $query) => $query->whereIn('site_id', $user->siteIds()));
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                StockActions::siteSelect()->visibleOn('create'),
                Select::make('origin')->label(__('used_tyres.fields.origin'))->options(UsedTyreOrigin::class)
                    ->default(UsedTyreOrigin::Supplier)->required()->visibleOn('create'),
                TextInput::make('width')->label(__('used_tyres.fields.width'))->integer()->minValue(100)->maxValue(455)->required(),
                TextInput::make('aspect_ratio')->label(__('used_tyres.fields.aspect_ratio'))->integer()->minValue(10)->maxValue(95),
                TextInput::make('rim_diameter')->label(__('used_tyres.fields.rim_diameter'))->numeric()->minValue(8)->maxValue(26)->required(),
                Select::make('brand_id')->label(__('used_tyres.fields.brand'))->relationship('brand', 'name')->searchable()->preload()
                    ->createOptionForm([TextInput::make('name')->label(__('catalog.brands.fields.name'))->required()->unique('brands', 'name')]),
                TextInput::make('pattern')->label(__('used_tyres.fields.pattern'))->maxLength(255),
                TextInput::make('tread_depth_tenths')->label(__('used_tyres.fields.tread_depth'))
                    ->numeric()->minValue(0)->maxValue(20)->step(0.1)
                    ->formatStateUsing(fn ($state) => $state === null ? null : number_format($state / 10, 1, '.', ''))
                    ->dehydrateStateUsing(fn ($state) => blank($state) ? null : (int) round(((float) str_replace(',', '.', (string) $state)) * 10)),
                Fields::money('price_cents', __('used_tyres.fields.price')),
            ]),
            Section::make(__('app.more_details'))->collapsed()->columns(3)->schema([
                TextInput::make('load_index')->label(__('used_tyres.fields.load_index'))->maxLength(10),
                TextInput::make('speed_index')->label(__('used_tyres.fields.speed_index'))->maxLength(3),
                TextInput::make('location')->label(__('used_tyres.fields.location'))->maxLength(50),
                TextInput::make('dot_week')->label(__('used_tyres.fields.dot_week'))->integer()->minValue(1)->maxValue(53),
                TextInput::make('dot_year')->label(__('used_tyres.fields.dot_year'))->integer()->minValue(1990)->maxValue((int) date('Y')),
                Fields::money('cost_cents', __('used_tyres.fields.cost'))->visible(fn () => Fields::canSeeCosts()),
                FileUpload::make('photos')->label(__('used_tyres.fields.photos'))->image()->multiple()->maxFiles(6)
                    ->disk('public')->directory('used-tyres')->maxSize(5120)->columnSpanFull(),
                Textarea::make('notes')->label(__('used_tyres.fields.notes'))->columnSpanFull(),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(4)->schema([
                TextEntry::make('code')->label(__('used_tyres.fields.code'))->weight('bold')->size('lg'),
                TextEntry::make('status')->label(__('used_tyres.fields.status'))->badge()
                    ->color(fn (UsedTyreStatus $state) => self::statusColor($state)),
                TextEntry::make('size')->label(__('used_tyres.fields.size'))->state(fn (UsedTyre $record) => $record->sizeLabel())->weight('bold'),
                TextEntry::make('site.name')->label(__('used_tyres.fields.site')),
                TextEntry::make('brand.name')->label(__('used_tyres.fields.brand'))->placeholder('—'),
                TextEntry::make('pattern')->label(__('used_tyres.fields.pattern'))->placeholder('—'),
                TextEntry::make('tread')->label(__('used_tyres.fields.tread_depth'))->state(fn (UsedTyre $record) => $record->treadDepthMm())->placeholder('—'),
                TextEntry::make('price_cents')->label(__('used_tyres.fields.price'))->formatStateUsing(fn ($state) => Money::format($state))->placeholder('—'),
                TextEntry::make('origin')->label(__('used_tyres.fields.origin'))->badge(),
                TextEntry::make('cost_cents')->label(__('used_tyres.fields.cost'))->formatStateUsing(fn ($state) => Money::format($state))
                    ->placeholder('—')->visible(fn () => Fields::canSeeCosts()),
                TextEntry::make('location')->label(__('used_tyres.fields.location'))->placeholder('—'),
                TextEntry::make('notes')->label(__('used_tyres.fields.notes'))->placeholder('—')->columnSpanFull(),
                ImageEntry::make('photos')->label(__('used_tyres.fields.photos'))->disk('public')->columnSpanFull()
                    ->visible(fn (UsedTyre $record) => filled($record->photos)),
            ]),
            Section::make(__('used_tyres.sections.history'))->schema([
                RepeatableEntry::make('inspections')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('created_at')->label(__('used_tyres.fields.inspected_at'))->dateTime('d/m/Y H:i'),
                    TextEntry::make('inspector.name')->label(__('used_tyres.fields.inspected_by')),
                    TextEntry::make('passed')->label(__('used_tyres.fields.result'))->badge()
                        ->formatStateUsing(fn (bool $state) => __($state ? 'used_tyres.fields.passed' : 'used_tyres.fields.failed'))
                        ->color(fn (bool $state) => $state ? 'success' : 'danger'),
                    TextEntry::make('failed_checks')->label('')->state(fn (UsedTyreInspection $record) => collect($record->checks)
                        ->filter(fn ($ok) => ! $ok)->keys()->map(fn ($k) => UsedTyreCheck::from($k)->label())->implode(', ') ?: null)
                        ->color('danger')->placeholder(''),
                ]),
            ])->visible(fn (UsedTyre $record) => $record->inspections()->exists()),
        ]);
    }

    public static function statusColor(UsedTyreStatus $state): string
    {
        return match ($state) {
            UsedTyreStatus::PendingInspection => 'warning',
            UsedTyreStatus::InStock => 'success',
            UsedTyreStatus::Reserved => 'info',
            UsedTyreStatus::Unsellable, UsedTyreStatus::Scrapped => 'danger',
            UsedTyreStatus::Sold => 'gray',
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->splitSearchTerms(false)
            ->defaultSort('id', 'desc')
            ->searchPlaceholder(__('used_tyres.search_placeholder'))
            ->columns([
                TextColumn::make('code')->label(__('used_tyres.fields.code'))->weight('bold')
                    ->searchable(query: function (Builder $query, string $search) {
                        if ($size = TyreSize::parse($search)) {
                            return $query->where('width', $size['width'])->where('rim_diameter', $size['rim_diameter'])
                                ->when($size['aspect_ratio'] !== null, fn ($w) => $w->where('aspect_ratio', $size['aspect_ratio']));
                        }

                        return $query->where(fn ($w) => $w->whereRaw('lower(code) like ?', ['%'.mb_strtolower($search).'%'])
                            ->orWhereRaw('lower(pattern) like ?', ['%'.mb_strtolower($search).'%']));
                    }),
                TextColumn::make('size')->label(__('used_tyres.fields.size'))->state(fn (UsedTyre $record) => $record->sizeLabel()),
                TextColumn::make('brand.name')->label(__('used_tyres.fields.brand'))->visibleFrom('md')
                    ->description(fn (UsedTyre $record) => $record->pattern)->placeholder('—'),
                TextColumn::make('tread_depth_tenths')->label(__('used_tyres.fields.tread_depth'))
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format($state / 10, 1, ',', ''))->sortable(),
                TextColumn::make('site.code')->label(__('used_tyres.fields.site'))->badge()->visibleFrom('md'),
                TextColumn::make('status')->label(__('used_tyres.fields.status'))->badge()->color(fn (UsedTyreStatus $state) => self::statusColor($state)),
                Fields::moneyColumn('price_cents', __('used_tyres.fields.price')),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('used_tyres.fields.status'))->options(UsedTyreStatus::class)->multiple(),
                SelectFilter::make('site_id')->label(__('used_tyres.fields.site'))
                    ->options(fn () => Site::query()->visibleTo(auth()->user())->orderBy('code')->pluck('name', 'id')),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsedTyres::route('/'),
            'create' => CreateUsedTyre::route('/create'),
            'sets' => UsedTyreSets::route('/sets'),
            'view' => ViewUsedTyre::route('/{record}'),
            'edit' => EditUsedTyre::route('/{record}/edit'),
        ];
    }
}
