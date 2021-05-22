<?php

/**
 * Class A
 *
 * @since 1.0.0
 */
class A {
	public function foo() {
		$foo = 'foo';
		echo "{$foo} is foo";
	}

	public function bar() {
		$x    = 'X';
		$bar  = 'x';
		$name = 'x';

		$a = 'hello';

		/** @var string $hello */
		$$a = 'world';

		echo "${x}\n"; // T_STRING_VARNAME
		echo "$a ${$a}\n";
		echo "$a $hello\n";
		echo "{${$name}}"; // T_DOLLAR_OPEN_CURLY_BRACES

		echo "${$bar} is great\n";
	}

	function bas(): string {
		return "bas: {${xyz()}}";
	}
}

/**
 * 'xyz' function.
 *
 * @return string
 * @since 1.0.0
 *
 */
function xyz(): string {
	return 'xyz';
}

/**
 * Class B
 *
 * @since 1.0.0
 */
class B extends A {
	public function bas_ext() {
		$x = function () {
		};
	}

	/**
	 * @since 1.0.1
	 */
	public function mac() {
	}
}

/**
 * Class C
 *
 * @deprecated 1.0.1
 */
class C extends A {
	/**
	 * @deprecated 1.0.0
	 */
	public function dep_func() {
	}
}

/**
 * @since
 * @deprecated
 */
function foo() {
}

/**
 * 'xyz' function.
 *
 * @since    1.0.0
 * @deprecated 1.0.1
 */
function bar() {
}

( new A() )->bar();