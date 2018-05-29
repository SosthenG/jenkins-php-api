<?php
namespace JenkinsApi\Item;

use DOMDocument;
use JenkinsApi\AbstractItem;
use JenkinsApi\Exceptions\JenkinsApiException;
use JenkinsApi\Exceptions\JobNotFoundException;
use JenkinsApi\Jenkins;
use RuntimeException;

/**
 * @package    JenkinsApi\Item
 * @author     Christopher Biel <christopher.biel@jungheinrich.de>
 * @author     Sosthèn Gaillard <sosthen.gaillard@gmail.com>
 * @version    $Id$
 *
 * @method string getName()
 * @method string getColor()
 */
class Job extends AbstractItem
{
    /**
     * @var string
     */
    private $_jobName;
    /**
     * @var Job
     */
    private $_parentJob;

    /**
     * @param string      $jobName
     * @param Jenkins|Job $jenkins_job
     */
    public function __construct($jobName, $jenkins_job)
    {
        $this->_jobName = $jobName;
        if ($jenkins_job instanceof Jenkins) {
            $this->_jenkins = $jenkins_job;
        }
        elseif ($jenkins_job instanceof Job) {
            $this->_jenkins = $jenkins_job->getJenkins();
            $this->_parentJob = $jenkins_job;
        }
        else {
            throw new \InvalidArgumentException("You should pass either a jenkins instance or a job.");
        }

        $this->refresh();
    }

    public function refresh()
    {
        try {
            return parent::refresh();
        } catch (JenkinsApiException $e) {
            throw new JobNotFoundException($this->_jobName, 0, $e);
        }
    }

    /**
     * @return string
     */
    protected function getUrl()
    {
        return $this->getBaseUrl() . '/api/json';
    }

    /**
     * @return string
     */
    public function getBaseUrl()
    {
        if ($this->_parentJob === null) {
            return sprintf('job/%s', rawurlencode($this->_jobName));
        }
        else {
            $endpoint = '';
            $args = [];

            $parent = $this->_parentJob;
            while ($parent !== null) {
                $endpoint .= '/job/%s';
                $args[] = rawurlencode($parent->getName());

                $parent = $parent->getParentJob();
            }
            $args = array_reverse($args);
            $args[] = rawurlencode($this->_jobName);
            return vsprintf($endpoint . '/job/%s', $args);
        }
    }


    /**
     * @return Job|Jenkins
     */
    public function getParentJob()
    {
        return $this->_parentJob;
    }

    /**
     * @param string $jobName
     *
     * @return Job
     */
    public function getJob($jobName)
    {
        return new Job($jobName, $this);
    }

    /**
     * @return Job[]
     */
    public function getJobs()
    {
        $data = $this->_jenkins->get($this->getUrl());

        $jobs = array();
        foreach ($data->jobs as $job) {
            $jobs[$job->name] = $this->getJob($job->name);
        }

        return $jobs;
    }


    /**
     * @return Build[]
     */
    public function getBuilds()
    {
        $builds = array();
        foreach ($this->_data->builds as $build) {
            $builds[] = $this->getBuild($build->number);
        }

        return $builds;
    }

    /**
     * @param int $buildId
     *
     * @return Build
     * @throws RuntimeException
     */
    public function getBuild($buildId)
    {
        return $this->_jenkins->getBuild($this, $buildId);
    }

    /**
     * @return array
     */
    public function getParametersDefinition()
    {
        $parameters = array();

        foreach ($this->_data->actions as $action) {
            if (!property_exists($action, 'parameterDefinitions')) {
                continue;
            }

            foreach ($action->parameterDefinitions as $parameterDefinition) {
                $default = property_exists($parameterDefinition, 'defaultParameterValue') ? $parameterDefinition->defaultParameterValue->value : null;
                $description = property_exists($parameterDefinition, 'description') ? $parameterDefinition->description : null;
                $choices = property_exists($parameterDefinition, 'choices') ? $parameterDefinition->choices : null;

                $parameters[$parameterDefinition->name] = array('default' => $default, 'choices' => $choices, 'description' => $description);
            }
        }

        return $parameters;
    }

