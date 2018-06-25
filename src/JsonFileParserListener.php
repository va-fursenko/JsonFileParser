<?php

/**
 * Interface JsonFileParserListener
 *
 * JsonFileParser listener for found objects handling
 *
 * @author Viktor.Fursenko
 * @version 1.0
 * @date 14.06.2018
 * @time 9:46
 * @link
 */
interface JsonFileParserListener
{
    /**
     * Next object found event listener
     *
     * @param string $jsonObject
     * @throws Exception
     */
    public function onObjectFound($jsonObject);

    /**
     * Parse beginning event listener
     */
    public function onStart();

    /**
     * Parse ending event listener
     */
    public function onEnd();

    /**
     * Error event listener. The exception will be thrown after that method call
     *
     * @param Exception $e
     */
    public function onError(Exception $e);

    /**
     * Next text block read event listener
     *
     * @param string $textChunk
     * @param int $streamPosition
     */
    public function onStreamRead($textChunk, $streamPosition);
}