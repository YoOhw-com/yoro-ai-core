<?php
/** Safety/capability copy projection only; synthetic settings and no service calls. */
require __DIR__ . '/appearance-test.php';
foreach ( [ false, true ] as $orders ) {
    ob_start(); YoRo_Support_View::widget( [ 'contact' => '<Contact>', 'preferences' => [ 'features' => [ 'orders' => $orders, 'quote' => false, 'products' => false ], 'appearance' => [ 'starters' => [ 'orders', 'quote', 'products', 'policies' ] ] ] ] ); $html = ob_get_clean();
    verify( str_contains( $html, 'Ask a question about the store, or contact the team for more help.' ) );
    verify( str_contains( $html, 'AI can make mistakes. Messages are encrypted and retained for up to 7 days. Do not share codes or sensitive information in chat.' ) );
    verify( ! str_contains( $html, 'Use the verification form for order access' ) && ! str_contains( $html, 'securely look up an order' ) );
    verify( str_contains( $html, 'data-starter="orders"' ) === $orders );
    verify( ! str_contains( $html, 'data-starter="quote"' ) && ! str_contains( $html, 'data-starter="products"' ) );
    verify( str_contains( $html, 'data-end-private' ) && str_contains( $html, '&lt;Contact&gt;' ) );
}
echo json_encode( [ 'suite' => 'G040-public-copy', 'assertions_cumulative' => $checks, 'result' => 'passed', 'orders_on_off' => true, 'private_positive_journey' => 'NOT_RUN' ] ) . "\n";
