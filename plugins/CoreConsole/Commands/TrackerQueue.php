<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */

namespace Piwik\Plugins\CoreConsole\Commands;

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
        $trackerUrl = $this->getPiwikTrackerUrl($input);
        $this->checkCompatibility($trackerUrl);

        $queue = new Queue();

        if ($queue->isLocked()) {
            // we shouldn't check for $queue->isEnabled() I think! Eg if someone wants to disable queue temporarily but
            // there are thousands of requests in queue they wouldn't be processed
            return;
        }

        $queue->lock($ttlInSeconds = 120);

        try {
            $this->process($trackerUrl, $queue);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }

        $queue->unlock();
    }

    private function process($trackerUrl, Queue $queue)
    {
        while ($queue->shouldProcess()) {
            $requests = $queue->shiftRequests();
            $data     = array('requests' => $requests);

            if(!empty($token_auth)) {
                $data['token_auth'] = $token_auth;
            }

            $postData = json_encode($data);

            $this->sendBulkRequest($trackerUrl, $postData);
        }
    }

    private function checkCompatibility($trackerUrl)
    {
        if (!function_exists('curl_init') && !function_exists('stream_context_create')) {
            throw new \Exception('Curl and stream not available');
        }

        if (empty($trackerUrl)) {
            throw new \Exception('Cannot find Piwik URL, maybe not installed? Use param --piwikUrl instead');
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

    private function sendBulkRequest($url, $data)
    {
        if (function_exists('curl_init')) {
            $options = array(
                CURLOPT_URL            => $url,
                CURLOPT_HEADER         => true,
                CURLOPT_TIMEOUT        => $this->requestTimeout,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true
            );

            // only supports JSON data
            if (!empty($data)) {
                $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
                $options[CURLOPT_HTTPHEADER][] = 'Expect:';
                $options[CURLOPT_POSTFIELDS]   = $data;
            }

            $ch = curl_init();
            curl_setopt_array($ch, $options);
            ob_start();
            @curl_exec($ch);
            ob_end_clean();

        } else if (function_exists('stream_context_create')) {
            $stream_options = array(
                'http' => array(
                    'method'  => 'POST',
                    'timeout' => $this->requestTimeout,
                )
            );

            // only supports JSON data
            if (!empty($data)) {
                $stream_options['http']['header'] .= "Content-Type: application/json \r\n";
                $stream_options['http']['content'] = $data;
            }

            $ctx      = stream_context_create($stream_options);
            $response = file_get_contents($url, 0, $ctx);
        }
    }
}
