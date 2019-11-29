<?php

namespace pcfreak30 {

	use pcfreak30\WordPress_Profiler\Hook;
	use pcfreak30\WordPress_Profiler\ReporterInterface;
	use ReflectionFunction;
	use ReflectionMethod;

	/**
	 * Class WordPress_Profiler
	 *
	 * @package pcfreak30
	 * @author  Derrick Hammer
	 * @version 0.1.0
	 */
	class WordPress_Profiler {
		/*
		 * Add version identifier
		 */
		const VERSION = '0.1.0';
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
		 * @var bool
		 */
		private $function_tracing = false;

		/**
		 * @var \pcfreak30\WordPress_Profiler\ReporterInterface
		 */
		private $report_handler;

		/**
		 *
		 */
		public function init() {
			add_action( 'all', [ $this, 'start_timer' ] );
			add_action( 'shutdown', '__return_true', PHP_INT_MAX );
			$this->data           = $this->record( true );
			$this->current_hook   = &$this->data;
			$this->report_handler = [ $this, 'do_save' ];
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
				$data['functions'] = [];
			}

			if ( $this->function_tracing ) {
				$data['caller'] = null;
				if ( ! $root ) {
					$debug          = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 5 );
					$data['caller'] = end( $debug );
				}
			}
			$data['children'] = [];

			return $data;
		}

		/**
		 * @return array
		 */
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
		 *
		 */
		public function start_timer() {
			$action = current_action();
			if ( ! has_action( $action ) ) {
				return;
			}

			$this->maybe_inject_hook( $action );

			$this->inject_function_timers( $action );

			$this->current_hook['children'][] = $this->record();
			$this->maybe_change_current_hook();

			add_action( $action, [ $this, 'stop_timer' ], PHP_INT_MAX );

		}

		/**
		 * @param $action
		 */
		private function maybe_inject_hook( $action ) {
			if ( ! ( $GLOBALS['wp_filter'][ $action ] instanceof Hook ) ) {
				$GLOBALS['wp_filter'][ $action ] = new Hook( $GLOBALS['wp_filter'][ $action ], $action );
			}
		}

		/**
		 * @param $action
		 */
		private function inject_function_timers( $action ) {
			/** @var Hook $hook */
			$hook = $GLOBALS['wp_filter'][ $action ];
			$hook->maybe_inject_function_timer();
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

		/**
		 * @param $item
		 */
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
			/** @var Hook $sanitize_title */
			$sanitize_title = $GLOBALS['wp_filter']['sanitize_title'];
			$sanitize_title->remove_function_hooks();

			$path = sanitize_title( $_SERVER['REQUEST_URI'] );
			if ( empty( $path ) ) {
				$path = 'root';
			}

			$filename = $this->current_hook ['time'] . '-' . $path . '-' . $_SERVER['REQUEST_METHOD'] . '-' . time() . '.json';

			$this->sanitize_data();

			$data = [
				'server'    => $_SERVER['HTTP_HOST'],
				'url'       => ! empty( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/',
				'timestamp' => $time,
				'method'    => $_SERVER['REQUEST_METHOD'],
				'referer'   => isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : null,
				'recording' => $this->data,
			];

			if ( $this->report_handler instanceof ReporterInterface ) {
				$this->report_handler->execute( $filename, $data );
			}
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
		 * @return array
		 */
		public function get_current_hook() {
			return $this->current_hook;
		}

		/**
		 * @param $function
		 *
		 * @throws \ReflectionException
		 */
		public function start_function_timer( $function ) {
			$function = $function['function'];
			if ( is_array( $function ) && $function[0] === $this ) {
				return;
			}
			$data = $this->create_timer_struct();
			if ( is_array( $function ) ) {
				$reflect = new ReflectionMethod( $function[0], $function[1] );
				if ( is_object( $function[0] ) ) {
					$function[0] = get_class( $function[0] );
				}
				$data ['file']     = $reflect->getFileName();
				$data ['line']     = $reflect->getStartLine();
				$data ['function'] = "{$function[0]}::$function[1]";
			}
			if ( is_string( $function ) || is_object( $function ) ) {
				$reflect           = new ReflectionFunction( $function );
				$data ['file']     = $reflect->getFileName();
				$data ['line']     = $reflect->getStartLine();
				$data ['function'] = $reflect->getName();
			}
			if ( empty( $data ['function'] ) ) {
				$data ['function'] = 'UNKNOWN';
			}
			$this->current_hook['functions'][] = $data;
		}

		/**
		 *
		 */
		public function stop_function_timer() {
			end( $this->current_hook['functions'] );
			$function = &$this->current_hook['functions'][ key( $this->current_hook['functions'] ) ];
			$this->record_stop( $function );
		}

		/**
		 * @param bool $function_tracing
		 */
		public function set_function_tracing( $function_tracing ) {
			$this->function_tracing = (bool) $function_tracing;
		}

		/**
		 * @return callable
		 */
		public function get_report_handler() {
			return $this->report_handler;
		}

		/**
		 * @param \pcfreak30\WordPress_Profiler\ReporterInterface $report_handler
		 */
		public function set_report_handler( ReporterInterface $report_handler ) {
			$this->report_handler = $report_handler;
		}
	}
}

