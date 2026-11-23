<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class AdminDocumentListTest extends TestCase
{
    use RefreshDatabase;

    private function document(User $creator, string $title, DocumentStatus $status = DocumentStatus::InProgress, ?string $number = null): Document
    {
        return Document::query()->create([
            'public_id' => Str::lower(Str::random(32)),
            'title' => $title,
            'number' => $number,
            'creator_id' => $creator->id,
            'status' => $status,
        ]);
    }

    public function test_admin_sees_documents_of_everyone_with_counts(): void
    {
        $admin = User::factory()->admin()->create();
        $rina = User::factory()->withTwoFactor()->create(['name' => 'Rina']);
        $andi = User::factory()->withTwoFactor()->create(['name' => 'Andi']);
        $this->document($rina, 'Surat Tugas');
        $this->document($andi, 'SK Kepanitiaan', DocumentStatus::Completed);

        $this->actingAs($admin)->get(route('admin.documents.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Documents')
                ->has('documents', 2)
                ->where('pagination.total', 2)
                ->where('counts.in_progress.total', 1)
                ->where('counts.completed.total', 1)
                ->where('counts.revoked.total', 0));
    }

    public function test_search_matches_title_number_and_creator_case_insensitively(): void
    {
        $admin = User::factory()->admin()->create();
        $rina = User::factory()->withTwoFactor()->create(['name' => 'Rina Wati']);
        $andi = User::factory()->withTwoFactor()->create(['name' => 'Andi']);
        $this->document($rina, 'Surat Tugas');
        $this->document($andi, 'SK Kepanitiaan', number: '421/007/2026');
        $this->document($andi, 'Undangan Rapat');

        $titles = fn (string $q): array => $this->actingAs($admin)
            ->get(route('admin.documents.index', ['q' => $q]))
            ->viewData('page')['props']['documents'];

        $this->assertSame(['Surat Tugas'], array_column($titles('surat'), 'title'));
        $this->assertSame(['SK Kepanitiaan'], array_column($titles('007'), 'title'));
        $this->assertSame(['Surat Tugas'], array_column($titles('rina'), 'title'));
        $this->assertSame([], $titles('%'), 'wildcards are matched literally');
    }

    public function test_status_filter_and_pagination(): void
    {
        $admin = User::factory()->admin()->create();
        $creator = User::factory()->withTwoFactor()->create();
        foreach (range(1, 30) as $i) {
            $this->document($creator, "Surat {$i}", DocumentStatus::Completed);
        }
        $this->document($creator, 'Dicabut', DocumentStatus::Revoked);

        $this->actingAs($admin)->get(route('admin.documents.index', ['status' => 'completed']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents', 25)
                ->where('pagination.total', 30)
                ->where('pagination.last_page', 2)
                ->where('filters.status', 'completed'));

        $this->actingAs($admin)->get(route('admin.documents.index', ['status' => 'completed', 'page' => 2]))
            ->assertInertia(fn (Assert $page) => $page->has('documents', 5));

        $this->actingAs($admin)->get(route('admin.documents.index', ['status' => 'bogus']))
            ->assertSessionHasErrors('status');
    }

    public function test_staff_cannot_open_the_admin_list(): void
    {
        $staff = User::factory()->withTwoFactor()->create();

        $this->actingAs($staff)->get(route('admin.documents.index'))->assertForbidden();
    }
}
