<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;
use DBench86\Driver\DatabaseDriver;
use DBench86\Driver\AbstractDriver;
use ReflectionClass;
use ReflectionMethod;
use ReflectionType;

class DriverContractTest extends TestCase
{
    public function testDatabaseDriverInterfaceDefinesNameProperty(): void
    {
        $reflection = new ReflectionClass(DatabaseDriver::class);
        $this->assertTrue(
            $reflection->hasProperty('name'),
            "DatabaseDriver interface must define name property"
        );

        $property = $reflection->getProperty('name');
        $this->assertTrue(
            $property->isPublic(),
            "name property must be public"
        );

        $this->assertTrue(
            !$property->isStatic(),
            "name property must not be static"
        );
    }

    public function testDatabaseDriverInterfaceDefinesPdoDriverProperty(): void
    {
        $reflection = new ReflectionClass(DatabaseDriver::class);
        $this->assertTrue(
            $reflection->hasProperty('pdoDriver'),
            "DatabaseDriver interface must define pdoDriver property"
        );

        $property = $reflection->getProperty('pdoDriver');
        $this->assertTrue(
            $property->isPublic(),
            "pdoDriver property must be public"
        );

        $this->assertTrue(
            !$property->isStatic(),
            "pdoDriver property must not be static"
        );
    }

    public function testDatabaseDriverInterfaceDefinesConnectMethod(): void
    {
        $reflection = new ReflectionClass(DatabaseDriver::class);
        $this->assertTrue(
            $reflection->hasMethod('connect'),
            "DatabaseDriver interface must define connect() method"
        );

        $method = $reflection->getMethod('connect');
        $this->assertTrue(
            $method->isPublic(),
            "connect() must be public"
        );
    }

    public function testDatabaseDriverInterfaceDefinesResetSchemaMethod(): void
    {
        $reflection = new ReflectionClass(DatabaseDriver::class);
        $this->assertTrue(
            $reflection->hasMethod('resetSchema'),
            "DatabaseDriver interface must define resetSchema() method"
        );

        $method = $reflection->getMethod('resetSchema');
        $this->assertTrue(
            $method->isPublic(),
            "resetSchema() must be public"
        );

        $params = $method->getParameters();
        $this->assertEquals(
            1,
            count($params),
            "resetSchema() must accept exactly one parameter"
        );

        $firstParam = $params[0];
        $this->assertEquals(
            'pdo',
            $firstParam->getName(),
            "First parameter must be named 'pdo'"
        );
    }

    public function testDatabaseDriverInterfaceDefinesCreateSchemaMethod(): void
    {
        $reflection = new ReflectionClass(DatabaseDriver::class);
        $this->assertTrue(
            $reflection->hasMethod('createSchema'),
            "DatabaseDriver interface must define createSchema() method"
        );

        $method = $reflection->getMethod('createSchema');
        $this->assertTrue(
            $method->isPublic(),
            "createSchema() must be public"
        );

        $params = $method->getParameters();
        $this->assertEquals(
            1,
            count($params),
            "createSchema() must accept exactly one parameter"
        );
    }

    public function testDatabaseDriverInterfaceDefinesDiscoverEngineVersionMethod(): void
    {
        $reflection = new ReflectionClass(DatabaseDriver::class);
        $this->assertTrue(
            $reflection->hasMethod('discoverEngineVersion'),
            "DatabaseDriver interface must define discoverEngineVersion() method"
        );

        $method = $reflection->getMethod('discoverEngineVersion');
        $this->assertTrue(
            $method->isPublic(),
            "discoverEngineVersion() must be public"
        );

        $params = $method->getParameters();
        $this->assertEquals(
            1,
            count($params),
            "discoverEngineVersion() must accept exactly one parameter"
        );

        $returnType = $method->getReturnType();
        if ($returnType !== null) {
            $this->assertEquals(
                'string',
                $returnType->getName(),
                "discoverEngineVersion() must return string"
            );
        }
    }

    public function testAbstractDriverImplementsDatabaseDriver(): void
    {
        $reflection = new ReflectionClass(AbstractDriver::class);
        $interfaces = $reflection->getInterfaceNames();

        $this->assertTrue(
            in_array(DatabaseDriver::class, $interfaces),
            "AbstractDriver must implement DatabaseDriver interface"
        );
    }

    public function testAbstractDriverDefinesMaxBatchSizeConstant(): void
    {
        $reflection = new ReflectionClass(AbstractDriver::class);
        $this->assertTrue(
            $reflection->hasConstant('MAX_BATCH_SIZE'),
            "AbstractDriver must define MAX_BATCH_SIZE constant"
        );

        $value = $reflection->getConstant('MAX_BATCH_SIZE');
        $this->assertEquals(
            500,
            $value,
            "MAX_BATCH_SIZE must equal 500"
        );
    }

    public function testAbstractDriverDefinesMaxBoundParametersConstant(): void
    {
        $reflection = new ReflectionClass(AbstractDriver::class);
        $this->assertTrue(
            $reflection->hasConstant('MAX_BOUND_PARAMETERS'),
            "AbstractDriver must define MAX_BOUND_PARAMETERS constant"
        );

        $value = $reflection->getConstant('MAX_BOUND_PARAMETERS');
        $this->assertEquals(
            10_000,
            $value,
            "MAX_BOUND_PARAMETERS must equal 10000"
        );
    }

    public function testAbstractDriverConstantsArePublic(): void
    {
        $reflection = new ReflectionClass(AbstractDriver::class);

        // In Reflection, public constants are just accessible without prefix
        // We verify by attempting to read them directly
        $maxBatchSize = AbstractDriver::MAX_BATCH_SIZE;
        $maxBoundParameters = AbstractDriver::MAX_BOUND_PARAMETERS;

        $this->assertEquals(500, $maxBatchSize);
        $this->assertEquals(10_000, $maxBoundParameters);
    }

    public function testAbstractDriverIsAbstract(): void
    {
        $reflection = new ReflectionClass(AbstractDriver::class);
        $this->assertTrue(
            $reflection->isAbstract(),
            "AbstractDriver must be declared as abstract"
        );
    }

    public function run(): void
    {
        echo "\nDriverContractTest\n";
        echo "──────────────────\n";
        $this->testDatabaseDriverInterfaceDefinesNameProperty();
        $this->testDatabaseDriverInterfaceDefinesPdoDriverProperty();
        $this->testDatabaseDriverInterfaceDefinesConnectMethod();
        $this->testDatabaseDriverInterfaceDefinesResetSchemaMethod();
        $this->testDatabaseDriverInterfaceDefinesCreateSchemaMethod();
        $this->testDatabaseDriverInterfaceDefinesDiscoverEngineVersionMethod();
        $this->testAbstractDriverImplementsDatabaseDriver();
        $this->testAbstractDriverDefinesMaxBatchSizeConstant();
        $this->testAbstractDriverDefinesMaxBoundParametersConstant();
        $this->testAbstractDriverConstantsArePublic();
        $this->testAbstractDriverIsAbstract();
    }
}

$test = new DriverContractTest();
$test->run();
