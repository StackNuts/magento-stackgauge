<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\ReportRunner;
use StackNuts\StackGauge\Model\ReportSender;
use StackNuts\StackGauge\Model\Slot;
use StackNuts\StackGauge\Model\SlotResolver;

class ReportRunnerTest extends TestCase
{
    /** @var ReportSender&\PHPUnit\Framework\MockObject\MockObject */
    private ReportSender $sender;

    /** @var ReportRunner */
    private ReportRunner $runner;

    protected function setUp(): void
    {
        $this->sender = $this->createMock(ReportSender::class);
        $resolver = $this->createMock(SlotResolver::class);
        $resolver->method('currentHourly')->willReturn(new Slot(2, 3));
        $resolver->method('forIndex')->willReturnCallback(
            static fn (string $cadence, int $index) => new Slot($index, $cadence === 'hourly' ? 3 : 2)
        );
        $config = $this->createMock(Config::class);
        $config->method('getSlotCount')->willReturnCallback(static fn (string $c) => $c === 'hourly' ? 3 : 2);
        $this->runner = new ReportRunner($this->sender, $resolver, $config);
    }

    /**
     * @param \StackNuts\StackGauge\Model\ReportRun[] $runs
     * @return string[]
     */
    private function labels(array $runs): array
    {
        return array_map(static fn ($run) => $run->label(), $runs);
    }

    public function testAllCoversEverySlotThenConfigSync(): void
    {
        $this->assertSame(
            ['hourly:0', 'hourly:1', 'hourly:2', 'daily:0', 'daily:1', 'config'],
            $this->labels($this->runner->resolve('all'))
        );
    }

    #[DataProvider('targetProvider')]
    public function testResolve(string $target, array $expected): void
    {
        $this->assertSame($expected, $this->labels($this->runner->resolve($target)));
    }

    public static function targetProvider(): array
    {
        return [
            ['hourly', ['hourly:0', 'hourly:1', 'hourly:2']],
            ['daily', ['daily:0', 'daily:1']],
            ['daily:1', ['daily:1']],
            ['HOURLY:2', ['hourly:2']],
            ['current', ['hourly:2']],
            ['config', ['config']],
        ];
    }

    #[DataProvider('badTargetProvider')]
    public function testBadTargetsThrow(string $target): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->runner->resolve($target);
    }

    public static function badTargetProvider(): array
    {
        return [['weekly'], ['hourly:x'], ['hourly:3'], ['daily:2'], ['']];
    }

    public function testForceSelectsSendNow(): void
    {
        $run = $this->runner->resolve('daily:1')[0];
        $this->sender->expects($this->once())->method('sendNow')->with('daily', $run->slot)->willReturn(true);
        $this->sender->expects($this->never())->method('send');
        $this->assertTrue($this->runner->send($run, true));
    }

    public function testConfigRunUsesConfigSync(): void
    {
        $this->sender->expects($this->once())->method('sendConfigSync')->willReturn(true);
        $this->assertTrue($this->runner->send($this->runner->resolve('config')[0], false));
    }
}
