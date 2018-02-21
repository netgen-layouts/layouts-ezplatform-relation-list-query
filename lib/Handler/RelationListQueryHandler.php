<?php

namespace Netgen\Layouts\RelationListQuery\Handler;

use Exception;
use eZ\Publish\API\Repository\ContentService;
use eZ\Publish\API\Repository\Exceptions\NotFoundException;
use eZ\Publish\API\Repository\LocationService;
use eZ\Publish\API\Repository\SearchService;
use eZ\Publish\API\Repository\Values\Content\Location;
use eZ\Publish\API\Repository\Values\Content\LocationQuery;
use eZ\Publish\API\Repository\Values\Content\Query\Criterion;
use eZ\Publish\API\Repository\Values\Content\Query\SortClause;
use eZ\Publish\API\Repository\Values\Content\Search\SearchHit;
use eZ\Publish\Core\FieldType\RelationList\Value as RelationListValue;
use eZ\Publish\Core\Helper\TranslationHelper;
use eZ\Publish\SPI\Persistence\Content\Type\Handler;
use Netgen\BlockManager\API\Values\Collection\Query;
use Netgen\BlockManager\Collection\QueryType\QueryTypeHandlerInterface;
use Netgen\BlockManager\Ez\ContentProvider\ContentProviderInterface;
use Netgen\BlockManager\Ez\Parameters\ParameterType as EzParameterType;
use Netgen\BlockManager\Parameters\ParameterBuilderInterface;
use Netgen\BlockManager\Parameters\ParameterType;

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
     * @var \eZ\Publish\API\Repository\ContentService
     */
    private $contentService;

    /**
     * @var \eZ\Publish\API\Repository\SearchService
     */
    private $searchService;

    /**
     * @var \eZ\Publish\SPI\Persistence\Content\Type\Handler
     */
    private $contentTypeHandler;

    /**
     * @var \eZ\Publish\Core\Helper\TranslationHelper
     */
    private $translationHelper;

    /**
     * @var \Netgen\BlockManager\Ez\ContentProvider\ContentProviderInterface
     */
    private $contentProvider;

    /**
     * Injected list of prioritized languages.
     *
     * @var array
     */
    private $languages = array();

    /**
     * @var array
     */
    private $sortClauses = array(
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
    );

    public function __construct(
        LocationService $locationService,
        ContentService $contentService,
        SearchService $searchService,
        Handler $contentTypeHandler,
        TranslationHelper $translationHelper,
        ContentProviderInterface $contentProvider
    ) {
        $this->locationService = $locationService;
        $this->contentService = $contentService;
        $this->searchService = $searchService;
        $this->contentTypeHandler = $contentTypeHandler;
        $this->translationHelper = $translationHelper;
        $this->contentProvider = $contentProvider;
    }

    /**
     * Sets the current siteaccess languages into the handler.
     *
     * @param array $languages
     */
    public function setLanguages(array $languages = null)
    {
        $this->languages = is_array($languages) ? $languages : array();
    }

    public function buildParameters(ParameterBuilderInterface $builder)
    {
        $builder->add(
            'use_current_location',
            ParameterType\Compound\BooleanType::class,
            array(
                'reverse' => true,
            )
        );

        $builder->get('use_current_location')->add(
            'location_id',
            EzParameterType\LocationType::class,
            array(
                'allow_invalid' => true,
            )
        );

        $builder->add(
            'field_definition_identifier',
            ParameterType\TextLineType::class,
            array(
                'required' => true,
            )
        );

        $builder->add(
            'sort_type',
            ParameterType\ChoiceType::class,
            array(
                'required' => true,
                'options' => array(
                    'Defined by field' => 'defined_by_field',
                    'Published' => 'date_published',
                    'Modified' => 'date_modified',
                    'Alphabetical' => 'content_name',
                ),
            )
        );

        $builder->add(
            'sort_direction',
            ParameterType\ChoiceType::class,
            array(
                'required' => true,
                'options' => array(
                    'Descending' => LocationQuery::SORT_DESC,
                    'Ascending' => LocationQuery::SORT_ASC,
                ),
            )
        );

        $builder->add(
            'only_main_locations',
            ParameterType\BooleanType::class,
            array(
                'default_value' => true,
                'groups' => array(self::GROUP_ADVANCED),
            )
        );

        $builder->add(
            'filter_by_content_type',
            ParameterType\Compound\BooleanType::class,
            array(
                'groups' => array(self::GROUP_ADVANCED),
            )
        );

        $builder->get('filter_by_content_type')->add(
            'content_types',
            EzParameterType\ContentTypeType::class,
            array(
                'multiple' => true,
                'groups' => array(self::GROUP_ADVANCED),
            )
        );

        $builder->get('filter_by_content_type')->add(
            'content_types_filter',
            ParameterType\ChoiceType::class,
            array(
                'required' => true,
                'options' => array(
                    'Include content types' => 'include',
                    'Exclude content types' => 'exclude',
                ),
                'groups' => array(self::GROUP_ADVANCED),
            )
        );
    }

    public function getValues(Query $query, $offset = 0, $limit = null)
    {
        $relatedContentIds = $this->getRelatedContentIds($query);
        $sortType = $query->getParameter('sort_type')->getValue() ?: 'default';
        $sortDirection = $query->getParameter('sort_direction')->getValue() ?: LocationQuery::SORT_DESC;

        if (count($relatedContentIds) === 0) {
            return array();
        }

        $locationQuery = $this->buildLocationQuery($relatedContentIds, $query, false, $offset, $limit);
        $locationQuery->performCount = false;

        $searchResult = $this->searchService->findLocations(
            $locationQuery,
            array('languages' => $this->languages)
        );

        $locations = array_map(
            function (SearchHit $searchHit) {
                return $searchHit->valueObject;
            },
            $searchResult->searchHits
        );

        if ($sortType === 'defined_by_field') {
            $this->sortLocationsByField($relatedContentIds, $locations, $sortDirection);
        }

        return $locations;
    }

    public function getCount(Query $query)
    {
        $relatedContentIds = $this->getRelatedContentIds($query);

        if (count($relatedContentIds) === 0) {
            return 0;
        }

        $searchResult = $this->searchService->findLocations(
            $this->buildLocationQuery($relatedContentIds, $query, true),
            array('languages' => $this->languages)
        );

        return $searchResult->totalCount;
    }

    public function isContextual(Query $query)
    {
        return $query->getParameter('use_current_location')->getValue() === true;
    }

    /**
     * Returns content type IDs for all existing content types.
     *
     * @param array $contentTypeIdentifiers
     *
     * @return array
     */
    private function getContentTypeIds(array $contentTypeIdentifiers)
    {
        $idList = array();

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
     * @param mixed $sortDirection
     */
    private function sortLocationsByField(
        array $relatedContentIds,
        array &$locations,
        $sortDirection
    ) {
        $sortMap = array_flip($relatedContentIds);

        usort(
            $locations,
            function (Location $location1, Location $location2) use ($sortMap, $sortDirection) {
                if ($location1->contentId === $location2->contentId) {
                    return 0;
                }

                if ($sortDirection === LocationQuery::SORT_ASC) {
                    return ($sortMap[$location1->contentId] < $sortMap[$location2->contentId]) ? -1 : 1;
                }

                return ($sortMap[$location1->contentId] > $sortMap[$location2->contentId]) ? -1 : 1;
            }
        );
    }

    /**
     * Return filtered offset value to use.
     *
     * @param int $offset
     *
     * @return int
     */
    private function getOffset($offset)
    {
        if (is_int($offset) && $offset >= 0) {
            return $offset;
        }

        return 0;
    }

    /**
     * Return filtered limit value to use.
     *
     * @param int $limit
     *
     * @return int
     */
    private function getLimit($limit)
    {
        if (is_int($limit) && $limit >= 0) {
            return $limit;
        }

        return null;
    }

    /**
     * Returns a list of related Content IDs defined in the given collection $query.
     *
     * @param \Netgen\BlockManager\API\Values\Collection\Query $query
     *
     * @return int[]|string[]
     */
    private function getRelatedContentIds(Query $query)
    {
        $content = $this->getSelectedContent($query);

        if ($content === null) {
            return array();
        }

        $fieldDefinitionIdentifier = $query->getParameter('field_definition_identifier')->getValue();

        $field = $this->translationHelper->getTranslatedField(
            $content,
            $fieldDefinitionIdentifier
        );

        if ($field === null || !$field->value instanceof RelationListValue) {
            return array();
        }

        return $field->value->destinationContentIds;
    }

    /**
     * Returns the selected Content item.
     *
     * @param \Netgen\BlockManager\API\Values\Collection\Query $query
     *
     * @return \eZ\Publish\API\Repository\Values\Content\Content|null
     */
    private function getSelectedContent(Query $query)
    {
        if ($query->getParameter('use_current_location')->getValue()) {
            return $this->contentProvider->provideContent();
        }

        $locationId = $query->getParameter('location_id')->getValue();
        if (empty($locationId)) {
            return null;
        }

        try {
            $location = $this->locationService->loadLocation($locationId);

            return $this->contentService->loadContent($location->contentId, $this->languages);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Builds the Location query from given parameters.
     *
     * @param array $relatedContentIds
     * @param \Netgen\BlockManager\API\Values\Collection\Query $query
     * @param bool $buildCountQuery
     * @param int $offset
     * @param int $limit
     *
     * @return \eZ\Publish\API\Repository\Values\Content\LocationQuery
     */
    private function buildLocationQuery(array $relatedContentIds, Query $query, $buildCountQuery = false, $offset = 0, $limit = null)
    {
        $locationQuery = new LocationQuery();
        $offset = $this->getOffset($offset);
        $limit = $this->getLimit($limit);
        $sortType = $query->getParameter('sort_type')->getValue() ?: 'default';
        $sortDirection = $query->getParameter('sort_direction')->getValue() ?: LocationQuery::SORT_DESC;

        if ($sortType === 'defined_by_field') {
            $relatedContentIds = array_slice($relatedContentIds, $offset, $limit);
        }

        $criteria = array(
            new Criterion\ContentId($relatedContentIds),
            new Criterion\Visibility(Criterion\Visibility::VISIBLE),
        );

        if ($query->getParameter('only_main_locations')->getValue()) {
            $criteria[] = new Criterion\Location\IsMainLocation(
                Criterion\Location\IsMainLocation::MAIN
            );
        }

        if ($query->getParameter('filter_by_content_type')->getValue()) {
            $contentTypes = $query->getParameter('content_types')->getValue();
            if (!empty($contentTypes)) {
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
            $locationQuery->limit = $limit;
        }

        if ($sortType !== 'defined_by_field') {
            $locationQuery->sortClauses = array(
                new $this->sortClauses[$sortType]($sortDirection),
            );
        }

        return $locationQuery;
    }
}
