<?php
declare(strict_types=1);

namespace Momos\Kernel\I18n;

use Momos\Kernel\Fields\FieldRegistry;
use Momos\Kernel\Fields\OptionsPage;
use Momos\Kernel\ModuleInterface;

/**
 * Kernel services that every module relies on: field layer, options pages, language routing,
 * translation storage and the translation editor.
 */
final class I18nModule implements ModuleInterface {
	public function register(): void {
		FieldRegistry::boot();
		OptionsPage::boot();
		Router::register();
		Admin\TranslationEditor::register();

		// Native content types: pages and news posts translate title, content and excerpt.
		$core = [
			'title'   => [ 'label' => 'Title', 'type' => 'text', 'source' => 'post_title', 'lccs' => true ],
			'content' => [ 'label' => 'Content', 'type' => 'html', 'source' => 'post_content', 'lccs' => true ],
			'excerpt' => [ 'label' => 'Summary', 'type' => 'textarea', 'source' => 'post_excerpt' ],
		];
		Translations::register( 'post:page', $core );
		Translations::register( 'post:post', $core );

		add_action( 'init', [ self::class, 'load_textdomain' ], 0 );
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'momos-core', false, dirname( plugin_basename( MOMOS_CORE_FILE ) ) . '/languages' );
	}
}
