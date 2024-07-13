<?php

/**
 * @TODO Improve the error messages, e.g  implement
 * `throw new AsyncResourceException($resource)`
 * that would report which URL failed to download.
 */

namespace WordPress\AsyncHttp;
use WordPress\Streams\StreamPeekerWrapper;
use WordPress\Streams\StreamPeekerData;

use Exception;
use WordPress\Streams\VanillaStreamWrapperData;

class HttpError {
	public $message;

	public function __construct( $message ) {
		$this->message = $message;
	}

}

function is_stream_error( $value ) {
	return $value instanceof HttpError;
}

/**
 * Opens multiple HTTP streams in a non-blocking manner.
 *
 * @param  array  $urls  An array of URLs to open streams for.
 *
 * @return array An array of opened streams.
 * @see stream_http_open_nonblocking
 */
function streams_http_open_nonblocking( $urls ) {
	$streams = array();
	foreach ( $urls as $k => $url ) {
		$streams[ $k ] = stream_http_open_nonblocking( $url );
	}

	return $streams;
}

/**
 * Opens a HTTP or HTTPS stream using stream_socket_client() without blocking,
 * and returns nearly immediately.
 *
 * The act of opening a stream is non-blocking itself. This function uses
 * a tcp:// stream wrapper, because both https:// and ssl:// wrappers would block
 * until the SSL handshake is complete.
 * The actual socket it then switched to non-blocking mode using stream_set_blocking().
 *
 * @param  string  $url  The URL to open the stream for.
 *
 * @return resource|HttpError The opened stream resource or false on failure.
 */
function stream_http_open_nonblocking( $url ) {
	$parts  = parse_url( $url );
	$scheme = $parts['scheme'];
	if ( ! in_array( $scheme, array( 'http', 'https' ) ) ) {
		return new HttpError( 'stream_http_open_nonblocking: Invalid scheme in URL ' . $url . ' – only http:// and https:// URLs are supported' );
	}

	$port = $parts['port'] ?? ( $scheme === 'https' ? 443 : 80 );
	$host = $parts['host'];

	// Create stream context
	$context = stream_context_create(
		array(
			'socket' => array(
				'isSsl'       => $scheme === 'https',
				'originalUrl' => $url,
				'socketUrl'   => 'tcp://' . $host . ':' . $port,
			),
		)
	);

	$stream = @stream_socket_client(
		'tcp://' . $host . ':' . $port,
		$errno,
		$errstr,
		30,
		STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
		$context
	);
	if ( $stream === false ) {
		return new HttpError( "stream_http_open_nonblocking: stream_socket_client() was unable to open a stream to $url. $errno: $errstr" );
	}

	if ( PHP_VERSION_ID >= 72000 ) {
		// In PHP <= 7.1 and later, making the socket non-blocking before the
		// SSL handshake makes the stream_socket_enable_crypto() call always return
		// false. Therefore, we only make the socket non-blocking after the
		// SSL handshake.
		if ( false === stream_set_blocking( $stream, 0 ) ) {
			fclose( $stream );

			return new HttpError( 'stream_http_open_nonblocking: stream_set_blocking() failed for ' . $url );
		}
	}

	return $stream;
}

/**
 * Sends HTTP requests using streams.
 *
 * Takes an array of asynchronous streams open using stream_http_open_nonblocking(),
 * enables crypto on the streams, and sends the request headers asynchronously.
 *
 * @param  array  $streams  An array of streams to send the requests.
 *
 * @throws Exception If there is an error enabling crypto or if stream_select times out.
 */
function streams_http_requests_send( $streams ) {
	$errors            = [];
	$read              = $except = null;
	$remaining_streams = $streams;
	while ( count( $remaining_streams ) ) {
		$write = $remaining_streams;
		// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
		$ready = @stream_select( $read, $write, $except, 0, 5000000 );
		if ( $ready === false ) {
			// stream_select failed
			foreach ( $remaining_streams as $k => $stream ) {
				$errors[ $k ] = new HttpError( 'Error: ' . error_get_last()['message'] );
				return $errors;
			}
		} elseif ( $ready <= 0 ) {
			// stream_select timed out
			foreach ( $remaining_streams as $k => $stream ) {
				$errors[ $k ] = new HttpError( 'stream_select timed out' );
				return $errors;
			}
		}

		foreach ( $write as $k => $stream ) {
			if ( PHP_VERSION_ID <= 71999 ) {
				// In PHP <= 7.1, stream_select doesn't preserve the keys of the array
				$k = array_search( $stream, $streams, true );
			}
			$enabled_crypto = stream_socket_enable_crypto( $stream, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT );
			if ( false === $enabled_crypto ) {
				$errors[ $k ] = new HttpError( 'Failed to enable crypto: ' . error_get_last()['message'] );
				fclose( $remaining_streams[ $k ] );
				unset( $remaining_streams[ $k ] );
			} elseif ( 0 === $enabled_crypto ) {
				// Wait for the handshake to complete
			} else {
				// SSL handshake complete, send the request headers
				// @TODO: Error handling
				$context = stream_context_get_options( $stream );

				// @TODO: Error handling
				$request = stream_http_prepare_request_bytes( $context['socket']['originalUrl'] );

				if ( PHP_VERSION_ID <= 72000 ) {
					// In PHP <= 7.1 and later, making the socket non-blocking before the
					// SSL handshake makes the stream_socket_enable_crypto() call always return
					// false. Therefore, we only make the socket non-blocking after the
					// SSL handshake.

					// @TODO: Error handling
					stream_set_blocking( $stream, 0 );
				}
				// @TODO: Error handling
				fwrite( $stream, $request );
				unset( $remaining_streams[ $k ] );
			}
		}
	}

	return $errors;
}


