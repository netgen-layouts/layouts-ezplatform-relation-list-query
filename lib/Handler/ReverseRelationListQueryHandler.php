<?php

declare(strict_types=1);

namespace Netgen\Layouts\Ez\RelationListQuery\Handler;

use eZ\Publish\API\Repository\ContentService;
use eZ\Publish\API\Repository\LocationService;
use eZ\Publish\API\Repository\SearchService;
use eZ\Publish\API\Repository\Values\Content\LocationQuery;
use eZ\Publish\API\Repository\Values\Content\Query\Criterion;
use eZ\Publish\API\Repository\Values\Content\Query\SortClause;
use eZ\Publish\API\Repository\Values\Content\Search\SearchHit;
use eZ\Publish\API\Repository\Values\ValueObject;
use eZ\Publish\Core\MVC\ConfigResolverInterface;
use Netgen\Layouts\API\Values\Collection\Query;
use Netgen\Layouts\Collection\QueryType\QueryTypeHandlerInterface;
use Netgen\Layouts\Ez\ContentProvider\ContentProviderInterface;
use Netgen\Layouts\Ez\Parameters\ParameterType as EzParameterType;
use Netgen\Layouts\Ez\RelationListQuery\Handler\Traits\SelectedContentTrait;
use Netgen\Layouts\Parameters\ParameterBuilderInterface;
use Netgen\Layouts\Parameters\ParameterType;

use function array_map;
use function count;
use function is_array;

/**
 * Query handler implementation providing values through eZ Platform reverse relation.
 */
final class ReverseRelationListQueryHandler implements QueryTypeHandlerInterface
{
    use SelectedContentTrait;

    /**
     * @var class-string[]
     */
    private static array $sortClauses = [
        'default' => SortClause\DatePublished::class,
        'date_published' => SortClause\DatePublished::class,
        'date_modified' => SortClause\DateModified::class,
        'content_name' => SortClause\ContentName::class,
    ];

    private ContentService $contentService;

    private SearchService $searchService;

    private ConfigResolverInterface $configResolver;

    public function __construct(
        LocationService $locationService,
        ContentService $contentService,
        SearchService $searchService,
        ContentProviderInterface $contentProvider,
        ConfigResolverInterface $configResolver
    ) {
        $this->contentService = $contentService;
        $this->searchService = $searchService;
        $this->contentProvider = $contentProvider;
        $this->configResolver = $configResolver;
        $this->locationService = $locationService;
    }

    public function buildParameters(ParameterBuilderInterface $builder): void
    {
        $builder->add(
            'use_current_location',
            ParameterType\Compound\BooleanType::class,
            [
                'reverse' => true,
            ],
        );

        $builder->get('use_current_location')->add(
            'location_id',
            EzParameterType\LocationType::class,
            [
                'allow_invalid' => true,
            ],
        );

        $builder->add(
            'sort_type',
            ParameterType\ChoiceType::class,
            [
                'required' => true,
                'options' => [
                    'Published' => 'date_published',
                    'Modified' => 'date_modified',
                    'Alphabetical' => 'content_name',
                ],
            ],
        );

        $builder->add(
            'sort_direction',
            ParameterType\ChoiceType::class,
            [
                'required' => true,
                'options' => [
                    'Descending' => LocationQuery::SORT_DESC,
                    'Ascending' => LocationQuery::SORT_ASC,
                ],
            ],
        );

        $builder->add(
            'filter_by_content_type',
            ParameterType\Compound\BooleanType::class,
            [
                'groups' => [self::GROUP_ADVANCED],
            ],
        );

        $builder->get('filter_by_content_type')->add(
            'content_types',
            EzParameterType\ContentTypeType::class,
            [
                'multiple' => true,
                'groups' => [self::GROUP_ADVANCED],
            ],
        );

        $builder->get('filter_by_content_type')->add(
            'content_types_filter',
            ParameterType\ChoiceType::class,
            [
                'required' => true,
                'options' => [
                    'Include content types' => 'include',
                    'Exclude content types' => 'exclude',
                ],
                'groups' => [self::GROUP_ADVANCED],
            ],
        );

        $builder->get('filter_by_content_type')->add(
            'field_definition_identifier',
            ParameterType\TextLineType::class,
            [
                'required' => false,
                'groups' => [self::GROUP_ADVANCED],
            ],
        );

        $builder->add(
            'only_main_locations',
            ParameterType\BooleanType::class,
            [
                'default_value' => true,
                'groups' => [self::GROUP_ADVANCED],
            ],
        );
    }

