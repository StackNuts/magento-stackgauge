<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\FactsSection;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Config;
use StackNuts\StackGauge\Model\ReporterPool;

class ReporterPoolTest extends TestCase
{
    /**
     * @param array<string, mixed> $status
     */
    private function fakeReporterWithCadence(string $name, string $cadence, array $status): ReporterInterface
    {
        return new class ($name, $cadence, $status) implements ReporterInterface, DeclaresCadenceInterface {
            public function __construct(
                private readonly string $name,
                private readonly string $cadence,
                private readonly array $status
            ) {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getLabel(): string
            {
                return ucfirst($this->name);
            }

            public function getDescription(): string
            {
                return 'A fake reporter for tests.';
            }

            public function getSchemaVersion(): string
            {
                return '1.0';
            }

            public function getCadence(): string
            {
                return $this->cadence;
            }

            public function getStatus(): array
            {
                return $this->status;
            }
        };
    }

    /**
     * @param array<string, mixed> $status
     */
    private function fakeReporter(string $name, string $schemaVersion, array $status): ReporterInterface
    {
        return new class ($name, $schemaVersion, $status) implements ReporterInterface {
            public function __construct(
                private readonly string $name,
                private readonly string $schemaVersion,
                private readonly array $status
            ) {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getLabel(): string
            {
                return ucfirst($this->name);
            }

            public function getDescription(): string
            {
                return 'A fake reporter for tests.';
            }

            public function getSchemaVersion(): string
            {
                return $this->schemaVersion;
            }

            public function getStatus(): array
            {
                return $this->status;
            }
        };
    }

    /**
     * @param array<string, mixed> $status
     */
    private function fakeReporterWithSection(string $name, string $section, array $status): ReporterInterface
    {
        return new class ($name, $section, $status) implements ReporterInterface, DeclaresSectionInterface {
            public function __construct(
                private readonly string $name,
                private readonly string $section,
                private readonly array $status
            ) {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getLabel(): string
            {
                return ucfirst($this->name);
            }

            public function getDescription(): string
            {
                return 'A fake reporter for tests.';
            }

            public function getSchemaVersion(): string
            {
                return '1.0';
            }

            public function getSection(): string
            {
                return $this->section;
            }

            public function getStatus(): array
            {
                return $this->status;
            }
        };
    }

    private function throwingReporter(string $name, string $message): ReporterInterface
    {
        return new class ($name, $message) implements ReporterInterface {
            public function __construct(private readonly string $name, private readonly string $message)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getLabel(): string
            {
                return ucfirst($this->name);
            }

            public function getDescription(): string
            {
                return 'A fake reporter for tests.';
            }

            public function getSchemaVersion(): string
            {
                return '1.0';
            }

            public function getStatus(): array
            {
                throw new RuntimeException($this->message);
            }
        };
    }

    /**
     * @param array<string, \StackNuts\StackGauge\Api\Field\FieldInterface> $fields
     */
    private function factsSection(array $fields): FactsSection
    {
        return Section::facts('general', 'General', '', $fields);
    }

    /**
     * A badly-behaved reporter returning a raw scalar instead of a Section - exactly the
     * mistake ReporterPool's validation exists to catch.
     */
    private function malformedReporter(string $name): ReporterInterface
    {
        return $this->fakeReporter($name, '1.0', ['general' => 'Community']);
    }

    public function testWrapsSectionsUnderTheReporterEnvelope(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn(['core']);

        $section = $this->factsSection(['edition' => Field::varchar('Edition', 'Community')]);

        $pool = new ReporterPool(
            [$this->fakeReporter('core', '2.0', ['general' => $section])],
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $this->assertEquals(
            [
                'core' => [
                    'schema_version' => '2.0',
                    'label' => 'Core',
                    'description' => 'A fake reporter for tests.',
                    'sections' => [$section],
                ],
            ],
            $pool->collect()
        );
    }

    public function testDisabledBuiltInReporterIsSkipped(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn(['modules']); // "core" not enabled

        $pool = new ReporterPool(
            [$this->fakeReporter('core', '1.0', ['general' => $this->factsSection(['edition' => Field::varchar('Edition', 'Community')])])],
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame([], $pool->collect());
    }

    public function testThirdPartyReporterNameAlwaysRunsRegardlessOfEnabledList(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn([]); // nothing built-in enabled

        $section = $this->factsSection(['purge_queue_backlog' => Field::number('Backlog', 0)]);

        $pool = new ReporterPool(
            [$this->fakeReporter('cloudflare', '1.0', ['general' => $section])],
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $result = $pool->collect();
        $this->assertArrayHasKey('cloudflare', $result);
        $this->assertSame('1.0', $result['cloudflare']['schema_version']);
        $this->assertEquals([$section], $result['cloudflare']['sections']);
    }

    public function testAFailingReporterProducesAnErrorBlockWithoutBlockingOthers(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn(['core', 'cron']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $pool = new ReporterPool(
            [
                $this->throwingReporter('core', 'boom'),
                $this->fakeReporter('cron', '1.0', ['general' => $this->factsSection(['alive' => Field::bool('Alive', true)])]),
            ],
            $config,
            $logger
        );

        $result = $pool->collect();
        $this->assertSame(['error' => 'boom'], $result['core']);
        $this->assertSame('1.0', $result['cron']['schema_version']);
    }

    public function testAReporterReturningARawScalarInsteadOfASectionProducesAnErrorBlock(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn(['core']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $pool = new ReporterPool([$this->malformedReporter('core')], $config, $logger);

        $result = $pool->collect();
        $this->assertArrayHasKey('error', $result['core']);
        $this->assertStringContainsString('SectionInterface', $result['core']['error']);
    }

    public function testAReporterWithNoCadenceDeclarationIsAlwaysTreatedAsHourly(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn(['core']);

        $pool = new ReporterPool(
            [$this->fakeReporter('core', '1.0', ['general' => $this->factsSection(['edition' => Field::varchar('Edition', 'Community')])])],
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $this->assertArrayHasKey('core', $pool->collect(DeclaresCadenceInterface::CADENCE_HOURLY));
        $this->assertArrayNotHasKey('core', $pool->collect(DeclaresCadenceInterface::CADENCE_DAILY));
    }

    public function testADailyCadenceReporterIsExcludedFromAnHourlyCollection(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn(['modules']);

        $pool = new ReporterPool(
            [
                $this->fakeReporterWithCadence(
                    'modules',
                    DeclaresCadenceInterface::CADENCE_DAILY,
                    ['general' => $this->factsSection(['count' => Field::number('Count', 42)])]
                ),
            ],
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame([], $pool->collect(DeclaresCadenceInterface::CADENCE_HOURLY));
        $this->assertArrayHasKey('modules', $pool->collect(DeclaresCadenceInterface::CADENCE_DAILY));
    }

    public function testAReporterDeclaringAValidSectionIncludesItInTheEnvelope(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn(['sales']);

        $section = $this->factsSection(['orders' => Field::number('Orders', 5)]);

        $pool = new ReporterPool(
            [$this->fakeReporterWithSection('sales', DeclaresSectionInterface::SECTION_COMMERCE, ['general' => $section])],
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame('commerce', $pool->collect()['sales']['section']);
    }

    public function testAReporterDeclaringAnInvalidSectionHasItOmittedWithAWarning(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn(['sales']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $section = $this->factsSection(['orders' => Field::number('Orders', 5)]);

        $pool = new ReporterPool(
            [$this->fakeReporterWithSection('sales', 'not_a_real_section', ['general' => $section])],
            $config,
            $logger
        );

        $result = $pool->collect();
        $this->assertArrayNotHasKey('section', $result['sales']);
        // The rest of the envelope still comes through fine - an invalid section is cosmetic
        // metadata, never a reason to fail the whole reporter.
        $this->assertSame('1.0', $result['sales']['schema_version']);
    }

    public function testGetReportersReturnsEveryRegisteredReporterRegardlessOfEnabledState(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getEnabledReporterCodes')->willReturn([]);

        $reporters = [
            $this->fakeReporter('core', '1.0', []),
            $this->fakeReporter('cloudflare', '1.0', []),
        ];

        $pool = new ReporterPool($reporters, $config, $this->createStub(LoggerInterface::class));

        $this->assertSame($reporters, $pool->getReporters());
    }
}
