<?php
namespace JenkinsApi\Item;

use JenkinsApi\AbstractItem;
use JenkinsApi\Exceptions\BuildNotFoundException;
use JenkinsApi\Exceptions\JenkinsApiException;
use JenkinsApi\Jenkins;

/**
 * Represents a single build
 *
 * @package    JenkinsApi\Item
 * @author     Christopher Biel <christopher.biel@jungheinrich.de>
 * @author     Sosthèn Gaillard <sosthen.gaillard@gmail.com>
 * @version    $Id$
 *
 * @method int getNumber()
 * @method string getBuiltOn()
 */
class Build extends AbstractItem
{
    /**
     * @var string
     */
    const FAILURE = 'FAILURE';

    /**
     * @var string
     */
    const SUCCESS = 'SUCCESS';

    /**
     * @var string
     */
    const RUNNING = 'RUNNING';

    /**
     * @var string
     */
    const WAITING = 'WAITING';

    /**
     * @var string
     */
    const UNSTABLE = 'UNSTABLE';

    /**
     * @var string
     */
    const ABORTED = 'ABORTED';

    /**
     * @var string
     */
    protected $_buildNumber;

    /**
     * @var Job|string
     */
    protected $_job;

    /**
     * @var null|string
     */
    protected $_url;

    /**
     * @param string  $buildNumber
     * @param string  $jobName
     * @param Jenkins $jenkins
     */
    public function __construct($buildNumber, $job, Jenkins $jenkins, $url = null)
    {
        $this->_buildNumber = $buildNumber;
        $this->_job = $job;
        $this->_jenkins = $jenkins;
        $this->_url = $url;

        $this->refresh();
    }

    public function refresh()
    {
        try {
            return parent::refresh();
        } catch (JenkinsApiException $e) {
            throw new BuildNotFoundException($this->_buildNumber, (string) $this->_job, 0, $e);
        }
    }

    /**
     * @return string
     */
    protected function getUrl()
    {
        return $this->getBaseUrl() . '/api/json';
    }

    protected function getBaseUrl()
    {
        if ($this->_job instanceof Job) {
            return sprintf($this->_job->getBaseUrl() . '/%d', rawurlencode($this->_buildNumber));
        }
        else {
            return sprintf('job/%s/%d', rawurlencode((string) $this->_job), rawurlencode($this->_buildNumber));
        }
    }

    /**
     * @return array
     */
    public function getInputParameters()
    {
        $parameters = array();
        if (($this->get('actions')) === null || $this->get('actions') === array()) {
            return $parameters;
        }

        foreach ($this->get('actions') as $action) {
            if (property_exists($action, 'parameters')) {
                foreach ($action->parameters as $parameter) {
                    if (property_exists($parameter, 'value')) {
                        $parameters[$parameter->name] = $parameter->value;
                    } elseif (property_exists($parameter, 'number') && property_exists($parameter, 'jobName')) {
                        $parameters[$parameter->name]['number'] = $parameter->number;
                        $parameters[$parameter->name]['jobName'] = $parameter->jobName;
                    }
                }
                break;
            }
        }

        return $parameters;
    }

    /**
     * @return null|int
     */
    public function getProgress()
    {
        $progress = null;
        if (null !== ($executor = $this->getExecutor())) {
            $progress = $executor->getProgress();
        }

        return $progress;
    }

    /**
     * @return float|null
     */
    public function getEstimatedDuration()
    {
        //since version 1.461 estimatedDuration is displayed in jenkins's api
        //we can use it witch is more accurate than calcule ourselves
        //but older versions need to continue to work, so in case of estimated
        //duration is not found we fallback to calcule it.
        if ($this->get('estimatedDuration')) {
            return $this->get('estimatedDuration') / 1000;
        }

        $duration = null;
        $progress = $this->getProgress();
        if (null !== $progress && $progress >= 0) {
            $duration = ceil((time() - $this->getTimestamp()) / ($progress / 100));
        }
        return $duration;
    }

    /**
     * Returns remaining execution time (seconds)
     *
     * @return int|null
     */
    public function getRemainingExecutionTime()
    {
        $remaining = null;
        if (null !== ($estimatedDuration = $this->getEstimatedDuration())) {
            //be carefull because time from JK server could be different
            //of time from Jenkins server
            //but i didn't find a timestamp given by Jenkins api

            $remaining = $estimatedDuration - (time() - $this->getTimestamp());
        }

        return max(0, $remaining);
    }

    /**
     * @return null|string
     */
    public function getResult()
    {
        $result = null;
        switch ($this->get('result')) {
            case 'FAILURE':
                $result = Build::FAILURE;
                break;
            case 'SUCCESS':
                $result = Build::SUCCESS;
                break;
            case 'UNSTABLE':
                $result = Build::UNSTABLE;
                break;
            case 'ABORTED':
                $result = Build::ABORTED;
                break;
            case 'WAITING':
                $result = Build::WAITING;
                break;
            default:
                $result = Build::RUNNING;
                break;
        }

        return $result;
    }

    /**
     * @return Executor|null
     */
    public function getExecutor()
    {
        if (!$this->isBuilding()) {
            return null;
        }

        $runExecutor = null;
        foreach ($this->getJenkins()->getExecutors() as $executor) {
            /** @var Executor $executor */
            if ($this->getBuildUrl() === $executor->getBuildUrl()) {
                $runExecutor = $executor;
            }
        }
        return $runExecutor;
    }

    /**
     * @param $text
     *
     * @return bool
     */
    public function setDescription($text)
    {
        $url = $this->getBaseUrl() . '/submitDescription';
        return $this->getJenkins()->post($url, array('description' => $text));
    }

    /**
     * @return string
     */
    public function getConsoleTextBuild()
    {
        return $this->getJenkins()->get($this->getBaseUrl() . '/consoleText', 1, array(), array(), true);
    }

    /**
     * @return bool
     */
    public function isBuilding()
    {
        return (bool) $this->get('building');
    }

    /**
     * @return int
     */
    public function getTimestamp()
    {
        return (int) ($this->get('timestamp') / 1000);
    }

    /**
     * @return int
     */
    public function getDuration()
    {
        if ($this->get('duration') == 0) {
            // duration is not set by Jenkins, let's calculate ourselves
            return (int) (time() - $this->getTimestamp());
        }
        return (int) ($this->get('duration') / 1000);
    }

    /**
     * @return string
     */
    public function getBuildUrl()
    {
        return $this->get('url');
    }

    /**
     * @return string
     */
    public function getJobName()
    {
        return (string) $this->_job;
    }

    /**
     * @return bool
     */
    public function stop()
    {
        if (!$this->isBuilding()) {
            return null;
        }

        return $this->getJenkins()->post(
            $this->getBaseUrl() . '/stop'
        );
    }
}
