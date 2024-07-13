<?php

use WordPress\AsyncHttp\Client;
use WordPress\AsyncHttp\Request;
use function WordPress\AsyncHttp\streams_http_response_await_bytes;
use function WordPress\AsyncHttp\streams_send_http_requests;

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/src/WordPress/AsyncHttp/ChunkedEncodingFilter.php';
require_once __DIR__ . '/src/WordPress/Streams/StreamWrapperInterface.php';
require_once __DIR__ . '/src/WordPress/Streams/VanillaStreamWrapper.php';
require_once __DIR__ . '/src/WordPress/Streams/StreamPeekerWrapper.php';
require_once __DIR__ . '/src/WordPress/AsyncHttp/InflateStreamWrapper.php';
require_once __DIR__ . '/src/WordPress/AsyncHttp/InflateStreamWrapperData.php';

$requests = [
//	new Request("https://playground.internal"),
	// new Request("https://anglesharp.azurewebsites.net/Chunked"),
	new Request("https://anglesharp.azurewebsites.net/Chunked"),
];
// list($streams, $headers, $errors) = streams_send_http_requests($requests);
// print_r($streams);
// print_r($errors);

// var_dump(streams_http_response_await_bytes($streams, 1024));
// Enqueuing another request here is instant and won't start the download yet.
$client = new Client();
$streams = $client->enqueue( $requests );
// var_dump($client->read_bytes($requests[0], 166));
var_dump(stream_get_contents($streams[0]));
die();
$client = new Client();
$client->set_progress_callback( function ( Request $request, $downloaded, $total ) {
	echo "$request->url – Downloaded: $downloaded / $total\n";
} );

$requests = [
	new Request("https://anglesharp.azurewebsites.net/Chunked")
	// new Request( "https://downloads.wordpress.org/plugin/gutenberg.17.7.0.zip" ),
	// new Request( "https://downloads.wordpress.org/theme/pendant.zip" ),
];
$streams1 = $client->enqueue( $requests );
// Enqueuing another request here is instant and won't start the download yet.
//$streams2 = $client->enqueue( [
//	new Request( "https://downloads.wordpress.org/plugin/hello-dolly.1.7.3.zip" ),
//] );

try {
	$client->read_bytes($requests[0], 4096);
	// var_dump(stream_get_contents($streams1[0]));
} catch (Exception $e) {
	echo $e->getMessage();
}
print_r($client);
print_r(stream_context_get_options($streams1[0]));
// Stream a single file, while streaming all the files
// file_put_contents( 'output-round1-0.zip', stream_get_contents( $streams1[0] ) );
//file_put_contents( 'output-round1-1.zip', stream_get_contents( $streams1[1] ) );
die();
// Initiate more HTTPS requests
$streams3 = $client->enqueue( [
	new Request( "https://downloads.wordpress.org/plugin/akismet.4.1.12.zip" ),
	new Request( "https://downloads.wordpress.org/plugin/hello-dolly.1.7.3.zip" ),
	new Request( "https://downloads.wordpress.org/plugin/hello-dolly.1.7.3.zip" ),
] );

// Download the rest of the files. Foreach() seems like downloading things
// sequentially, but we're actually streaming all the files in parallel.
$streams = array_merge( $streams2, $streams3 );
foreach ( $streams as $k => $stream ) {
	file_put_contents( 'output-round2-' . $k . '.zip', stream_get_contents( $stream ) );
}

echo "Done! :)";

// ----------------------------
//
// Previous explorations:

// Non-blocking parallel processing – the fastest method.
//while ( $results = sockets_http_response_await_bytes( $streams, 8096 ) ) {
//	foreach ( $results as $k => $chunk ) {
//		file_put_contents( 'output' . $k . '.zip', $chunk, FILE_APPEND );
//	}
//}

// Blocking sequential processing – the slowest method.
//foreach ( $streams as $k => $stream ) {
//	stream_set_blocking( $stream, 1 );
//	file_put_contents( 'output' . $k . '.zip', stream_get_contents( $stream ) );
//}
