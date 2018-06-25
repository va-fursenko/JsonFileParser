<?php

/**
 * Class JsonFileParser
 *
 * Large JSON-files parser
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
     * Read buffer size (in characters)
     *
     * @var int
     */
    protected $bufferSize;

    /**
     * Lines delimeter
     *
     * @var string
     */
    protected $lineEnding;

    /**
     * Source file descriptor
     *
     * @var resource
     */
    protected $file;

    /**
     * Source filename
     *
     * @var string
     */
    protected $filename;

    /**
     * Still not parsed part of the previous text block
     *
     * @var string
     */
    protected $previousChunk = '';

    /**
     * Listener object
     *
     * @var JsonFileParserListener
     */
    protected $listener;

    /**
     * JsonSerialParser constructor.
     *
     * @param int    $bufferSize Read buffer size (in characters)
     * @param string $lineEnding Lines delimeter (optional)
     */
    public function __construct($bufferSize = 8192, $lineEnding = null)
    {
        $this->bufferSize = $bufferSize;
        $this->lineEnding = $lineEnding;
    }

    /**
     * Parse method
     *
     * @param string|resource        $target   Source filename or descriptor
     * @param JsonFileParserListener $listener Listener object
     */
    public function parse($target, JsonFileParserListener $listener)
    {
        $this->listener = $listener;
        // Open source file
        $this->openStream($target);
        // Check it starts from '['
        $this->beginStream();
        $strPosition = $bracesDepth = 0;
        $commaFlag = false;
        // Read the file in blocks of $this->bufferSize characters
        // If an empty block is received, the file is done
        while (!$this->isStreamFinished() && $textChunk = $this->readStream()) {
            // Parse the rest part of previous chunk and next one
            $this->parseChunk($textChunk, $strPosition, $bracesDepth, $commaFlag);
        }
        // Check file ending
        $this->finalizeStream();
        // Close file
        $this->closeStream();
    }

    /**
     * Parsing the rest part of previous chunk and next one
     *
     * @param string $textChunk    New text chunk
     * @param int   &$strPosition  Current cursor position in it
     * @param int   &$bracesDepth  Nesting brackets depth
     * @param bool  &$commaFlag    Opened quotation mark flag
     * @throws Exception
     */
    protected function parseChunk($textChunk, &$strPosition = 0, &$bracesDepth = 0, &$commaFlag = false)
    {
        // Concatenate the rest part of previous chunk and next one
        $textChunk = $this->previousChunk . $textChunk;
        $blockLength = strlen($textChunk);
        if ($blockLength <= $strPosition) {

            $this->fail("Bad format! Unexpected end of stream or stream is too short: '" . substr($textChunk, 0, 40) . (strlen($textChunk) > 40 ? "'..." : "'"));
        }
        // Object must starts with brace '{'
        if ($strPosition == 0 && $textChunk{$strPosition} !== '{') {

            $this->fail("Bad format! Object must starts with brace, but '" . substr($textChunk, 0, 10) . "'... found");
        }
        // Find closing brace '}' for it
        do {
            // Check if current character located in string constants ("Say \" Hello, world! \ "")
            if ($textChunk{$strPosition} === '"' && ($strPosition == 0 || $textChunk{$strPosition - 1} !== '\\')) {
                $commaFlag = !$commaFlag;
            }
            // Skip all characters between non-escaped quotation marks
            if ($commaFlag) {

                continue;
            }
            if ($textChunk{$strPosition} === '{') {
                $bracesDepth++;
            } elseif ($textChunk{$strPosition} === '}') {
                $bracesDepth--;
            }
        } while (++$strPosition < $blockLength && $bracesDepth > 0);
        // If current object not finished yet, read next one chunk
        if ($bracesDepth > 0) {
            $this->previousChunk = $textChunk;

            return;
        }

        // Handle found object with listener
        $this->listener->onObjectFound(substr($textChunk, 0, $strPosition));

        // If there is nothing after the found object in the text chunk, read a new one to look for a comma-delimiter and other spaces
        // Because someone can put a comma at the beginning of the next line, and not at the end of the current line
        if ($strPosition >= $blockLength) {
            $textChunk = $this->readStream();
            $blockLength = strlen($textChunk);
            $strPosition = 0;
            if (!$textChunk) {

                return;
            }
        }
        // Save rest part of text chunk, skipping comma-delimiter and other spaces
        // Spaces before delimeter
        $this->skipSpaces($textChunk, $strPosition);
        if ($textChunk{$strPosition} === ',') {
            $strPosition++;
            // Spaces after delimeter
            $this->skipSpaces($textChunk, $strPosition);
        }
        $this->previousChunk = $strPosition < $blockLength
            ? substr($textChunk, $strPosition, $blockLength - $strPosition)
            : '';
        // If all that is left of the chunk is the closing bracket ']', then the file is done
        // Do not consider the situation when this bracket will go right after the last object in the chunk, 
        // followed by a text in the file, because it will be invalid json
        if ($this->previousChunk === ']') {

            return;
        }
        // Reset the cursor pointer in the text chunk, because it has been changed
        $strPosition = 0;
        // Recursively check to see if there is another whole object in the remainder
        if ($this->previousChunk) {
            $this->parseChunk('', $strPosition, $bracesDepth, $commaFlag);
        }
    }

    /**
     * Skip all leading spaces in the string
     *
     * @param string $textChunk   Text chunk
     * @param int   &$strPosition Cursor pointer
     */
    protected function skipSpaces($textChunk, &$strPosition)
    {
        $strLength = strlen($textChunk);
        while ($strPosition <= $strLength && ($textChunk{$strPosition} === ' ' || $textChunk{$strPosition} === "\t" || $textChunk{$strPosition} === "\n" || $textChunk{$strPosition} === "\r")) {
            $strPosition++;
        }
    }

    /**
     * Source file ending getter
     *
     * @return bool
     */
    protected function isStreamFinished()
    {
        return gzeof($this->file);
    }

    /**
     * Source file cursor pointer getter
     *
     * @return int
     * @throws Exception
     */
    protected function getStreamPos()
    {
        return gztell($this->file);
    }

    /**
     * Source file opening
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
        // Handle event with listener
        $this->listener->onStart();
    }

    /**
     * Source file description closing
     */
    protected function closeStream()
    {
        gzclose($this->file);
        // Handle event with listener
        $this->listener->onEnd();
    }

    /**
     * Reading next one text chunk from file
     *
     * @param int $length Text chunk length (in characters)
     * @return string
     */
    protected function readStream($length = null)
    {
        $textChunk = stream_get_line($this->file, $length ?: $this->bufferSize, $this->lineEnding);
        // Handle event with listener
        $this->listener->onStreamRead($textChunk, $this->getStreamPos());

        return $textChunk;
    }

    /**
     * Stream initialization
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
     * Stream finalization
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
     * Parse fail with specified message
     *
     * @param string $message Fail message
     * @throws Exception
     */
    protected function fail($message)
    {
        if ($this->file) {
            $message .= '. Stream position: ' . $this->getStreamPos();
        }
        $exception = new Exception($message);
        try {
            // Handle event with listener
            $this->listener->onError($exception);

            // If the listener didn't throw any exception, throw it
            throw $exception;
        } finally {
            // In any case, close the open file descriptor
            $this->closeStream();
        }
    }
}