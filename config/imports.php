<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Import Chunk Size
    |--------------------------------------------------------------------------
    |
    | Number of rows each ProcessProductImportChunk job handles. Keep a chunk
    | well within the queue's retry_after window, or a slow job is handed to
    | a second worker while it is still running.
    |
    */

    'chunk_size' => (int) env('IMPORT_CHUNK_SIZE', 500),

];
