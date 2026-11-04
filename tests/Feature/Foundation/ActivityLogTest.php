<?php

use App\Enums\RoleName;
use App\Filament\Resources\Activities\Pages\ListActivities;
use App\Models\Activity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    activity()->log('test entry');
    $this->entry = Activity::query()->latest('id')->first();
});

it('refuses to modify a journal entry through the model', function () {
    $this->entry->update(['description' => 'tampered']);
})->throws(LogicException::class);

it('refuses to delete a journal entry through the model', function () {
    $this->entry->delete();
})->throws(LogicException::class);

it('refuses UPDATE at database level', function () {
    DB::table('activity_log')->where('id', $this->entry->id)->update(['description' => 'tampered']);
})->throws(QueryException::class, 'append-only');

it('refuses DELETE at database level', function () {
    DB::table('activity_log')->delete();
})->throws(QueryException::class, 'append-only');

it('lets the owner browse and filter the journal by site', function () {
    $owner = owner();
    $this->actingAs($owner);

    activity()->causedBy($owner)->tap(fn ($a) => $a->site_id = site('M02')->id)->log('m02 operation');
    $m02 = Activity::query()->where('description', 'm02 operation')->first();

    Livewire::test(ListActivities::class)
        ->assertCanSeeTableRecords([$m02, $this->entry])
        ->filterTable('site_id', site('M02')->id)
        ->assertCanSeeTableRecords([$m02])
        ->assertCanNotSeeTableRecords([$this->entry]);

    $this->get('/admin/activities/'.$m02->id)->assertOk();
});

it('hides the journal from sellers', function () {
    $this->actingAs(userWithRole(RoleName::Seller, 'M01'))
        ->get('/admin/activities/'.$this->entry->id)
        ->assertForbidden();
});
