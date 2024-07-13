<?php

namespace WordPress\AsyncHttp;

class ChunkedEncodingFilter extends \php_user_filter {
	private $state = self::SCAN_CHUNK_SIZE;
	const SCAN_CHUNK_SIZE = 'SCAN_CHUNK_SIZE';
	const SCAN_CHUNK_DATA = 'SCAN_CHUNK_DATA';
	const SCAN_CHUNK_TRAILER = 'SCAN_CHUNK_TRAILER';
	const SCAN_FINAL_CHUNK = 'SCAN_FINAL_CHUNK';

	private $buffer = '';
	private $chunk_remaining_bytes = 0;

	#[\ReturnTypeWillChange]
	public function onCreate(  ) {
//		$this->$this->params
	}

	#[\ReturnTypeWillChange]
	public function filter( $in, $out, &$consumed, $closing ) {
		while ( $bucket = stream_bucket_make_writeable( $in ) ) {
			var_dump($bucket->data);
			$this->buffer .= $bucket->data;
			$bucket->data = $this->read_next_chunks();
			$bucket->datalen = strlen($bucket->data);
			stream_bucket_append($out, $bucket);
		}
		return PSFS_PASS_ON;
	}

	private function read_next_chunks() {
		$at = 0;
		$chunks = [];
		while($at < strlen($this->buffer)) {
			if ( $this->state === self::SCAN_CHUNK_SIZE ) {
				if ( strpos( $this->buffer, "\r\n", $at ) === false ) {
					break;
				}

				$chunk_bytes = substr( $this->buffer, $at, 2 );
				$clrf_at     = strpos( $this->buffer, "\r\n", $at );
				$at = $clrf_at + 2;

				$this->chunk_remaining_bytes = hexdec( $chunk_bytes );
				$this->state      = self::SCAN_CHUNK_DATA;
			} else if ( $this->state === self::SCAN_CHUNK_DATA ) {
				$bytes_to_read          = min( 
					$this->chunk_remaining_bytes, 
					strlen($this->buffer) - $at
				);
				$data = substr( $this->buffer, $at, $bytes_to_read );
				$chunks[] = $data;
				$at += $bytes_to_read;

				$this->chunk_remaining_bytes -= strlen( $data );
				if ( $this->chunk_remaining_bytes === 0 ) {
					$this->state = self::SCAN_CHUNK_TRAILER;
				}
			} else if ($this->state === self::SCAN_CHUNK_TRAILER) {
				if(strlen($this->buffer) - $at < 2) {
					break;
				}
				$at += 2;
				$this->state = self::SCAN_CHUNK_SIZE;
			}
		}
		$this->buffer = substr($this->buffer, $at);
		return implode('', $chunks);
	}

}

/* Register our filter with PHP */
stream_filter_register( "chunked_encoding", ChunkedEncodingFilter::class ) or die( "Failed to register the chunked_encoding filter" );
