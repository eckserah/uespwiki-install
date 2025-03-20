<?php

// @codingStandardsIgnoreLine Squiz.Classes.ValidClassName.NotCamelCaps
class Scribunto_LuaStandaloneTest extends Scribunto_LuaEngineTestBase {
	protected static $moduleName = 'StandaloneTests';

	public static function suite( $className ) {
		return self::makeSuite( $className, 'LuaStandalone' );
	}

	protected function setUp() {
		parent::setUp();

		$interpreter = $this->getEngine()->getInterpreter();
		$func = $interpreter->wrapPhpFunction( function ( $v ) {
			return [ preg_replace( '/\s+/', ' ', trim( var_export( $v, 1 ) ) ) ];
		} );
		$interpreter->callFunction(
			$interpreter->loadString( 'mw.var_export = ...', 'fortest' ), $func
		);
	}

	protected function getTestModules() {
		return parent::getTestModules() + [
			'StandaloneTests' => __DIR__ . '/StandaloneTests.lua',
		];
	}
}
