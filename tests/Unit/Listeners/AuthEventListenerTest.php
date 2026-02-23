<?php

namespace Tests\Unit\Listeners;

use App\Jobs\EmitDomainEventJob;
use App\Listeners\AuthEventListener;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Laravel\Pennant\Feature;
use Tests\TestCase;
use Tests\Traits\ActsAsTenant;

class AuthEventListenerTest extends TestCase
{
    use RefreshDatabase, ActsAsTenant;

    private AuthEventListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTenant();
        $this->listener = new AuthEventListener();
    }

    public function test_handle_login_emits_domain_event(): void
    {
        Bus::fake([EmitDomainEventJob::class]);
        Feature::for($this->company)->activate('ledger-v1');

        $request = Request::create('/login', 'POST', [], [], [], [
            'REMOTE_ADDR' => '192.168.1.100',
            'HTTP_USER_AGENT' => 'TestBrowser/1.0',
        ]);
        $this->app->instance('request', $request);

        $event = new Login('web', $this->user, false);
        $this->listener->handleLogin($event);

        Bus::assertDispatched(EmitDomainEventJob::class, function (EmitDomainEventJob $job) {
            return $job->companyId === $this->company->id
                && $job->entityType === 'user'
                && $job->entityId === (string) $this->user->id
                && $job->eventType === 'auth.login'
                && $job->payload['guard'] === 'web'
                && $job->actorType === 'user'
                && $job->actorId === (string) $this->user->id;
        });
    }

    public function test_handle_login_skips_user_without_company(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $userWithoutCompany = User::factory()->create(['company_id' => null]);
        $event = new Login('web', $userWithoutCompany, false);

        $this->listener->handleLogin($event);

        Bus::assertNotDispatched(EmitDomainEventJob::class);
    }

    public function test_handle_logout_emits_domain_event(): void
    {
        Bus::fake([EmitDomainEventJob::class]);
        Feature::for($this->company)->activate('ledger-v1');

        $request = Request::create('/logout', 'POST', [], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
        ]);
        $this->app->instance('request', $request);

        $event = new Logout('web', $this->user);
        $this->listener->handleLogout($event);

        Bus::assertDispatched(EmitDomainEventJob::class, function (EmitDomainEventJob $job) {
            return $job->companyId === $this->company->id
                && $job->entityType === 'user'
                && $job->entityId === (string) $this->user->id
                && $job->eventType === 'auth.logout'
                && isset($job->payload['ip'])
                && $job->actorType === 'user'
                && $job->actorId === (string) $this->user->id;
        });
    }

    public function test_handle_logout_skips_null_user(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $event = new Logout('web', null);
        $this->listener->handleLogout($event);

        Bus::assertNotDispatched(EmitDomainEventJob::class);
    }

    public function test_handle_logout_skips_user_without_company(): void
    {
        Bus::fake([EmitDomainEventJob::class]);

        $userWithoutCompany = User::factory()->create(['company_id' => null]);
        $event = new Logout('web', $userWithoutCompany);

        $this->listener->handleLogout($event);

        Bus::assertNotDispatched(EmitDomainEventJob::class);
    }
}
