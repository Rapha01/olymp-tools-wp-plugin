<?php
// phpcs:ignoreFile -- Bundled third-party library (MaxMind DB Reader, Apache-2.0), kept unmodified; exceptions are never rendered as HTML and the reader needs random-access fopen/fread on the local .mmdb file, which WP_Filesystem cannot provide.

declare(strict_types=1);

namespace MaxMind\Db\Reader;

/**
 * This class should be thrown when unexpected data is found in the database.
 */
// phpcs:disable
class InvalidDatabaseException extends \Exception {}
