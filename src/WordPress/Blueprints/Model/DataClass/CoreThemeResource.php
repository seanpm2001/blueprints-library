<?php

namespace WordPress\Blueprints\Model\DataClass;

class CoreThemeResource implements ResourceDefinitionInterface
{
	public const DISCRIMINATOR = 'wordpress.org/themes';

	/**
	 * Identifies the file resource as a WordPress Core theme
	 * @var string
	 */
	public $resource = 'wordpress.org/themes';

	/**
	 * The slug of the WordPress Core theme
	 * @var string
	 */
	public $slug;


	public function setResource(string $resource)
	{
		$this->resource = $resource;
		return $this;
	}


	public function setSlug(string $slug)
	{
		$this->slug = $slug;
		return $this;
	}
}
