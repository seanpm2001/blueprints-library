<?php

namespace WordPress\Blueprints\Steps;

use WordPress\Blueprints\Model\DataClass\WriteFileStep;
use WordPress\Blueprints\Progress\Tracker;

class WriteFileHandler extends BaseStepHandler {

	public function execute(
		WriteFileStep $input,
		Tracker $progress = null
	) {
		$fp2 = fopen( $input->path, 'w' );
		if ( $fp2 === false ) {
			throw new \Exception( "Failed to open file at {$input->path}" );
		}
		stream_copy_to_stream( $this->getResource( $input->data ), $fp2 );
		fclose( $fp2 );
	}

}
