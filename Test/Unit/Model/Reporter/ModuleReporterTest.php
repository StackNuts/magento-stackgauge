<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Test\Unit\Model\Reporter;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Module\FullModuleList;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use StackNuts\StackGauge\Model\Reporter\ModuleReporter;
use StackNuts\StackGauge\Model\Util\ComposerLockReader;

class ModuleReporterTest extends TestCase
{
    private function fieldsFor(array $rows, string $moduleName): array
    {
        foreach ($rows as $row) {
            $fields = $row->getValue();
            if ($fields['name']->getValue() === $moduleName) {
                return $fields;
            }
        }

        $this->fail("No row found for module \"{$moduleName}\".");
    }

    public function testFallsBackToModuleXmlSetupVersionWhenComposerLockHasNoMatch(): void
    {
        $fullModuleList = $this->createStub(FullModuleList::class);
        $fullModuleList->method('getAll')->willReturn([
            'Magento_Catalog' => ['setup_version' => '2.4.9'],
        ]);

        $enabledModuleList = $this->createStub(ModuleListInterface::class);
        $enabledModuleList->method('has')->willReturn(true);

        // No module path resolvable - package name always null, so composer.lock (even if it
        // has data) can never match and the resolver must fall through to setup_version.
        $componentRegistrar = $this->createStub(ComponentRegistrar::class);
        $componentRegistrar->method('getPath')->willReturn(null);

        $composerLockReader = $this->createStub(ComposerLockReader::class);
        $composerLockReader->method('getDecoded')->willReturn(['packages' => []]);

        $reporter = new ModuleReporter($fullModuleList, $enabledModuleList, $componentRegistrar, new Json(), $composerLockReader);
        $rows = $reporter->getStatus()['modules']->getRows();

        $fields = $this->fieldsFor($rows, 'Magento_Catalog');
        $this->assertSame('2.4.9', $fields['version']->getValue());
        $this->assertSame('module_xml', $fields['version_source']->getValue());
        $this->assertTrue($fields['enabled']->getValue());
    }

    public function testReportsUnknownVersionWhenNeitherComposerLockNorSetupVersionResolve(): void
    {
        $fullModuleList = $this->createStub(FullModuleList::class);
        $fullModuleList->method('getAll')->willReturn(['Acme_Foo' => ['setup_version' => null]]);

        $enabledModuleList = $this->createStub(ModuleListInterface::class);
        $enabledModuleList->method('has')->willReturn(false);

        $componentRegistrar = $this->createStub(ComponentRegistrar::class);
        $componentRegistrar->method('getPath')->willReturn(null);

        $composerLockReader = $this->createStub(ComposerLockReader::class);
        $composerLockReader->method('getDecoded')->willReturn(null);

        $reporter = new ModuleReporter($fullModuleList, $enabledModuleList, $componentRegistrar, new Json(), $composerLockReader);
        $rows = $reporter->getStatus()['modules']->getRows();

        $fields = $this->fieldsFor($rows, 'Acme_Foo');
        $this->assertSame('', $fields['version']->getValue());
        $this->assertSame('unknown', $fields['version_source']->getValue());
        $this->assertFalse($fields['enabled']->getValue());
    }
}
