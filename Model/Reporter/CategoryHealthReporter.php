<?php
/**
 * Copyright © StackNuts. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace StackNuts\StackGauge\Model\Reporter;

use StackNuts\StackGauge\Api\DeclaresCadenceInterface;
use StackNuts\StackGauge\Api\DeclaresSectionInterface;
use StackNuts\StackGauge\Api\DeclaresSlotInterface;
use StackNuts\StackGauge\Api\DeclaresSubjectInterface;
use StackNuts\StackGauge\Api\Field\ArrayField;
use StackNuts\StackGauge\Api\Field\Field;
use StackNuts\StackGauge\Api\MetricCatalogInterface;
use StackNuts\StackGauge\Api\MetricDefinition;
use StackNuts\StackGauge\Api\ReporterInterface;
use StackNuts\StackGauge\Api\Section\Section;
use StackNuts\StackGauge\Model\Reporter\CategoryHealth\CategoryHealthQueries;
use StackNuts\StackGauge\Model\Reporter\Concern\CommerceSectionTrait;
use StackNuts\StackGauge\Model\Reporter\Concern\DailyCadenceTrait;

/**
 * Category tree data-quality checks: empty menu categories, categories hidden by a disabled
 * ancestor, categories without a URL rewrite, and store groups pointing at a missing or
 * disabled root category. The SQL lives in CategoryHealthQueries; this class shapes the
 * payload. Only the empty-category count and the store-root problem count are alertable; the
 * other two are display-only.
 */
