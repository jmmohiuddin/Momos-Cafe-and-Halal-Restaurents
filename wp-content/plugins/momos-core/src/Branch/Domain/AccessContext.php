<?php
declare(strict_types=1);

namespace Momos\Branch\Domain;

use Momos\Branch\Service\BranchAccess;

/**
 * Who performs a branch-data write (SEC-011). Repositories that change hours/closures require a
 * context; a user context must be allowed to edit the branch aspect, a system context (jobs, CLI
 * seeding) is always allowed but recorded as actor 0.
 */
final class AccessContext {
	private function __construct( public readonly int $user_id, public readonly bool $system ) {}

	public static function user( int $user_id ): self {
		return new self( $user_id, false );
	}

	public static function current(): self {
		return new self( get_current_user_id(), false );
	}

	public static function system(): self {
		return new self( 0, true );
	}

	/** @throws UnscopedQueryException when the user may not edit $aspect of the branch. */
	public function assert_can( int $branch_id, string $aspect ): void {
		if ( $this->system ) {
			return;
		}
		if ( ! $this->user_id || ! BranchAccess::can_edit( $branch_id, $aspect, $this->user_id ) ) {
			throw new UnscopedQueryException( sprintf( 'User %d may not edit %s of branch %d', $this->user_id, $aspect, $branch_id ) );
		}
	}

	public function actor_id(): ?int {
		return $this->system || ! $this->user_id ? null : $this->user_id;
	}
}
