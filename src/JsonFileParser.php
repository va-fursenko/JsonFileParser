<?php

/**
 * Class JsonFileParser
 *
 * Парсер больших JSON-файлов
 *
 * @author Viktor.Fursenko
 * @version 1.0
 * @date 08.06.2018
 * @time 9:46
 * @link
 */
class JsonFileParser
{
    /**
     * Размер буфера чтения в символах
     *
     * @var int
     */
    protected $bufferSize;

    /**
     * Разделитель строк
     *
     * @var string
     */
    protected $lineEnding;

    /**
     * Дексриптор открытого файла
     *
     * @var resource
     */
    protected $file;

    /**
     * Имя открытого файла
     *
     * @var string
     */
    protected $filename;

    /**
     * Пока ещё не использованная часть предыдущего текстового блока
     *
     * @var string
     */
    protected $previousChunk = '';

    /**
     * Листенер парсера
     *
     * @var JsonFileParserListener
     */
    protected $listener;

    /**
     * JsonSerialParser constructor.
     *
     * @param int    $bufferSize Размер буфера чтения
     * @param string $lineEnding Окончания строк
     */
    public function __construct($bufferSize = 8192, $lineEnding = "\n")
    {
        $this->bufferSize = $bufferSize;
        $this->lineEnding = $lineEnding;
    }

    /**
     * Парсинг строки
     *
     * @param string|resource $target Имя файла или дексриптор соединения
     * @param JsonFileParserListener $listener
     */
    public function parse($target, JsonFileParserListener $listener)
    {
        $this->listener = $listener;
        // Открываем файл для чтения
        $this->openStream($target);
        // Проверяем, что текст начинается с открывающей скобки [
        $this->beginStream();
        $strPosition = $bracesDepth = 0;
        $commaFlag = false;
        // Читаем файл блоками по $this->bufferSize символов
        // Если получен пустой блок, значит файл кончился
        while (!$this->isStreamFinished() && $textChunk = $this->readStream()) {
            // Парсим остаток предыдущего чанка и только что полученный
            $this->parseChunk($textChunk, $strPosition, $bracesDepth, $commaFlag);
        }
        // Проверяем, правильно ли заканчивается чанк
        $this->finalizeStream();
        // Закрываем файл
        $this->closeStream();
    }

    /**
     * Парсинг текущего блока и части предыдущего
     *
     * @param string $textChunk    Новый текстовый чанк
     * @param int   &$strPosition  Текущая позиция курсора распознавания в нём. Изменяется по ссылке
     * @param int   &$bracesDepth  Глубина вложенности (фигурных скобок). Изменяется по ссылке
     * @param bool  &$commaFlag    Флаг открытой кавычки
     * @throws Exception
     */
    protected function parseChunk($textChunk, &$strPosition = 0, &$bracesDepth = 0, &$commaFlag = false)
    {
        // Складываем часть предыдущего чанка и новый считанный
        $textChunk = $this->previousChunk . $textChunk;
        $blockLength = strlen($textChunk);
        if ($blockLength <= $strPosition) {

            $this->fail("Bad format! Unexpected end of stream or stream is too short: '" . substr($textChunk, 0, 40) . (strlen($textChunk) > 40 ? "'..." : "'"));
        }
        // Объект должен начинаться со скобки {
        if ($strPosition == 0 && $textChunk{$strPosition} !== '{') {

            $this->fail("Bad format! Object must starts with brace, but '" . substr($textChunk, 0, 10) . "'... found");
        }
        // Ищем, где закрывается текущая скобка - получится целый объект
        do {
            // Вычисляем начало строк в кавычках "Say \"Hello, world!\""
            // Все символы внутри неэкранированных кавычек пропускаем
            if ($textChunk{$strPosition} === '"' && ($strPosition == 0 || $textChunk{$strPosition - 1} !== '\\')) {
                $commaFlag = !$commaFlag;
            }
            // Пропускаем экранированные символы внутри кавычек
            if ($commaFlag) {

                continue;
            }
            if ($textChunk{$strPosition} === '{') {
                $bracesDepth++;
            } elseif ($textChunk{$strPosition} === '}') {
                $bracesDepth--;
            }
        } while (++$strPosition < $blockLength && $bracesDepth > 0);
        // Если объект не завершён, читаем ещё один чанк
        if ($bracesDepth > 0) {
            $this->previousChunk = $textChunk;

            return;
        }

        // Отдаём найденный объект листенеру
        $this->listener->onObjectFound(substr($textChunk, 0, $strPosition));

        // Если после объекта в строке ничего нет, прочитаем новую, чтобы поискать там запятую-разделитель и прочие пробелы
        // Потому что кое-кто может ставить запятую в начале следующей строки, а не в конце текущей
        if ($strPosition >= $blockLength) {
            $textChunk = $this->readStream();
            $blockLength = strlen($textChunk);
            $strPosition = 0;
            if (!$textChunk) {

                return;
            }
        }
        // Сохраняем оставшуюся часть чанка, пропустив запятые-разделители и все пустые символы
        $this->skipSpaces($textChunk, $strPosition);
        if ($textChunk{$strPosition} === ',') {
            $strPosition++;
            // Пустые символы после разделителя
            $this->skipSpaces($textChunk, $strPosition);
        }
        $this->previousChunk = $strPosition < $blockLength
            ? substr($textChunk, $strPosition, $blockLength - $strPosition)
            : '';
        // Если всё, что осталось от чанка, это закрывающая скобка ], значит всё готово
        // Не будем учитывать ситуацию, когда эта скобка будет идти сразу после последнего объекта в чанке,
        // за которым в файле есть ещё текст, потому что это будет невалидный json
        if ($this->previousChunk === ']') {

            return;
        }
        // Сбрасываем указатель курсора в чанке, т.к. у него изменилось начало
        $strPosition = 0;
        // Рекурсивно проверяем, нет ли в оставшейся части ещё одного целого объекта
        if ($this->previousChunk) {
            $this->parseChunk('', $strPosition, $bracesDepth, $commaFlag);
        }
    }

