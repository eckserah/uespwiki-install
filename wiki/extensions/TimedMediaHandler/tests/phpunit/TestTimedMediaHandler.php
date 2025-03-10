<?php

class TestTimedMediaHandler extends MediaWikiTestCase {

	/** @var TimedMediaHandler */
	private $handler;

	function setUp() {
		$this->handler = new TimedMediaHandler;
		parent::setUp();
	}

	/**
	 * @dataProvider providerParseParamString
	 * @param $str String a thumbnail parameter string
	 * @param $expected Array Expected thumbnailing parameters
	 */
	function testParseParamString( $str, $expected ) {
		$result = $this->handler->parseParamString( $str );
		$this->assertEquals( $result, $expected );
	}

	function providerParseParamString() {
		return [
			[
				'mid',
				[],
			],
			[
				'220px-',
				[ 'width' => 220 ],
			],
			[
				'seek=30',
				[ 'thumbtime' => 30.0 ],
			],
			[
				'seek=15.72',
				[ 'thumbtime' => 15.72 ],
			],
			[
				'180px-seek=15',
				[ 'thumbtime' => 15, 'width' => 180 ],
			],
		];
	}
}
