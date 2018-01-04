<?php
/**
 * ChurchSuite plugin for Craft CMS 3.x
 *
 * Communicate and process data from the ChurchSuite API
 *
 * @link      https://boxhead.io
 * @copyright Copyright (c) 2018 Boxhead
 */

namespace boxhead\churchsuite\tasks;

use boxhead\churchsuite\ChurchSuite;

use Craft;
use craft\base\Task;

/**
 * ChurchSuiteTask Task
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
 * use boxhead\churchsuite\tasks\ChurchSuiteTask as ChurchSuiteTaskTask;
 *
 * $tasks = Craft::$app->getTasks();
 * if (!$tasks->areTasksPending(ChurchSuiteTaskTask::class)) {
 *     $tasks->createTask(ChurchSuiteTaskTask::class);
 * }
 *
 * https://craftcms.com/classreference/services/TasksService
 *
 * @author    Boxhead
 * @package   ChurchSuite
 * @since     1.0.0
 */
class ChurchSuiteTask extends Task
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
    private $_smallGroupsToUpdate = [];
    private $_localSmallGroupData;

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
        Craft::Info('Update Small Groups: Get Total Steps', __METHOD__);

        // Pass false to get all small groups
        // Limited to most recent 1000
        $this->_localSmallGroupData = ChurchSuite::$plugin->churchSuiteService->getLocalData(1000);

        if (! $this->_localSmallGroupData) {
            Craft::Info('Update Small Groups: No local data to work with', __METHOD__);
        }

        foreach ($this->_localSmallGroupData['smallGroups'] as $groupId => $entryId) {
            $this->_smallGroupsToUpdate[] = $entryId;
        }

        Craft::Info('Update Small Groups - Total Steps: ' . count($this->_smallGroupsToUpdate), __METHOD__);

        return count($this->_smallGroupsToUpdate);
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
        Craft::Info('Update Small Groups: Running Step ' . $step, __METHOD__);

        $id = $this->_smallGroupsToUpdate[$step];

        // Update existing DB entry
        ChurchSuite::$plugin->churchSuiteService->updateEntry($id);

        return true;
    }


    /**
     * Returns the default description for this task.
     *
     * @return string
     */
    public function getDescription()
    {
        return 'Update local ChurchSuite data';
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
        return Craft::t('church-suite', 'ChurchSuiteTask');
    }
}
