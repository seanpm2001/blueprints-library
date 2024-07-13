<?php

namespace WordPress\Streams;

class VanillaStreamWrapper implements StreamWrapperInterface {
	protected $stream;

	protected $context;

	protected $wrapper_data;

	const SCHEME = 'vanilla';

	/**
	 * @param \WordPress\Streams\VanillaStreamWrapperData $data
	 */
	public static function create_resource( $data ) {
		static::register();

		$context = stream_context_create(
			array(
				static::SCHEME => array(
					'wrapper_data' => $data,
				),
			)
		);

		return fopen( static::SCHEME . '://', 'r', false, $context );
	}

	public static function register() {
		if ( in_array( static::SCHEME, stream_get_wrappers() ) ) {
			return;
		}

		if ( ! stream_wrapper_register( static::SCHEME, static::class ) ) {
			throw new \Exception( 'Failed to register protocol' );
		}
	}

	public static function unregister() {
		stream_wrapper_unregister( 'async' );
	}


	/**
	 * @param int      $option
	 * @param int      $arg1
	 * @param int|null $arg2
	 */
	public function stream_set_option( $option, $arg1, $arg2 = null ): bool {
		if ( \STREAM_OPTION_BLOCKING === $option ) {
			return stream_set_blocking( $this->stream, (bool) $arg1 );
		} elseif ( \STREAM_OPTION_READ_TIMEOUT === $option ) {
			return stream_set_timeout( $this->stream, $arg1, $arg2 );
		}

		return false;
	}

	public function stream_open( $path, $mode, $options, &$opened_path ) {
		$contextOptions = stream_context_get_options( $this->context );

		if ( ! isset( $contextOptions[ static::SCHEME ]['wrapper_data'] ) || ! is_object( $contextOptions[ static::SCHEME ]['wrapper_data'] ) ) {
			return false;
		}

		$this->wrapper_data = $contextOptions[ static::SCHEME ]['wrapper_data'];

		if ( $this->wrapper_data->fp ) {
			$this->stream = $this->wrapper_data->fp;
		}

		return true;
	}

	/**
	 * @param int $cast_as
	 */
	public function stream_cast( $cast_as ) {
		return $this->stream;
	}

	public function stream_read( $count ) {
		if ( ! $this->stream ) {
			return false;
		}
		
		return fread( $this->stream, $count );
	}

	public function stream_write( $data ) {
		if ( ! $this->stream ) {
			return false;
		}

		return fwrite( $this->stream, $data );
	}

	public function stream_tell() {
		if ( ! $this->stream ) {
			return false;
		}

		return ftell( $this->stream );
	}

	public function stream_close() {
		if ( ! $this->stream ) {
			return false;
		}

		if ( ! $this->has_valid_stream() ) {
			return false;
		}

		return fclose( $this->stream );
	}

	public function stream_eof() {
		if ( ! $this->stream ) {
			return false;
		}

		if ( ! $this->has_valid_stream() ) {
			return true;
		}

		return feof( $this->stream );
	}

	public function stream_seek( $offset, $whence ) {
		if ( ! $this->stream ) {
			return false;
		}

		return fseek( $this->stream, $offset, $whence );
	}

	public function stream_stat() {
		return array();
	}
	
	/*
	 * This stream_close call could be initiated not by the developer,
	 * but by the PHP internal request shutdown handler (written in C).
	 *
	 * The underlying resource ($this->stream) may have already been closed
	 * and freed independently from the resource represented by $this stream
	 * wrapper. In this case, the type of $this->stream will be "Unknown",
	 * and the fclose() call will trigger a fatal error.
	 *
	 * Let's refuse to call fclose() in that scenario.
	 */
	protected function has_valid_stream() {
		return get_resource_type( $this->stream ) !== 'Unknown';
	}
}
