<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model;

use DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\Slot;
use StackNuts\StackGauge\Model\SlotResolver;

class SlotTest extends TestCase
{
    public function testReporterWithoutDeclarationBelongsToSlotZero(): void
    {
        $reporter = $this->createMock(ReporterInterface::class);

        $this->assertTrue((new Slot(0, 3))->includes($reporter));
        $this->assertFalse((new Slot(1, 3))->includes($reporter));
    }

    public function testDeclaredSlotWrapsWhenCountIsLowered(): void
    {
        $reporter = new class implements ReporterInterface, DeclaresSlotInterface {
            public function getSlot(): int { return 2; }
            public function getName(): string { return 'x'; }
            public function getLabel(): string { return 'x'; }
            public function getDescription(): string { return 'x'; }
            public function getSchemaVersion(): string { return '1'; }
            public function getStatus(): array { return []; }
        };

        $this->assertTrue((new Slot(2, 3))->includes($reporter));
        $this->assertTrue((new Slot(0, 2))->includes($reporter));
        $this->assertFalse((new Slot(1, 2))->includes($reporter));
        $this->assertTrue((new Slot(0, 1))->includes($reporter));
    }

    #[DataProvider('clockProvider')]
    public function testCurrentHourlySlotComesFromTheClock(int $count, string $time, int $expected): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getSlotCount')->willReturn($count);
        $timezone = $this->createMock(TimezoneInterface::class);
        $timezone->method('date')->willReturn(new DateTime($time));

        $slot = (new SlotResolver($config, $timezone))->currentHourly();

        $this->assertSame($expected, $slot->index);
        $this->assertSame($count, $slot->count);
    }

    public static function clockProvider(): array
    {
        return [
            'hourly :00' => [3, '2026-10-12 09:00:00', 0],
            'hourly :20' => [3, '2026-10-12 09:20:00', 1],
            'hourly :41 (late tick)' => [3, '2026-10-12 09:41:00', 2],
            'hourly :59' => [3, '2026-10-12 09:59:00', 2],
        ];
    }
}