    public function getValues(Query $query, int $offset = 0, ?int $limit = null): iterable
    {
        $reverseRelatedContentIds = $this->getReverseRelatedContentIds($query);

        if (count($reverseRelatedContentIds) === 0) {
            return [];
        }

        $locationQuery = $this->buildLocationQuery($reverseRelatedContentIds, $query, false, $offset, $limit);

        // We're disabling query count for performance reasons, however
        // it can only be disabled if limit is not 0
        $locationQuery->performCount = $locationQuery->limit === 0;

        $searchResult = $this->searchService->findLocations(
            $locationQuery,
            ['languages' => $this->configResolver->getParameter('languages')],
        );

        /** @var \eZ\Publish\API\Repository\Values\Content\Location[] $locations */
        $locations = array_map(
            static fn (SearchHit $searchHit): ValueObject => $searchHit->valueObject,
            $searchResult->searchHits,
        );

        return $locations;
    }

    public function getCount(Query $query): int
    {
        $content = $this->getSelectedContent($query, $this->configResolver->getParameter('languages'));
        if ($content === null) {
            return 0;
        }

        $relatedContentIds = $this->getReverseRelatedContentIds($query);

        if (count($relatedContentIds) === 0) {
            return 0;
        }

        $searchResult = $this->searchService->findLocations(
            $this->buildLocationQuery($relatedContentIds, $query, true),
            ['languages' => $this->configResolver->getParameter('languages')],
        );

        return $searchResult->totalCount ?? 0;
    }

    public function isContextual(Query $query): bool
    {
        return $query->getParameter('use_current_location')->getValue() === true;
    }

    /**
     * Returns a list Content IDs whose content relates to selected content.
     *
     * @return int[]
     */
    private function getReverseRelatedContentIds(Query $query): array
    {
        $content = $this->getSelectedContent($query, $this->configResolver->getParameter('languages'));

        if ($content === null) {
            return [];
        }

        $reverseRelations = $this->contentService->loadReverseRelations($content->contentInfo);
        $contentIds = [];
        foreach ($reverseRelations as $relation) {
            $contentIds[] = $relation->getSourceContentInfo()->id;
        }

        return $contentIds;
    }

    /**
     * Builds the Location query from given parameters.
     *
     * @param int[] $reverseRelatedContentIds
     */
    private function buildLocationQuery(
        array $reverseRelatedContentIds,
        Query $query,
        bool $buildCountQuery = false,
        int $offset = 0,
        ?int $limit = null
    ): LocationQuery {
        $locationQuery = new LocationQuery();
        $sortType = $query->getParameter('sort_type')->getValue() ?? 'default';
        $sortDirection = $query->getParameter('sort_direction')->getValue() ?? LocationQuery::SORT_DESC;

        $criteria = [
            new Criterion\ContentId($reverseRelatedContentIds),
            new Criterion\Visibility(Criterion\Visibility::VISIBLE),
        ];

        if ($query->getParameter('only_main_locations')->getValue() === true) {
            $criteria[] = new Criterion\Location\IsMainLocation(
                Criterion\Location\IsMainLocation::MAIN,
            );
        }

        if ($query->getParameter('filter_by_content_type')->getValue() === true) {
            /** @var string[]|null $contentTypes */
            $contentTypes = $query->getParameter('content_types')->getValue();
            if (is_array($contentTypes) && count($contentTypes) > 0) {
                $contentTypeFilter = new Criterion\ContentTypeIdentifier($contentTypes);

                if ($query->getParameter('content_types_filter')->getValue() === 'exclude') {
                    $contentTypeFilter = new Criterion\LogicalNot($contentTypeFilter);
                }

                $criteria[] = $contentTypeFilter;
            }

            $fieldDefinitionIdentifier = $query->getParameter('field_definition_identifier')->getValue();
            $selectedContent = $this->getSelectedContent($query, $this->configResolver->getParameter('languages'));

            if ($fieldDefinitionIdentifier !== null && $selectedContent !== null) {
                $criteria[] = new Criterion\Field(
                    $fieldDefinitionIdentifier,
                    Criterion\Operator::CONTAINS,
                    $selectedContent->id,
                );
            }
        }

        $locationQuery->filter = new Criterion\LogicalAnd($criteria);

        $locationQuery->limit = 0;
        if (!$buildCountQuery) {
            $locationQuery->offset = $offset;
            if ($limit !== null) {
                $locationQuery->limit = $limit;
            }
        }

        /** @var \eZ\Publish\API\Repository\Values\Content\Query\SortClause $sortClause */
        $sortClause = new self::$sortClauses[$sortType]($sortDirection);
        $locationQuery->sortClauses = [$sortClause];

        return $locationQuery;
    }
}
