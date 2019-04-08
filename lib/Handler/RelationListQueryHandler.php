<?php

declare(strict_types=1);

namespace Netgen\Layouts\RelationListQuery\Handler;

use eZ\Publish\API\Repository\Exceptions\NotFoundException;
use eZ\Publish\API\Repository\LocationService;
use eZ\Publish\API\Repository\SearchService;
use eZ\Publish\API\Repository\Values\Content\Content;
use eZ\Publish\API\Repository\Values\Content\Location;
use eZ\Publish\API\Repository\Values\Content\LocationQuery;
use eZ\Publish\API\Repository\Values\Content\Query\Criterion;
use eZ\Publish\API\Repository\Values\Content\Query\SortClause;
use eZ\Publish\API\Repository\Values\Content\Search\SearchHit;
use eZ\Publish\Core\FieldType\RelationList\Value as RelationListValue;
use eZ\Publish\SPI\Persistence\Content\Type\Handler;
use Netgen\BlockManager\API\Values\Collection\Query;
use Netgen\BlockManager\Collection\QueryType\QueryTypeHandlerInterface;
use Netgen\BlockManager\Ez\ContentProvider\ContentProviderInterface;
use Netgen\BlockManager\Ez\Parameters\ParameterType as EzParameterType;
use Netgen\BlockManager\Parameters\ParameterBuilderInterface;
use Netgen\BlockManager\Parameters\ParameterType;
use Throwable;

/**
 * Query handler implementation providing values through eZ Platform relation list field.
 *
 * @final
 */
class RelationListQueryHandler implements QueryTypeHandlerInterface
{
    /**
     * @var \eZ\Publish\API\Repository\LocationService
     */
    private $locationService;

    /**
     * @var \eZ\Publish\API\Repository\SearchService
     */
    private $searchService;

    /**
     * @var \eZ\Publish\SPI\Persistence\Content\Type\Handler
     */
    private $contentTypeHandler;

    /**
     * @var \Netgen\BlockManager\Ez\ContentProvider\ContentProviderInterface
     */
    private $contentProvider;

    /**
     * Injected list of prioritized languages.
     *
     * @var array
     */
    private $languages = [];

    /**
     * @var array
     */
    private static $sortClauses = [
        'default' => SortClause\DatePublished::class,
        'date_published' => SortClause\DatePublished::class,
        'date_modified' => SortClause\DateModified::class,
        'content_name' => SortClause\ContentName::class,
        'location_priority' => SortClause\Location\Priority::class,
        Location::SORT_FIELD_PATH => SortClause\Location\Path::class,
        Location::SORT_FIELD_PUBLISHED => SortClause\DatePublished::class,
        Location::SORT_FIELD_MODIFIED => SortClause\DateModified::class,
        Location::SORT_FIELD_SECTION => SortClause\SectionIdentifier::class,
        Location::SORT_FIELD_DEPTH => SortClause\Location\Depth::class,
        Location::SORT_FIELD_PRIORITY => SortClause\Location\Priority::class,
        Location::SORT_FIELD_NAME => SortClause\ContentName::class,
        Location::SORT_FIELD_NODE_ID => SortClause\Location\Id::class,
        Location::SORT_FIELD_CONTENTOBJECT_ID => SortClause\ContentId::class,
    ];

    public function __construct(
        LocationService $locationService,
        SearchService $searchService,
        Handler $contentTypeHandler,
        ContentProviderInterface $contentProvider
    ) {
        $this->locationService = $locationService;
        $this->searchService = $searchService;
        $this->contentTypeHandler = $contentTypeHandler;
        $this->contentProvider = $contentProvider;
    }

    /**
     * Sets the current siteaccess languages into the handler.
     */
    public function setLanguages(?array $languages = null): void
    {
        $this->languages = $languages ?? [];
    }

