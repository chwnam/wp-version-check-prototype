<?php

class WP_Version_Detect {
	private $database;

	private $tokens;

	private $function_calls = [];

	public function __construct( string $json_database ) {
		if ( ! file_exists( $json_database ) || ! is_readable( $json_database ) ) {
			die( "{$json_database} not found!\n" );
		}

		$this->database = json_decode( file_get_contents( $json_database ), true );

		if ( empty( $this->database ) ) {
			die( "{$json_database} is an invalid JSON file.\n" );
		}
	}

	public function detect( string $file_name ) {
		if ( ! file_exists( $file_name ) || ! is_readable( $file_name ) ) {
			return;
		}

		$this->tokens = token_get_all( file_get_contents( $file_name ) );
		if ( ! is_array( $this->tokens ) ) {
			return;
		}

		// Initialization.
		$this->function_calls = [];

		while ( ( $token = current( $this->tokens ) ) ) {
//			$this->trace_line( $token );
			$this->consume_token( $token );
			next( $this->tokens );
		}

		$this->filter_wp_core_functions();
	}

	private function consume_token( $token ) {
		if ( is_array( $token ) && 3 === count( $token ) ) {
			switch ( $token[0] ) {
				case T_FUNCTION:
					$this->skip_function();
					break;

				case T_STRING:
					$this->detect_string();
					break;
			}
		}
	}

	private function skip_function() {
		while ( ( $token = next( $this->tokens ) ) ) {
			if ( is_string( $token ) && '(' === $token ) {
				return;
			}
		}
	}

	private function detect_string() {
		$token = current( $this->tokens );

		$function_name    = $token[1];
		$function_line    = $token[2];
		$is_function_call = false;

		while ( ( $token = next( $this->tokens ) ) ) {
			if ( is_array( $token ) && 3 === count( $token ) ) {
				if ( T_WHITESPACE === $token[0] ) {
					continue;
				} else {
					break;
				}
			} elseif ( is_string( $token ) && '(' ) {
				$is_function_call = true;
				break;
			}
		}

		if ( $is_function_call ) {
			$this->function_calls[] = [
				'function' => $function_name,
				'line'     => $function_line,
			];

			// Advance to semicolon.
			while ( ( $token = next( $this->tokens ) ) ) {
				if ( is_string( $token ) && ';' === $token ) {
					break;
				}
			}
		}
	}

	private function filter_wp_core_functions() {
		$filtered = [];

		foreach ( $this->function_calls as $call ) {
			$f = $call['function'];
			$l = $call['line'];

			if ( isset( $this->database['function'][ $f ] ) ) {
				$record = $this->database['function'][ $f ];

				if ( ! isset( $filtered[ $f ] ) ) {
					$filtered[ $f ] = [
						'function'     => $f,
						'wp_core_file' => $record['file'],
						'wp_core_line' => $record['line'],
						'since'        => $record['since'],
						'deprecated'   => $record['deprecated'],
						'line'         => [],
					];
				}

				$filtered[ $f ]['line'][] = $l;
			}
		}

		// replace function calls.
		$this->function_calls = $filtered;
	}

	public function get_function_calls(): array {
		return $this->function_calls;
	}

	private function trace_line( $token ) {
		if ( is_array( $token ) ) {
			echo "LINE: {$token[2]} " . token_name( $token[0] ) . ": {$token[1]}" . PHP_EOL;
		} else {
			echo "STRING: " . $token . PHP_EOL;
		}
	}
}

function test1() {
	$detector = new WP_Version_Detect( __DIR__ . '/wp-class-function-version.json' );
	$detector->detect( __DIR__ . '/wp/wp-content/plugins/hello.php' );
	echo "\n";
	print_r( $detector->get_function_calls() );
}

function test2() {
	$detector = new WP_Version_Detect( __DIR__ . '/wp-class-function-version.json' );
	$detector->detect( __DIR__ . '/wp/wp-content/themes/twentytwenty/index.php' );
	echo "\n";
	print_r( $detector->get_function_calls() );
}

function detect_wp_function_calls() {
	$detector = new WP_Version_Detect( __DIR__ . '/wp-class-function-version.json' );

	$plugin = __DIR__ . '/wp/wp-content/plugins/akismet';

	$wp_dir     = __DIR__ . '/wp';
	$wp_dir_len = strlen( $wp_dir );
	$iterator   = new RegexIterator(
		new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin ) ),
		'/\.php$/',
		RecursiveRegexIterator::MATCH
	);

	$output = [];

	foreach ( $iterator as $iter ) {
		$rel = substr( $iter->getPathName(), $wp_dir_len + 1 );

		echo $rel . PHP_EOL;

		$detector->detect( $iter->getPathName() );

		$calls = $detector->get_function_calls();

		foreach ( $calls as $call ) {
			if ( ! isset( $output[ $call['function'] ] ) ) {
				$output[ $call['function'] ] = [
					'function'     => $call['function'],
					'wp_core_file' => $call['wp_core_file'],
					'wp_core_line' => $call['wp_core_line'],
					'since'        => $call['since'],
					'deprecated'   => $call['deprecated'],
					// 'line' is going to be modified, because many function calls from many files should be referred in this section.
					'line'         => [],
				];
			}

			$output[ $call['function'] ]['line'][ $rel ] = $call['line'];
		}
	}

	file_put_contents(
		__DIR__ . '/wp-version-detect.json',
		json_encode( $output, JSON_PRETTY_PRINT )
	);

	$since = array_map( function ( $item ) {
		return $item['since'];
	}, $output );

	sort( $since );
	$highest_ver = array_pop( $since );

	echo "\nThe highest WP core version number is {$highest_ver}.\n";
}

if ( 'cli' === php_sapi_name() ) {
	detect_wp_function_calls();
}
