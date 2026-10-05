<?php
declare(strict_types=1);

namespace Tests\Unit;

use DBench86\Workload\Workload;
use PDO;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

final class WorkloadContractTest extends TestCase
{
    public function run(): void
    {
        $this->testWorkloadInterfaceExistsAndIsInterface();
        $this->testWorkloadHasNameMethod();
        $this->testWorkloadHasPrepareMethod();
        $this->testWorkloadHasExecuteMethod();
        $this->testWorkloadHasCleanupMethod();
        $this->testWorkloadHasBoundParametersPerRowMethod();
        $this->testWorkloadMethodsHaveConsistentVisibility();
    }

    public function testWorkloadInterfaceExistsAndIsInterface(): void
    {
        $this->assertTrue(interface_exists(Workload::class));
    }

    public function testWorkloadHasNameMethod(): void
    {
        $reflection = new ReflectionClass(Workload::class);
        $this->assertTrue($reflection->hasMethod('name'));

        $method = $reflection->getMethod('name');
        $this->assertTrue($method->isPublic());

        // Check return type is string
        $returnType = $method->getReturnType();
        $this->assertTrue($returnType !== null, 'name() should have return type');
        $this->assertEquals('string', (string)$returnType);
    }

    public function testWorkloadHasPrepareMethod(): void
    {
        $reflection = new ReflectionClass(Workload::class);
        $this->assertTrue($reflection->hasMethod('prepare'));

        $method = $reflection->getMethod('prepare');
        $this->assertTrue($method->isPublic());

        // Check parameters
        $params = $method->getParameters();
        $this->assertEquals(1, count($params));
        $this->assertEquals('pdo', $params[0]->getName());

        // Check return type is void
        $returnType = $method->getReturnType();
        $this->assertTrue($returnType !== null, 'prepare() should have return type');
        $this->assertEquals('void', (string)$returnType);
    }

    public function testWorkloadHasExecuteMethod(): void
    {
        $reflection = new ReflectionClass(Workload::class);
        $this->assertTrue($reflection->hasMethod('execute'));

        $method = $reflection->getMethod('execute');
        $this->assertTrue($method->isPublic());

        // Check parameters
        $params = $method->getParameters();
        $this->assertEquals(1, count($params));
        $this->assertEquals('pdo', $params[0]->getName());

        // Check return type is int
        $returnType = $method->getReturnType();
        $this->assertTrue($returnType !== null, 'execute() should have return type');
        $this->assertEquals('int', (string)$returnType);
    }

    public function testWorkloadHasCleanupMethod(): void
    {
        $reflection = new ReflectionClass(Workload::class);
        $this->assertTrue($reflection->hasMethod('cleanup'));

        $method = $reflection->getMethod('cleanup');
        $this->assertTrue($method->isPublic());

        // Check parameters
        $params = $method->getParameters();
        $this->assertEquals(1, count($params));
        $this->assertEquals('pdo', $params[0]->getName());

        // Check return type is void
        $returnType = $method->getReturnType();
        $this->assertTrue($returnType !== null, 'cleanup() should have return type');
        $this->assertEquals('void', (string)$returnType);
    }

    public function testWorkloadHasBoundParametersPerRowMethod(): void
    {
        $reflection = new ReflectionClass(Workload::class);
        $this->assertTrue($reflection->hasMethod('boundParametersPerRow'));

        $method = $reflection->getMethod('boundParametersPerRow');
        $this->assertTrue($method->isPublic());

        // Check return type is nullable int
        $returnType = $method->getReturnType();
        $this->assertTrue($returnType !== null, 'boundParametersPerRow() should have return type');
        $this->assertTrue($returnType->allowsNull(), 'Return type should allow null');
    }

    public function testWorkloadMethodsHaveConsistentVisibility(): void
    {
        $reflection = new ReflectionClass(Workload::class);
        $methods = $reflection->getMethods();

        // All methods in an interface should be public
        foreach ($methods as $method) {
            $this->assertTrue(
                $method->isPublic(),
                "Method {$method->getName()} should be public"
            );
        }
    }
}

$workloadContractTest = new WorkloadContractTest();
$workloadContractTest->run();
