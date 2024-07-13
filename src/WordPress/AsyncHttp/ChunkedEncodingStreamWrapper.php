<?php

namespace WordPress\AsyncHttp;

use WordPress\Streams\VanillaStreamWrapper;

class ChunkedEncodingStreamWrapper extends VanillaStreamWrapper {

	const SCHEME = 'chunked-http-response';

	private $state = self::SCAN_CHUNK_SIZE;
	const SCAN_CHUNK_SIZE = 'SCAN_CHUNK_SIZE';
	const SCAN_CHUNK_DATA = 'SCAN_CHUNK_DATA';
	const SCAN_CHUNK_TRAILER = 'SCAN_CHUNK_TRAILER';
	const SCAN_FINAL_CHUNK = 'SCAN_FINAL_CHUNK';

	private $raw_buffer = '';
	private $decoded_buffer = '';
	private $chunk_remaining_bytes = 0;

	public function stream_read( $count ) {
		$bytes = parent::stream_read( $count );
		if($bytes === false) {
			return false;
		}
		$this->raw_buffer .= $bytes;

		$this->decoded_buffer .= $this->decode_chunks();
		$return_bytes = substr($this->decoded_buffer, 0, $count);
		$this->decoded_buffer = substr($this->decoded_buffer, strlen($return_bytes));

		return $return_bytes;
	}

	private function decode_chunks() {
		if(self::SCAN_FINAL_CHUNK === $this->state) {
			return '';
		}
		
		$at = 0;
		$chunks = [];
		while($at < strlen($this->raw_buffer)) {
			if ( $this->state === self::SCAN_CHUNK_SIZE ) {
				$chunk_bytes_nb = strspn( $this->raw_buffer, '0123456789abcdefABCDEF', $at );
				// We can't yet be sure that the chunk size is complete, let's wait for the CRLF.
				if($chunk_bytes_nb === 0 || strlen($this->raw_buffer) < $chunk_bytes_nb + 2 ) {
					break;
				}
				
				// Check if we received chunk extension and skip over it if yes.
				if($this->raw_buffer[$chunk_bytes_nb] === ";") {
					++$at;
				}

				// Ensure that the chunk size is followed by CRLF. If not, the data
				// is likely incomplete. Let's bale and wait for more bytes.
				$clrf_at = strpos( $this->raw_buffer, "\r\n", $at );
				if ( false === $clrf_at ) {
					break;
				}

				$chunk_bytes = substr( $this->raw_buffer, $at, $chunk_bytes_nb );
				$at = $clrf_at + 2;

				$this->chunk_remaining_bytes = hexdec( $chunk_bytes );
				if(0 === $this->chunk_remaining_bytes) {
					$this->state = self::SCAN_FINAL_CHUNK;
					break;
				} else {
					$this->state = self::SCAN_CHUNK_DATA;
				}
			} else if ( $this->state === self::SCAN_CHUNK_DATA ) {
				$bytes_to_read          = min( 
					$this->chunk_remaining_bytes, 
					strlen($this->raw_buffer) - $at
				);
				$data = substr( $this->raw_buffer, $at, $bytes_to_read );
				$chunks[] = $data;
				$at += $bytes_to_read;

				$this->chunk_remaining_bytes -= strlen( $data );
				if ( $this->chunk_remaining_bytes === 0 ) {
					$this->state = self::SCAN_CHUNK_TRAILER;
				}
			} else if ($this->state === self::SCAN_CHUNK_TRAILER) {
				if(strlen($this->raw_buffer) - $at < 2) {
					break;
				}
				if("\r\n" !== substr($this->raw_buffer, $at, 2)) {
					throw new \Exception('Expected CRLF after chunk data');
				}
				$at += 2;
				$this->state = self::SCAN_CHUNK_SIZE;
			}
		}
		$this->raw_buffer = substr($this->raw_buffer, $at);
		return implode('', $chunks);
	}

}
