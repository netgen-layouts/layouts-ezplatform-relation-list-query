<?php

declare(strict_types=1);

namespace Netgen\Layouts\Ez\RelationListQuery\Handler\Traits;

use eZ\Publish\API\Repository\LocationService;
use eZ\Publish\API\Repository\Values\Content\Content;
use Netgen\Layouts\API\Values\Collection\Query;
use Netgen\Layouts\Ez\ContentProvider\ContentProviderInterface;
use Throwable;

trait SelectedContentTrait
{
    private LocationService $locationService;

    private ContentProviderInterface $contentProvider;

    /**
     * Returns the selected Content item.
     *
     * @param string[] $languages
     */
    private function getSelectedContent(Query $query, ?array $languages = null): ?Content
    {
        if ($query->getParameter('use_current_location')->getValue() === true) {
            return $this->contentProvider->provideContent();
        }

        $locationId = $query->getParameter('location_id')->getValue();
        if ($locationId === null) {
            return null;
        }

        try {
            return $this->locationService->loadLocation((int) $locationId, $languages)->getContent();
        } catch (Throwable $t) {
            return null;
        }
    }
}
