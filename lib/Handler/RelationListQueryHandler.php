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
use Netgen\BlockManager\Version;

/**
 * Query handler implementation providing values through eZ Platform relation list field.
 */
class RelationListQueryHandler implements QueryTypeHandlerInterface
{
    /**
     * @var int
     */
    const DEFAULT_LIMIT = 25;

    /**
     * @var \eZ\Publish\API\Repository\LocationService
     */
    protected $locationService;

    /**
     * @var \eZ\Publish\API\Repository\ContentService
     */
    protected $contentService;

    /**
     * @var \eZ\Publish\API\Repository\SearchService
     */
    protected $searchService;

    /**
     * @var \eZ\Publish\SPI\Persistence\Content\Type\Handler
     */
    protected $contentTypeHandler;

    /**
     * @var \eZ\Publish\Core\Helper\TranslationHelper
     */
    protected $translationHelper;

    /**
     * @var \Netgen\BlockManager\Ez\ContentProvider\ContentProviderInterface
     */
    protected $contentProvider;

    /**
     * Injected list of prioritized languages.
     *
     * @var array
     */
    protected $languages = array();

    /**
     * @var array
     */
    protected $sortClauses = array(
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

    /**
     * @var array
     */
    protected $sortDirections = array(
        Location::SORT_ORDER_ASC => LocationQuery::SORT_ASC,
        Location::SORT_ORDER_DESC => LocationQuery::SORT_DESC,
    );

    /**
     * @var array
     */
    protected $advancedGroups = array();

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

        if (Version::VERSION_ID >= 800) {
            $this->advancedGroups = array('advanced');
        }
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
            EzParameterType\LocationType::class
        );

        $builder->add(
            'field_definition_identifier',
            ParameterType\TextLineType::class,
            array(
                'required' => true,
                'default_value' => 'Text',
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
            'limit',
            ParameterType\IntegerType::class,
            array(
                'min' => 0,
            )
        );

        $builder->add(
            'offset',
            ParameterType\IntegerType::class,
            array(
                'min' => 0,
                'groups' => $this->advancedGroups,
            )
        );

        $builder->add(
            'only_main_locations',
            ParameterType\BooleanType::class,
            array(
                'default_value' => true,
                'groups' => $this->advancedGroups,
            )
        );

        $builder->add(
            'filter_by_content_type',
            ParameterType\Compound\BooleanType::class,
            array(
                'groups' => $this->advancedGroups,
            )
        );

        $builder->get('filter_by_content_type')->add(
            'content_types',
            EzParameterType\ContentTypeType::class,
            array(
                'multiple' => true,
                'groups' => $this->advancedGroups,
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
                'groups' => $this->advancedGroups,
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

        $searchResult = $this->searchService->findLocations(
            $this->buildQuery($relatedContentIds, $query),
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
            $this->buildQuery($relatedContentIds, $query, true),
            array('languages' => $this->languages)
        );

        return $searchResult->totalCount;
    }

    public function getInternalLimit(Query $query)
    {
        $limit = $query->getParameter('limit')->getValue();

        if (!is_int($limit)) {
            return self::DEFAULT_LIMIT;
        }

        return $limit >= 0 ? $limit : self::DEFAULT_LIMIT;
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
    protected function getContentTypeIds(array $contentTypeIdentifiers)
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
     * Return offset value to use from the given collection $query.
     *
     * @param \Netgen\BlockManager\API\Values\Collection\Query $query
     *
     * @return int
     */
    private function getOffset(Query $query)
    {
        $offset = $query->getParameter('offset')->getValue();

        if (is_int($offset) && $offset >= 0) {
            return $offset;
        }

        return 0;
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
     *
     * @return \eZ\Publish\API\Repository\Values\Content\LocationQuery
     */
    private function buildQuery(array $relatedContentIds, Query $query, $buildCountQuery = false)
    {
        $locationQuery = new LocationQuery();
        $internalLimit = $this->getInternalLimit($query);
        $offset = $this->getOffset($query);
        $sortType = $query->getParameter('sort_type')->getValue() ?: 'default';
        $sortDirection = $query->getParameter('sort_direction')->getValue() ?: LocationQuery::SORT_DESC;

        if ($sortType === 'defined_by_field') {
            $relatedContentIds = array_slice($relatedContentIds, $offset, $internalLimit);
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
            $locationQuery->limit = $internalLimit;
        }

        if ($sortType !== 'defined_by_field') {
            $locationQuery->sortClauses = array(
                new $this->sortClauses[$sortType]($sortDirection),
            );
        }

        return $locationQuery;
    }
}
