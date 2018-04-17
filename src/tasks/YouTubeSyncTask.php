<?php
/**
 * YouTubeSync plugin for Craft CMS 3.x
 *
 * Communicate and process data from the YouTubeSync API
 *
 * @link      https://boxhead.io
 * @copyright Copyright (c) 2018 Boxhead
 */

namespace boxhead\youtubesync\tasks;

use boxhead\youtubsync\YouTubeSync;

use Craft;
use craft\base\Task;

/**
 * YouTubeSyncTask Task
 *
 * Tasks let you run background processing for things that take a long time,
 * dividing them up into steps.  For example, Asset Transforms are regenerated
 * using Tasks.
 *
 * Keep in mind that tasks only get timeslices to run when Craft is handling
 * requests on your website.  If you need a task to be run on a regular basis,
 * write a Controller that triggers it, and set up a cron job to
 * trigger the controller.
 *
 * The pattern used to queue up a task for running is:
 *
 * use boxhead\youtubesync\tasks\YouTubeSyncTask as YouTubeSyncTaskTask;
 *
 * $tasks = Craft::$app->getTasks();
 * if (!$tasks->areTasksPending(YouTubeSyncTaskTask::class)) {
 *     $tasks->createTask(YouTubeSyncTaskTask::class);
 * }
 *
 * https://craftcms.com/classreference/services/TasksService
 *
 * @author    Boxhead
 * @package   YouTubeSync
 * @since     1.0.0
 */
class YouTubeSyncTask extends Task
{
    // Public Properties
    // =========================================================================

    /**
     * Some attribute
     *
     * @var string
     */
    public $someAttribute = 'Some Default';


    // Private Properties
    // =========================================================================
    private $_videosToUpdate = [];
    private $_localVideoData;

    // Public Methods
    // =========================================================================

    /**
     * Returns the validation rules for attributes.
     *
     * Validation rules are used by [[validate()]] to check if attribute values are valid.
     * Child classes may override this method to declare different validation rules.
     *
     * More info: http://www.yiiframework.com/doc-2.0/guide-input-validation.html
     *
     * @return array
     */
    public function rules()
    {
        return [
            ['someAttribute', 'string'],
            ['someAttribute', 'default', 'value' => 'Some Default'],
        ];
    }

    /**
     * Returns the total number of steps for this task.
     *
     * @return int The total number of steps for this task
     */
    public function getTotalSteps(): int
    {
        Craft::Info('Update YouTube Videos: Get Total Steps', __METHOD__);

        // Pass false to get all small groups
        // Limited to most recent 1000
        $this->_localVideoData = YouTubeSync::$plugin->youTubeSyncService->getLocalData(1000);

        if (! $this->_localVideoData) {
            Craft::Info('Update YouTube Videos: No local data to work with', __METHOD__);
        }

        foreach ($this->_localVideoData['videos'] as $groupId => $entryId) {
            $this->_videosToUpdate[] = $entryId;
        }

        Craft::Info('Update YouTube Videos - Total Steps: ' . count($this->_videosToUpdate), __METHOD__);

        return count($this->_videosToUpdate);
    }

    /**
     * Runs a task step.
     *
     * @param int $step The step to run
     *
     * @return bool|string True if the step was successful, false or an error message if not
     */
    public function runStep(int $step)
    {
        Craft::Info('Update YouTube Video: Running Step ' . $step, __METHOD__);

        $id = $this->_videosToUpdate[$step];

        // Update existing DB entry
        YouTubeSync::$plugin->youTubeSyncService->updateEntry($id);

        return true;
    }


    /**
     * Returns the default description for this task.
     *
     * @return string
     */
    public function getDescription()
    {
        return 'Update local YouTubeSync data';
    }


    // Protected Methods
    // =========================================================================

    /**
     * Returns a default description for [[getDescription()]], if [[description]] isn’t set.
     *
     * @return string The default task description
     */
    protected function defaultDescription(): string
    {
        return Craft::t('youtube-sync', 'YouTubeSyncTask');
    }
}
