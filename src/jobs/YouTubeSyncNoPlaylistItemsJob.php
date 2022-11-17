<?php

/**
 * @link      https://boxhead.io
 * @copyright Copyright (c) Boxhead
 */

namespace boxhead\youtubesync\jobs;

use craft\helpers\Queue;
use craft\queue\BaseJob;
use boxhead\youtubesync\YouTubeSync;
use boxhead\youtubesync\jobs\YouTubeCreateUpdateEntryJob;

class YouTubeSyncNoPlaylistItemsJob extends BaseJob
{
    private ?array $noPlaylistVideoIds;
    private ?array $localData;

    /**
     * @inheritdoc
     */
    public function __construct($noPlaylistVideoIds, $localData)
    {
        $this->noPlaylistVideoIds = $noPlaylistVideoIds;
        $this->localData = $localData;
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        // Get all videos not a part of a playlist
        $noPlaylistVideos = YouTubeSync::$plugin->youTubeSyncService->getNoPlaylistItems($this->noPlaylistVideoIds);

        foreach ($noPlaylistVideos as $video) {
            if (in_array($video->id, $this->localData['ids'])) {
                $entryId = $this->localData['videos'][$video->id];
            } else {
                $entryId = null;
            }

            Queue::push(new YouTubeCreateUpdateEntryJob($video, $entryId));
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return \Craft::t('app', 'Syncing YouTube Videos that aren\'t in a playlist');
    }
}
