<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Profile photos are private: stored on the local disk and served only to signed-in users.
 *
 * @group security
 */
class PrivateAvatarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

        $this->user = User::factory()->create();
        $this->user->assignRole('employee');
        $this->employee = Employee::factory()->create(['user_id' => $this->user->id]);
    }

    /** @test */
    public function an_uploaded_photo_is_stored_privately_and_linked_through_the_api(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg')])
            ->assertOk();

        $path = $this->employee->fresh()->avatar;
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->assertStringStartsWith("/api/v1/employees/{$this->employee->id}/avatar", $response->json('avatar_url'));
        $this->assertStringNotContainsString('/storage/', $response->json('avatar_url'));
    }

    /** @test */
    public function a_signed_in_colleague_can_see_the_photo_but_a_guest_cannot(): void
    {
        Storage::disk('local')->put('avatars/photo.jpg', 'jpeg-bytes');
        $this->employee->update(['avatar' => 'avatars/photo.jpg']);

        $colleague = User::factory()->create();
        $colleague->assignRole('employee');

        $this->getJson("/api/v1/employees/{$this->employee->id}/avatar")->assertUnauthorized();

        $this->actingAs($colleague, 'sanctum')
            ->get("/api/v1/employees/{$this->employee->id}/avatar")
            ->assertOk();
    }

    /** @test */
    public function photos_uploaded_before_the_move_are_still_served(): void
    {
        Storage::disk('public')->put('avatars/old.jpg', 'jpeg-bytes');
        $this->employee->update(['avatar' => 'avatars/old.jpg']);

        $this->actingAs($this->user, 'sanctum')
            ->get("/api/v1/employees/{$this->employee->id}/avatar")
            ->assertOk();
    }

    /** @test */
    public function the_move_command_moves_existing_photos_to_private_storage(): void
    {
        Storage::disk('public')->put('avatars/old.jpg', 'jpeg-bytes');

        $this->artisan('uploads:move-private')->assertSuccessful();

        Storage::disk('local')->assertExists('avatars/old.jpg');
        Storage::disk('public')->assertMissing('avatars/old.jpg');
    }
}
