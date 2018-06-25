# JsonFileParser
Large json files parser with gzip support

Usage:
```php
  $listener = new YourCustomJsonFileParserListener();
  $parser = new JsonFileParser($bufferSize, null);
  $parser->parse($archiveFilename, $listener);
```
