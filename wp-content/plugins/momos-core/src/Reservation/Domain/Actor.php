<?php
declare(strict_types=1);

namespace Momos\Reservation\Domain;

/**
 * Who performs a reservation change (docs/CONTRACTS.md `$actor`). Staff actors carry a display-name
 * snapshot for the timeline (`actor_display`).
 */
final class Actor {
	private function __construct(
		public readonly ActorType $type,
		public readonly ?int $id,
		public readonly ?string $display
	) {}

	public static function customer(): self {
		return new self( ActorType::Customer, null, null );
	}

	public static function system(): self {
		return new self( ActorType::System, null, null );
	}

	public static function staff( int $user_id ): self {
		$user = get_userdata( $user_id );
		$name = $user ? (string) $user->display_name : '';
		return new self( ActorType::Staff, $user_id, '' !== $name ? mb_substr( $name, 0, 100 ) : null );
	}

	public static function current_staff(): self {
		return self::staff( get_current_user_id() );
	}

	/** @return array{type:string,id:?int,display:?string} */
	public function to_array(): array {
		return [ 'type' => $this->type->value, 'id' => $this->id, 'display' => $this->display ];
	}
}