namespace pcfreak30\WordPress_Profiler {

	use ArrayAccess;
	use Iterator;
	use RuntimeException;
	use WP_Hook;

	interface ReporterInterface {
		public function execute( $filename, array $data );
	}

	class FileSystemReporter implements ReporterInterface {
		public function execute( $filename, array $data ) {
			$dir = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'profiler';
			if ( ! @mkdir( $dir ) && ! @is_dir( $dir ) ) {
				throw new RuntimeException( sprintf( 'Directory "%s" was not created', $dir ) );
			}
			file_put_contents( $dir . DIRECTORY_SEPARATOR . $filename, wp_json_encode( $data, JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * Class Hook
	 *
	 * @package pcfreak30
	 */
	class Hook implements Iterator, ArrayAccess {

		/**
		 * @var bool
		 */
		private $injected = false;

		/**
		 * @var \WP_Hook
		 */
		private $hook;
		/**
		 * @var string
		 */
		private $hook_name;

		/**
		 * @var array
		 */
		private $start_cb;

		/**
		 * @var array
		 */
		private $stop_cb;

		/**
		 * @var bool
		 */
		private $foreach_copy = false;

		/**
		 * WordPress_Profiler_Hook constructor.
		 *
		 * @param \WP_Hook $hook
		 */
		public function __construct( WP_Hook $hook, $hook_name ) {
			$this->hook         = $hook;
			$this->hook_name    = $hook_name;
			$this->start_cb     = [ $this, 'start_function_timer' ];
			$this->stop_cb      = [ $this, 'stop_function_timer' ];
			$this->foreach_copy = version_compare( PHP_VERSION, '5.6.0' ) >= 0;
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

		/**
		 * @inheritDoc
		 */
		public function next() {
			return $this->__call( __FUNCTION__, [] );
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
		 * @inheritDoc
		 */
		public function key() {
			return $this->__call( __FUNCTION__, [] );
		}

		/**
		 * @inheritDoc
		 */
		public function valid() {
			return $this->__call( __FUNCTION__, [] );
		}

		/**
		 * @inheritDoc
		 */
		public function rewind() {
			return $this->__call( __FUNCTION__, [] );
		}

		/**
		 * @inheritDoc
		 */
		public function offsetExists( $offset ) {
			return $this->__call( __FUNCTION__, [ $offset ] );
		}

		/**
		 * @inheritDoc
		 */
		public function offsetGet( $offset ) {
			return $this->__call( __FUNCTION__, [ $offset ] );
		}

		/**
		 * @inheritDoc
		 */
		public function offsetSet( $offset, $value ) {
			return $this->__call( __FUNCTION__, [ $offset, $value ] );
		}

		/**
		 * @inheritDoc
		 */
		public function offsetUnset( $offset ) {
			return $this->__call( __FUNCTION__, [ $offset ] );
		}

		/**
		 * @return bool
		 */
		public function is_injected() {
			return $this->injected;
		}

		/**
		 * @param bool $injected
		 */
		public function set_injected( $injected ) {
			$this->injected = $injected;
		}

		/**
		 *
		 */
		public function maybe_inject_function_timer() {
			if ( $this->injected ) {
				return;
			}
			$this->inject_function_timer();
			$this->injected = true;
		}

		/**
		 * @param null $priority
		 */
		public function inject_function_timer( $priority = null ) {
			if ( null === $priority ) {
				$priority = array_keys( $this->hook->callbacks );
			}

			if ( null !== $priority ) {
				$priority = (array) $priority;
			}

			$start_wrapper = $this->get_start_cb_wrapper();
			$stop_wrapper  = $this->get_stop_cb_wrapper();

			foreach ( $priority as $hook_priority ) {
				$new_callbacks = [];

				foreach ( $this->hook->callbacks[ $hook_priority ] as $function ) {
					$start_id    = _wp_filter_build_unique_id( $this->hook_name, $this->start_cb, $hook_priority );
					$stop_id     = _wp_filter_build_unique_id( $this->hook_name, $this->stop_cb, $hook_priority );
					$function_id = _wp_filter_build_unique_id( $this->hook_name, $function['function'], $hook_priority );
					$this->append_array_unique( $new_callbacks, $start_id, $start_wrapper );
					$new_callbacks[ $function_id ] = $function;
					$this->append_array_unique( $new_callbacks, $stop_id, $stop_wrapper );
				}
				$this->hook->callbacks[ $hook_priority ] = $new_callbacks;
			}
		}

		/**
		 * @return array
		 */
		private function get_start_cb_wrapper() {
			return $this->get_cb_wrapper( $this->start_cb );
		}

		/**
		 * @param $cb
		 *
		 * @return array
		 */
		private function get_cb_wrapper( $cb ) {
			return [ 'function' => $cb, 'accepted_args' => 1 ];
		}

		/**
		 * @return array
		 */
		private function get_stop_cb_wrapper() {
			return $this->get_cb_wrapper( $this->stop_cb );
		}

		/**
		 * @param $array
		 * @param $key
		 * @param $value
		 *
		 * @return string
		 */
		private function append_array_unique( &$array, $key, $value ) {
			while ( isset( $array[ $key ] ) ) {
				$key .= '_0';
			}
			$array[ $key ] = $value;

			return $key;
		}

		/**
		 * @param mixed|null $value
		 *
		 * @throws \ReflectionException
		 * @noinspection PhpUnused
		 */
		public function start_function_timer( $value = null ) {
			profiler()->start_function_timer( $this->advance_hook() );

			return $value;
		}

		/**
		 * @param bool $end
		 *
		 * @return mixed
		 */
		private function advance_hook( $end = false ) {
			$hook = &$this->hook->callbacks[ $this->hook->current_priority() ];
			if ( $this->foreach_copy ) {
				$pointer = next( $hook );
				if ( $end ) {
					$pointer = next( $hook );
				}
				if ( ! $pointer ) {
					$pointer = reset( $hook );
				}
			}

			return $pointer ?: current( $hook );
		}

		/**
		 * @inheritDoc
		 */
		public function current() {
			return $this->__call( __FUNCTION__, [] );
		}

		/**
		 * @param null $value
		 *
		 * @return mixed|null
		 * @noinspection PhpUnused
		 */
		public function stop_function_timer( $value = null ) {
			$this->advance_hook( true );
			profiler()->stop_function_timer();

			return $value;
		}

		/**
		 * @param $tag
		 * @param $function_to_add
		 * @param $priority
		 * @param $accepted_args
		 *
		 * @return bool|void
		 */
		public function add_filter( $tag, $function_to_add, $priority, $accepted_args ) {
			$start_id         = _wp_filter_build_unique_id( $this->hook_name, $this->start_cb, $priority );
			$stop_id          = _wp_filter_build_unique_id( $this->hook_name, $this->stop_cb, $priority );
			$profiler_stop_id = _wp_filter_build_unique_id( $this->hook_name, [ profiler(), 'stop_timer' ], $priority );
			$function_id      = _wp_filter_build_unique_id( $this->hook_name, $function_to_add, $priority );

			if ( in_array( $function_id, [ $start_id, $stop_id, $profiler_stop_id ] ) ) {
				return $this->hook->add_filter( $tag, $function_to_add, $priority, $accepted_args );
			}

			$callbacks = &$this->hook->callbacks[ $priority ];

			$this->hook->add_filter( $tag, $function_to_add, $priority, $accepted_args );
			$this->append_array_unique( $callbacks, $stop_id, $this->get_stop_cb_wrapper() );

			return true;
		}

		/**
		 * @param $tag
		 * @param $function_to_remove
		 * @param $priority
		 *
		 * @return bool
		 */
		public function remove_filter( $tag, $function_to_remove, $priority ) {
			$function_id      = _wp_filter_build_unique_id( $this->hook_name, $function_to_remove, $priority );
			$profiler_stop_id = _wp_filter_build_unique_id( $this->hook_name, [ profiler(), 'stop_timer' ], $priority );
			if ( $function_id == $profiler_stop_id ) {
				return $this->hook->remove_filter( $tag, $function_to_remove, $priority );
			}
			if ( $this->hook->has_filter( $tag, $function_to_remove ) ) {
				$callbacks = &$this->hook->callbacks[ $priority ];
				$current   = key( $callbacks );

				$keys          = array_keys( $callbacks );
				$indexes       = array_flip( $keys );
				$current_index = $indexes[ $current ];

				$function_key       = _wp_filter_build_unique_id( $tag, $function_to_remove, $priority );
				$function_index     = $indexes[ $function_key ];
				$start_function_key = $keys[ $function_index - 1 ];
				$stop_function_key  = $keys[ $function_index + 1 ];

				$this->hook->remove_filter( $tag, $function_to_remove, $priority );
				unset( $callbacks[ $start_function_key ] );
				unset( $callbacks[ $stop_function_key ] );

				if ( $function_index < $current_index ) {
					reset( $callbacks );
					do {
						next( $callbacks );
					} while ( $indexes[ key( $callbacks ) ] < $function_index );
				}

				return true;
			}

			return false;
		}

		/**
		 *
		 */
		public function remove_function_hooks() {
			$callbacks = &$this->hook->callbacks;

			foreach ( array_keys( $callbacks ) as $priority ) {
				$callbacks[ $priority ] = array_filter( $callbacks[ $priority ], function ( $function ) {
					return ! ( is_array( $function['function'] ) && $function['function'][0] instanceof self );
				} );
			}
		}
	}
}

namespace pcfreak30\WordPress_Profiler {

	use pcfreak30\WordPress_Profiler;

	/**
	 * @return \pcfreak30\WordPress_Profiler
	 */
	function profiler() {
		static $instance;

		if ( ! $instance ) {
			$instance = new WordPress_Profiler();
			$instance->set_report_handler( new FileSystemReporter() );
			$instance->init();
		}

		return $instance;
	}
}

namespace {
	pcfreak30\WordPress_Profiler\profiler();
}