/**
 * Waits for response bytes to be available in the given streams.
 *
 * @param  array  $streams  The array of streams to wait for.
 * @param  int  $length  The number of bytes to read from each stream.
 * @param  int  $timeout_microseconds  The timeout in microseconds for the stream_select function.
 *
 * @return array|false An array of chunks read from the streams, or false if no streams are available.
 * @throws Exception If an error occurs during the stream_select operation or if the operation times out.
 */
function streams_http_response_await_bytes( $streams, $length, $timeout_microseconds = 50000000 ) {
	$read = $streams;
	if ( count( $read ) === 0 ) {
		return false;
	}

	$write  = array();
	$except = null;

	// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
	$ready = @stream_select( $read, $write, $except, 0, $timeout_microseconds );

	if ( $ready === false ) {
		throw new Exception( 'Could not retrieve response bytes: ' . error_get_last()['message'] );
	} elseif ( $ready <= 0 ) {
		throw new Exception( 'stream_select timed out' );
	}

	$buffers = array();
	foreach ( $read as $k => $stream ) {
		if ( PHP_VERSION_ID <= 71999 ) {
			// In PHP <= 7.1, stream_select doesn't preserve the keys of the array
			$k = array_search( $stream, $streams, true );
		}

		$buffers[ $k ] = fread( $stream, $length );
	}

	return $buffers;
}

function fread_guarantee_n_bytes( $stream, $length ) {
	$buffer = '';
	while ( strlen( $buffer ) < $length ) {
		$next_bytes = fread( $stream, $length - strlen( $buffer ) );
		if ( false === $next_bytes ) {
			return false;
		}
		$buffer .= $next_bytes;
	}

	return $buffer;
}



/**
 * Parses an HTTP headers string into an array containing the status and headers.
 *
 * @param  string  $headers  The HTTP headers to parse.
 *
 * @return array An array containing the parsed status and headers.
 */
function parse_http_headers( string $headers ) {
	$lines   = explode( "\r\n", $headers );
	$status  = array_shift( $lines );
	$status  = explode( ' ', $status );
	$status  = array(
		'protocol' => $status[0],
		'code'     => $status[1],
		'message'  => $status[2],
	);
	$headers = array();
	foreach ( $lines as $line ) {
		if ( strpos( $line, ': ' ) === false ) {
			continue;
		}
		$line = explode( ': ', $line );
		/**
		 * Headers names are case-insensitive.
		 *
		 * RFC 7230 states:
		 *
		 * > Each header field consists of a case-insensitive field name followed by a colon (":"),
		 * > optional leading whitespace, the field value, and optional trailing whitespace."
		 */
		$headers[ strtolower( $line[0] ) ] = $line[1];
	}

	return array(
		'status'  => $status,
		'headers' => $headers,
	);
}

/**
 * Prepares an HTTP request string for a given URL.
 *
 * @param  string  $url  The URL to prepare the request for.
 *
 * @return string The prepared HTTP request string.
 */
function stream_http_prepare_request_bytes( $url ) {
	$parts         = parse_url( $url );
	$host          = $parts['host'];
	$path          = $parts['path'] . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	$request_parts = array(
		"GET $path HTTP/1.1",
		"Host: $host",
		"User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/93.0.4577.82 Safari/537.36",
		"Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9",
		"Accept-Encoding: gzip",
		"Accept-Language: en-US,en;q=0.9",
		"Connection: close",
	);

	return implode( "\r\n", $request_parts ) . "\r\n\r\n";
}

/**
 * Awaits and retrieves the HTTP response headers for multiple streams.
 *
 * @param  array  $streams  An array of streams.
 *
 * @return array An array of HTTP response headers for each stream.
 */
