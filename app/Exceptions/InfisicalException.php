<?php

namespace App\Exceptions;

use Exception;

/**
 * Raised when the Infisical API cannot be reached, rejects our credentials, or
 * answers with something we cannot use. The message is user-facing: it is shown
 * in the sync report and, for pre-deployment syncs, in the deployment log.
 */
class InfisicalException extends Exception {}
