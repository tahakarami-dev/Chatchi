<?php
/**
 * Widget color tokens.
 *
 * @package ChatChi
 */

namespace ChatChi\Frontend;

use ChatChi\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Derives the primary color tokens of the design system from one color.
 *
 * Text on the primary color switches from white to dark when white would
 * not reach the WCAG AA contrast ratio of 4.5:1.
 */
final class ColorPalette {

	public const DEFAULT_PRIMARY = '#5B5BD6';

	private const DARK_TEXT = '#1A1A2E';

	private const MIN_CONTRAST = 4.5;

	private ?Settings $settings;

	/**
	 * @param Settings|null $settings Plugin settings; without them the default color is used.
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * Returns the configured primary color as #RRGGBB.
	 */
	public function primary(): string {
		$chosen = $this->settings ? (string) $this->settings->get( 'primary_color' ) : '';

		/**
		 * Filters the widget's primary color.
		 *
		 * @param string $color Hex color, e.g. #5B5BD6: the one chosen in the settings, or the default.
		 */
		$color = sanitize_hex_color( (string) apply_filters( 'chatchi_primary_color', '' !== $chosen ? $chosen : self::DEFAULT_PRIMARY ) );

		return $color ? $this->normalize( $color ) : self::DEFAULT_PRIMARY;
	}

	/**
	 * Returns the primary color tokens.
	 *
	 * @return array{primary: string, primaryHover: string, primarySoft: string, primaryContrast: string}
	 */
	public function tokens(): array {
		$primary = $this->primary();
		$rgb     = $this->rgb( $primary );

		return array(
			'primary'         => $primary,
			'primaryHover'    => $this->hex( $this->mix( $rgb, array( 0, 0, 0 ), 0.12 ) ),
			'primarySoft'     => $this->hex( $this->mix( $rgb, array( 255, 255, 255 ), 0.9 ) ),
			'primaryContrast' => $this->contrast( $rgb, array( 255, 255, 255 ) ) >= self::MIN_CONTRAST ? '#FFFFFF' : self::DARK_TEXT,
		);
	}

	/**
	 * Returns the WCAG contrast ratio of two colors.
	 *
	 * @param int[] $a RGB.
	 * @param int[] $b RGB.
	 */
	public function contrast( array $a, array $b ): float {
		$la = $this->luminance( $a );
		$lb = $this->luminance( $b );

		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * @param string $hex #RGB or #RRGGBB.
	 */
	private function normalize( string $hex ): string {
		$hex = ltrim( $hex, '#' );

		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		return '#' . strtoupper( $hex );
	}

	/**
	 * @param string $hex #RRGGBB.
	 * @return int[]
	 */
	private function rgb( string $hex ): array {
		return array_map( 'hexdec', str_split( ltrim( $hex, '#' ), 2 ) );
	}

	/**
	 * @param int[] $rgb RGB.
	 */
	private function hex( array $rgb ): string {
		return sprintf( '#%02X%02X%02X', ...$rgb );
	}

	/**
	 * Mixes a color towards a target by the given weight (0–1).
	 *
	 * @param int[] $rgb    Base color.
	 * @param int[] $target Target color.
	 * @param float $weight Share of the target.
	 * @return int[]
	 */
	private function mix( array $rgb, array $target, float $weight ): array {
		$mixed = array();

		foreach ( $rgb as $i => $channel ) {
			$mixed[] = (int) round( $channel + ( $target[ $i ] - $channel ) * $weight );
		}

		return $mixed;
	}

	/**
	 * @param int[] $rgb RGB.
	 */
	private function luminance( array $rgb ): float {
		$linear = array_map(
			static function ( int $channel ): float {
				$c = $channel / 255;

				return $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
			},
			$rgb
		);

		return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
	}
}
