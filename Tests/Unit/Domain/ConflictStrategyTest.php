<?php

declare(strict_types=1);

namespace Robbi\RobbiCopy\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Robbi\RobbiCopy\Domain\ConflictStrategy;

class ConflictStrategyTest extends TestCase
{
    #[Test]
    public function parsesValidStrings(): void
    {
        self::assertSame(ConflictStrategy::Overwrite, ConflictStrategy::fromInput('overwrite'));
        self::assertSame(ConflictStrategy::Skip, ConflictStrategy::fromInput('skip'));
        self::assertSame(ConflictStrategy::Ask, ConflictStrategy::fromInput('ask'));
    }

    #[Test]
    public function nullAndEmptyDefaultToOverwrite(): void
    {
        self::assertSame(ConflictStrategy::Overwrite, ConflictStrategy::fromInput(null));
        self::assertSame(ConflictStrategy::Overwrite, ConflictStrategy::fromInput(''));
    }

    #[Test]
    public function passesThroughEnumInstance(): void
    {
        self::assertSame(ConflictStrategy::Skip, ConflictStrategy::fromInput(ConflictStrategy::Skip));
    }

    #[Test]
    public function invalidValueThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConflictStrategy::fromInput('merge');
    }
}
