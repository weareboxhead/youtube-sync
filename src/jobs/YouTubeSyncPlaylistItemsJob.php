<?php

/**
 * @link      https://boxhead.io
 * @copyright Copyright (c) Boxhead
 */

namespace boxhead\youtubesync\jobs;

use craft\helpers\Queue;
use craft\queue\BaseJob;
use boxhead\youtubesync\jobs\YouTubeCreateUpdateEntryJob;

class YouTubeSyncPlaylistItemsJob extends BaseJob
{
    private $videos;
    private $playlistCategory;
    private $localData;

    /**
     * @inheritdoc
     */
    public function __construct($videos, $playlistCategory, $localData)
    {
        $this->videos = $videos;
        $this->playlistCategory = $playlistCategory;
        $this->localData = $localData;
    }

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        // Get all videos not a part of a playlist
        foreach ($this->videos as $video) {
            if (in_array($video->id, $this->localData['ids'])) {
                $entryId = $this->localData['videos'][$video->id];
            } else {
                $entryId = null;
            }

            Queue::push(new YouTubeCreateUpdateEntryJob($video, $entryId, $this->playlistCategory->id));
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return \Craft::t('app', 'Syncing YouTube videos in playlist - "' . $this->playlistCategory->title . '"');
    }
}
