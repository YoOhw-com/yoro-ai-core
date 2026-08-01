<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface YoRo_Connector_Media_Client_Interface {

	public function download_media_reference( $reference_id, $destination );
}