function streams_http_response_await_headers( $streams ) {
	$headers = array();
	foreach ( $streams as $k => $stream ) {
		$headers[ $k ] = '';
	}
	$remaining_streams = $streams;
	while ( true ) {
		$bytes = streams_http_response_await_bytes( $remaining_streams, 1 );
		if ( false === $bytes ) {
			break;
		}
		foreach ( $bytes as $k => $byte ) {
			$headers[ $k ] .= $byte;
			if ( strlen( $headers[ $k ] ) >= 4 && substr_compare( $headers[ $k ], "\r\n\r\n", - strlen( "\r\n\r\n" ) ) === 0 ) {
				unset( $remaining_streams[ $k ] );
			}
		}
	}

	foreach ( $headers as $k => $header ) {
		$headers[ $k ] = parse_http_headers( $header );
	}

	return $headers;
}

/**
 * Monitors the progress of a stream while reading its content.
 *
 * @param  resource  $stream  The stream to monitor.
 * @param  callable  $onProgress  The callback function to be called on each progress update.
 *                             It should accept a single parameters: the number of bytes streamed so far.
 *
 * @return resource The wrapped stream resource.
 */
function stream_monitor_progress( $stream, $onProgress ) {
	return StreamPeekerWrapper::create_resource(
		new StreamPeekerData(
			$stream,
			function ( $data ) use ( $onProgress ) {
				static $streamedBytes = 0;
				$streamedBytes += strlen( $data );
				$onProgress( $streamedBytes );
			}
		)
	);
}

/**
 * Sends multiple HTTP requests asynchronously and returns the response streams.
 *
 * @param  array  $requests  An array of HTTP requests.
 *
 * @return array An array containing the final streams and response headers.
 * @throws Exception If any of the requests fail with a non-successful HTTP code.
 */
function streams_send_http_requests( array $requests ) {
	$urls = array();
	foreach ( $requests as $k => $request ) {
		$urls[ $k ] = $request->url;
	}
	$redirects        = $urls;
	$errors           = array();
	$final_streams    = array();
	$response_headers = array();
	do {
		$streams = streams_http_open_nonblocking( $redirects );
		foreach ( $streams as $k => $result ) {
			if ( is_stream_error( $result ) ) {
				$errors[ $k ] = $result;
				unset( $streams[ $k ] );
			}
		}
		foreach ( streams_http_requests_send( $streams ) as $k => $result ) {
			if ( is_stream_error( $result ) ) {
				$errors[ $k ] = $result;
				unset( $streams[ $k ] );
			}
		}

		$redirects = array();
		// @TODO: Error handling
		$headers = streams_http_response_await_headers( $streams );
		foreach ( array_keys( $headers ) as $k ) {
			$code = $headers[ $k ]['status']['code'];
			if ( $code > 399 || $code < 200 ) {
				throw new Exception( 'Failed to download file ' . $requests[ $k ]->url . ': Server responded with HTTP code ' . $code );
			}
			if ( isset( $headers[ $k ]['headers']['location'] ) ) {
				$redirects[ $k ] = $headers[ $k ]['headers']['location'];
				// @TODO: Error handling
				fclose( $streams[ $k ] );
				continue;
			}

			$final_streams[ $k ]    = $streams[ $k ];
			$response_headers[ $k ] = $headers[ $k ];
		}
	} while ( count( $redirects ) );

	/**
	 * Handle transfer encodings.
	 */
	foreach ( $response_headers as $k => $headers ) {
		$transfer_encodings = array();
		if(isset($headers['headers']['transfer-encoding'])) {
			$transfer_encodings = array_map('trim', explode(',', $headers['headers']['transfer-encoding']));
		}

		if(isset($headers['headers']['content-encoding']) && !in_array($headers['headers']['content-encoding'], $transfer_encodings)) {
			$transfer_encodings[] = $headers['headers']['content-encoding'];
		}

		// var_dump($transfer_encodings);
		// die();
		foreach($transfer_encodings as $transfer_encoding) {
			switch($transfer_encoding) {
				case 'chunked':
					/**
					 * Wrap the stream in a chunked encoding decoder.
					 * There was an attempt to use stream filters, but unfortunately 
					 * they are incompatible with stream_select().
					 */
					$final_streams[ $k ] = ChunkedEncodingStreamWrapper::create_resource(new VanillaStreamWrapperData(
						$final_streams[ $k ]
					));
					break;
				case 'gzip':
				case 'deflate':
					$final_streams[ $k ] = InflateStreamWrapper::create_resource(new InflateStreamWrapperData(
						$final_streams[ $k ],
						$transfer_encoding === 'gzip' ? ZLIB_ENCODING_GZIP : ZLIB_ENCODING_RAW
					));
					break;
				default:
					$errors[ $k ] = new HttpError( 'Unsupported transfer encoding received from the server: ' . $transfer_encoding );
					unset( $streams[ $k ] );
					break;
			}
		}
	}

	return array( $final_streams, $response_headers, $errors );
}
