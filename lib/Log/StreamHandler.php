<?php

namespace Resque\Log;

use Resque\Libs\Monolog\Handler\AbstractProcessingHandler;

class StreamHandler extends AbstractProcessingHandler {

    /**
     * {@inheritdoc}
     */
    protected function write(array $record): void {
        file_put_contents('php://stdout', (string)$record['formatted'], FILE_APPEND);
    }
}