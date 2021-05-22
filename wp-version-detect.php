<?php
/**
 * File: wp-version-detect.php
 *
 * wp-class-function-version.php 에서 생성된 JSON 파일을 읽어 데이터베이스로 사용합니다.
 * 
 * 시범적으로 다운로드 받은 워드프레스 코어에 번들로 있는 Akismet 플러그인을 대상으로
 * 해당 플러그인이 사용한 클래스, 함수의 정보를 수집하여 JSON 파일로 저장합니다.
 * 
 * 표준 출력으로 호출된 *함수* 중 가장 최근에 코어에 합류한 함수를 찾아 그 코어 버전을 알려줍니다.
 *
 * 주의:
 * 클래스 메소드에 대해서는 분석을 하지 않습니다. 클래스 메소드는 보다 어려운 코드 파싱이 필요하기 때문에
 * 단순한 아이디어 스케치를 위한 소규모 프로토타입에서 진행하기에는 너무 큰 주제라고 판단하였습니다.
 * 또한 워드프레스의 플러그인, 테마 등에서는 클래스보다는 코어 함수를 사용하여 제작하는 비중이 더 크기도 하므로
 * 함수 사용 분석만으로도 그럭저럭 결과를 얻을 수 있다고도 생각했습니다.
 */

/**
 * Class WP_Version_Detect
 */
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
