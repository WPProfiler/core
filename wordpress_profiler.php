<?php

namespace pcfreak30 {

	use pcfreak30\WordPress_Profiler\Hook;

	/**
	 * Class WordPress_Profiler
	 *
	 * @package pcfreak30
	 * @author  Derrick Hammer
	 * @version 0.1.0
	 */
	class WordPress_Profiler {
		/**
		 * Enable tracing of where a hook was called from
		 */
		const ENABLE_FUNCTION_TRACING = false;
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
			$this->data         = $this->record( true );
			$this->current_hook = &$this->data;
		}

		/**
		 * @param bool $root
		 *
		 * @return array
		 */
		private function record( $root = false ) {
			$data = $this->create_timer_struct();
			$data = [ 'hook' => current_action() ] + $data;
			if ( $this->current_hook ) {
				$data['parent'] = &$this->current_hook;
			}

			if ( $data['hook'] ) {
				$data['functions'] = $this->get_current_functions();
			}

			if ( self::ENABLE_FUNCTION_TRACING ) {
				$data['caller'] = null;
				if ( ! $root ) {
					$debug          = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 5 );
					$data['caller'] = end( $debug );
				}
			}
			$data['children'] = [];

			return $data;
		}

		private function create_timer_struct() {
			$data                 = [];
			$data['start']        = $this->time();
			$data['stop']         = null;
			$data['time']         = null;
			$data['human_time']   = null;
			$data['memory_start'] = memory_get_usage();
			$data['memory_stop']  = null;
			$data['memory']       = null;

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

			$this->maybe_inject_hook( $action );

			$this->current_hook['children'][] = $this->record();
			$this->maybe_change_current_hook();

			add_action( $action, [ $this, 'stop_timer' ], PHP_INT_MAX );

		}

		private function maybe_inject_hook( $action ) {
			if ( ! ( $GLOBALS['wp_filter'][ $action ] instanceof Hook ) ) {
				$GLOBALS['wp_filter'][ $action ] = new Hook( $GLOBALS['wp_filter'][ $action ] );
			}
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
			$this->record_stop( $this->current_hook );
		}

		private function record_stop( &$item ) {
			$item ['stop']        = $this->time();
			$item ['memory_stop'] = memory_get_usage();
			$item ['time']        = $item['stop'] - $item['start'];
			$item ['memory']      = $item ['memory_stop'] - $item ['memory_start'];
			$item['human_time']   = sprintf( '%f', $item['time'] );
		}

		/**
		 *
		 */
		private function save_report() {
			$time = time();
			remove_action( 'all', [ $this, 'start_timer' ] );
			$path = sanitize_title( $_SERVER['REQUEST_URI'] );
			if ( empty( $path ) ) {
				$path = 'root';
			}

			$filename = $this->current_hook ['time'] . '-' . $path . '-' . $_SERVER['REQUEST_METHOD'] . '-' . time() . '.json';

			$this->sanitize_data();

			$data = wp_json_encode( [
				'server'    => $_SERVER['HTTP_HOST'],
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

			foreach ( $item['children'] as &$child ) {
				$this->sanitize_data( $child );
			}
		}

		/**
		 * @param $filename
		 * @param $data
		 */
		private function do_save( $filename, $data ) {
			$dir = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'profiler';
			if ( ! @mkdir( $dir ) && ! @is_dir( $dir ) ) {
				throw new RuntimeException( sprintf( 'Directory "%s" was not created', $dir ) );
			}
			file_put_contents( $dir . DIRECTORY_SEPARATOR . $filename, $data );
		}

		/**
		 * @return array
		 */
		public function get_current_hook() {
			return $this->current_hook;
		}
	}
}

namespace pcfreak30\WordPress_Profiler {

	/**
	 * Class Hook
	 *
	 * @package pcfreak30
	 */
	class Hook {
		/**
		 * @var \WP_Hook
		 */
		private $hook;

		/**
		 * WordPress_Profiler_Hook constructor.
		 *
		 * @param \WP_Hook $hook
		 */
		public function __construct( \WP_Hook $hook ) {
			$this->hook = $hook;
		}

		/**
		 * @param string $name
		 * @param array  $arguments
		 *
		 * @return mixed
		 */
		public function __call( $name, $arguments ) {
			if ( method_exists( $this, $name ) ) {
				return call_user_func_array( [ $this, $name ], $arguments );
			}

			return call_user_func_array( [ $this->hook, $name ], $arguments );
		}

		/**
		 * @param string $name
		 *
		 * @return mixed
		 */
		public function __get( $name ) {
			if ( property_exists( $this, $name ) ) {
				return $this->{$name};
			}

			return $this->hook->{$name};
		}

		/**
		 * @param string $name
		 * @param mixed  $value
		 */
		public function __set( $name, $value ) {
			if ( property_exists( $this, $name ) ) {
				$this->{$name} = $value;

				return;
			}
			$this->hook->{$name} = $value;
		}

		/**
		 * @param string $name
		 *
		 * @return bool
		 */
		public function __isset( $name ) {
			if ( property_exists( $this, $name ) ) {
				return true;
			}

			return property_exists( $this->hook, $name );
		}

	}
}

namespace pcfreak30\WordPress_Profiler {

	use pcfreak30\WordPress_Profiler;

	function profiler() {
		static $instance;

		if ( ! $instance ) {
			$instance = new WordPress_Profiler();
			$instance->init();
		}

		return $instance;
	}
}