class CategoryHealthReporter implements
    ReporterInterface,
    DeclaresCadenceInterface,
    MetricCatalogInterface,
    DeclaresSectionInterface,
    DeclaresSubjectInterface,
    DeclaresSlotInterface
{
    use DailyCadenceTrait;
    use CommerceSectionTrait;

    private const SCHEMA_VERSION = '1.0';
    private const METRIC_EMPTY_CATEGORIES = 'category_health.empty_categories_count';
    private const METRIC_STORE_ROOT_PROBLEM = 'category_health.store_root_problem_count';

    /**
     * @param CategoryHealthQueries $queries
     * @param Field $field
     * @param Section $section
     */
    public function __construct(
        private readonly CategoryHealthQueries $queries,
        private readonly Field $field,
        private readonly Section $section
    ) {
    }

    /**
     * Payload key for the category-health reporter.
     */
    public function getName(): string
    {
        return 'category_health';
    }

    /**
     * Human-readable label for the category-health reporter block.
     */
    public function getLabel(): string
    {
        return 'Category Health';
    }

    /**
     * One-line summary of the category-health reporter, shown on the dashboard alongside the label.
     */
    public function getDescription(): string
    {
        return 'Empty menu categories, categories hidden by a disabled parent, categories missing '
            . 'a URL rewrite, and store roots pointing at a missing or disabled category.';
    }

    /**
     * Schema version for this reporter's payload shape.
     */
    public function getSchemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Counts for the four checks in a "general" facts section, plus up to
     * CategoryHealthQueries::SAMPLE_LIMIT sample rows per check.
     */
    public function getStatus(): array
    {
        $limit = CategoryHealthQueries::SAMPLE_LIMIT;
        $storeViews = $this->queries->storeViews();

        $empty = $this->queries->emptyCategories($storeViews);
        $unreachable = $this->queries->unreachableCategories();
        $urlMissing = $this->queries->missingUrlRewrites($storeViews);
        $rootProblems = $this->queries->storeRootProblems();

        $emptySamples = array_slice($empty, 0, $limit);
        $unreachableSamples = array_slice($unreachable, 0, $limit);
        $urlSamples = $urlMissing['samples'];

        $nameIds = [];
        foreach ([$emptySamples, $unreachableSamples, $urlSamples] as $samples) {
            foreach ($samples as $sample) {
                array_push($nameIds, ...$this->pathIds($sample['path']));
            }
        }
        foreach ($unreachableSamples as $sample) {
            $nameIds[] = $sample['ancestor_id'];
        }
        $names = $this->queries->categoryNames($nameIds);

        return [
            'general' => $this->section->facts('general', 'General', 'Category tree problems found.', [
                'empty_categories_count' => $this->field->trackableNumber(
                    'Empty Categories',
                    count($empty),
                    self::METRIC_EMPTY_CATEGORIES,
                    MetricDefinition::AGGREGATION_LATEST
                ),
                'unreachable_categories_count' => $this->field->number('Unreachable Categories', count($unreachable)),
                'url_rewrite_missing_count' => $this->field->number('Missing URL Rewrites', $urlMissing['count']),
                'store_root_problem_count' => $this->field->trackableNumber(
                    'Store Root Problems',
                    count($rootProblems),
                    self::METRIC_STORE_ROOT_PROBLEM,
                    MetricDefinition::AGGREGATION_LATEST
                ),
            ]),
            'empty_categories' => $this->section->table(
                'empty_categories',
                'Empty Categories',
                'Active, in-menu categories with no products in the category-product index of their store view.',
                $this->categoryRows($emptySamples, $names, fn (array $s): array => [
                    'store' => $this->field->varchar('Store', $s['store']),
                ]),
                keyName: 'category_id'
            ),
            'unreachable_categories' => $this->section->table(
                'unreachable_categories',
                'Unreachable Categories',
                'Active categories hidden because a parent category is disabled.',
                $this->categoryRows($unreachableSamples, $names, fn (array $s): array => [
                    'disabled_ancestor' => $this->field->varchar(
                        'Disabled Ancestor',
                        sprintf('%s (#%d)', $names[$s['ancestor_id']] ?? '', $s['ancestor_id'])
                    ),
                ]),
                keyName: 'category_id'
            ),
            'url_rewrite_missing' => $this->section->table(
                'url_rewrite_missing',
                'Missing URL Rewrites',
                'Active categories with no URL rewrite for a store view they belong to.',
                $this->categoryRows($urlSamples, $names, fn (array $s): array => [
                    'store' => $this->field->varchar('Store', $s['store']),
                    'key' => $this->field->varchar('Key', $s['store'] . ':' . $s['category_id']),
                ]),
                keyName: 'key'
            ),
            'store_root_problems' => $this->section->table(
                'store_root_problems',
                'Store Root Problems',
                'Store groups whose root category is missing or disabled.',
                array_map(fn (array $p): ArrayField => $this->field->array($p['code'], [
                    'code' => $this->field->varchar('Store Group Code', $p['code']),
                    'name' => $this->field->varchar('Store Group Name', $p['name']),
                    'root_category_id' => $this->field->number('Root Category ID', $p['root_category_id']),
                    'problem' => $this->field->varchar('Problem', $p['problem']),
                ]), array_slice($rootProblems, 0, $limit)),
                keyName: 'code'
            ),
        ];
    }

    /**
     * Alertable metrics: empty menu categories (warning, tolerant of seasonal ones) and store
     * root problems (critical, storefront navigation breaks).
     */
    public function getTrackableMetrics(): array
    {
        return [
            new MetricDefinition(
                self::METRIC_EMPTY_CATEGORIES,
                'Empty Categories',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                5,
                1500,
                null,
                description: '{value} active menu categories have no products (alert above {threshold}).',
                impact: 'Customers hit empty category pages from the menu, which hurts conversion and SEO.'
            ),
            new MetricDefinition(
                self::METRIC_STORE_ROOT_PROBLEM,
                'Store Root Problems',
                MetricDefinition::AGGREGATION_LATEST,
                MetricDefinition::OPERATOR_GT,
                0,
                1500,
                null,
                description: '{value} store groups point at a missing or disabled root category (alert above {threshold}).',
                impact: 'The store has no category navigation and its catalog pages may not load.',
                severity: MetricDefinition::SEVERITY_CRITICAL
            ),
        ];
    }

    /**
     * Subject shared with the other catalog reporters, for cross-linking.
     */
    public function getSubject(): string
    {
        return 'catalog';
    }

    /**
     * Daily tier has two slots (daily_slots = 2); slot 0 is the lighter one, slot 1 is crowded.
     */
    public function getSlot(): int
    {
        return 0;
    }

    /**
     * Numeric category ids from a path, excluding the tree root and store root (levels 0 and 1).
     *
     * @param string $path
     * @return list<int>
     */
    private function pathIds(string $path): array
    {
        return array_map('intval', array_slice(explode('/', $path), 2));
    }

    /**
     * Builds table rows: category id, name, breadcrumb, plus check-specific extra columns.
     *
     * @param list<array<string,mixed>> $samples
     * @param array<int,string> $names
     * @param callable $extra function(array $sample): array<string,\StackNuts\StackGauge\Api\Field\FieldInterface>
     * @return list<ArrayField>
     */
    private function categoryRows(array $samples, array $names, callable $extra): array
    {
        $rows = [];
        foreach ($samples as $sample) {
            $id = (int) $sample['category_id'];
            $breadcrumb = implode(' > ', array_map(
                static fn (int $pathId): string => $names[$pathId] ?? '#' . $pathId,
                $this->pathIds((string) $sample['path'])
            ));

            $rows[] = $this->field->array((string) $id, [
                'category_id' => $this->field->number('Category ID', $id),
                'name' => $this->field->varchar('Name', $names[$id] ?? ''),
                'breadcrumb' => $this->field->varchar('Path', $breadcrumb),
            ] + $extra($sample));
        }

        return $rows;
    }
}