    /**
     * Пропускает все пустые символы в строке
     *
     * @param string $textChunk   Текстовый чанк
     * @param int   &$strPosition Указатель курсора в чанке. Изменяется по ссылке
     */
    protected function skipSpaces($textChunk, &$strPosition)
    {
        $strLength = strlen($textChunk);
        while ($strPosition <= $strLength && ($textChunk{$strPosition} === ' ' || $textChunk{$strPosition} === "\t" || $textChunk{$strPosition} === "\n" || $textChunk{$strPosition} === "\r")) {
            $strPosition++;
        }
    }

    /**
     * Флаг завершения чтения исходного файла
     *
     * @return bool
     */
    protected function isStreamFinished()
    {
        return gzeof($this->file);
    }

    /**
     * Геттер позиции курсора чтения исходного файла
     *
     * @return int
     * @throws Exception
     */
    protected function getStreamPos()
    {
        return gztell($this->file);
    }

    /**
     * Открытие дескриптора исходного файла
     *
     * @param string|resource $stream Filename or opened file description
     * @throws Exception
     */
    protected function openStream($stream)
    {
        if (is_resource($stream)) {
            $this->filename = stream_get_meta_data($stream)["uri"];

            return;
        }
        if (!is_readable($stream)) {

            $this->fail("File '$stream' is not readable");
        }
        $this->filename = $stream;
        $this->file = gzopen($stream, 'r');
        // Обрабатываем событие
        $this->listener->onStart();
    }

    /**
     * Закрытие дескриптора исходного файла
     */
    protected function closeStream()
    {
        gzclose($this->file);
        // Обрабатываем событие
        $this->listener->onEnd();
    }

    /**
     * Чтение из исходного файла текстового чанка
     *
     * @param int $length Длина блока
     * @return string
     */
    protected function readStream($length = null)
    {
        $textChunk = stream_get_line($this->file, $length ?: $this->bufferSize, $this->lineEnding);
        // Обрабатываем событие
        $this->listener->onStreamRead($textChunk, $this->getStreamPos());

        return $textChunk;
    }

    /**
     * Инициализация входных данных
     *
     * @throws Exception
     */
    protected function beginStream()
    {
        $firstCahracter = $this->readStream(1);
        if ($firstCahracter !== '[') {

            $this->fail("Bad format! First letter should be square bracket '['");
        }
    }

    /**
     * Финализация входных данных
     *
     * @throws Exception
     */
    protected function finalizeStream()
    {
        if ($this->previousChunk !== ']') {

            $this->fail("Bad format! Last letter should be square bracket ']'");
        }
    }

    /**
     * Остановка выполнения с указанным сообщением
     *
     * @param $message
     * @throws Exception
     */
    protected function fail($message)
    {
        if ($this->file) {
            $message .= '. Stream position: ' . $this->getStreamPos();
        }
        $exception = new Exception($message);
        try {
            // Обрабатываем событие
            $this->listener->onError($exception);

            // Если обработчик не вызвал своё исключение, сами это сделаем
            throw $exception;
        } finally {
            // В любом случае, закрываем дескриптор открытого файла
            $this->closeStream();
        }
    }
}