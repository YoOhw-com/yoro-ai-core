<?php

if ( ! defined( 'ABSPATH' ) ) { exit; }

class YoRo_Support_View {
	public static function color( $color ) {
		if ( ! is_string( $color ) || ! preg_match( '/^#[a-f0-9]{6}$/i', $color ) ) { return '#2b3a20'; }
		$channels = [];
		foreach ( [ 1, 3, 5 ] as $offset ) {
			$value = hexdec( substr( $color, $offset, 2 ) ) / 255;
			$channels[] = $value <= 0.04045 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}
		$luminance = .2126 * $channels[0] + .7152 * $channels[1] + .0722 * $channels[2];
		return 1.05 / ( $luminance + .05 ) >= 4.5 ? $color : '#2b3a20';
	}

	/** Fixed bundled assets only; an invalid stored choice falls back to the default. */
	public static function icon( $choice ) {
		$choice = in_array( $choice, [ 'yoro-animated', 'yoro-static', 'chat' ], true ) ? $choice : 'yoro-animated';
		$glyph = '<span data-icon-fallback' . ( 'chat' === $choice ? '' : ' hidden' ) . '><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" focusable="false"><path d="M5 4h14a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-6 4V6a2 2 0 0 1 2-2Z"/><path d="M7 9h10M7 13h7"/></svg></span>';
		if ( 'chat' === $choice ) { return '<span class="ys-support-icon" aria-hidden="true">' . $glyph . '</span>'; }
		$static = plugins_url( 'assets/images/yoro-c-64-static.svg', YORO_AI_CORE_FILE );
		$src = 'yoro-animated' === $choice ? plugins_url( 'assets/images/yoro-c-64-2x-3-cycles.svg', YORO_AI_CORE_FILE ) : $static;
		return '<span class="ys-support-icon" aria-hidden="true"><img data-support-icon data-static="' . esc_url( $static ) . '" src="' . esc_url( $src ) . '" alt="" width="32" height="32">' . $glyph . '</span>';
	}

	public static function widget( array $settings ) {
		$p = YoRo_Support_Context::preferences( $settings ); $a = $p['appearance'];
		$position = 'left' === $a['position'] ? 'left' : 'right';
		$starters = [ 'products' => __( 'Find a product', 'yoro-ai-core' ), 'policies' => __( 'Shipping & returns', 'yoro-ai-core' ), 'quote' => __( 'Estimate my total', 'yoro-ai-core' ), 'orders' => __( 'Track my order', 'yoro-ai-core' ) ];
		echo '<div id="yoro-support" class="yoro-support-' . esc_attr( $position ) . '" style="--ys-accent:' . esc_attr( self::color( $a['color'] ) ) . '" data-endpoint="' . esc_url( rest_url( 'yoro/v1/support/' ) ) . '">';
		echo '<button type="button" class="yoro-support-launch" aria-expanded="false" aria-controls="yoro-support-panel">' . self::icon( $a['icon'] ) . ' ' . esc_html( $a['launcher'] ) . '</button>';
		echo '<section id="yoro-support-panel" hidden role="dialog" aria-label="' . esc_attr( $a['name'] ) . '"><header><span class="yoro-support-avatar">' . self::icon( $a['icon'] ) . '</span><div><strong>' . esc_html( $a['name'] ) . '</strong><small>' . esc_html__( 'AI shopping assistant', 'yoro-ai-core' ) . '</small></div><button type="button" data-reset aria-label="' . esc_attr__( 'New conversation', 'yoro-ai-core' ) . '">↺</button><button type="button" data-close aria-label="' . esc_attr__( 'Close chat', 'yoro-ai-core' ) . '">×</button></header>';
		echo '<div class="yoro-support-verified" hidden><span data-verified-label></span><button type="button" data-end-private>' . esc_html__( 'End private lookup', 'yoro-ai-core' ) . '</button></div>';
		echo '<div class="yoro-support-body"><div class="yoro-support-welcome"><span class="yoro-support-eyebrow">' . esc_html__( 'HERE TO HELP', 'yoro-ai-core' ) . '</span><h2>' . esc_html( $a['welcome'] ) . '</h2><p>' . esc_html__( 'Ask a question about the store, or contact the team for more help.', 'yoro-ai-core' ) . '</p><div class="yoro-support-starters">';
		foreach ( array_slice( $a['starters'], 0, 4 ) as $key ) {
			if ( ! isset( $starters[ $key ] ) || ( 'orders' === $key && ! $p['features']['orders'] ) || ( 'quote' === $key && ! $p['features']['quote'] ) || ( 'products' === $key && ! $p['features']['products'] ) ) { continue; }
			echo '<button type="button" data-starter="' . esc_attr( $key ) . '">' . esc_html( $starters[ $key ] ) . ' <span aria-hidden="true">↗</span></button>';
		}
		echo '</div></div><div class="yoro-support-messages" role="log" aria-live="polite" aria-relevant="additions"></div><div class="yoro-support-context" aria-label="' . esc_attr__( 'Support information form', 'yoro-ai-core' ) . '"></div></div>';
		echo '<p data-status role="status"></p><form class="yoro-support-composer"><label class="yoro-support-sr" for="yoro-support-message">' . esc_html__( 'Your message', 'yoro-ai-core' ) . '</label><textarea id="yoro-support-message" maxlength="2000" rows="1" placeholder="' . esc_attr__( 'Ask us anything about the store…', 'yoro-ai-core' ) . '" required></textarea><button type="submit" aria-label="' . esc_attr__( 'Send message', 'yoro-ai-core' ) . '">↑</button><button type="button" data-retry hidden>' . esc_html__( 'Retry', 'yoro-ai-core' ) . '</button></form>';
		echo '<footer><details><summary>' . esc_html__( 'AI answers · Privacy & contact', 'yoro-ai-core' ) . '</summary><p>' . esc_html__( 'AI can make mistakes. Messages are encrypted and retained for up to 7 days. Do not share codes or sensitive information in chat.', 'yoro-ai-core' ) . '</p><p class="yoro-support-contact">' . esc_html( $settings['contact'] ) . '</p></details></footer></section></div>';
	}
}
