# Piwik QueuedTracking Plugin

## Description

Add your plugin description here.



## FAQ

__What are the requirements for this plugin?__

* [Redis server 2.8+](http://redis.io/), [Redis quickstart](http://redis.io/topics/quickstart)
* [phpredis PHP extension](https://github.com/nicolasff/phpredis), [Install](https://github.com/nicolasff/phpredis#installingconfiguring)
* Transactions will be used and must be supported by the SQL database.

__Where can I configure and enable the queue?__

In your Piwik instance go to "Settings => Plugin Settings". There will be a section for this plugin.

__Why do some tests fail on my local Piwik instance?__

Make sure the requirements mentioned above are met and Redis needs to run on 127.0.0.1:6379 with no password for the
integration tests to work. It will use the database "15" and the tests may flush all data it contains.

__What if I want to disable the queue?__

It might be possible that you disable the queue but there are still some pending requests in the queue. We recommend to 
change the "Number of requests to process" in plugin settings to "1" and process all requests using the command 
`./console queuedtracking:process` shortly before disabling the queue and directly afterwards.

__Are there known issues?__

In case you are using bulk tracking the response varies compared to the normal bulk tracking. We will always return either
an image or a 204 HTTP response code in case the parameter `send_image=0` is sent.

## Changelog

Here goes the changelog text.

## Support

Please direct any feedback to ...

## TODO

For usage with multiple redis servers we should lock differently: 
http://redis.io/topics/distlock eg using https://github.com/ronnylt/redlock-php 