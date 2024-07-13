<?php

namespace WordPress\AsyncHttp;

class InflateStreamWrapperData {
	public $fp;
	public $encoding;

	public function __construct( $fp, $encoding ) {
		$this->fp = $fp;
		$this->encoding = $encoding;
	}
}
