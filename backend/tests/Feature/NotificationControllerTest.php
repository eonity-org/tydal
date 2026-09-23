<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Notification;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();
        $this->user = User::factory()->create(['last_organization_id' => $org->id]);
        $org->users()->attach($this->user->id, ['role' => 'admin']);

        $this->token = $this->user->createToken('test', ['*'], now()->addHour())->plainTextToken;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Create and store a generic database notification for $this->user. */
    private function seedNotification(bool $read = false): string
    {
        $this->user->notify(new TestDatabaseNotification);

        $notification = $this->user->notifications()->latest()->first();

        if ($read) {
            $notification->markAsRead();
        }

        return $notification->id;
    }

    // =========================================================================
    // GET /api/v1/notifications
    // =========================================================================

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }

    public function test_index_returns_empty_list_when_no_notifications(): void
    {
        $this->withToken($this->token)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.notifications', [])
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_index_returns_notifications_with_expected_shape(): void
    {
        $this->seedNotification();

        $this->withToken($this->token)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonStructure([
                'data' => [
                    'notifications' => [['id', 'type', 'data', 'read_at', 'created_at']],
                    'unread_count',
                ],
            ]);
    }

    public function test_index_returns_correct_unread_count(): void
    {
        $this->seedNotification();          // unread
        $this->seedNotification(read: true); // read

        $this->withToken($this->token)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonCount(2, 'data.notifications');
    }

    public function test_index_returns_at_most_30_notifications(): void
    {
        for ($i = 0; $i < 35; $i++) {
            $this->seedNotification();
        }

        $this->withToken($this->token)
            ->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(30, 'data.notifications');
    }

    // =========================================================================
    // POST /api/v1/notifications/{id}/read
    // =========================================================================

    public function test_mark_read_marks_notification_as_read(): void
    {
        $id = $this->seedNotification();

        $this->withToken($this->token)
            ->postJson("/api/v1/notifications/{$id}/read")
            ->assertOk();

        $this->assertNotNull(
            $this->user->notifications()->where('id', $id)->first()->read_at
        );
    }

    public function test_mark_read_returns_404_for_unknown_id(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/notifications/00000000-0000-0000-0000-000000000000/read')
            ->assertNotFound();
    }

    public function test_mark_read_cannot_access_another_users_notification(): void
    {
        $otherUser = User::factory()->create();

        $otherUser->notify(new TestDatabaseNotification);
        $otherId = $otherUser->notifications()->first()->id;

        // Our user cannot mark the other user's notification
        $this->withToken($this->token)
            ->postJson("/api/v1/notifications/{$otherId}/read")
            ->assertNotFound();
    }

    // =========================================================================
    // POST /api/v1/notifications/read-all
    // =========================================================================

    public function test_mark_all_read_clears_unread_count(): void
    {
        $this->seedNotification();
        $this->seedNotification();
        $this->seedNotification();

        $this->withToken($this->token)
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk();

        $this->assertSame(0, $this->user->unreadNotifications()->count());
    }

    public function test_mark_all_read_is_safe_when_no_notifications(): void
    {
        $this->withToken($this->token)
            ->postJson('/api/v1/notifications/read-all')
            ->assertOk();
    }
}

/**
 * Minimal database notification used by these tests. Lives in this file so
 * removing it (when a real notification class exists) is a single delete.
 */
class TestDatabaseNotification extends Notification
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Test notification',
            'message' => 'Generic fixture for the notification controller tests.',
        ];
    }
}
