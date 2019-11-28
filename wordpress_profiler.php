<?php

namespace pcfreak30;

/**
 * Class WordPress_Profiler
 *
 * @package pcfreak30
 * @author  Derrick Hammer
 * @version 0.1.0
 */
class WordPress_Profiler {
	/**
	 * @var array
	 */
	private $data = [];
	/**
	 * @var array
	 */
	private $current_hook = null;

	/**
	 * @var int
	 */
	private $level = 0;

	/**
	 *
	 */
	public function init() {
		add_action( 'all', [ $this, 'start_timer' ] );
		add_action( 'shutdown', '__return_true', PHP_INT_MAX );
		$this->data         = $this->record();
		$this->current_hook = &$this->data;
	}

	/**
	 * @return array
	 */
	private function record() {
		$data = [];
		if ( $this->current_hook ) {
			$data['parent'] = &$this->current_hook;
		}

		$data['hook']         = current_action();
		$data['start']        = $this->time();
		$data['stop']         = null;
		$data['time']         = null;
		$data['human_time']   = null;
		$data['memory_start'] = memory_get_usage();
		$data['memory_stop']  = null;
		$data['memory']       = null;
		if ( $data['hook'] ) {
			$data['functions'] = $this->get_current_functions();
		}
		$data['children'] = [];

		return $data;
	}

	/**
	 * @return float|string
	 */
	private function time() {
		return microtime( true );
	}

	/**
	 * @return array
	 */
	private function get_current_functions() {
		$functions = $GLOBALS['wp_filter'][ current_action() ]->callbacks;
		$functions = call_user_func_array( 'array_merge', $functions );
		$functions = array_map( function ( $item ) {
			return $item['function'];
		}, $functions );

		return array_values( array_map( function ( $item ) {
			if ( is_object( $item ) ) {
				$item = [ $item, '' ];
			}
			if ( is_array( $item ) ) {
				if ( is_object( $item[0] ) ) {
					$item[0] = get_class( $item[0] );
				}

				return "{$item[0]}::{$item[1]}";
			}

			return $item;
		}, $functions ) );
	}

	/**
	 *
	 */
	public function start_timer() {
		$action = current_action();
		if ( ! has_action( $action ) ) {
			return;
		}

		$this->current_hook['children'][] = $this->record();
		$this->maybe_change_current_hook();

		add_action( $action, [ $this, 'stop_timer' ], PHP_INT_MAX );

	}

	/**
	 *
	 */
	private function maybe_change_current_hook() {
		$count = count( $GLOBALS['wp_current_filter'] );
		if ( $this->level < $count ) {
			$this->move_down();
			$this->level ++;
		} else if ( $this->level >= $count ) {
			$this->move_up();
			$this->level --;
		}
	}

	/**
	 *
	 */
	private function move_down() {
		end( $this->current_hook['children'] );
		$this->current_hook = &$this->current_hook['children'][ key( $this->current_hook['children'] ) ];
	}

	/**
	 *
	 */
	private function move_up() {
		$this->current_hook = &$this->current_hook['parent'];
	}

	/**
	 *
	 */
	public function stop_timer( $data = null ) {
		$this->record_end();
		$this->maybe_change_current_hook();
		if ( 'shutdown' === current_action() ) {
			$this->record_end();
			$this->save_report();
		}

		return $data;
	}

	/**
	 *
	 */
	private function record_end() {
		$count  = array_count_values( $GLOBALS['wp_current_filter'] );
		$action = current_action();
		if ( 1 === $count[ $action ] ) {
			remove_action( $action, [ $this, 'stop_timer' ], PHP_INT_MAX );
		}
		$this->current_hook ['stop']        = $this->time();
		$this->current_hook ['memory_stop'] = memory_get_usage();
		$this->current_hook ['time']        = $this->current_hook ['stop'] - $this->current_hook ['start'];
		$this->current_hook ['memory']      = $this->current_hook ['memory_stop'] - $this->current_hook ['memory_start'];
	}

	/**
	 *
	 */
	private function save_report() {
		$time = time();
		remove_all_filters( 'sanitize_key' );
		$path = sanitize_key( $_SERVER['REQUEST_URI'] );
		if ( empty( $path ) ) {
			$path = 'root';
		}

		$filename = $this->current_hook ['time'] . '-' . $path . '-' . $_SERVER['REQUEST_METHOD'] . '-' . time() . '.json';

		$this->sanitize_data();

		$data = wp_json_encode( [
			'url'       => ! empty( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/',
			'timestamp' => $time,
			'method'    => $_SERVER['REQUEST_METHOD'],
			'referer'   => isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : null,
			'recording' => $this->data,
		], JSON_PRETTY_PRINT );

		$this->do_save( $filename, $data );


	}

	/**
	 * @param null $item
	 */
	private function sanitize_data( &$item = null ) {
		if ( null === $item ) {
			$item = &$this->data;
		}
		if ( isset( $item['parent'] ) ) {
			unset( $item['parent'] );
		}
		$item['human_time'] = sprintf( '%f', $item['time'] );

		foreach ( $item['children'] as &$child ) {
			$this->sanitize_data( $child );
		}
	}

	private function do_save( $filename, $data ) {
		$dir = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'profiler';
		if ( ! @mkdir( $dir ) && ! @is_dir( $dir ) ) {
			throw new \RuntimeException( sprintf( 'Directory "%s" was not created', $dir ) );
		}
		file_put_contents( $dir . DIRECTORY_SEPARATOR . $filename, $data );
	}
}

( new WordPress_Profiler )->init();
