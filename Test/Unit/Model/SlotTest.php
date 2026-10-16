<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\Slot;
use StackNuts\StackGauge\Model\SlotResolver;
use StackNuts\StackGauge\Model\Util\Clock;

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
    public function testHourlySlotComesFromTheScheduledTimeInUtc(string $time, int $expected): void
    {
        $slot = $this->resolver()->currentHourly($time);

        $this->assertSame($expected, $slot->index);
        $this->assertSame(3, $slot->count);
    }

    public function testFallsBackToTheClockWithoutASchedule(): void
    {
        $clock = $this->createMock(Clock::class);
        $clock->method('now')->willReturn(new DateTimeImmutable('2026-10-12 09:41:00', new \DateTimeZone('UTC')));
        $config = $this->createMock(Config::class);
        $config->method('getSlotCount')->willReturn(3);

        $this->assertSame(2, (new SlotResolver($config, $clock))->currentHourly()->index);
        $this->assertSame(2, (new SlotResolver($config, $clock))->currentHourly('garbage')->index);
    }

    public function testSlotCountsMatchTheCronSchedules(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../../etc/config.xml');
        $jobs = $xml->default->crontab->stackgauge->jobs;
        $expression = static fn(string $job): string => trim((string)$jobs->{$job}->schedule->cron_expr);

        // Hourly tier: "*/N * * * *" runs 60/N times an hour, one per slot.
        $this->assertSame(1, preg_match('#^\*/(\d+) \* \* \* \*$#', $expression('stacknuts_stackgauge_send_report'), $m));
        $this->assertSame(Config::HOURLY_SLOTS, intdiv(60, (int)$m[1]));

        // Daily tier: one job per slot, each at a fixed time once a day.
        $dailyJobs = array_filter(
            array_keys((array)$jobs),
            static fn(string $name): bool => str_starts_with($name, 'stacknuts_stackgauge_send_daily_report')
        );
        $this->assertCount(Config::DAILY_SLOTS, $dailyJobs);
        foreach ($dailyJobs as $name) {
            $this->assertMatchesRegularExpression('#^\d+ \d+ \* \* \*$#', $expression($name));
        }

        $config = (new \ReflectionClass(Config::class))->newInstanceWithoutConstructor();
        $this->assertSame(Config::HOURLY_SLOTS, $config->getSlotCount('hourly'));
        $this->assertSame(Config::DAILY_SLOTS, $config->getSlotCount('daily'));
    }

    private function resolver(): SlotResolver
    {
        $config = $this->createMock(Config::class);
        $config->method('getSlotCount')->willReturn(3);

        return new SlotResolver($config, $this->createMock(Clock::class));
    }

    public static function clockProvider(): array
    {
        return [
            'hourly :00' => ['2026-10-12 09:00:00', 0],
            'hourly :20' => ['2026-10-12 09:20:00', 1],
            'hourly :40' => ['2026-10-12 09:40:00', 2],
            'hourly :59' => ['2026-10-12 09:59:00', 2],
        ];
    }
}
