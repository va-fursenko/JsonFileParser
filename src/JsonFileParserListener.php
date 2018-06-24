<?php

/**
 * Interface JsonFileParserListener
 *
 * Описание интерфейса
 *
 * @copyright Online Express, Ltd. (www.online-express.ru)
 * @author Viktor.Fursenko
 * @project oex
 * @version 1.0
 * @date 14.06.2018
 * @time 9:46
 * @link
 */
interface JsonFileParserListener
{
    /**
     * Обработчик события нахождения следующего объекта в файле
     *
     * @param string $jsonObject
     * @throws Exception
     */
    public function onObjectFound($jsonObject);

    /**
     * Обработчик начала парсинга файла
     */
    public function onStart();

    /**
     * Обработчик окончания парсинга файла
     */
    public function onEnd();

    /**
     * Обработчик ошибки. Само исключение будет брошено после выполнения этого метода
     *
     * @param Exception $e
     */
    public function onError(Exception $e);

    /**
     * Обработчик чтения очередного блока из файла
     *
     * @param string $textChunk
     * @param int $streamPosition
     */
    public function onStreamRead($textChunk, $streamPosition);
}