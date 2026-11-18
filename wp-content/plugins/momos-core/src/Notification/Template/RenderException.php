<?php
declare(strict_types=1);

namespace Momos\Notification\Template;

/** Template/data problem → permanent `render_failed` (spec §4.3 step 3). Message carries no PII. */
final class RenderException extends \RuntimeException {}
