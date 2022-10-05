<?php

namespace boxhead\youtubesync\services;

use boxhead\youtubesync\YouTubeSync;
use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\Category;
use craft\helpers\DateTimeHelper;
use Google_Client;
use Google_Service_YouTube;
use DateInterval;

/**
 * @author    Boxhead
 * @package   YouTubeSync
 */
class YouTubeSyncService extends Component
{
    private $settings;
    private $service;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function __construct()
    {
        // Check for all required settings
        $this->checkSettings();
    }

    public function getLocalData($limit = 2000)
    {
        // Create a Craft Element Criteria Model
        $query = Entry::find()
            ->sectionId($this->settings->sectionId)
            ->limit($limit)
            ->status(null)
            ->all();

        $data = array(
            'ids'      =>  [],
            'videos'   =>  []
        );

        // For each entry
        foreach ($query as $entry) {
            $youtubeVideoId = "";

            // Get the id of this video
            if (isset($entry->ytVideoId)) {
                $youtubeVideoId = $entry->ytVideoId;
            }

            // Add this id to our array
            $data['ids'][] = $youtubeVideoId;

            // Add this entry id to our array, using the video id as the key for reference
            $data['videos'][$youtubeVideoId] = $entry->id;
        }

        return $data;
    }

    public function getPlaylists()
    {
        // Setup the YouTube connection
        $this->createService();

        $items = [];

        // Get all YouTube Playlists for the channel specified in settings
        do {
            $playlistsResponse = $this->service->playlists->listPlaylists(
                'snippet',
                [
                    'channelId'     => $this->settings->channelId,
                    'maxResults'    => 50,
                    'pageToken'     => (isset($playlistsResponse->nextPageToken) && !empty($playlistsResponse->nextPageToken)) ? $playlistsResponse->nextPageToken : ''
                ]
            );

            $items = array_merge($items, $playlistsResponse->items);
        } while (!empty($playlistsResponse->nextPageToken));

        return $items;
    }

    public function parsePlaylist($playlist) {
        // If there is no category group specified, don't do this
        if (! $this->settings->youtubePlaylistsCategoryGroupId) {
            return;
        }

        $categorySet = false;

        // Get all existing categories from our playlist category group
        $query = Category::find()
            ->groupId($this->settings->youtubePlaylistsCategoryGroupId)
            ->all();

        // For each existing category
        foreach ($query as $category) {
            // If this playlist is already one of our existing categories flag it
            if ($category->ytPlaylistId == $playlist->id) {
                $categorySet = true;
                break;
            }
        }

        if (!$categorySet) {
            // Create the category
            $category = new Category();

            $category->groupId = $this->settings->youtubePlaylistsCategoryGroupId;
        }

        // Always Set/Update the field values so they remain in sync
        $category->title = $playlist->snippet->title;

        $category->setFieldValues([
            'ytPlaylistId'          => $playlist->id,
            'ytPlaylistDescription' => (!empty($playlist->snippet->description)) ? $playlist->snippet->description : '',
            'ytPlaylistImageMaxRes' => (isset($playlist->snippet->thumbnails->maxres->url) ? $playlist->snippet->thumbnails->maxres->url : isset($playlist->snippet->thumbnails->standard->url)) ? $playlist->snippet->thumbnails->standard->url : $playlist->snippet->thumbnails->high->url,
        ]);

        // Save the category!
        if (!Craft::$app->elements->saveElement($category)) {
            Craft::error('YouTubeSync: Couldn’t save the category "' . $category->title . '"', __METHOD__);

            return false;
        }

        return $category;
    }

