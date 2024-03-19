<?php
/* ============================================================================
 * Copyright 2020 Zindex Software
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * ============================================================================ */

namespace Opis\JsonSchema;

use InvalidArgumentException, RuntimeException;
use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Resolvers\{SchemaResolver};

class Validator
{
    /**
     * @var \Opis\JsonSchema\SchemaLoader
     */
    protected $loader;
    /**
     * @var int
     */
    protected $maxErrors = 1;

    /**
     * @param SchemaLoader|null $loader
     * @param int $max_errors
     */
    public function __construct($loader = null, int $max_errors = 1)
    {
        $this->loader = $loader ?? new SchemaLoader(new SchemaParser(), new SchemaResolver(), true);
        $this->maxErrors = $max_errors;
    }

    /**
     * @param $data
     * @param bool|string|Uri|Schema|object $schema
     * @param array|null $globals
     * @param array|null $slots
     * @return ValidationResult
     */
    public function validate($data, $schema, $globals = null, $slots = null): ValidationResult
    {
        if (is_string($schema)) {
            if ($uri = Uri::parse($schema, true)) {
                $schema = $uri;
            } else {
                $schema = json_decode($schema, false);
            }
        }

        $error = null;
        if (is_bool($schema)) {
            $error = $this->dataValidation($data, $schema, $globals, $slots);
        } elseif (is_object($schema)) {
            if ($schema instanceof Uri) {
                $error = $this->uriValidation($data, $schema, $globals, $slots);
            } elseif ($schema instanceof Schema) {
                $error = $this->schemaValidation($data, $schema, $globals, $slots);
            } else {
                $error = $this->dataValidation($data, $schema, $globals, $slots);
            }
        } else {
            throw new InvalidArgumentException("Invalid schema");
        }

        return new ValidationResult($error);
    }

    /**
     * @param $data
     * @param Uri|string $uri
     * @param array|null $globals
     * @param array|null $slots
     * @return null|ValidationError
     */
    public function uriValidation($data, $uri, $globals = null, $slots = null)
    {
        if (is_string($uri)) {
            $uri = Uri::parse($uri, true);
        }

        if (!($uri instanceof Uri)) {
            throw new InvalidArgumentException("Invalid uri");
        }

        if ($uri->fragment() === null) {
            $uri = Uri::merge($uri, null, true);
        }

        $schema = $this->loader->loadSchemaById($uri);

        if ($schema === null) {
            throw new RuntimeException("Schema not found: $uri");
        }

        return $this->schemaValidation($data, $schema, $globals, $slots);
    }

    /**
     * @param $data
     * @param string|object|bool $schema
     * @param array|null $globals
     * @param array|null $slots
     * @param string|null $id
     * @param string|null $draft
     * @return ValidationError|null
     */
    public function dataValidation(
        $data,
        $schema,
        $globals = null,
        $slots = null,
        $id = null,
        $draft = null
    )
    {
        if (is_string($schema)) {
            $schema = json_decode($schema, false);
        }

        if ($schema === true) {
            return null;
        }

        if ($schema === false) {
            $schema = $this->loader->loadBooleanSchema(false, $id, $draft);
        } else {
            if (!is_object($schema)) {
                throw new InvalidArgumentException("Invalid schema");
            }

            $schema = $this->loader->loadObjectSchema($schema, $id, $draft);
        }

        return $this->schemaValidation($data, $schema, $globals, $slots);
    }

    /**
     * @param $data
     * @param Schema $schema
     * @param array|null $globals
     * @param array|null $slots
     * @return null|ValidationError
     */
    public function schemaValidation(
        $data,
        $schema,
        $globals = null,
        $slots = null
    )
    {
        return $schema->validate($this->createContext($data, $globals, $slots));
    }

    /**
     * @param $data
     * @param array|null $globals
     * @param array|null $slots
     * @return ValidationContext
     */
    public function createContext($data, $globals = null, $slots = null): ValidationContext
    {
        if ($slots) {
            $slots = $this->parseSlots($slots);
        }

        return new ValidationContext($data, $this->loader, null, null, $globals ?? [], $slots, $this->maxErrors);
    }

    /**
     * @return SchemaParser
     */
    public function parser(): SchemaParser
    {
        return $this->loader->parser();
    }

    /**
     * @param SchemaParser $parser
     * @return Validator
     */
    public function setParser($parser): self
    {
        $this->loader->setParser($parser);

        return $this;
    }

    /**
     * @return SchemaResolver|null
     */
    public function resolver()
    {
        return $this->loader->resolver();
    }

    /**
     * @param SchemaResolver|null $resolver
     * @return Validator
     */
    public function setResolver($resolver): self
    {
        $this->loader->setResolver($resolver);

        return $this;
    }

    /**
     * @return SchemaLoader
     */
    public function loader(): SchemaLoader
    {
        return $this->loader;
    }

    /**
     * @param SchemaLoader $loader
     * @return Validator
     */
    public function setLoader($loader): self
    {
        $this->loader = $loader;

        return $this;
    }

    /**
     * @return int
     */
    public function getMaxErrors(): int
    {
        return $this->maxErrors;
    }

    /**
     * @param int $max_errors
     * @return Validator
     */
    public function setMaxErrors($max_errors): self
    {
        $this->maxErrors = $max_errors;

        return $this;
    }

    /**
     * @param array $slots
     * @return array
     */
    protected function parseSlots($slots): array
    {
        foreach ($slots as $name => &$value) {
            if (!is_string($name)) {
                unset($slots[$name]);
                continue;
            }

            if (is_string($value)) {
                $value = Uri::parse($value, true);
            }

            if ($value instanceof Uri) {
                $value = $this->loader->loadSchemaById($value);
            } elseif (is_bool($value)) {
                $value = $this->loader->loadBooleanSchema($value);
            }

            if (!is_object($value)) {
                unset($slots[$name]);
            }

            unset($value);
        }

        return $slots;
    }
}
