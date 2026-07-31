<?php

// SPDX-License-Identifier: EUPL-1.2

declare(strict_types=1);

use Core\Tenant\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

// Declared per file: Pest.php binds the Testbench case but not RefreshDatabase,
// so without this the package's own migrations never run and every table is
// missing.
uses(RefreshDatabase::class);

it('gives workspaces somewhere to hang a parent', function () {
    expect(Schema::hasColumn('workspaces', 'parent_id'))->toBeTrue();
});

it('treats a workspace with no parent as a root', function () {
    $workspace = Workspace::factory()->create();

    expect($workspace->parent)->toBeNull()
        ->and($workspace->ancestors())->toBe([])
        ->and($workspace->root()->id)->toBe($workspace->id);
});

it('links a child to its parent both ways', function () {
    $parent = Workspace::factory()->create();
    $child = Workspace::factory()->create(['parent_id' => $parent->id]);

    expect($child->parent->id)->toBe($parent->id)
        ->and($parent->children->pluck('id')->all())->toBe([$child->id]);
});

it('walks the whole way up, nearest first', function () {
    $root = Workspace::factory()->create();
    $middle = Workspace::factory()->create(['parent_id' => $root->id]);
    $leaf = Workspace::factory()->create(['parent_id' => $middle->id]);

    expect(array_map(fn (Workspace $w): int => $w->id, $leaf->ancestors()))
        ->toBe([$middle->id, $root->id])
        ->and($leaf->root()->id)->toBe($root->id);
});

it('stops rather than hangs when the data has a cycle', function () {
    // parent_id is a plain column and nothing at the database level prevents
    // A→B→A, so a walk that trusted the data would spin forever.
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create(['parent_id' => $a->id]);
    $a->forceFill(['parent_id' => $b->id])->save();

    expect(count($a->fresh()->ancestors()))->toBeLessThanOrEqual(2);
});

it('answers whether a workspace is at or below another', function () {
    $root = Workspace::factory()->create();
    $child = Workspace::factory()->create(['parent_id' => $root->id]);
    $stranger = Workspace::factory()->create();

    // The question an authorisation check asks: a parent may act on its
    // children, so "is this mine" has to mean "mine, or under mine".
    expect($child->isWithin($root))->toBeTrue()
        ->and($child->isWithin($child))->toBeTrue()
        ->and($root->isWithin($child))->toBeFalse()
        ->and($stranger->isWithin($root))->toBeFalse();
});

it('orphans children rather than deleting them with their parent', function () {
    $parent = Workspace::factory()->create();
    $child = Workspace::factory()->create(['parent_id' => $parent->id]);

    $parent->forceDelete();

    // Cascading would take the children and everything they own. Becoming a
    // root is recoverable; being deleted is not.
    $survivor = Workspace::query()->find($child->id);
    expect($survivor)->not->toBeNull()
        ->and($survivor->parent_id)->toBeNull();
});
