<?php
declare(strict_types=1);

namespace Momos\Menu\Support;

/**
 * Domain error of a branch menu write, mapped 1:1 to the API error catalogue (API-15):
 * `{code, message, data:{status, fields}}`.
 */
final class MenuItemException extends \RuntimeException {
	/**
	 * @param array<string,string> $fields field => required|invalid_format|out_of_range|not_allowed|forbidden
	 * @param array<string,mixed>  $data   extra data (e.g. current row on version_conflict)
	 */
	public function __construct(
		public readonly string $error_code,
		public readonly int $status,
		string $message,
		public readonly array $fields = [],
		public readonly array $data = []
	) {
		parent::__construct( $message );
	}

	public function to_wp_error(): \WP_Error {
		$data = [ 'status' => $this->status ];
		if ( $this->fields ) {
			$data['fields'] = $this->fields;
		}
		return new \WP_Error( $this->error_code, $this->getMessage(), $data + $this->data );
	}
}
