<?php

/**
 * @link      https://boxhead.io
 * @copyright Copyright (c) Boxhead
 */

namespace boxhead\youtubesync\jobs;

use craft\queue\BaseJob;
use boxhead\youtubesync\YouTubeSync;

class YouTubeCloseMissingEntriesJob extends BaseJob
{
    private $closeEntryIds;

    /**
     * @inheritdoc
     */
    public function __construct($closeEntryIds)
    {
        $this->closeEntryIds = $closeEntryIds;
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        $totalEntries = count($this->closeEntryIds);

        if ($totalEntries) {
            // Loop over each YT playlist
            foreach ($this->closeEntryIds as $i => $entryId) {
                $this->setProgress(
                    $queue,
                    $i / $totalEntries,
                    \Craft::t('app', '{step, number} of {total, number}', [
                        'step' => $i + 1,
                        'total' => $totalEntries,
                    ])
                );

                // Close any entries for which there is no live data...
                YouTubeSync::$plugin->youTubeSyncService->closeEntry($entryId);
            }
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return \Craft::t('app', 'Closing Craft entries where no YouTube video found');
    }
}
