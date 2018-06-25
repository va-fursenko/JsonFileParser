<?php

/**
 * Created by PhpStorm.
 * User: viktor
 * Date: 6/25/18
 * Time: 4:38 PM
 */
class ExampleListener implements \JsonFileParser\Listener
{
    /**
     * @param string $jsonObject
     */
    public function onObjectFound($jsonObject)
    {
        $object = json_decode($jsonObject);

        echo date('H:i:s') . "> {$object->code2} - {$object->name}" . PHP_EOL;
    }

    public function onStart()
    {
        echo date('H:i:s') . "> Let's go";
    }

    public function onEnd()
    {
        echo date('H:i:s') . "> Ready";
    }

    public function onError(\Exception $e)
    {
        echo date('H:i:s') . "> Exception: " . $e->getMessage();
    }

    public function onStreamRead($textChunk, $streamPosition)
    {
        echo date('H:i:s') . "> Chunk length: " . strlen($textChunk) . ". Stream position: $streamPosition" . PHP_EOL;
    }
}