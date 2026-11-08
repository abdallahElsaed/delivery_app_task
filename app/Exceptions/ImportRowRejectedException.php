<?php

namespace App\Exceptions;

use Exception;

/**
 * A row that can't be imported as it stands; the message is the reason
 * recorded on its failed-row record.
 */
class ImportRowRejectedException extends Exception {}
