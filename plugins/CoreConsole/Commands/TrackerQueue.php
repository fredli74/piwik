<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\CoreConsole\Commands;

use Piwik\CliMulti;
use Piwik\Common;
use Piwik\Http;
use Piwik\Log;
use Piwik\SettingsPiwik;
use Piwik\Tracker\Queue;
use Piwik\Plugin\ConsoleCommand;
use Piwik\Tracker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * This class lets you define a new command. To read more about commands have a look at our Piwik Console guide on
 * http://developer.piwik.org/guides/piwik-on-the-command-line
 *
 * As Piwik Console is based on the Symfony Console you might also want to have a look at
 * http://symfony.com/doc/current/components/console/index.html
 */
class TrackerQueue extends ConsoleCommand
{

    private $requestTimeout = 600;

    /**
     * This methods allows you to configure your command. Here you can define the name and description of your command
     * as well as all options and arguments you expect when executing it.
     */
    protected function configure()
    {
        $this->setName('core:process-tracker-queue');
        $this->setDescription('Process Tracker Queue');
    }

    /**
     * The actual task is defined in this method. Here you can access any option or argument that was defined on the
     * command line via $input and write anything to the console via $output argument.
     * In case anything went wrong during the execution you should throw an exception to make sure the user will get a
     * useful error message and to make sure the command does not exit with the status code 0.
     *
     * Ideally, the actual command is quite short as it acts like a controller. It should only receive the input values,
     * execute the task by calling a method of another class and output any useful information.
     *
     * Execute the command like: ./console coreconsole:tracker-queue --name="The Piwik Team"
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $trackrUrl = $this->getPiwikTrackerUrl($input);

        $this->checkCompatibility();

        $tracker = new Tracker();

        if (!$tracker->isEnabled()) {
            throw new \RuntimeException('Tracker is not enabled');
        }

        $tracker->setUp();

        $queue = new Queue();

        if ($queue->isLocked()) {
            // we shouldn't check for $queue->isEnabled(). Eg if someone wants to disable queue temporarily but
            // there are thousands of requests in queue they wouldn't be processed. We have to think more about this
            // scenario as it can result in invalid data anyway (new tracking requests inserted directly) while old
            // queued ones still being inserted
            return;
        }

        $queue->lock($ttlInSeconds = 120);

        try {
            $this->process($trackrUrl, $queue);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }

        $queue->unlock();

        $tracker->tearDown();
    }

    private function process($trackerUrl, Queue $queue)
    {
        while ($queue->shouldProcess()) {
            $requests = $queue->shiftRequests();

            $data     = array('requests' => $requests);

            $cliMulti = new CliMulti();
            $cliMulti->request(array($trackerUrl . '?' . http_build_query($data)));
        }
    }

    private function getPiwikTrackerUrl(InputInterface $input)
    {
        if ($input->hasOption('piwik-domain')) {
            $piwikUrl = $input->getOption('piwik-domain');
        }
        if (empty($piwikUrl)) {
            $piwikUrl = SettingsPiwik::getPiwikUrl();
        }
        if (!empty($piwikUrl)) {
            if (!Common::stringEndsWith($piwikUrl, '/')) {
                $piwikUrl .= '/';
            }
            $piwikUrl .= 'piwik.php';
        }
        return $piwikUrl;
    }

    private function checkCompatibility()
    {
        $queueBackend = new Queue\Backend\Redis();
        $queueBackend->checkIsInstalled(); // todo command should not know about this backend
    }

}