    public function getPlaylistItems($ytPlaylistId, $categoryId)
    {
        // Setup the YouTube connection
        $this->createService();

        // Now all our playlists are setup as categories we need to get all the videoIds within them
        // Get all YouTube Playlist Items for each playlist
        $playlistVideoIds = [];
        $playlistVideos = [];

        do {
            $playlistItemsResponse = $this->service->playlistItems->listPlaylistItems(
                'snippet',
                [
                    'playlistId'    => $ytPlaylistId,
                    'maxResults'    => 50,
                    'pageToken'     => (isset($playlistItemsResponse->nextPageToken) && !empty($playlistItemsResponse->nextPageToken)) ? $playlistItemsResponse->nextPageToken : ''
                ]
            );

            // Loop over each playlist item
            foreach ($playlistItemsResponse->items as $item) {
                $playlistVideoIds[] = $item->snippet->resourceId->videoId;
            }
        } while (!empty($playlistItemsResponse->nextPageToken));

        // Request the full details of all videos associated with each user playlist
        if (!empty($playlistVideoIds)) {
            // Split $playlistVideoIds into chunks of 50 - Due to YouTube limitations
            $videoIdChunks = array_chunk($playlistVideoIds, 50);

            // Keep requesting video items until there are no more pages of videos
            foreach ($videoIdChunks as $idChunk) {
                $videosResponse = $this->service->videos->listVideos(
                    'snippet, contentDetails',
                    [
                        'id' => implode(',', $idChunk)
                    ]
                );

                // Push all items onto the $playlistsVideos array
                $playlistVideos = array_merge($playlistVideos, $videosResponse->items);
            }
        }

        return [
            'playlistVideoIds' => $playlistVideoIds,
            'playlistVideos' => $playlistVideos
        ];
    }

    public function getNoPlaylistItems($noPlaylistVideoIds)
    {
        // Setup the YouTube connection
        $this->createService();

        $noPlaylistVideos = [];

        if (!empty($noPlaylistVideoIds)) {
            // Split $videoIds into chunks of 50 - Due to YouTube limitations
            $videoIdChunks = array_chunk($noPlaylistVideoIds, 50);

            foreach ($videoIdChunks as $idChunk) {
                $otherVideosResponse = $this->service->videos->listVideos(
                    'snippet, contentDetails',
                    [
                        'id' => implode(',', $idChunk)
                    ]
                );

                // Push all items onto the main $videos array grouped by youtubePlaylistId
                $noPlaylistVideos = array_merge($noPlaylistVideos, $otherVideosResponse->items);
            }
        }

        return $noPlaylistVideos;
    }

    public function getAllChannelVideos()
    {
        // Setup the YouTube connection
        $this->createService();

        // Get all uploads for this channel
        // Get the 'uploads' playlist ID
        $channelsResponse = $this->service->channels->listChannels(
            'contentDetails',
            [
                'id' => $this->settings->channelId
            ]
        );

        $channels = $channelsResponse->items;

        // Get all playlist items for the 'uploads' playlist
        $channelVideoIds = [];

        // Only try to get the uploads videoIds if there is in fact a channel returned
        if (!empty($channels) && !empty($channels[0]->contentDetails->relatedPlaylists->uploads)) {
            // Keep requesting playlist items until there are no more pages of items
            do {
                $channelItemsResponse = $this->service->playlistItems->listPlaylistItems(
                    'snippet',
                    [
                        'playlistId'     => $channels[0]->contentDetails->relatedPlaylists->uploads,
                        // 'playlistId'     => $this->settings->channelId,
                        'maxResults'    => 50,
                        'pageToken'     => (isset($channelItemsResponse->nextPageToken) && !empty($channelItemsResponse->nextPageToken)) ? $channelItemsResponse->nextPageToken : ''
                    ]
                );

                // Loop over item and add to our array of channel video Ids
                foreach ($channelItemsResponse->items as $item) {
                    // $channelVideoIds[$item->snippet->resourceId->videoId] = $item->snippet->playlistId;
                    $channelVideoIds[] = $item->snippet->resourceId->videoId;
                }
            } while (!empty($channelItemsResponse->nextPageToken));
        }

        return $channelVideoIds;
    }

    // public function closeEntries($entryIds)
    // {
    //     if (count($entryIds)) {
    //         foreach ($entryIds as $id) {
    //             $this->closeEntry($id);
    //         }
    //     }
    // }

    public function closeEntry($entryId)
    {
        // Create a new instance of the Craft Entry Model
        $entry = Entry::find()
            ->sectionId($this->settings->sectionId)
            ->id($entryId)
            ->status(null)
            ->one();

        $entry->enabled = false;

        // Re-save the entry
        Craft::$app->elements->saveElement($entry);
    }