    /**
     * @return Build|null
     */
    public function getLastSuccessfulBuild()
    {
        if (null === $this->_data->lastSuccessfulBuild) {
            return null;
        }

        return $this->_jenkins->getBuild($this, $this->_data->lastSuccessfulBuild->number);
    }

    /**
     * @return Build|null
     */
    public function getLastBuild()
    {
        if (null === $this->_data->lastBuild) {
            return null;
        }
        return $this->_jenkins->getBuild($this, $this->_data->lastBuild->number);
    }

    /**
     * @return bool
     */
    public function isCurrentlyBuilding()
    {
        $lastBuild = $this->getLastBuild();
        if ($lastBuild === null) {
            return false;
        }
        return $lastBuild->isBuilding();
    }

    /**
     * @param array $parameters
     *
     * @return bool|resource
     */
    public function launch($parameters = array())
    {
        if (empty($parameters)) {
            return $this->_jenkins->post($this->getBaseUrl() . '/build');
        } else {
            return $this->_jenkins->post($this->getBaseUrl() . '/buildWithParameters', $parameters);
        }
    }

    /**
     * @param array $parameters
     *
     * @param int $timeoutSeconds
     * @param int $checkIntervalSeconds
     * @return bool|Build
     */
    public function launchAndWait($parameters = array(), $timeoutSeconds = 86400, $checkIntervalSeconds = 5)
    {
        if (!$this->isCurrentlyBuilding()) {
            $lastNumber = $this->getLastBuild()->getNumber();
            $startTime = time();
            $response = $this->launch($parameters);
            // TODO evaluate the response correctly, to get the queued item and later the build
            if ($response) {
                //                list($header, $body) = explode("\r\n\r\n", $response, 2);
            }

            while ((time() < $startTime + $timeoutSeconds)
                   && (($this->getLastBuild()->getNumber() == $lastNumber)
                       || ($this->getLastBuild()->getNumber() == $lastNumber + 1
                           && $this->getLastBuild()->isBuilding()))) {
                sleep($checkIntervalSeconds);
                $this->refresh();
            }
        } else {
            while ($this->getLastBuild()->isBuilding()) {
                sleep($checkIntervalSeconds);
                $this->refresh();
            }
        }
        return $this->getLastBuild();
    }

    public function delete()
    {
        if (!$this->getJenkins()->post($this->getBaseUrl() . '/doDelete')) {
            throw new RuntimeException(sprintf('Error deleting job %s on %s', $this->_jobName, $this->getJenkins()->getBaseUrl()));
        }
    }

    public function getConfig()
    {
        $config = $this->getJenkins()->get($this->getBaseUrl() . '/config.xml');
        if ($config) {
            throw new RuntimeException(sprintf('Error during getting configuation for job %s', $this->_jobName));
        }
        return $config;
    }

    /**
     * @param string $jobname
     * @param DomDocument $document
     *
     * @deprecated use setJobConfig instead
     */
    public function setConfigFromDomDocument($jobname, DomDocument $document)
    {
        $this->setJobConfig($jobname, $document->saveXML());
    }

    /**
     * @param string $configuration config XML
     *
     */
    public function setJobConfig($configuration)
    {
        $return = $this->getJenkins()->post($this->getBaseUrl() . '/config.xml', $configuration, array(CURLOPT_HTTPHEADER => array('Content-Type: text/xml')));
        if ($return != 1) {
            throw new RuntimeException(sprintf('Error during setting configuration for job %s', $this->_jobName));
        }
    }

    public function disable()
    {
        if (!$this->getJenkins()->post($this->getBaseUrl() . '/disable')) {
            throw new RuntimeException(sprintf('Error disabling job %s on %s', $this->_jobName, $this->getJenkins()->getBaseUrl()));
        }
    }

    public function enable()
    {
        if (!$this->getJenkins()->post($this->getBaseUrl() . '/enable')) {
            throw new RuntimeException(sprintf('Error enabling job %s on %s', $this->_jobName, $this->getJenkins()->getBaseUrl()));
        }
    }

    /**
     * @return boolean
     */
    public function isBuildable()
    {
        return $this->_data->buildable;
    }

    public function __toString()
    {
        return $this->_jobName;
    }
}
