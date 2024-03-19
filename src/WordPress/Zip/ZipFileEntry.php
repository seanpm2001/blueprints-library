<?php

namespace WordPress\Zip;

class ZipFileEntry {
	/**
  * @var bool
  */
 public $isDirectory;
	/**
  * @var int
  */
 public $version;
	/**
  * @var int
  */
 public $generalPurpose;
	/**
  * @var int
  */
 public $compressionMethod;
	/**
  * @var int
  */
 public $lastModifiedTime;
	/**
  * @var int
  */
 public $lastModifiedDate;
	/**
  * @var int
  */
 public $crc;
	/**
  * @var int
  */
 public $compressedSize;
	/**
  * @var int
  */
 public $uncompressedSize;
	/**
  * @var string
  */
 public $path;
	/**
  * @var string
  */
 public $extra;
	/**
  * @var string
  */
 public $bytes;

	public function __construct(
		int $version,
		int $generalPurpose,
		int $compressionMethod,
		int $lastModifiedTime,
		int $lastModifiedDate,
		int $crc,
		int $compressedSize,
		int $uncompressedSize,
		string $path,
		string $extra,
		string $bytes
	) {
		$this->bytes             = $bytes;
		$this->extra             = $extra;
		$this->path              = $path;
		$this->uncompressedSize  = $uncompressedSize;
		$this->compressedSize    = $compressedSize;
		$this->crc               = $crc;
		$this->lastModifiedDate  = $lastModifiedDate;
		$this->lastModifiedTime  = $lastModifiedTime;
		$this->compressionMethod = $compressionMethod;
		$this->generalPurpose    = $generalPurpose;
		$this->version           = $version;
		$this->isDirectory       = $this->path[- 1] === '/';
	}

	public function isFileEntry() {
		return true;
	}
}
