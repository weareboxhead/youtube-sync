<?php

/**
 * @link      https://boxhead.io
 * @copyright Copyright (c) Boxhead
 */

namespace boxhead\youtubesync\jobs;

use craft\helpers\Queue;
use craft\queue\BaseJob;
use boxhead\youtubesync\YouTubeSync;
use boxhead\youtubesync\jobs\YouTubeSyncPlaylistItemsJob;
use boxhead\youtubesync\jobs\YouTubeSyncNoPlaylistItemsJob;
use boxhead\youtubesync\jobs\YouTubeCloseMissingEntriesJob;

class YouTubeSyncJob extends BaseJob
{
    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        // Get local data
        $localData = YouTubeSync::$plugin->youTubeSyncService->getLocalData(null);

        // Setup playlists
        $ytPlaylists = YouTubeSync::$plugin->youTubeSyncService->getPlaylists();
        $playlistsCount = count($ytPlaylists) + 1; // Add 1 to account for additional get all video Ids step
        $playlistVideoIds = [];

        // Get all ids for videos uploaded to YouTube channel
        $this->setProgress(
            $queue,
            0 / $playlistsCount,
            \Craft::t('app', 'Getting YouTube video IDs', [
                'step' => 1,
                'total' => $playlistsCount,
            ])
        );

        $channelVideoIds = YouTubeSync::$plugin->youTubeSyncService->getAllChannelVideos();

        if ($ytPlaylists) {
            // Loop over each YT playlist
            foreach ($ytPlaylists as $i => $ytPlaylist) {
                $this->setProgress(
                    $queue,
                    $i / $playlistsCount,
                    \Craft::t('app', 'Syncing playlist {step, number} of {total, number}', [
                        'step' => $i + 2,
                        'total' => $playlistsCount,
                    ])
                );

                // Create/Update a Craft playlist category
                $playlistCategory = YouTubeSync::$plugin->youTubeSyncService->parsePlaylist($ytPlaylist);

                // Sync all the playlist items
                // Queue::push(new YouTubeSyncPlaylistItemsJob($ytPlaylist->id, $playlistCategory, $localData));
                $playlistVideoData = YouTubeSync::$plugin->youTubeSyncService->getPlaylistItems($ytPlaylist->id, $playlistCategory->id,);
                $playlistVideoIds = array_merge($playlistVideoIds, $playlistVideoData['playlistVideoIds']);

                Queue::push(new YouTubeSyncPlaylistItemsJob($playlistVideoData['playlistVideos'], $playlistCategory, $localData));
            }
        }

        // Compare the videos that don't exist within user created playlists
        $noPlaylistVideoIds = array_diff($channelVideoIds, $playlistVideoIds);

        // Sync videos that don't have a playlist
        Queue::push(new YouTubeSyncNoPlaylistItemsJob($noPlaylistVideoIds, $localData));

        $missingVideoIds = array_diff($localData['ids'], $channelVideoIds);
        $closeEntryIds = [];

        foreach ($missingVideoIds as $missingVideoId) {
            $closeEntryIds[] = $localData['videos'][$missingVideoId];
        }

        Queue::push(new YouTubeCloseMissingEntriesJob($closeEntryIds));
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): string
    {
        return \Craft::t('app', 'Syncing YouTube playlist and video data');
    }
}
