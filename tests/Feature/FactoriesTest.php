<?php

declare(strict_types=1);

namespace LaravelPlus\PaymentGateway\Tests\Feature;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;
use LaravelPlus\PaymentGateway\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;

/**
 * gh-17: every shipped factory, with every state, must insert against the
 * shipped migrations; host apps use them in their own tests.
 */
class FactoriesTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('payment-gateway.billable_model', User::class);
    }

    public static function factories(): array
    {
        $cases = [];
        foreach (glob(__DIR__.'/../../database/factories/*Factory.php') as $file) {
            $class = 'LaravelPlus\\PaymentGateway\\Database\\Factories\\'.basename($file, '.php');
            $cases[basename($file, '.php')] = [$class];
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('factories')]
    public function every_factory_and_state_inserts(string $class): void
    {
        $user = User::forceCreate(['name' => 'A', 'email' => uniqid().'@example.com', 'password' => 'x']);

        /** @var Factory $factory */
        $factory = $class::new();
        if (Schema::hasColumn($factory->newModel()->getTable(), 'user_id')) {
            $factory = $factory->state(['user_id' => $user->id]);
        }
        $this->assertNotNull($factory->create()->getKey(), "{$class} default");

        // Parameterless public states declared on the factory itself.
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class !== $class || $method->getNumberOfRequiredParameters() > 0 || in_array($method->name, ['definition', 'configure', 'modelName'], true)) {
                continue;
            }
            $this->assertNotNull($factory->{$method->name}()->create()->getKey(), "{$class}::{$method->name}()");
        }
    }
}
