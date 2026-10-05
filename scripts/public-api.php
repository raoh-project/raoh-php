<?php

declare(strict_types=1);

// Writes the public API of raoh-php to tests/public-api.txt, which PublicApiTest holds the code to.

require dirname(__DIR__) . '/vendor/autoload.php';

file_put_contents(dirname(__DIR__) . '/tests/public-api.txt', \Raoh\Tests\PublicApi::describe());
