<?php
/**
 * Created by PhpStorm.
 * User: viktor
 * Date: 6/25/18
 * Time: 4:42 PM
 */

require_once '../vendor/autoload.php';
require_once './ExampleListener.php';

$filename = __DIR__ . DIRECTORY_SEPARATOR . 'countries.json.gz';

$parser = new \JsonFileParser\Parser(512, null);
$listener = new ExampleListener();
$parser->parse($filename, $listener);

echo PHP_EOL . PHP_EOL . "We've done!";