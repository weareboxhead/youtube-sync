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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;


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
    // private $baseUrl = 'https://api.churchsuite.co.uk/v1/';
    private $remoteData;
    private $localData;

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

        // Request data form the API
        $this->remoteData = $this->getAPIData();

        // Get local Small Group data
        $this->localData = $this->getLocalData();

        Craft::info('YouTubeSync: Compare remote data with local data', __METHOD__);

        // Determine which entries we are missing by id
        $missingIds = array_diff($this->remoteData['ids'], $this->localData['ids']);

        // Determine which entries we shouldn't have by id
        $removedIds = array_diff($this->localData['ids'], $this->remoteData['ids']);

        // Determine which entries need updating (all active entries which we aren't about to create)
        $updatingIds = array_diff($this->remoteData['ids'], $missingIds);

        Craft::info('YouTubeSync: Create entries for all new Small Groups', __METHOD__);

        // Create all missing small groups
        foreach ($missingIds as $id) {
           $this->createEntry($this->remoteData['videos'][$id]);
        }

        // Update all small groups that have been previously saved to keep our data in sync
        foreach ($updatingIds as $id) {
            $this->updateEntry($this->localData['videos'][$id], $this->remoteData['videos'][$id]);
        }

        // If we have local data that doesn't match with anything from remote we should close the local entry
        foreach ($removedIds as $id) {
            $this->closeEntry($this->localData['videos'][$id]);
        }

        return;
    }




    // Private Methods
    // =========================================================================

    private function checkSettings()
    {
        // Check our Plugin's settings for the clientId
        // if ($this->settings->clientId === null) {
        //     Craft::error('YouTubeSync: No Client ID provided in settings', __METHOD__);

        //     return false;
        // }

        // // Check our Plugin's settings for the clientSecret
        // if ($this->settings->clientSecret === null) {
        //     Craft::error('YouTubeSync: No Client Secret provided in settings', __METHOD__);

        //     return false;
        // }

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

        if (!$this->settings->sectionId)
        {
            Craft::error('YouTubeSync: No Section ID provided in settings', __METHOD__);

            return false;
        }

        if (!$this->settings->entryTypeId)
        {
            Craft::error('YouTubeSync: No Entry Type ID provided in settings', __METHOD__);

            return false;
        }

        if (!$this->settings->youtubePlaylistsCategoryGroupId)
        {
            Craft::error('YouTubeSync: No YouTube Playlists Category Group ID provided in settings', __METHOD__);

            return false;
        }
    }


    private function getAPIData()
    {
        Craft::info('YouTubeSync: Begin sync with API', __METHOD__);

        // Get all YouTube Playlists for the channel specified in settings
        $client = new Client();

        $response = $client->request('GET', $this->baseUrl . 'smallgroups/groups', [
            'query'   => [
                'per_page'  => 500,
                'tags'      => 'true',
                'view'      => 'active_future'
            ],
            'headers' => [
                'Content-Type'  => 'application/json',
                'X-Account'     => 'weareemmanuel',
                'X-Application' => 'WeAreEmmanuel-Website',
                'X-Auth'        => $this->settings->apiKey
            ]
        ]);

        // Do we have a success response?
        if ($response->getStatusCode() !== 200)
        {
            Craft::error('ChurchSuite: API Reponse Error ' . $response->getStatusCode() . ": " . $response->getReasonPhrase(), __METHOD__);

            return false;
        }

        $body = json_decode($response->getBody());

        // Are there any results
        if (count($body->groups) === 0)
        {
            Craft::error('ChurchSuite: No results from API Request', __METHOD__);

            return false;
        }

// echo '<pre>'; print_r($body); echo '</pre>';

        $data = array(
            'ids'       =>  array(),
            'smallgroups'    =>  array(),
        );

        // For each Small Group
        foreach ($body->groups as $group)
        {
            // Get the id
            $smallGroupId = $group->id;

            // Add this id to our array
            $data['ids'][] = $smallGroupId;

            // Add this tweet to our array, using the id as the key
            $data['smallgroups'][$smallGroupId] = $group;
        }

        Craft::info('ChurchSuite: Finished getting remote data', __METHOD__);

        return $data;
    }


    private function getLocalData()
    {
        Craft::info('ChurchSuite: Get local Small Group data', __METHOD__);

        // Create a Craft Element Criteria Model
        $query = Entry::find()
            ->sectionId($this->settings->sectionId)
            ->limit(null)
            ->status(null)
            ->all();

        $data = array(
            'ids'           =>  [],
            'smallgroups'   =>  []
        );

        Craft::info('ChurchSuite: Query for all Small Group entries', __METHOD__);

        // For each entry
        foreach ($query as $entry)
        {
            $smallGroupId = "";

            // Get the id of this Small Group
            if (isset($entry->smallGroupId))
            {
                $smallGroupId = $entry->smallGroupId;
            }

            // Add this id to our array
            $data['ids'][] = $smallGroupId;

            // Add this entry id to our array, using the small group id as the key for reference
            $data['smallgroups'][$smallGroupId] = $entry->id;
        }

        Craft::info('ChurchSuite: Return local data for comparison', __METHOD__);

        return $data;
    }


    private function createEntry($group)
    {
        // Create a new instance of the Craft Entry Model
        $entry = new Entry();

        // Set the section id
        $entry->sectionId = $this->settings->sectionId;

        // Set the entry type
        $entry->typeId = $this->settings->entryTypeId;

        // Set the author as super admin
        $entry->authorId = 1;

        $this->saveFieldData($entry, $group);
    }


    private function updateEntry($entryId, $group)
    {
        // Create a new instance of the Craft Entry Model
        $entry = Entry::find()
            ->sectionId($this->settings->sectionId)
            ->id($entryId)
            ->status(null)
            ->one();

        $this->saveFieldData($entry, $group);
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


    private function saveFieldData($entry, $group)
    {
        // Enabled?
        $entry->enabled = ($group->embed_visible == "1") ? true : false;

        // Set the title
        $entry->title = $group->name;

        $leaders = $this->getLeaders($group);

        // Set the other content
        $entry->setFieldValues([
            'smallGroupId'              => $group->id,
            'smallGroupIdentifier'      => $group->identifier,
            'smallGroupName'            => $group->name,
            'smallGroupDescription'     => $group->description,
            'smallGroupDay'             => $group->day,
            'smallGroupFrequency'       => $group->frequency,
            'smallGroupTime'            => $group->time,
            'smallGroupStartDate'       => $group->date_start,
            'smallGroupEndDate'         => $group->date_end,
            'smallGroupSignupStartDate' => $group->signup_date_start,
            'smallGroupSignupEndDate'   => $group->signup_date_end,
            'smallGroupCapacity'        => $group->signup_capacity,
            'smallGroupNumberMembers'   => $group->no_members,
            'smallGroupLeaders'         => $leaders,
            'smallGroupAddress'         => (isset($group->location->address)) ? $group->location->address : '',
            'smallGroupAddressName'     => (isset($group->location->address_name)) ? $group->location->address_name : '',
            'smallGroupLatitude'        => (isset($group->location->latitude)) ? $group->location->latitude : '',
            'smallGroupLongitude'       => (isset($group->location->longitude)) ? $group->location->longitude : '',
            'smallGroupCategories'      => (isset($group->tags)) ? $this->parseTags($group->tags) : [],
            'smallGroupSite'            => (isset($group->site)) ? $this->parseSite($group->site) : [],
        ]);

        // Save the entry!
        if (!Craft::$app->elements->saveElement($entry)) {
            Craft::error('ChurchSuite: Couldn’t save the entry "' . $entry->title . '"', __METHOD__);

            return false;
        }

        // Set the signup start date as post date
        // $entry->postDate = DateTimeHelper::toDateTime(strtotime($group->signup_date_start));

        // Set the postdate to now
        $entry->postDate = DateTimeHelper::toDateTime(time());

        // Re-save the entry
        Craft::$app->elements->saveElement($entry);
    }

    private function getLeaders($group)
    {
        $leaders = '';

        if (!isset($group->custom_fields)) {
            return $leaders;
        }

        foreach ($group->custom_fields as $custom_field) {
            if ($custom_field->name === 'Leaders') {
                $leaders = $custom_field->value;
            }
        }

        return $leaders;
    }


    private function parseTags($tags)
    {
        // If there is no category group specified, don't do this
        if (!$this->settings->categoryGroupId) {
            return [];
        }

        // Are thre any tags even assigned?
        if (! $tags) {
            return [];
        }

        // Get all existing categories
        $categories = [];

        // Create a Craft Element Criteria Model
        $query = Category::find()
            ->groupId($this->settings->categoryGroupId)
            ->all();

        // For each category
        foreach ($query as $category) {
            // Add its slug and id to our array
            $categories[$category->slug] = $category->id;
        }

        $returnIds = [];

        // Loop over tags assigned to the group
        foreach ($tags as $tag) {
            // We just need the text
            $tagSlug = ElementHelper::createSlug($tag->name);
            $categorySet = false;

            // Does this tag exist already as a category?
            foreach ($categories as $slug => $id) {
                // Tag already a category
                if ($tagSlug === $slug) {
                    $returnIds[] = $id;
                    $categorySet = true;

                    break;
                }
            }

            // Do we need to create the Category?
            if (!$categorySet) {
                // Create the category
                $newCategory = new Category();

                $newCategory->title = $tag->name;
                $newCategory->groupId = $this->settings->categoryGroupId;

                // Save the category!
                if (!Craft::$app->elements->saveElement($newCategory)) {
                    Craft::error('ChurchSuite: Couldn’t save the category "' . $newCategory->title . '"', __METHOD__);

                    return false;
                }

                $returnIds[] = $newCategory->id;
            }
        }

        return $returnIds;
    }


    private function parseSite($site)
    {
        // If there is no category group specified, don't do this
        if (!$this->settings->sitesCategoryGroupId) {
            return;
        }

        $categories = [];

        // Create a Craft Element Criteria Model
        $query = Category::find()
            ->groupId($this->settings->sitesCategoryGroupId)
            ->all();

        // For each category
        foreach ($query as $category) {
            // Add its churchSuiteSiteId and id to our array
            $categories[$category->churchSuiteSiteId] = $category->id;
        }

        $returnIds = [];

        // $siteSlug = ElementHelper::createSlug($site->name);
        $categorySet = false;

        // Does this site exist already as a category?
        foreach ($categories as $churchSuiteSiteId => $id) {
            // Site already a category
            if ($churchSuiteSiteId == $site->id) {
                $returnIds[] = $id;
                $categorySet = true;

                break;
            }
        }

        // Do we need to create the Category?
        if (!$categorySet) {
            // Create the category
            $newCategory = new Category();

            $newCategory->title = $site->name;
            $newCategory->groupId = $this->settings->sitesCategoryGroupId;

            $newCategory->setFieldValues([
                'churchSuiteSiteId' => $site->id
            ]);

            // Save the category!
            if (!Craft::$app->elements->saveElement($newCategory)) {
                Craft::error('ChurchSuite: Couldn’t save the category "' . $newCategory->title . '"', __METHOD__);

                return false;
            }

            $returnIds[] = $newCategory->id;
        }

        return $returnIds;
    }

    
    /**
     * Returns an authenticated Guzzle client
     *
     * @return Client
     */
    private function createClient()
    {
        $options = [
            'base_uri' => 'https://www.googleapis.com/youtube/v3/',
            'query'   => [
                'key' => $this->settings->clientKey
            ]
        ];

        return new Client($options);
    }

    /**
     * Performs a GET request to the YouTube Data API
     * 
     * @param       $uri
     * @param array $options
     *
     * @return array
     */
    private function get($uri, array $options = [])
    {
        $client = $this->createClient();

        try {
            $response = $client->request('GET', $uri, $options);
            $body = (string) $response->getBody();
            $data = Json::decode($body);
        } catch (BadResponseException $badResponseException) {
            $response = $badResponseException->getResponse();
            $body = (string) $response->getBody();

            try {
                $data = Json::decode($body);
            } catch (JsonParsingException $e) {
                throw $badResponseException;
            }
        }

        // $this->checkResponse($response, $data);

        return $data;
    }
}
