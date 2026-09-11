<?php
declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\Filesystem;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use StackNuts\StackGauge\Model\Reporter\DiskSpaceReporter;

/**
 * disk_free_space()/disk_total_space() are real filesystem calls, not something Filesystem
 * mocking can intercept - so these tests drive volumeField() directly via reflection with
 * controlled inputs, rather than exercising getStatus()'s real I/O path.
 */
class DiskSpaceReporterTest extends TestCase
{
    private function volumeField(string $purpose, bool $measurable, float $freePercent): array
    {
        $reporter = new DiskSpaceReporter($this->createMock(Filesystem::class));

        $method = new ReflectionMethod(DiskSpaceReporter::class, 'volumeField');

        return $method->invoke($reporter, $purpose, $measurable, 0, 0, $freePercent)->getValue();
    }

    public function testFreePercentIsCriticalBelowTenPercent(): void
    {
        $fields = $this->volumeField('media', true, 9.9);

        $this->assertSame('critical', $fields['free_percent']->jsonSerialize()['severity']);
    }

    public function testFreePercentIsOkAtOrAboveTenPercent(): void
    {
        $fields = $this->volumeField('media', true, 10.0);

        $this->assertSame('ok', $fields['free_percent']->jsonSerialize()['severity']);
    }

    public function testFreePercentHasNoSeverityWhenUnmeasurable(): void
    {
        $fields = $this->volumeField('media', false, 0.0);

        $this->assertArrayNotHasKey('severity', $fields['free_percent']->jsonSerialize());
    }

    public function testNonMediaVolumesGetTheSameSeverityTreatment(): void
    {
        $fields = $this->volumeField('var_log', true, 5.0);

        $this->assertSame('critical', $fields['free_percent']->jsonSerialize()['severity']);
    }
}
