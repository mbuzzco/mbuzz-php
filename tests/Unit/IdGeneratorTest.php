<?php

declare(strict_types=1);

namespace Mbuzz\Tests\Unit;

use Mbuzz\IdGenerator;
use PHPUnit\Framework\TestCase;

class IdGeneratorTest extends TestCase
{
    public function testGenerateReturns64CharHexString(): void
    {
        $id = IdGenerator::generate();

        $this->assertEquals(64, strlen($id));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $id);
    }

    public function testGenerateReturnsUniqueIds(): void
    {
        $ids = [];
        for ($i = 0; $i < 100; $i++) {
            $ids[] = IdGenerator::generate();
        }

        // All IDs should be unique
        $uniqueIds = array_unique($ids);
        $this->assertCount(100, $uniqueIds);
    }

    public function testGeneratedIdIsLowercase(): void
    {
        $id = IdGenerator::generate();

        $this->assertEquals(strtolower($id), $id);
    }
}
