<?php
/**
 * YouTubeSync plugin for Craft CMS 3.x
 *
 * Communicate and process data from the YouTube Data API
 *
 * @link      https://boxhead.io
 * @copyright Copyright (c) 2018 Boxhead
 */

namespace boxhead\youtubesync\services;

use boxhead\youtubesync\YouTubeSync;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\elements\Category;
use craft\helpers\ElementHelper;
use craft\helpers\DateTimeHelper;
use Google_Client;
use Google_Service_YouTube;
use DateInterval;
// use GuzzleHttp\Client;
// use GuzzleHttp\Exception\BadResponseException;


/**
 * YouTubeSyncService Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 *
 * @author    Boxhead
 * @package   YouTubeSync
 * @since     1.0.0
 */
class YouTubeSyncService extends Component
{
    private $settings;
    private $remoteData;
    private $localData;
    private $service;

    // Public Methods
    // =========================================================================

    /**
     * This function can literally be anything you want, and you can have as many service
     * functions as you want
     *
     * From any other plugin file, call it like this:
     *
     *     YouTubeSync::$plugin->YouTubeSyncService->sync()
     *
     * @return mixed
     */
    public function sync()
    {
        $this->settings = YouTubeSync::$plugin->getSettings();

        // Check for all required settings
        $this->checkSettings();

        // Setup the YouTube connection
        $this->createService();

        // Get local video entry data
        $this->localData = $this->getLocalData();

        // Request & sync data from the API
        $this->remoteData = $this->getAPIData();

        // Determine which entries we shouldn't have by id
        $removedIds = array_diff($this->localData['ids'], $this->remoteData['ids']);

        // If we have local data that doesn't match with anything from remote we should close the local entry
        foreach ($removedIds as $id) {
            $this->closeEntry($this->localData['videos'][$id]);
        }

        Craft::error('YouTubeSync: Finished', __METHOD__);

        return;
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

    private function getAPIData()
    {
        Craft::info('YouTubeSync: Begin sync with API', __METHOD__);

        /*
         * USER PLAYLISTS
         */

        // Get all YouTube Playlists for the channel specified in settings
        $response = $this->service->playlists->listPlaylists(
            'snippet',
            [
                'channelId'     => $this->settings->channelId,
                'maxResults'    => 50
            ]
        );

        $playlists = $response->items;

        // If we can't find any playlists in this channel there's nothing more we can do
        if (!empty($playlists)) {
            $playlistCategories = [];

            // Loop over each playlist
            foreach ($playlists as $playlist) {
                // See if this playlist category already exists within Craft
                $playlistCategories[$playlist->id] = $this->parsePlaylist($playlist);
            }
        }

        // Now all our playlists are setup as categories we need to get all the videoIds within them
        // Get all YouTube Playlist Items for each playlist
        $playlistVideoMeta = [];
        $playlistVideos = [];

        foreach ($playlistCategories as $youtubePlaylistId => $categoryId) {
            $playlistVideos[$youtubePlaylistId] = [];

            do {
                $response = $this->service->playlistItems->listPlaylistItems(
                    'snippet',
                    [
                        'playlistId'    => $youtubePlaylistId,
                        'maxResults'    => 50
                    ]
                );
                
                // Loop over each playlist item
                foreach ($response->items as $item) {
                    $videoId = $item->snippet->resourceId->videoId;

                    $playlistVideoMeta[$videoId] = $youtubePlaylistId;

                    $playlistVideos[$youtubePlaylistId][] = $videoId; 
                }
            } while (!empty($response->nextPage));
        }


        /*
         * CHANNEL UPLOADS PLAYLIST (aka all videos in channel)
         */

        // Now we need to get all uploads for this channel
        // Get the 'uploads' playlist ID
        $response = $this->service->channels->listChannels(
            'contentDetails',
            [
                'id' => $this->settings->channelId
            ]
        );

        $channels = $response->items;

        // Get all playlist items for the 'uploads' playlist
        $channelVideoMeta = [];

        // Only try to get the uploads videoIds if there is in fact a channel returned
        if (!empty($channels) && !empty($channels[0]->contentDetails->relatedPlaylists->uploads)) {
            // Keep requesting playlist items until there are no more pages of items
            do {
                $response = $this->service->playlistItems->listPlaylistItems(
                    'snippet',
                    [
                        'playlistId'     => $channels[0]->contentDetails->relatedPlaylists->uploads,
                        'maxResults'    => 50
                    ]
                );

                // Save all the playlist item video ids to an array so we can batch request
                // the full video data in a future API call    
                // Loop over each playlist item
                foreach ($response->items as $item) {
                    $channelVideoMeta[$item->snippet->resourceId->videoId] = $item->snippet->playlistId;
                }
            } while (!empty($response->nextPage));
        }

        

        // Now we have an id for every video in the channel let's && an id for every video in a user playlist
        // let's run a comparison to find all the videos that don't exist within user created playlists
        $noPlaylistVideoMeta = array_diff_key($channelVideoMeta, $playlistVideoMeta);
        

        /*
         * VIDEOS 
         */

        $playlists = [];

        // Request the full details of all videos associated with each user playlist
        foreach ($playlistVideos as $youtubePlaylistId => $videoIds) {
            $playlists[$youtubePlaylistId] = []; 

            // Keep requesting video items until there are no more pages of videos
            do {
                $response = $this->service->videos->listVideos(
                    'snippet, contentDetails',
                    [
                        'id'            => implode(',', $videoIds),
                        'maxResults'    => 50
                    ]
                );
                
                // Push all items onto the main $videos array grouped by youtubePlaylistId
                $playlists[$youtubePlaylistId] = array_merge($playlists[$youtubePlaylistId], $response->items);
            } while (!empty($response->nextPage));
        }


        // Request the full details of all videos NOT associated with each user playlist
        // Keep requesting video items until there are no more pages of videos
        $videoIds = array_keys($noPlaylistVideoMeta);

        $playlists['noPlaylist'] = []; 

        do {
            $response = $this->service->videos->listVideos(
                'snippet, contentDetails',
                [
                    'id'            => implode(',', $videoIds),
                    'maxResults'    => 50
                ]
            );
            
            // Push all items onto the main $videos array grouped by youtubePlaylistId
            $playlists['noPlaylist'] = array_merge($playlists['noPlaylist'], $response->items);
        } while (!empty($response->nextPage));

        // Loop over and process each playlist
        $videoIds = [];

        foreach ($playlists as $playlistId => $videos) {
            foreach ($videos as $video) {
                $videoIds[] = $video->id;

                // Pass through the Craft Category Id if it exists
                $categoryId = (isset($playlistCategories[$playlistId])) ? $playlistCategories[$playlistId] : NULL;

                $this->parseVideo($video, $categoryId);
            }
        }

        Craft::info('YouTubeSync: Finished syncing remote data', __METHOD__);

        // Return all video ids that exists within the channel
        $data = [
            'ids' => $videoIds
        ];
        
        return $data;
    }


    private function getLocalData()
    {
        Craft::info('YouTubeSync: Query for all YouTube Video entries', __METHOD__);

        // Create a Craft Element Criteria Model
        $query = Entry::find()
            ->sectionId($this->settings->sectionId)
            ->limit(null)
            ->status(null)
            ->all();

        $data = array(
            'ids'      =>  [],
            'videos'   =>  []
        );

        // For each entry
        foreach ($query as $entry)
        {
            $youtubeVideoId = "";

            // Get the id of this video
            if (isset($entry->ytVideoId))
            {
                $youtubeVideoId = $entry->ytVideoId;
            }

            // Add this id to our array
            $data['ids'][] = $youtubeVideoId;

            // Add this entry id to our array, using the video id as the key for reference
            $data['videos'][$youtubeVideoId] = $entry->id;
        }

        Craft::info('YouTubeSync: Return local data for comparison', __METHOD__);

        return $data;
    }


    private function createEntry($data, $playlistCategoryId)
    {
        // Create a new instance of the Craft Entry Model
        $entry = new Entry();

        // Set the section id
        $entry->sectionId = $this->settings->sectionId;

        // Set the entry type
        $entry->typeId = $this->settings->entryTypeId;

        // Set the author as super admin
        $entry->authorId = 1;

        $this->saveFieldData($entry, $data, $playlistCategoryId);
    }

    private function updateEntry($entryId, $data, $playlistCategoryId)
    {
        // Create a new instance of the Craft Entry Model
        $entry = Entry::find()
            ->sectionId($this->settings->sectionId)
            ->id($entryId)
            ->status(null)
            ->one();

        $this->saveFieldData($entry, $data, $playlistCategoryId);
    }

    private function closeEntry($entryId)
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
            'ytPlaylists'   => ($playlistCategoryId !== NULL) ? [$playlistCategoryId] : []
        ]);

        // Save the entry!
        if (!Craft::$app->elements->saveElement($entry)) {
            Craft::error('YouTubeSync: Couldn’t save the entry "' . $entry->title . '"', __METHOD__);

            return false;
        }

        // Set the postdate to the publishedAt date
        $entry->postDate = DateTimeHelper::toDateTime(strtotime($data->snippet->publishedAt));

        // Re-save the entry
        Craft::$app->elements->saveElement($entry);
    }

    private function parseVideo($video, $playlistCategoryId = NULL) {
        // Does this video already exist as an entry in Craft?
        if (in_array($video->id, $this->localData['ids'])) {
            // Entry exists, update it
            $this->updateEntry($this->localData['videos'][$video->id], $video, $playlistCategoryId);
        } else {
            // Entry doesn't exist, create it
            $this->createEntry($video, $playlistCategoryId);
        }
    }

    private function parsePlaylist($playlist) {
        // If there is no category group specified, don't do this
        if (! $this->settings->youtubePlaylistsCategoryGroupId) {
            return;
        }

        $returnCategoryId = NULL;
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
            'ytPlaylistImageMaxRes' => (isset($playlist->snippet->thumbnails->maxres->url)) ? $playlist->snippet->thumbnails->maxres->url : (isset($playlist->snippet->thumbnails->standard->url)) ? $playlist->snippet->thumbnails->standard->url : $playlist->snippet->thumbnails->high->url,
        ]);

        // Save the category!
        if (!Craft::$app->elements->saveElement($category)) {
            Craft::error('YouTubeSync: Couldn’t save the category "' . $category->title . '"', __METHOD__);

            return false;
        }

        $returnCategoryId = $category->id;

        return $returnCategoryId;
    }

    /**
     * Returns an authenticated YouTube client
     *
     * @return Google_Client
     */
    private function createClient()
    {
        $client = new Google_Client();

        $client->setApplicationName("Craft 3 CMS");

        $client->setDeveloperKey($this->settings->apiKey);

        return $client;
    }

    /**
     * Returns a YouTube API service
     *
     * @return Google_Service_YouTube
     */
    private function createService()
    {
        $client = $this->createClient();

        $this->service = new Google_Service_YouTube($client);
    }

    /**
     * Returns HH:MM:SS formatted time from YouTube duration
     */
    private function convertTime($youtubeTime){
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