    public function parseVideo($video, $entryId = null, $playlistCategoryId = null)
    {
        // Search for existing entry
        $entry = Entry::find()
            ->sectionId($this->settings->sectionId)
            ->id($entryId)
            ->status(null)
            ->one();

        if (!$entry) {
            // Create a new instance of the Craft Entry Model
            $entry = new Entry();

            // Set the section id
            $entry->sectionId = $this->settings->sectionId;

            // Set the entry type
            $entry->typeId = $this->settings->entryTypeId;

            // Set the author as super admin
            $entry->authorId = 1;
        }

        $this->saveFieldData($entry, $video, $playlistCategoryId);
    }


    // Private Methods
    // =========================================================================

    private function dd($data)
    {
        echo '<pre>';
        print_r($data);
        echo '</pre>';
        die();
    }


    private function checkSettings()
    {
        $this->settings = YouTubeSync::$plugin->getSettings();

        // Check our Plugin's settings for the apiKey
        if ($this->settings->apiKey === null) {
            Craft::error('YouTubeSync: No API Key provided in settings', __METHOD__);

            return false;
        }

        // Check our Plugin's settings for the channelId
        if ($this->settings->channelId === null) {
            Craft::error('YouTubeSync: No Youtube Channel ID provided in settings', __METHOD__);

            return false;
        }

        if (!$this->settings->sectionId) {
            Craft::error('YouTubeSync: No Section ID provided in settings', __METHOD__);

            return false;
        }

        if (!$this->settings->entryTypeId) {
            Craft::error('YouTubeSync: No Entry Type ID provided in settings', __METHOD__);

            return false;
        }

        if (!$this->settings->youtubePlaylistsCategoryGroupId) {
            Craft::error('YouTubeSync: No YouTube Playlists Category Group ID provided in settings', __METHOD__);

            return false;
        }
    }

    private function saveFieldData($entry, $data, $playlistCategoryId)
    {
        // Set all videos to closed by default unless previously enabled
        $entry->enabled = ($entry->enabled) ? true : false;

        // Set the title
        $entry->title = $data->snippet->title;

        // Set the other content
        $entry->setFieldValues([
            'ytVideoId'     => $data->id,
            'ytDescription' => (!empty($data->snippet->description)) ? $data->snippet->description : '',
            'ytDuration'    => $this->convertTime($data->contentDetails->duration),
            'ytImageMaxRes' => (isset($data->snippet->thumbnails->maxres->url)) ? $data->snippet->thumbnails->maxres->url : $data->snippet->thumbnails->high->url,
            'ytPlaylists'   => ($playlistCategoryId !== null) ? [$playlistCategoryId] : []
        ]);

        // Save the entry!
        if (!Craft::$app->elements->saveElement($entry)) {
            Craft::error('YouTubeSync: Couldn\'t save the entry "' . $entry->title . '"', __METHOD__);

            return false;
        }

        // Set the postdate to the publishedAt date
        $entry->postDate = DateTimeHelper::toDateTime(strtotime($data->snippet->publishedAt));

        // Re-save the entry
        Craft::$app->elements->saveElement($entry);
    }

    /**
     * Returns a YouTube API service
     *
     * @return Google_Service_YouTube
     */
    private function createService()
    {
        $client = new Google_Client();

        $client->setApplicationName("Craft 3 CMS");

        $client->setDeveloperKey($this->settings->apiKey);

        $this->service = new Google_Service_YouTube($client);
    }

    /**
     * Returns HH:MM:SS formatted time from YouTube duration
     */
    private function convertTime($youtubeTime)
    {
        $di = new DateInterval($youtubeTime);
        $output = '';

        if ($di->h > 0) {
            $hours = ($di->h < 10 ) ? '0' . $di->h : $di->h;

            $output .= $hours . ':';
        }

        $mins = ($di->i < 10 ) ? '0' . $di->i : $di->i;
        $secs = ($di->s < 10 ) ? '0' . $di->s : $di->s;

        return $output . $mins . ':' . $secs;
    }
}
