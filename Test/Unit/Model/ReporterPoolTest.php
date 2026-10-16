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
use StackNuts\StackGauge\Api\DeclaresSubjectInterface;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\FactsSection;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Api\Unit;
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
        return (new Section())->facts('general', 'General', '', $fields);
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
        $config->method('getDisabledReporterCodes')->willReturn([]);

        $section = $this->factsSection(['edition' => (new Field())->varchar('Edition', 'Community')]);

        $pool = new ReporterPool(
            [$this->fakeReporter('core', '2.0', ['general' => $section])],
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $result = $pool->collect();
        unset($result['core']['duration_ms']);

        $this->assertEquals(
            [
                'core' => [
                    'schema_version' => '2.0',
                    'label' => 'Core',
                    'description' => 'A fake reporter for tests.',
                    'sections' => [$section],
                ],
            ],
            $result
        );
    }

    public function testDisabledBuiltInReporterIsSkipped(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getDisabledReporterCodes')->willReturn(['core']);

        $pool = new ReporterPool(
            [
                $this->fakeReporter(
                    'core',
                    '1.0',
                    ['general' => $this->factsSection(['edition' => (new Field())->varchar('Edition', 'Community')])]
                ),
            ],
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame([], $pool->collect());
    }

    public function testThirdPartyReporterRunsByDefaultWhenNotDisabled(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getDisabledReporterCodes')->willReturn([]);

        $section = $this->factsSection(['purge_queue_backlog' => (new Field())->number('Backlog', 0)]);

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

    /**
     * A third-party reporter's name is subject to the exact same "Disabled Reporters" check as
     * a built-in one - there's no special-casing by origin, only by the name each reporter
     * itself declares via getName(). See Model\System\Config\Source\ReporterList, which derives
     * its admin options from every registered reporter for the same reason.
     */
    public function testThirdPartyReporterCanBeDisabledByNameJustLikeABuiltIn(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getDisabledReporterCodes')->willReturn(['cloudflare']);

        $pool = new ReporterPool(
            [$this->fakeReporter('cloudflare', '1.0', ['general' => $this->factsSection([])])],
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame([], $pool->collect());
    }

    public function testAFailingReporterProducesAnErrorBlockWithoutBlockingOthers(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getDisabledReporterCodes')->willReturn([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $pool = new ReporterPool(
            [
                $this->throwingReporter('core', 'boom'),
                $this->fakeReporter(
                    'cron',
                    '1.0',
                    ['general' => $this->factsSection(['alive' => (new Field())->bool('Alive', true)])]
                ),
            ],
            $config,
            $logger
        );

        $result = $pool->collect();
        $this->assertSame('boom', $result['core']['error']);
        $this->assertSame('1.0', $result['cron']['schema_version']);
    }

    public function testAReporterReturningARawScalarInsteadOfASectionProducesAnErrorBlock(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getDisabledReporterCodes')->willReturn([]);

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
        $config->method('getDisabledReporterCodes')->willReturn([]);

        $pool = new ReporterPool(
            [
                $this->fakeReporter(
                    'core',
                    '1.0',
                    ['general' => $this->factsSection(['edition' => (new Field())->varchar('Edition', 'Community')])]
                ),
            ],
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $this->assertArrayHasKey('core', $pool->collect(DeclaresCadenceInterface::CADENCE_HOURLY));
        $this->assertArrayNotHasKey('core', $pool->collect(DeclaresCadenceInterface::CADENCE_DAILY));
    }

    public function testADailyCadenceReporterIsExcludedFromAnHourlyCollection(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getDisabledReporterCodes')->willReturn([]);

        $pool = new ReporterPool(
            [
                $this->fakeReporterWithCadence(
                    'modules',
                    DeclaresCadenceInterface::CADENCE_DAILY,
                    ['general' => $this->factsSection(['count' => (new Field())->number('Count', 42)])]
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
        $config->method('getDisabledReporterCodes')->willReturn([]);

        $section = $this->factsSection(['orders' => (new Field())->number('Orders', 5)]);

        $pool = new ReporterPool(
            [
                $this->fakeReporterWithSection(
                    'sales',
                    DeclaresSectionInterface::SECTION_COMMERCE,
                    ['general' => $section]
                ),
            ],
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $this->assertSame('commerce', $pool->collect()['sales']['section']);
    }

    public function testAReporterDeclaringASubjectIncludesItInTheEnvelopeAndOthersOmitIt(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getDisabledReporterCodes')->willReturn([]);

        $status = ['general' => $this->factsSection(['orders' => (new Field())->number('Orders', 5)])];
        $withSubject = new class ($this->fakeReporterWithSection('cron_perf', 'performance', $status)) implements
            ReporterInterface,
            DeclaresSubjectInterface {
            public function __construct(private readonly ReporterInterface $inner)
            {
            }

            public function getName(): string
            {
                return $this->inner->getName();
            }

            public function getLabel(): string
            {
                return $this->inner->getLabel();
            }

            public function getDescription(): string
            {
                return $this->inner->getDescription();
            }

            public function getSchemaVersion(): string
            {
                return $this->inner->getSchemaVersion();
            }

            public function getStatus(): array
            {
                return $this->inner->getStatus();
            }

            public function getSubject(): string
            {
                return 'cron';
            }
        };

        $pool = new ReporterPool(
            [$withSubject, $this->fakeReporterWithSection('sales', 'commerce', $status)],
            $config,
            $this->createStub(LoggerInterface::class)
        );

        $result = $pool->collect();
        $this->assertSame('cron', $result['cron_perf']['subject']);
        $this->assertArrayNotHasKey('subject', $result['sales']);
    }

    public function testAReporterDeclaringAnInvalidSectionHasItOmittedWithAWarning(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getDisabledReporterCodes')->willReturn([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $section = $this->factsSection(['orders' => (new Field())->number('Orders', 5)]);

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
        $config->method('getDisabledReporterCodes')->willReturn([]);

        $reporters = [
            $this->fakeReporter('core', '1.0', []),
            $this->fakeReporter('cloudflare', '1.0', []),
        ];

        $pool = new ReporterPool($reporters, $config, $this->createStub(LoggerInterface::class));

        $this->assertSame($reporters, $pool->getReporters());
    }

    public function testAMetricDefinitionsUnitIsCopiedOntoItsTrackableFields(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getDisabledReporterCodes')->willReturn([]);
        $field = new Field();

        $reporter = new class($field, $this->factsSection([
            'used' => $field->trackableNumber('Used', 2048, 'redis.used', 'max'),
            'own' => $field->trackableNumber('Own', 5, 'redis.own', 'max', unit: Unit::SECONDS),
            'plain' => $field->number('Plain', 3),
        ])) implements ReporterInterface, MetricCatalogInterface {
            public function __construct(private Field $field, private FactsSection $section)
            {
            }

            public function getName(): string
            {
                return 'redis';
            }

            public function getLabel(): string
            {
                return 'Redis';
            }

            public function getDescription(): string
            {
                return '';
            }

            public function getSchemaVersion(): string
            {
                return '1.0';
            }

            public function getStatus(): array
            {
                return ['general' => $this->section];
            }

            public function getTrackableMetrics(): array
            {
                return [
                    new MetricDefinition('redis.used', 'Used', 'max', 'gt', 1, 60, unit: Unit::MEGABYTES),
                    new MetricDefinition('redis.own', 'Own', 'max', 'gt', 1, 60, unit: Unit::MINUTES),
                ];
            }
        };

        $fields = (new ReporterPool([$reporter], $config, $this->createStub(LoggerInterface::class)))
            ->collect()['redis']['sections'][0]['fields'];

        $this->assertSame('MB', $fields['used']['unit']);
        $this->assertSame('s', $fields['own']['unit'], 'A field naming its own unit keeps it.');
        $this->assertArrayNotHasKey('unit', $fields['plain']);
        $this->assertSame(2048, $fields['used']['value'], 'The value itself is untouched.');
    }

    public function testErrorBlocksAreRedactedAndTruncatedButTheFullMessageIsLogged(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getDisabledReporterCodes')->willReturn([]);

        $message = "SQLSTATE[23000]: Duplicate entry 'jane@example.com' for key 'EMAIL' from 203.0.113.9 " . str_repeat('x ', 400);
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('warning')->willReturnCallback(function (string $m) use (&$logged): void {
            $logged[] = $m;
        });

        $result = (new ReporterPool([$this->throwingReporter('db', $message)], $config, $logger))->collect();

        $this->assertLessThanOrEqual(300, mb_strlen($result['db']['error']));
        $this->assertStringNotContainsString('jane@example.com', $result['db']['error']);
        $this->assertStringNotContainsString('203.0.113.9', $result['db']['error']);
        $this->assertStringContainsString('jane@example.com', $logged[0]);
        $this->assertArrayHasKey('duration_ms', $result['db']);
    }

    public function testSuccessfulBlocksCarryDurationMs(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getDisabledReporterCodes')->willReturn([]);
        $section = $this->factsSection(['edition' => (new Field())->varchar('Edition', 'Community')]);

        $result = (new ReporterPool(
            [$this->fakeReporter('core', '1.0', ['general' => $section])],
            $config,
            $this->createStub(LoggerInterface::class)
        ))->collect();

        $this->assertIsInt($result['core']['duration_ms']);
        $this->assertGreaterThanOrEqual(0, $result['core']['duration_ms']);
    }

    public function testAnUnencodableValueOnlyBreaksThatReportersBlock(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('getDisabledReporterCodes')->willReturn([]);
        $bad = new class implements \StackNuts\StackGauge\Api\Section\SectionInterface {
            public function getKey(): string
            {
                return 'bad';
            }

            public function getKind(): string
            {
                return 'facts';
            }

            public function getLabel(): string
            {
                return 'Bad';
            }

            public function getDescription(): string
            {
                return '';
            }

            public function jsonSerialize(): array
            {
                return ['ratio' => NAN];
            }
        };
        $good = $this->factsSection(['edition' => (new Field())->varchar('Edition', 'Community')]);

        $result = (new ReporterPool(
            [$this->fakeReporter('bad', '1.0', ['general' => $bad]), $this->fakeReporter('core', '1.0', ['general' => $good])],
            $config,
            $this->createStub(LoggerInterface::class)
        ))->collect();

        $this->assertArrayHasKey('error', $result['bad']);
        $this->assertArrayNotHasKey('error', $result['core']);
    }
}