    public function buildParameters(ParameterBuilderInterface $builder): void
    {
        $builder->add(
            'use_current_location',
            ParameterType\Compound\BooleanType::class,
            [
                'reverse' => true,
            ]
        );

        $builder->get('use_current_location')->add(
            'location_id',
            EzParameterType\LocationType::class,
            [
                'allow_invalid' => true,
            ]
        );

        $builder->add(
            'field_definition_identifier',
            ParameterType\TextLineType::class,
            [
                'required' => true,
            ]
        );

        $builder->add(
            'sort_type',
            ParameterType\ChoiceType::class,
            [
                'required' => true,
                'options' => [
                    'Defined by field' => 'defined_by_field',
                    'Published' => 'date_published',
                    'Modified' => 'date_modified',
                    'Alphabetical' => 'content_name',
                ],
            ]
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
            ]
        );

        $builder->add(
            'only_main_locations',
            ParameterType\BooleanType::class,
            [
                'default_value' => true,
                'groups' => [self::GROUP_ADVANCED],
            ]
        );

        $builder->add(
            'filter_by_content_type',
            ParameterType\Compound\BooleanType::class,
            [
                'groups' => [self::GROUP_ADVANCED],
            ]
        );

        $builder->get('filter_by_content_type')->add(
            'content_types',
            EzParameterType\ContentTypeType::class,
            [
                'multiple' => true,
                'groups' => [self::GROUP_ADVANCED],
            ]
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
            ]
        );
    }

    public function getValues(Query $query, int $offset = 0, ?int $limit = null): iterable
    {
        $relatedContentIds = $this->getRelatedContentIds($query);
        $sortType = $query->getParameter('sort_type')->getValue() ?? 'default';
        $sortDirection = $query->getParameter('sort_direction')->getValue() ?? LocationQuery::SORT_DESC;

        if (count($relatedContentIds) === 0) {
            return [];
        }

        $locationQuery = $this->buildLocationQuery($relatedContentIds, $query, false, $offset, $limit);

        // We're disabling query count for performance reasons, however
        // it can only be disabled if limit is not 0
        $locationQuery->performCount = $locationQuery->limit === 0;

        $searchResult = $this->searchService->findLocations(
            $locationQuery,
            ['languages' => $this->languages]
        );

        $locations = array_map(
            static function (SearchHit $searchHit) {
                return $searchHit->valueObject;
            },
            $searchResult->searchHits
        );

        if ($sortType === 'defined_by_field') {
            $this->sortLocationsByField($relatedContentIds, $locations, $sortDirection);
        }

        return $locations;
    }

    public function getCount(Query $query): int
    {
        $relatedContentIds = $this->getRelatedContentIds($query);

        if (count($relatedContentIds) === 0) {
            return 0;
        }

        $searchResult = $this->searchService->findLocations(
            $this->buildLocationQuery($relatedContentIds, $query, true),
            ['languages' => $this->languages]
        );

        return $searchResult->totalCount ?? 0;
    }

    public function isContextual(Query $query): bool
    {
        return $query->getParameter('use_current_location')->getValue() === true;
    }

    /**
     * Returns content type IDs for all existing content types.
     */
    private function getContentTypeIds(array $contentTypeIdentifiers): array
    {
        $idList = [];

        foreach ($contentTypeIdentifiers as $identifier) {
            try {
                $contentType = $this->contentTypeHandler->loadByIdentifier($identifier);
                $idList[] = $contentType->id;
            } catch (NotFoundException $e) {
                continue;
            }
        }

        return $idList;
    }

    /**
     * Sort given $locations as defined by the given $relatedContentIds.
     *
     * @param int[]|string[] $relatedContentIds
     * @param \eZ\Publish\API\Repository\Values\Content\Location[] $locations
     * @param string $sortDirection
     */
    private function sortLocationsByField(
        array $relatedContentIds,
        array &$locations,
        string $sortDirection
    ): void {
        $sortMap = array_flip($relatedContentIds);

        usort(
            $locations,
            static function (Location $location1, Location $location2) use ($sortMap, $sortDirection): int {
                if ($location1->contentId === $location2->contentId) {
                    return 0;
                }

                if ($sortDirection === LocationQuery::SORT_ASC) {
                    return $sortMap[$location1->contentId] <=> $sortMap[$location2->contentId];
                }

                return $sortMap[$location2->contentId] <=> $sortMap[$location1->contentId];
            }
        );
    }

    /**
     * Return filtered offset value to use.
     */
    private function getOffset(int $offset): int
    {
        return $offset >= 0 ? $offset : 0;
    }

    /**
     * Return filtered limit value to use.
     */
    private function getLimit(?int $limit = null): ?int
    {
        if (is_int($limit) && $limit >= 0) {
            return $limit;
        }

        return null;
    }

    /**
     * Returns a list of related Content IDs defined in the given collection $query.
     *
     * @return int[]|string[]
     */
    private function getRelatedContentIds(Query $query): array
    {
        $content = $this->getSelectedContent($query);

        if ($content === null) {
            return [];
        }

        $fieldDefinitionIdentifier = $query->getParameter('field_definition_identifier')->getValue();
        $fieldValue = $content->getFieldValue($fieldDefinitionIdentifier);

        if ($fieldValue === null || !$fieldValue instanceof RelationListValue) {
            return [];
        }

        return $fieldValue->destinationContentIds;
    }

    /**
     * Returns the selected Content item.
     */
    private function getSelectedContent(Query $query): ?Content
    {
        if ($query->getParameter('use_current_location')->getValue() === true) {
            return $this->contentProvider->provideContent();
        }

        $locationId = $query->getParameter('location_id')->getValue();
        if ($locationId === null) {
            return null;
        }

        try {
            return $this->locationService->loadLocation($locationId)->getContent();
        } catch (Throwable $t) {
            return null;
        }
    }

    /**
     * Builds the Location query from given parameters.
     */
    private function buildLocationQuery(
        array $relatedContentIds,
        Query $query,
        bool $buildCountQuery = false,
        int $offset = 0,
        ?int $limit = null
    ): LocationQuery {
        $locationQuery = new LocationQuery();
        $offset = $this->getOffset($offset);
        $limit = $this->getLimit($limit);
        $sortType = $query->getParameter('sort_type')->getValue() ?? 'default';
        $sortDirection = $query->getParameter('sort_direction')->getValue() ?? LocationQuery::SORT_DESC;

        if ($sortType === 'defined_by_field') {
            $relatedContentIds = array_slice($relatedContentIds, $offset, $limit);
        }

        $criteria = [
            new Criterion\ContentId($relatedContentIds),
            new Criterion\Visibility(Criterion\Visibility::VISIBLE),
        ];

        if ($query->getParameter('only_main_locations')->getValue() === true) {
            $criteria[] = new Criterion\Location\IsMainLocation(
                Criterion\Location\IsMainLocation::MAIN
            );
        }

        if ($query->getParameter('filter_by_content_type')->getValue() === true) {
            $contentTypes = $query->getParameter('content_types')->getValue();
            if (is_array($contentTypes) && count($contentTypes) > 0) {
                $contentTypeFilter = new Criterion\ContentTypeId(
                    $this->getContentTypeIds($contentTypes)
                );

                if ($query->getParameter('content_types_filter')->getValue() === 'exclude') {
                    $contentTypeFilter = new Criterion\LogicalNot($contentTypeFilter);
                }

                $criteria[] = $contentTypeFilter;
            }
        }

        $locationQuery->filter = new Criterion\LogicalAnd($criteria);

        $locationQuery->limit = 0;
        if (!$buildCountQuery) {
            $locationQuery->offset = $offset;
            if (is_int($limit)) {
                $locationQuery->limit = $limit;
            }
        }

        if ($sortType !== 'defined_by_field') {
            $locationQuery->sortClauses = [
                new self::$sortClauses[$sortType]($sortDirection),
            ];
        }

        return $locationQuery;
    }
}
