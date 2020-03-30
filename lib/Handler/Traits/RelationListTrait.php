<?php

declare(strict_types=1);

namespace Netgen\Layouts\Ez\RelationListQuery\Handler\Traits;

use eZ\Publish\API\Repository\Values\Content\Content;
use eZ\Publish\API\Repository\Values\Content\Location;
use eZ\Publish\API\Repository\Values\Content\Query\SortClause;
use Netgen\Layouts\API\Values\Collection\Query;
use Throwable;

trait RelationListTrait
{
    /**
     * @var \eZ\Publish\API\Repository\LocationService
     */
    private $locationService;

    /**
     * @var \Netgen\Layouts\Ez\ContentProvider\ContentProviderInterface
     */
    private $contentProvider;

    /**
     * @var class-string[]
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

    public function isContextual(Query $query): bool
    {
        return $query->getParameter('use_current_location')->getValue() === true;
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
            return $this->locationService->loadLocation((int) $locationId)->getContent();
        } catch (Throwable $t) {
            return null;
        }
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
}
