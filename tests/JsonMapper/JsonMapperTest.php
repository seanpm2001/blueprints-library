<?php

namespace JsonMapper;

use ArrayObject;
use JsonMapper\resources\TestResourceClassComplexMapping;
use JsonMapper\resources\TestResourceClassSetValue;
use WordPress\JsonMapper\JsonMapper;
use PHPUnit\Framework\TestCase;
use WordPress\JsonMapper\JsonMapperException;

class JsonMapperTest extends TestCase {

	/**
	 * @var JsonMapper
	 */
	private $json_mapper;

	/**
	 * @before
	 */
	public function before() {
		$this->json_mapper = new JsonMapper();
	}

	/**
	 * Test checks if mapper works at all.
	 *
	 * @return void
	 */
	public function testMapsToArrayObject() {
		$raw_json = '{}';

		$parsed_json = json_decode( $raw_json );

		$result = $this->json_mapper->hydrate( $parsed_json, ArrayObject::class );

		$expected = new ArrayObject();

		$this->assertEquals( $expected, $result );
	}

	public function testSetsPublicProperties() {
		$raw_json = '{"publicProperty":"test"}';

		$parsed_json = json_decode( $raw_json );

		$result = $this->json_mapper->hydrate( $parsed_json, TestResourceClassSetValue::class );

		$expected                 = new TestResourceClassSetValue();
		$expected->publicProperty = 'test';

		$this->assertEquals( $expected, $result );
	}

	public function testSetsPrivatePropertiesWithSetter() {
		$raw_json = '{"privateProperty":"test"}';

		$parsed_json = json_decode( $raw_json );

		$result = $this->json_mapper->hydrate( $parsed_json, TestResourceClassSetValue::class );

		$expected = new TestResourceClassSetValue();
		$expected->setPrivateProperty( 'test' );

		$this->assertEquals( $expected, $result );
	}

	public function testSetsProtectedPropertiesWithSetter() {
		$raw_json = '{"protectedProperty":"test"}';

		$parsed_json = json_decode( $raw_json );

		$result = $this->json_mapper->hydrate( $parsed_json, TestResourceClassSetValue::class );

		$expected = new TestResourceClassSetValue();
		$expected->setProtectedProperty( 'test' );

		$this->assertEquals( $expected, $result );
	}

	public function testFailsSettingPrivatePropertyWithNoSetter() {
		$raw_json = '{"setterlessPrivateProperty":"test"}';

		$parsed_json = json_decode( $raw_json );

		$this->expectException( JsonMapperException::class );
		$this->expectExceptionMessage( "Property: 'JsonMapper\\resources\TestResourceClassSetValue::setterlessPrivateProperty' is non-public and no setter method was found." );
		$this->json_mapper->hydrate( $parsed_json, TestResourceClassSetValue::class );
	}

	public function testFailsSettingProtectedPropertyWithNoSetter() {
		$raw_json = '{"setterlessProtectedProperty":"test"}';

		$parsed_json = json_decode( $raw_json );

		$this->expectException( JsonMapperException::class );
		$this->expectExceptionMessage( "Property: 'JsonMapper\\resources\TestResourceClassSetValue::setterlessProtectedProperty' is non-public and no setter method was found." );
		$this->json_mapper->hydrate( $parsed_json, TestResourceClassSetValue::class );
	}

	public function testMapsToDeepScalarArray() {
		$raw_json = '{"arrayOfStringArrays":[["test1","test2"],["test3","test4"]]}';

		$parsed_json = json_decode( $raw_json );

		$result = $this->json_mapper->hydrate( $parsed_json, TestResourceClassComplexMapping::class );

		$expected                      = new TestResourceClassComplexMapping();
		$expected->arrayOfStringArrays = array(
			array( 'test1', 'test2' ),
			array( 'test3', 'test4' ),
		);

		$this->assertEquals( $expected, $result );
	}

	public function testMapsToDeepMixedArray() {
		$raw_json = '{"arrayOfMixedArrays":[["test1", 42],["test3", true]]}';

		$parsed_json = json_decode( $raw_json );

		$result = $this->json_mapper->hydrate( $parsed_json, TestResourceClassComplexMapping::class );

		$expected                      = new TestResourceClassComplexMapping();
		$expected->arrayOfMixedArrays = array(
			array( 'test1', 42 ),
			array( 'test3', true ),
		);

		$this->assertEquals( $expected, $result );
	}
}
