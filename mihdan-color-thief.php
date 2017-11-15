<?php
/**
 * Plugin Name: Mihdan: Color Thief
 * Description: Скрипт определения цветов для фото и сохранение их в базе
 * Plugin URI: https://www.kobzarev.com
 * Author: Mikhail Kobzarev
 * Author URI: https://www.kobzarev.com
 * Version: 1.0.0
 *
 * @link https://github.com/ksubileau/color-thief-php
 * @link http://www.emanueleferonato.com/2009/08/28/color-differences-algorithm/
 * @link https://github.com/renasboy/php-color-difference
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once 'vendor/autoload.php';

use ColorThief\ColorThief;

if ( ! class_exists( 'Mihdan_Color_Thief' ) ) :

	/**
	 * Класс создает таксономию для хранения цветов,
	 * определяет основные цвета изображения при его загрузке,
	 * округляет эти цвета до нашей палитры,
	 * сохраняет резальтат в базу данных, чтобы можно было
	 * сортировать вложения по цветам и делать соответствующие
	 * выборки в шаблонах и виджетах
	 */
	final class Mihdan_Color_Thief {

		const DEBUG = false;
		const TAXONOMY = 'colors';
		const SLUG = 'mihdan-color-thief';

		/**
		 * Название эвента для определения цвета по крону.
		 */
		const SCHEDULE = 'mihdan_color_thief_event';

		/**
		 * Название подстроено под wordpress-seo-premium
		 * от Yoast, чтобы хранить инфу в их метаполе
		 */
		const META = '_yoast_wpseo_primary_colors';

		/**
		 * Путь к плагину
		 *
		 * @var string
		 */
		public static $dir_path;

		/**
		 * URL до плагина
		 *
		 * @var string
		 */
		public static $dir_uri;

		/**
		 * Хранит синглетон класса.
		 *
		 * @var null
		 */
		private static $instance = null;

		/**
		 * Палитра.
		 *
		 * @var array
		 */
		private static $palette = array();

		/**
		 * Возвращает тольо один экземпляр класса.
		 *
		 * @return Mihdan_Color_Thief|null
		 */
		public static function get_instance() {

			if ( is_null( self::$instance ) ) {
				self::$instance = new self;
			}

			return self::$instance;
		}

		/**
		 * Mihdan_Color_Thief constructor.
		 */
		private function __construct() {

			// Дебаггер.
			if ( true === self::DEBUG && file_exists( WP_PLUGIN_DIR . '/wp-php-console/vendor/autoload.php' ) ) {
				require_once WP_PLUGIN_DIR . '/wp-php-console/vendor/autoload.php';

				if ( ! class_exists( 'PC', false ) ) {
					PhpConsole\Helper::register();
				}
			}

			$this->setup();
			$this->hooks();
		}

		/**
		 * Выводит отладочное сообщение.
		 *
		 * @param mixed $str - что выодить.
		 * @param null $label
		 */
		public static function debug( $str, $label = null ) {
			if ( true === self::DEBUG ) {
				PC::debug( $str, $label );
			}
		}

		/**
		 * Установка переменных для работы плагина.
		 */
		private function setup() {
			self::$dir_path = trailingslashit( plugin_dir_path( __FILE__ ) );
			self::$dir_uri = trailingslashit( plugin_dir_url( __FILE__ ) );
		}

		/**
		 * Инициализируем хуки.
		 */
		private function hooks() {
			add_action( 'init', array( $this, 'register_taxonomy' ) );
			add_action( 'registered_taxonomy', array( $this, 'set_palette' ) );
			add_action( 'add_attachment' , array( $this, 'add_schedule' ) );
			add_action( self::SCHEDULE , array( $this, 'color_thief' ) );
			add_action( 'admin_enqueue_scripts' , array( $this, 'enqueue_scripts' ) );
			add_filter( 'manage_media_columns', array( $this, 'add_palette_column' ) );
			add_action( 'manage_media_custom_column', array( $this, 'set_palette_column_content' ), 10, 2 );
		}

		/**
		 * Получить первичный цвет для фото
		 *
		 * @param int $post_id идентификатор поста
		 * @param array $args массив аргументов
		 *
		 * @return array|stdClass|WP_Error
		 */
		public function get_dominant_color( $post_id, $args = array() ) {

			$result = new stdClass();

			$args = wp_parse_args( $args, array(
				'fields' => 'all'
			) );

			$colors = wp_get_post_terms( $post_id, self::TAXONOMY, $args );

			return $colors ? $colors[0] : $result;
		}

		/**
		 * Добавляет задачу на определение цветов по крону
		 * при успешной загрузке фотографии в медиатеку.
		 *
		 * @param integer $attachment_id - идентификатор вложения.
		 * @author mikhail@kobzarev.com
		 * @return null
		 */
		public function add_schedule( $attachment_id ) {

			// Не передан идентификатор вложения.
			if ( ! $attachment_id ) {
				return null;
			}

			// Через сколько выполнить крон-задачу.
			$timestamp = time() + HOUR_IN_SECONDS / 10;

			// Поставим задачу.
			wp_schedule_single_event( $timestamp, self::SCHEDULE, array( $attachment_id ) );
		}

		/**
		 * Добавить стили и скрипты от плагина.
		 */
		public function enqueue_scripts() {
			wp_enqueue_style( self::SLUG, self::$dir_uri . 'assets/css/style.css', array(), null );
		}

		/**
		 * Добавить колонку на страницу со
		 * списком цветов в админке.
		 *
		 * @param array $columns - текущие столбцы.
		 *
		 * @return array
		 */
		public function add_palette_column( $columns ) {
			$columns['mihdan_color_thief_column'] = 'Цвета';
			return $columns;
		}

		public function set_palette_column_content( $column, $post_id ) {
			if ( 'mihdan_color_thief_column' === $column ) {
				$colors = wp_get_post_terms( $post_id, self::TAXONOMY, array(
					'fields' => 'slugs'
				) );

				// Основной цвет.
				$dominant_color = '';
				$dominant_color_id = get_post_meta( $post_id, self::META, true );
				if ( $dominant_color_id ) {
					$dominant_color = get_term( $dominant_color_id, self::TAXONOMY );

					if ( $dominant_color ) {
						$dominant_color = $dominant_color->slug;
					}
				}

				if ( $colors ) {
					$output = '<ul class="mihdan-color-thief">';

					foreach ( $colors as $color ) {
						$class = ( $dominant_color == $color ) ? 'mihdan-color-thief__item_dominant' : '';
						$output .= '<li class="mihdan-color-thief__item ' . $class . '" style="background-color: ' . esc_attr( $color ) . '">' . esc_html( $color ) . '</li>';
					}

					$output .= '</ul>';

					echo $output;
				}
			}
		}


		/**
		 * Заполнить палитру цветами из базы.
		 * Ждем пока таксономия не будет зарегана,
		 * чтобы не получить фатал
		 *
		 * @return boolean
		 */
		public function set_palette( $taxonomy ) {

			if ( self::TAXONOMY === $taxonomy ) {

				$colors = get_terms( array(
					'taxonomy'   => self::TAXONOMY,
					'hide_empty' => false,
				) );

				if ( $colors ) {
					self::$palette = wp_list_pluck( $colors, 'description', 'term_id' );
				}
			}

			return true;
		}

		/**
		 * Получить палитру сайта. Если цвета заполнили в админке
		 *
		 * @return array
		 */
		public static function get_palette() {
			return self::$palette;
		}

		/**
		 * Регистрация такосномии для цветов
		 */
		public function register_taxonomy() {
			$labels = [
				"name" => "Цвета",
				"singular_name" => "Цвет",
			];

			$args = array(
				"labels" => $labels,
				"hierarchical" => true,
				"label" => "Цвета",
				"show_ui" => true,
				"show_in_nav_menus" => true,
				"show_in_quick_edit" => true,
				"query_var" => true,
				"rewrite" => [
					'slug' => 'colors',
					'with_front' => false
				],
				'show_admin_column' => false,
			);
			register_taxonomy( self::TAXONOMY, [ 'attachment', 'product_', 'post' ], $args );
		}

		/**
		 * Перевести цвет из RGB вида в HEX
		 *
		 * @param int $r красный.
		 * @param int $g зеленый.
		 * @param int $b синий.
		 *
		 * @return string
		 */
		public static function rgb_to_hex( $r, $g, $b ) {
			return sprintf( '#%02x%02x%02x', $r, $g, $b );
		}

		/**
		 * Перевести цвет из HEX в RGB
		 *
		 * @param string $hex - цвет вида #ff0011
		 *
		 * @return mixed
		 */
		public function hex_to_rgb( $hex ) {
			$hex = str_replace( '#', '', $hex );
			return sscanf( $hex, '%02x%02x%02x' );
		}

		/**
		 * Перевести цвет из RGB в XYZ
		 *
		 * @param array $rgb - цвет в RGB
		 *
		 * @return array
		 */
		private function rgb_to_xyz( $rgb ) {
			list( $r, $g, $b ) = $rgb;
			$r = $r <= 0.04045 ? $r / 12.92 : pow( ( $r + 0.055 ) / 1.055, 2.4 );
			$g = $g <= 0.04045 ? $g / 12.92 : pow( ( $g + 0.055 ) / 1.055, 2.4 );
			$b = $b <= 0.04045 ? $b / 12.92 : pow( ( $b + 0.055 ) / 1.055, 2.4 );
			$r *= 100;
			$g *= 100;
			$b *= 100;
			$x = $r * 0.412453 + $g * 0.357580 + $b * 0.180423;
			$y = $r * 0.212671 + $g * 0.715160 + $b * 0.072169;
			$z = $r * 0.019334 + $g * 0.119193 + $b * 0.950227;

			return array( $x, $y, $z );
		}

		/**
		 * Перевод цвета из XYZ в LAB формат
		 *
		 * @param array $xyz - цвет в формате XYZ
		 *
		 * @return array
		 */
		private function xyz_to_lab( $xyz ){
			list ( $x, $y, $z ) = $xyz;
			$x /= 95.047;
			$y /= 100;
			$z /= 108.883;
			$x = $x > 0.008856 ? pow( $x, 1 / 3 ) : $x * 7.787 + 16 / 116;
			$y = $y > 0.008856 ? pow( $y, 1 / 3 ) : $y * 7.787 + 16 / 116;
			$z = $z > 0.008856 ? pow( $z, 1 / 3 ) : $z * 7.787 + 16 / 116;
			$l = $y * 116 - 16;
			$a = ( $x - $y ) * 500;
			$b = ( $y - $z ) * 200;

			return array( $l, $a, $b );
		}

		/**
		 * Implemented as in "The CIEDE2000 Color-Difference Formula:
		 * Implementation Notes, Supplementary Test Data, and Mathematical Observations"
		 * by Gaurav Sharma, Wencheng Wu and Edul N. Dalal.
		 *
		 * @param array $c1 - цвет первый в формате LAB
		 * @param array $c2 - цвет второй в формате LAB
		 *
		 * @return float
		 */
		private function ciede2000( $c1, $c2 ) {

			list( $l1, $a1, $b1 ) = $c1;
			list( $l2, $a2, $b2 ) = $c2;

			$avg_lp     = ($l1 + $l2) / 2;
			$c1 = sqrt( pow( $a1, 2 ) + pow( $b1, 2 ) );
			$c2 = sqrt( pow( $a2, 2 ) + pow( $b2, 2 ) );
			$avg_c = ( $c1 + $c2 ) / 2;
			$g = ( 1 - sqrt( pow( $avg_c, 7 ) / ( pow( $avg_c, 7 ) + pow( 25, 7 ) ) ) ) / 2;
			$a1p = $a1 * ( 1 + $g );
			$a2p = $a2 * ( 1 + $g );
			$c1p = sqrt( pow( $a1p, 2 ) + pow( $b1, 2 ) );
			$c2p = sqrt( pow( $a2p, 2 ) + pow( $b2, 2 ) );
			$avg_cp = ( $c1p + $c2p ) / 2;
			$h1p = rad2deg( atan2( $b1, $a1p ) );
			if ($h1p < 0) {
				$h1p    += 360;
			}
			$h2p        = rad2deg(atan2($b2, $a2p));
			if ($h2p < 0) {
				$h2p    += 360;
			}
			$avg_hp     = abs($h1p - $h2p) > 180 ? ($h1p + $h2p + 360) / 2 : ($h1p + $h2p) / 2;
			$t          = 1 - 0.17 * cos(deg2rad($avg_hp - 30)) + 0.24 * cos(deg2rad(2 * $avg_hp)) + 0.32 * cos(deg2rad(3 * $avg_hp + 6)) - 0.2 * cos(deg2rad(4 * $avg_hp - 63));
			$delta_hp   = $h2p - $h1p;
			if (abs($delta_hp) > 180) {
				if ($h2p <= $h1p) {
					$delta_hp += 360;
				}
				else {
					$delta_hp -= 360;
				}
			}
			$delta_lp   = $l2 - $l1;
			$delta_cp   = $c2p - $c1p;
			$delta_hp   = 2 * sqrt($c1p * $c2p) * sin(deg2rad($delta_hp) / 2);
			$s_l        = 1 + ((0.015 * pow($avg_lp - 50, 2)) / sqrt(20 + pow($avg_lp - 50, 2)));
			$s_c        = 1 + 0.045 * $avg_cp;
			$s_h        = 1 + 0.015 * $avg_cp * $t;
			$delta_ro   = 30 * exp(-(pow(($avg_hp - 275) / 25, 2)));
			$r_c        = 2 * sqrt(pow($avg_cp, 7) / (pow($avg_cp, 7) + pow(25, 7)));
			$r_t        = -$r_c * sin(2 * deg2rad($delta_ro));
			$kl = $kc = $kh = 1;
			$delta_e    = sqrt(pow($delta_lp / ($s_l * $kl), 2) + pow($delta_cp / ($s_c * $kc), 2) + pow($delta_hp / ($s_h * $kh), 2) + $r_t * ($delta_cp / ($s_c * $kc)) * ($delta_hp / ($s_h * $kh)));
			return $delta_e;
		}

		/**
		 * При записи аттачмента в базу, определим его палитру цветов,
		 * округлим цвета до наших из базы, добавим полученные цвета к вложению
		 *
		 * @param int $attachment_id - идентификатор вложения.
		 * @author mikhail@kobzarev.com
		 * @return null
		 */
		public function color_thief( $attachment_id ) {

			// Не передан идентификатор вложения.
			if ( ! $attachment_id ) {
				return null;
			}

			if ( wp_attachment_is_image( $attachment_id ) ) {

				$colors = array();
				$file = get_attached_file( $attachment_id );
				$palette = ColorThief::getPalette( $file, 5, 5 );
				//$dominant  = ColorThief::getColor( $file );
				//self::debug($palette,'all');
				//self::debug($dominant,'dominant');

				if ( $palette ) {
					foreach ( $palette as $rgb ) {
						$rounded = $this->get_closest_color( $rgb );

						if ( false !== ( $key = array_search( $rounded, self::$palette ) ) ) {
							$colors[] = $key;
						}

					}
					$dominant_color = $colors[0];
					// Выбрать только уникальные цвета.
					$colors = array_unique( $colors );

					// Урезать до пяти.
					//$colors = array_slice( $colors, 0, 5 );

					// Прикрепить полученные цвета к фото.
					if ( $colors ) {
						wp_set_post_terms( $attachment_id, $colors, self::TAXONOMY );
						add_post_meta( $attachment_id, self::META, $dominant_color, true );
					}
				}
			}
		}

		/**
		 * Вычисляем расстояние между двух цветов
		 * по алгоритму CIEDE2000
		 *
		 * @param array $col1 - цвет первый в RGB
		 * @param array $col2 - цвет второй в RGB
		 *
		 * @return float
		 *
		 * @link ttps://en.wikipedia.org/wiki/Color_difference#CIEDE2000
		 */
		private function get_distance_between_colors( $col1, $col2 ) {

			$xyz1 = $this->rgb_to_xyz( $col1 );
			$xyz2 = $this->rgb_to_xyz( $col2 );

			$lab1 = $this->xyz_to_lab( $xyz1 );
			$lab2 = $this->xyz_to_lab( $xyz2 );

			return $this->ciede2000( $lab1, $lab2 );
		}

		/**
		 * @param $color
		 *
		 * @return mixed
		 *
		 * @link https://www.compuphase.com/cmetric.htm
		 */
		public function get_closest_color( $color ) {

			$distinction = array();

			//arsort( self::$palette );

			foreach ( self::$palette as $term_id => $baseColor ) {

				//list( $baseRedColor, $baseGreenColor, $baseBlueColor ) = $this->hex_to_rgb( $baseColor );

				// https://en.wikipedia.org/wiki/Color_difference
				// Расстояние считаем по формуле
				// d2 = (r1 - r2)^2 + (g1 - g2)^2 + (b1 - b2)^2;
				/*$sqrt = sqrt(
					pow( ( $color[0] - $baseRedColor ), 2 ) +
					pow( ( $color[1] - $baseGreenColor ), 2 ) +
					pow( ( $color[2] - $baseBlueColor ), 2 )
				);*/

				/*$sqrt = sqrt(
					pow( 30 * ( $color[0] - $baseRedColor ), 2 ) +
					pow( 59 * ( $color[1] - $baseGreenColor ), 2 ) +
					pow( 11 * ( $color[2] - $baseBlueColor ), 2 )
				);*/

				/*$sqrt = (
					abs ( $color[0] - $baseRedColor ) +
					abs ( $color[1] - $baseGreenColor ) +
					abs ( $color[2] - $baseBlueColor )
				);*/

				$sqrt = $this->get_distance_between_colors( $color, $this->hex_to_rgb( $baseColor ) );

				$distinction[ "$sqrt" ] = $term_id;
			}//PC::debug($distinction);

			$min_value = min( array_keys( $distinction ) );
			$index = $distinction[ $min_value ];

			return self::$palette[ $index ];
		}
	}

	/**
	 * Инифиализация плагина.
	 *
	 * @author mikhail@kobzarev.com
	 * @return Mihdan_Color_Thief|null
	 */
	function mihdan_color_thief() {
		return Mihdan_Color_Thief::get_instance();
	}
	add_action( 'plugins_loaded', 'mihdan_color_thief' );

endif;