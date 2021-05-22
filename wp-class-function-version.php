<?php
/**
 * File: wp-class-function-version.php
 *
 * 워드프레스 코에에서 모든 클래스와 함수 정의, 그리고 해당 부분의 Doc Comment 부분을 분석하여
 * 해당 함수의 메타 정보를 수집합니다.
 *
 * 수집된 파일은 JSON 형색으로 저장합니다.
 *
 * 프로젝트 루트에 `wp` 디렉토리를 생성하고, 거기에 워드프레스 코어를 다운로드 받아 주세요.
 */

/**
 * Class WP_Class_Function_Version
 */
class WP_Class_Function_Version {
	private $tokens;

	private $classes;

	private $functions;

	private $versions = [
		'class'    => [],
		'function' => [],
	];

	private $doc_comment = '';

	public function __construct( string $file_name ) {
		$this->classes   = [];
		$this->functions = [];
		$this->scan( $file_name );
	}

	private function scan( string $file_name ) {
		if ( ! file_exists( $file_name ) || ! is_readable( $file_name ) ) {
			return;
		}

		$this->tokens = token_get_all( file_get_contents( $file_name ) );
		if ( ! is_array( $this->tokens ) ) {
			return;
		}

		while ( ( $token = current( $this->tokens ) ) ) {
//			trace_line( $token );
			$this->consume_token( $token );
			next( $this->tokens );
		}
	}

	private function consume_token( $token ) {
		if ( is_array( $token ) && 3 === count( $token ) ) {
			switch ( $token[0] ) {
				case T_CLASS:
					$this->extract_class();
					break;

				case T_FUNCTION:
					$this->extract_function();
					break;

				case T_DOC_COMMENT:
					$this->doc_comment = $token[1];
					break;

				case T_WHITESPACE:
					break;

				default:
					$this->doc_comment = null;
					break;
			}
		}
	}

	private function extract_class() {
		$brace_stack = [];

		while ( ( $token = next( $this->tokens ) ) ) {
			if ( is_array( $token ) && count( $token ) && T_STRING === $token[0] ) {
				$this->classes[] = $token[1];
				break;
			}
		}

		if ( $this->doc_comment ) {
			$this->parse_doc_comment_for( 'class', $token );
		}

		// Consume all class implementation codes.
		while ( ( $token = next( $this->tokens ) ) ) {
			if ( is_string( $token ) && '{' === $token ) {
				array_push( $brace_stack, $token );
			} elseif ( is_string( $token ) && '}' == $token ) {
				array_pop( $brace_stack );
				if ( empty( $brace_stack ) ) {
					break;
				}
			} elseif (
				is_array( $token ) &&
				3 === count( $token ) &&
				in_array( $token[0], [ T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ], true )
			) {
				array_push( $brace_stack, '{' );
			}
		}
	}

	private function parse_doc_comment_for( string $type, array $token ) {
		$item = [
			'since'      => '',
			'deprecated' => '',
			'line'       => $token[2],
		];

		$lines = array_filter( array_map( 'trim', explode( "\n", $this->doc_comment ) ) );
		foreach ( $lines as $line ) {
			if ( preg_match( '/@(since|deprecated)(.*)?$/i', $line, $matches ) ) {
				// NOTE: since 여러 개 있어도 워드프레스 코어 팀이 문서를 정갈하게 작성했다고 가정한다.
				//       즉 @since 태그의 가장 첫번째는 최초 도입된 버전의 문자열을 정확하게 담고 있다고 생각한다.
				// TODO: since, deprecated 버전 문자열을 좀 더 정리하여 정확히 버전 번호만 기록하도록...
				$version = trim( $matches[2] );
				if ( ! empty( $version ) && 'since' === $matches[1] && empty( $item['since'] ) ) {
					$item['since'] = $version;
				} elseif ( ! empty( $version ) && 'deprecated' === $matches[1] ) {
					$item['deprecated'] = $version;
				} elseif ( empty( $version ) && 'deprecated' === $matches[1] ) {
					$item['deprecated'] = '-'; // Deprecated anyway.
				}
			}
		}

		$this->doc_comment = '';

		$this->versions[ $type ][ $token[1] ] = $item;
	}

	private function extract_function() {
		while ( ( $token = next( $this->tokens ) ) ) {
			if ( is_array( $token ) && 3 === count( $token ) && T_STRING === $token[0] ) {
				$this->functions[] = $token[1];
				if ( $this->doc_comment ) {
					$this->parse_doc_comment_for( 'function', $token );
				}
				break;
			} elseif ( is_string( $token ) && '(' === $token ) {
				// The function is anonymous. Bail it.
				break;
			}
		}
	}

	public function get_function_data(): array {
		return $this->functions;
	}

	public function get_version_data(): array {
		return $this->versions;
	}

	private function get_class_data(): array {
		return $this->classes;
	}
}

function trace_line( $token ) {
	if ( is_array( $token ) ) {
		echo "LINE: {$token[2]} " . token_name( $token[0] ) . ": {$token[1]}" . PHP_EOL;
	} else {
		echo "STRING: " . $token . PHP_EOL;
	}
}

function scan_version() {
	$wp_dir     = __DIR__ . '/wp';
	$wp_dir_len = strlen( $wp_dir );
	$iterator   = new RegexIterator(
		new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $wp_dir ) ),
		'/\.php$/',
		RecursiveRegexIterator::MATCH
	);

	$database = [];

	foreach ( $iterator as $iter ) {
		$rel = substr( $iter->getPathName(), $wp_dir_len + 1 );

		if ( false !== ( $pos = strpos( $rel, DIRECTORY_SEPARATOR ) ) ) {
			$first_path = substr( $rel, 0, $pos );
			// Exclude wp-content .php files.
			// Those are not WP core files.
			if ( 'wp-content' === $first_path ) {
				continue;
			}
		}

		echo $rel . PHP_EOL;

		/** @var SplFileInfo $iter */
		$scanner = new WP_Class_Function_Version( $iter->getPathName() );

		$data = $scanner->get_version_data();

		foreach ( $data as $type => $items ) {
			foreach ( $items as $name => $item ) {
				$database[ $type ][ $name ] = [
					'file'       => $rel,
					'line'       => $item['line'],
					'since'      => $item['since'],
					'deprecated' => $item['deprecated'],
				];
			}
		}
	}

	ksort( $database['class'] );
	ksort( $database['function'] );
	ksort( $database );

	file_put_contents(
		__DIR__ . '/wp-class-function-version.json',
		json_encode( $database, JSON_PRETTY_PRINT )
	);
}

if ( 'cli' === php_sapi_name() ) {
	scan_version();
}

// regex semver.
// ^((([0-9]+)\.([0-9]+)\.([0-9]+)(?:-([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?)(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?)$
// https://regexr.com/39s32
