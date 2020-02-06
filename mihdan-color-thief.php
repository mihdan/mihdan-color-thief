<?php
/**
 * Plugin Name: Mihdan: Color Thief
 * Description: Скрипт определения цветов для фото и сохранение их в базе
 * Plugin URI: https://www.kobzarev.com
 * Author: Mikhail Kobzarev
 * Author URI: https://www.kobzarev.com
 * Version: 1.0.1
 *
 * @link https://github.com/ksubileau/color-thief-php
 * @link http://www.emanueleferonato.com/2009/08/28/color-differences-algorithm/
 * @link https://github.com/renasboy/php-color-difference
 *
 * @package mihdan-color-thief
 */
use Mihdan\ColorThief\Main;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MIHDAN_COLOR_THIEF_FILE', __FILE__ );
define( 'MIHDAN_COLOR_THIEF_DIR', __DIR__ );

require_once 'vendor/autoload.php';

/**
 * Инициализация плагина.
 *
 * @author mikhail@kobzarev.com
 * @return Main|null
 */
function mihdan_color_thief() {
	return Main::get_instance();
}

add_action( 'plugins_loaded', 'mihdan_color_thief' );

// eol.
