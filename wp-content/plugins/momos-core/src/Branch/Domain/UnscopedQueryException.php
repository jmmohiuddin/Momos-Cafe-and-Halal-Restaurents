<?php
declare(strict_types=1);

namespace Momos\Branch\Domain;

/** Thrown when branch data is written without a permitted access context (SEC-011). */
final class UnscopedQueryException extends \RuntimeException {}
