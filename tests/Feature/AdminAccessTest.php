<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Feature;

use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use LaravelPlus\PaymentGateway\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * gh-12: the admin area answers 404 to anyone the viewPaymentAdmin gate refuses,
 * so it isn't disclosed, and works without spatie/laravel-permission installed.
 */
class AdminAccessTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('payment-gateway.webhooks.path', 'hooks/pay');
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    private function user(bool $admin = false): User
    {
        $user = new class extends User
        {
            protected $table = 'users';

            public bool $admin = false;

            public function hasRole($roles): bool
            {
                return $this->admin && array_intersect((array) $roles, ['super-admin', 'admin']) !== [];
            }
        };
        $user->forceFill(['name' => 'A', 'email' => uniqid().'@example.com', 'password' => 'x'])->save();
        $user->admin = $admin;

        return $user;
    }

    #[Test]
    public function the_admin_route_stack_uses_the_package_gate_middleware(): void
    {
        $route = Route::getRoutes()->getByName('admin.payments.dashboard');

        $this->assertContains('payment-gateway.admin', $route->gatherMiddleware());
        $this->assertNotContains('role:super-admin,admin', $route->gatherMiddleware());
    }

    #[Test]
    public function by_default_admin_roles_pass_and_everyone_else_is_refused(): void
    {
        $this->assertTrue(Gate::forUser($this->user(admin: true))->allows('viewPaymentAdmin'));
        $this->assertFalse(Gate::forUser($this->user())->allows('viewPaymentAdmin'));
        // A user model without hasRole() (no spatie) is refused, not crashed.
        $plain = User::forceCreate(['name' => 'B', 'email' => 'b@example.com', 'password' => 'x']);
        $this->assertFalse(Gate::forUser($plain)->allows('viewPaymentAdmin'));
    }

    #[Test]
    public function a_refused_user_and_a_guest_get_404_not_403(): void
    {
        $this->actingAs($this->user())->get('/admin/payments')->assertNotFound();
        auth()->logout();
        $this->get('/admin/payments')->assertNotFound();
    }

    #[Test]
    public function the_app_can_redefine_who_may_see_the_admin_area(): void
    {
        Gate::define('viewPaymentAdmin', fn ($user) => str_starts_with($user->email, 'ops@'));

        $ops = User::forceCreate(['name' => 'O', 'email' => 'ops@example.com', 'password' => 'x']);

        $this->assertTrue(Gate::forUser($ops)->allows('viewPaymentAdmin'));
        $this->assertFalse(Gate::forUser($this->user(admin: true))->allows('viewPaymentAdmin'));
    }

    #[Test]
    public function webhook_routes_follow_the_configured_path(): void
    {
        $this->assertSame('hooks/pay/stripe', Route::getRoutes()->getByName('payment.webhook.stripe')->uri());
    }
}
