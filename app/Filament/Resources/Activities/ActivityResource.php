<?php

namespace App\Filament\Resources\Activities;

use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Filament\Resources\Activities\Pages\ViewActivity;
use App\Filament\Support\PageHub;
use App\Models\Activity;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Read-only activity journal (§16.4): who, what, when, which site, before / after. */
class ActivityResource extends Resource
{
    public static function shouldRegisterNavigation(): bool
    {
        return PageHub::inMenu(static::class) && parent::shouldRegisterNavigation();
    }

    protected static ?string $model = Activity::class;

    /** French labels keep their own capitalisation ("Pneus d'occasion"). */
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 95;

    public static function getModelLabel(): string
    {
        return __('activity.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('activity.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('activity.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('app.navigation.settings');
    }

    public static function eventLabel(?string $event): string
    {
        return $event ? (__("activity.events.{$event}") === "activity.events.{$event}" ? $event : __("activity.events.{$event}")) : '—';
    }

    public static function subjectLabel(Activity $record): string
    {
        if (! $record->subject_type) {
            return '—';
        }

        return class_basename($record->subject_type).' #'.$record->subject_id;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['causer', 'site']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('activity.fields.date'))->dateTime('d/m/Y H:i:s')->sortable(),
                TextColumn::make('causer.name')->label(__('activity.fields.user'))->placeholder(__('activity.system'))->searchable(),
                TextColumn::make('site.code')->label(__('activity.fields.site'))->badge()->placeholder('—'),
                TextColumn::make('event')->label(__('activity.fields.event'))->badge()
                    ->formatStateUsing(fn (?string $state) => static::eventLabel($state)),
                TextColumn::make('subject_type')->label(__('activity.fields.subject'))
                    ->state(fn (Activity $record) => static::subjectLabel($record)),
                TextColumn::make('ip_address')->label(__('activity.fields.ip'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('site_id')->label(__('activity.fields.site'))->relationship('site', 'code'),
                SelectFilter::make('causer_id')->label(__('activity.fields.user'))
                    ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => $data['value']
                        ? $query->where('causer_type', (new User)->getMorphClass())->where('causer_id', $data['value'])
                        : $query),
                SelectFilter::make('event')->label(__('activity.fields.event'))
                    ->options(fn () => Activity::query()->whereNotNull('event')->distinct()->pluck('event')
                        ->mapWithKeys(fn (string $e) => [$e => static::eventLabel($e)])->all()),
                SelectFilter::make('subject_type')->label(__('activity.fields.subject'))
                    ->options(fn () => Activity::query()->whereNotNull('subject_type')->distinct()->pluck('subject_type')
                        ->mapWithKeys(fn (string $t) => [$t => class_basename($t)])->all()),
                Filter::make('date')
                    ->schema([
                        DatePicker::make('from')->label(__('activity.fields.date').' ≥'),
                        DatePicker::make('until')->label(__('activity.fields.date').' ≤'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $query, $d) => $query->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn (Builder $query, $d) => $query->whereDate('created_at', '<=', $d))),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('created_at')->label(__('activity.fields.date'))->dateTime('d/m/Y H:i:s'),
                TextEntry::make('causer.name')->label(__('activity.fields.user'))->placeholder(__('activity.system')),
                TextEntry::make('site.name')->label(__('activity.fields.site'))->placeholder('—'),
                TextEntry::make('event')->label(__('activity.fields.event'))->formatStateUsing(fn (?string $state) => static::eventLabel($state)),
                TextEntry::make('subject_type')->label(__('activity.fields.subject'))->state(fn (Activity $record) => static::subjectLabel($record)),
                TextEntry::make('ip_address')->label(__('activity.fields.ip'))->placeholder('—'),
            ]),
            Section::make()->columns(2)->schema([
                KeyValueEntry::make('before')->label(__('activity.fields.before'))
                    ->state(fn (Activity $record) => self::flatten($record->attribute_changes['old'] ?? $record->properties['old'] ?? [])),
                KeyValueEntry::make('after')->label(__('activity.fields.after'))
                    ->state(fn (Activity $record) => self::flatten($record->attribute_changes['attributes'] ?? $record->properties['attributes'] ?? [])),
            ]),
        ]);
    }

    /** @return array<string, string> */
    private static function flatten(mixed $values): array
    {
        return collect($values)->map(fn ($v) => is_scalar($v) || $v === null ? (string) json_encode($v, JSON_UNESCAPED_UNICODE) : json_encode($v, JSON_UNESCAPED_UNICODE))->all();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
            'view' => ViewActivity::route('/{record}'),
        ];
    }
}
