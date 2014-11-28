# Piwik QueuedTracking Plugin

## Description

Add your plugin description here.

## FAQ

__What are the requirements for this plugin?__

* [Redis server](http://redis.io/)
* [phpredis PHP extension](https://github.com/nicolasff/phpredis)

__Where can I configure and enable the queue?__

In your Piwik instance go to "Settings => Plugin Settings". There will be a section for this plugin.

__Why do some tests fail on my local Piwik instance?__

Make sure the requirements mentioned above are met and Redis needs to run on 127.0.0.1:6379 with no password for the
integration tests to work.

__What if I want to disable the queue?__

It might be possible that you disable the queue but there are still some pending requests in the queue. We recommend to 
change the "Number of requests to process" in plugin settings to "1" and process all requests using the command 
`./console queuedtracking:process` shortly before disabling the queue and directly afterwards.

## Changelog

Here goes the changelog text.

## Support

Please direct any feedback to ...