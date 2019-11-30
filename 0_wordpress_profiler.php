<?php

namespace WPProfiler\Core {

	/**
	 * Class Core
	 *
	 * @package WPProfiler
	 * @author  Derrick Hammer
	 * @version 0.1.0
	 */
	class Profiler {

		/**
		 * Add version identifier
		 */
		const VERSION = '0.1.0';

		/**
		 * @var \WPProfiler\Core\ReporterInterface
		 */
		private $report_handler;

		/**
		 * @var array
		 */
		private $meta = [];

		/**
		 * @var array
		 */
		private $enabled_collectors = [];

		/**
		 * @var CollectorInterface[]
		 */
		private $collectors = [];

		/**
		 * @var bool
		 */
		private $report_saved = false;

		/**
		 * @var float
		 */
		private $time_started = 0;

		/**
		 *
		 */
		public function init() {
			register_shutdown_function( [ $this, 'save_report' ] );
			$this->time_started = $this->time();
		}

		/**
		 * @return float
		 */
		public function time() {
			return microtime( true );
		}

		/**
		 * @param $name
		 *
		 * @return bool
		 */
		public function enable_collector( $name ) {
			if ( ! $this->is_collector( $name ) ) {
				return false;
			}
			$this->enabled_collectors[ $name ] = true;

			$this->collectors[ $name ]->enable();

			return true;
		}

		/**
		 * @param $name
		 *
		 * @return bool
		 */
		public function is_collector( $name ) {
			return isset( $this->collectors[ $name ] );
		}

		/**
		 * @return \WPProfiler\Core\ReporterInterface
		 * @noinspection PhpUnused
		 */
		public function get_report_handler() {
			return $this->report_handler;
		}

		/**
		 * @param \WPProfiler\Core\ReporterInterface $report_handler
		 */
		public function set_report_handler( ReporterInterface $report_handler ) {
			$this->report_handler = $report_handler;
		}

		/**
		 * @param string $key
		 *
		 * @return bool
		 */
		public function meta_exists( $key ) {
			return isset( $this->meta[ $key ] );
		}

		/**
		 * @return array
		 */
		public function get_all_meta() {
			return $this->meta;
		}

		/**
		 * @param string $key
		 *
		 * @return mixed
		 */
		public function get_meta( $key ) {
			return $this->meta[ $key ];
		}

		/**
		 * @param                                                  $name
		 * @param \WPProfiler\Core\CollectorInterface              $collector
		 */
		public function register_collector( $name, CollectorInterface $collector ) {
			$this->collectors[ $name ] = $collector;
			$this->collectors[ $name ]->init();
		}

		/**
		 * @param       $name
		 * @param       $method
		 * @param mixed ...$args
		 *
		 * @return bool|mixed
		 */
		public function call_collector( $name, $method, ...$args ) {
			if ( ! $this->is_collector_enabled( $name ) ) {
				if ( isset( $args[0] ) ) {
					return $args[0];
				}

				return false;
			}

			return $this->collectors[ $name ]->{$method}( ...$args );
		}

		/**
		 * @param $name
		 *
		 * @return bool
		 */
		public function is_collector_enabled( $name ) {
			return isset( $this->enabled_collectors[ $name ] );
		}

		/**
		 * @return array
		 */
		public function create_timer_store() {
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
		 * @param string $key
		 * @param array  $value
		 */
		public function add_meta( $key, $value ) {
			$this->meta[ $key ] = $value;
		}

		/**
		 *
		 */

		public function save_report() {
			if ( $this->report_saved ) {
				return;
			}


			$report = $this->generate_report();

			$this->disable_all_collectors();

			$filename_parts = [
				$_SERVER['REQUEST_METHOD'],
				time(),
			];
			$filename_parts = apply_filters( 'wp_profiler_report_filename', $filename_parts );

			if ( $this->report_handler instanceof ReporterInterface ) {
				$this->report_handler->execute( implode( '-', $filename_parts ) . '.json', $report );
			}
			$this->report_saved = true;
		}

		/**
		 * @return array
		 */
		public function generate_report() {
			$collected_data = [];

			/** @var CollectorInterface $collector */
			foreach ( array_keys( $this->enabled_collectors ) as $collector ) {
				$collected_data[ $collector ] = $this->collectors[ $collector ]->get();
			}

			$collected_data = array_filter( $collected_data );

			$time        = $this->time() - $this->time_started;
			$memory      = memory_get_usage();
			$peak_memory = memory_get_peak_usage();

			return array_filter( [
				'server'           => isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : null,
				'url'              => ! empty( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/',
				'timestamp'        => time(),
				'method'           => $_SERVER['REQUEST_METHOD'],
				'referer'          => isset( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : null,
				'total_time'       => $time,
				'total_human_time' => sprintf( '%f', $time ),
				'memory_used'      => $memory,
				'peak_memory_used' => $peak_memory,
				'is_cron'          => wp_doing_cron(),
				'is_ajax'          => wp_doing_ajax(),
				'is_cli'           => php_sapi_name() === 'cli' ? true : null,
				'wp_cli_command'   => class_exists( '\WP_CLI' ) ? (array) implode( ' ', array_map( 'trim', array_filter( array_slice( $_SERVER['argv'], 1 ) ) ) ) : null,
				'collectors'       => $collected_data,
				'meta'             => $this->meta,
			], function ( $item ) {
				return null !== $item;
			} );
		}

		/**
		 *
		 */
		public function disable_all_collectors() {
			foreach ( array_keys( $this->enabled_collectors ) as $collector ) {
				$this->disable_collector( $collector );
			}
		}

		/**
		 * @param $name
		 *
		 * @return bool
		 */
		public function disable_collector( $name ) {
			if ( ! $this->is_collector( $name ) ) {
				return false;
			}

			unset( $this->enabled_collectors[ $name ] );

			$this->collectors[ $name ]->disable();

			return true;
		}
	}
}

namespace WPProfiler\Core {

	use ArrayAccess;
	use Iterator;
	use RuntimeException;
	use WP_Hook;
	use WPProfiler\Core\Collectors\Function_;

	/**
	 * Interface ReporterInterface
	 *
	 * @package WPProfiler\Core
	 */
	interface ReporterInterface {
		/**
		 * @param string $filename
		 * @param array  $data
		 *
		 * @return mixed
		 */
		public function execute( $filename, array $data );
	}

	/**
	 * Interface CollectorInterface
	 *
	 * @package WPProfiler\Core
	 */
	interface CollectorInterface {

		/**
		 * CollectorInterface constructor.
		 *
		 * @param \WPProfiler\Core\Profiler $profiler
		 */
		public function __construct( Profiler $profiler );

		/**
		 * @return mixed
		 */
		public function init();

		/**
		 * @param mixed|null $data
		 *
		 * @return array
		 */
		public function get( $data = null );

		/**
		 * @return mixed
		 */
		public function enable();

		/**
		 * @return mixed
		 */
		public function disable();

		/**
		 * @return mixed
		 */
		public function start();

		/**
		 * @return mixed
		 */
		public function stop();
	}

	/**
	 * Class CollectorAbstract
	 *
	 * @package WPProfiler\Core
	 */
	abstract class CollectorAbstract implements CollectorInterface {
		/**
		 *
		 */
		const NAME = '';

		/**
		 *
		 */
		const BUILD_FILENAME_PRIORITY = 0;

		/**
		 * @var Profiler
		 */
		protected $profiler;

		/**
		 * @var bool
		 */
		protected $enabled = false;

		/**
		 * CollectorAbstract constructor.
		 *
		 * @param \WPProfiler\Core\Profiler $profiler
		 */
		public function __construct( Profiler $profiler ) {
			$this->profiler = $profiler;
		}

		/**
		 * @param $parts
		 *
		 * @return mixed
		 */
		public function build_report_filename( $parts ) {
			return $parts;
		}

		/**
		 * @return mixed|void
		 */
		public function init() {
			add_filter( 'wp_profiler_report_filename', [
				$this,
				'build_report_filename',
			], static::BUILD_FILENAME_PRIORITY );
		}

		/**
		 * @return void
		 */
		public function enable() {
			$this->enabled = true;
		}

		/**
		 * @return void
		 */
		public function disable() {
			$this->enabled = false;
		}
	}

	/**
	 * Class FileSystemReporter
	 *
	 * @package WPProfiler\Core
	 */
	class FileSystemReporter implements ReporterInterface {
		/** @noinspection PhpUnused */
		public function execute( $filename, array $data ) {
			$dir  = WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'profiler' . DIRECTORY_SEPARATOR;
			$type = 'web';

			if ( $data['is_cli'] ) {
				$type = 'cli';
				if ( $data['wp_cli_command'] ) {
					$type = 'wpcli';
				}
			}

			if ( $data['is_cron'] ) {
				$type = 'cron';
			}

			if ( $data['is_ajax'] ) {
				$type = 'ajax';
			}
			$dir .= $type;
			$dir = apply_filters( 'wp_profiler_report_storage_directory', $dir );
			if ( ! @mkdir( $dir ) && ! @is_dir( $dir ) ) {
				throw new RuntimeException( sprintf( 'Directory "%s" was not created', $dir ) );
			}
			file_put_contents( $dir . DIRECTORY_SEPARATOR . $filename, wp_json_encode( $data, JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * Class Hook
	 *
	 * @package WPProfiler
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
		 * @var Profiler
		 */
		private $profiler;
		/**
		 * @var \WPProfiler\Core\Collectors\Function_
		 */
		private $collector;

		/**
		 * Hook constructor.
		 *
		 * @param \WP_Hook                                         $hook
		 * @param                                                  $hook_name
		 *
		 * @param Profiler                                         $profiler
		 *
		 * @param                                                  $collector
		 *
		 * @noinspection PhpUnused
		 */
		public function __construct( WP_Hook $hook, $hook_name, Profiler $profiler, Function_ $collector ) {
			$this->hook      = $hook;
			$this->hook_name = $hook_name;
			$this->start_cb  = [ $this, 'start_function_timer' ];
			$this->stop_cb   = [ $this, 'stop_function_timer' ];
			$this->profiler  = $profiler;
			$this->collector = $collector;
		}

		/**
		 * @param string $name
		 *
		 * @return mixed
		 * @noinspection PhpUnused
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
		 *
		 * @noinspection PhpUnused
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
		 * @noinspection PhpUnused
		 */
		public function __isset( $name ) {
			if ( property_exists( $this, $name ) ) {
				return true;
			}

			return property_exists( $this->hook, $name );
		}

		/**
		 * @inheritDoc
		 * @noinspection PhpUnused
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
		 * @noinspection PhpUnused
		 */
		public function key() {
			return $this->__call( __FUNCTION__, [] );
		}

		/**
		 * @inheritDoc
		 * @noinspection PhpUnused
		 */
		public function valid() {
			return $this->__call( __FUNCTION__, [] );
		}

		/**
		 * @inheritDoc
		 * @noinspection PhpUnused
		 */
		public function rewind() {
			return $this->__call( __FUNCTION__, [] );
		}

		/**
		 * @inheritDoc
		 * @noinspection PhpUnused
		 */
		public function offsetExists( $offset ) {
			return $this->__call( __FUNCTION__, [ $offset ] );
		}

		/**
		 * @inheritDoc
		 * @noinspection PhpUnused
		 */
		public function offsetGet( $offset ) {
			return $this->__call( __FUNCTION__, [ $offset ] );
		}

		/**
		 * @inheritDoc
		 * @noinspection PhpUnused
		 */
		public function offsetSet( $offset, $value ) {
			return $this->__call( __FUNCTION__, [ $offset, $value ] );
		}

		/**
		 * @inheritDoc
		 * @noinspection PhpUnused
		 */
		public function offsetUnset( $offset ) {
			return $this->__call( __FUNCTION__, [ $offset ] );
		}

		/**
		 * @return bool
		 * @noinspection PhpUnused
		 */
		public function is_injected() {
			return $this->injected;
		}

		/**
		 * @param bool $injected
		 *
		 * @noinspection PhpUnused
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
		 * @param int[] $priority
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
					$is_collector = is_array( $function['function'] ) && $function['function'] instanceof CollectorInterface;
					$start_id     = _wp_filter_build_unique_id( $this->hook_name, $this->start_cb, $hook_priority );
					$stop_id      = _wp_filter_build_unique_id( $this->hook_name, $this->stop_cb, $hook_priority );
					$function_id  = _wp_filter_build_unique_id( $this->hook_name, $function['function'], $hook_priority );

					if ( ! $is_collector ) {
						$this->append_array_unique( $new_callbacks, $start_id, $start_wrapper );
					}
					$new_callbacks[ $function_id ] = $function;
					if ( ! $is_collector ) {
						$this->append_array_unique( $new_callbacks, $stop_id, $stop_wrapper );
					}
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
		 * @param callable $cb
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
		 * @param array  $array
		 * @param string $key
		 * @param array  $value
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
		 * @return mixed|null
		 * @throws \ReflectionException
		 * @noinspection PhpUnused
		 *
		 */
		public function start_function_timer( $value = null ) {
			$this->collector->start_timer( $this->advance_hook() );

			return $value;
		}

		/**
		 * @param bool $end
		 *
		 * @return mixed
		 */
		private function advance_hook( $end = false ) {
			$hook    = &$this->hook->callbacks[ $this->hook->current_priority() ];
			$pointer = next( $hook );
			if ( $end ) {
				$pointer = next( $hook );
			}
			if ( ! $pointer ) {
				$pointer = reset( $hook );
			}

			return $pointer ?: current( $hook );
		}

		/**
		 * @inheritDoc
		 * @noinspection PhpUnused
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
			$this->collector->stop_timer();

			return $value;
		}

		/**
		 * @param string   $tag
		 * @param callable $function_to_add
		 * @param int      $priority
		 * @param int      $accepted_args
		 *
		 * @return bool|void
		 * @noinspection PhpUnused
		 */
		public function add_filter( $tag, $function_to_add, $priority, $accepted_args ) {
			$stop_id = _wp_filter_build_unique_id( $this->hook_name, $this->stop_cb, $priority );

			if ( ( is_array( $function_to_add ) && $function_to_add[0] instanceof CollectorInterface ) || $this->collector->is_function_ignored( $function_to_add ) ) {
				return $this->hook->add_filter( $tag, $function_to_add, $priority, $accepted_args );
			}

			$callbacks = &$this->hook->callbacks[ $priority ];

			$this->hook->add_filter( $tag, $function_to_add, $priority, $accepted_args );
			$this->append_array_unique( $callbacks, $stop_id, $this->get_stop_cb_wrapper() );

			return true;
		}

		/**
		 * @param string   $tag
		 * @param callable $function_to_remove
		 * @param int      $priority
		 *
		 * @return bool
		 * @noinspection PhpUnused
		 */
		public function remove_filter( $tag, $function_to_remove, $priority ) {
			if ( ( is_array( $function_to_remove ) && $function_to_remove[0] instanceof CollectorInterface ) || $this->collector->is_function_ignored( $function_to_remove ) ) {
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
				unset( $callbacks[ $start_function_key ], $callbacks[ $stop_function_key ] );

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
				$callbacks[ $priority ] = array_filter( $callbacks[ $priority ], static function ( $function ) {
					return ! ( is_array( $function['function'] ) && $function['function'][0] instanceof self );
				} );
			}
		}
	}
}

namespace WPProfiler\Core\Collectors {

	use ReflectionFunction;
	use ReflectionMethod;
	use WPProfiler\Core;

	/**
	 * Class Hook
	 *
	 * @package WPProfiler\Core\Collectors
	 */
	class Hook extends Core\CollectorAbstract {

		/**
		 *
		 */
		const BUILD_FILENAME_PRIORITY = 1;

		/**
		 *
		 */
		const NAME = 'hook';
		/**
		 * @var array
		 */
		private $current_hook = [];
		/**
		 * @var int
		 */
		private $level = 0;
		/**
		 * @var array
		 */
		private $data = [];

		/**
		 * @var array
		 */
		private $ignored_hooks;

		/**
		 * @param $parts
		 *
		 * @return mixed
		 */
		public function build_report_filename( $parts ) {
			array_unshift( $parts, $this->data['time'] );

			return $parts;
		}

		/**
		 * @return mixed|void
		 */
		public function init() {
			parent::init();
			$this->data         = $this->record( true );
			$this->current_hook = &$this->data;
		}

		/**
		 * @param bool $root
		 *
		 * @return array
		 */
		private function record( $root = false ) {
			$data = $this->profiler->create_timer_store();
			$data = [ 'hook' => current_action() ] + $data;
			if ( $this->current_hook ) {
				$data['parent'] = &$this->current_hook;
			}
			$data             = $this->profiler->call_collector( Function_::NAME, 'init_store', $data, $root );
			$data             = $this->profiler->call_collector( FunctionTracer::NAME, 'collect', $data, $root );
			$data['children'] = [];

			return $data;
		}

		/**
		 * @inheritDoc
		 */
		public function get( $data = null ) {
			$collected = &$this->data;
			$shutdown  = did_action( 'shutdown' );
			if ( ! $shutdown ) {
				$collected = $data;
			}
			if ( $shutdown ) {
				$this->record_stop( $this->data );
			}

			if ( ! empty( $collected ) ) {
				$this->sanitize_data( $collected );
			}

			return $collected;
		}

		/**
		 * @param array $item
		 */
		public function record_stop( &$item ) {
			$item ['stop']        = $this->profiler->time();
			$item ['memory_stop'] = memory_get_usage();
			$item ['time']        = $item['stop'] - $item['start'];
			$item ['memory']      = $item ['memory_stop'] - $item ['memory_start'];
			$item['human_time']   = sprintf( '%f', $item['time'] );
		}

		/**
		 * @param array $item
		 */
		private function sanitize_data( array &$item ) {
			if ( isset( $item['parent'] ) ) {
				unset( $item['parent'] );
			}

			foreach ( $item['children'] as &$child ) {
				$this->sanitize_data( $child );
			}
		}

		/**
		 *
		 */
		public function start_timer() {
			$action = current_action();
			if ( ! has_action( $action ) || $this->is_hook_ignored( $action ) || ! $this->enabled ) {
				return;
			}

			$this->profiler->call_collector( Function_::NAME, 'maybe_inject_hook', $action );
			$this->profiler->call_collector( Function_::NAME, 'inject_timers', $action );

			$this->current_hook['children'][] = $this->record();
			$this->maybe_change_current_hook();

			add_action( $action, [ $this, 'stop_timer' ], PHP_INT_MAX );

		}

		/**
		 * @param $hook
		 *
		 * @return bool
		 */
		public function is_hook_ignored( $hook ) {
			$hook = (string) $hook;

			return isset( $this->ignored_hooks[ $hook ] );
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
		 * @param null $data
		 *
		 * @return null
		 */
		public function stop_timer( $data = null ) {
			$this->record_end();
			$this->maybe_change_current_hook();

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
		 * @return void
		 */
		public function enable() {
			parent::enable();
			add_action( 'all', [ $this, 'start_timer' ] );
		}

		/**
		 * @return mixed|void
		 */
		public function disable() {
			parent::disable();
			remove_filter( 'all', [ $this, 'start_timer' ] );
			$this->record_stop( $this->current_hook );
			$this->profiler->disable_collector( Function_::NAME );
			$this->profiler->disable_collector( FunctionTracer::NAME );
		}

		/**
		 * @return mixed|void
		 */
		public function start() {
			// TODO: Implement start() method.
		}

		/**
		 * @return mixed|void
		 */
		public function stop() {
			// TODO: Implement stop() method.
		}

		/**
		 * @return array
		 */
		public function get_current_hook() {
			return $this->current_hook;
		}

		/**
		 * @param string $hook
		 */
		public function ignore_hook( $hook ) {
			$hook                         = (string) $hook;
			$this->ignored_hooks[ $hook ] = true;
			remove_action( $hook, [ $this, 'stop_timer' ], PHP_INT_MAX );
		}

		/**
		 * @param $hook
		 *
		 * @return bool
		 */
		public function remove_ignored_hook( $hook ) {
			$hook = (string) $hook;
			if ( $this->is_hook_ignored( $hook ) ) {
				unset( $this->ignored_hooks[ $hook ] );

				return true;
			}

			return false;
		}
	}

	/**
	 * Class Function_
	 *
	 * @package WPProfiler\Core\Collectors
	 */
	class Function_ extends Core\CollectorAbstract {

		/**
		 *
		 */
		const NAME = 'function';

		/**
		 *
		 */
		const BUILD_FILENAME_PRIORITY = 0;

		/**
		 * @var array
		 */
		private $current_hook;

		/**
		 * @var array
		 */
		private $ignored_functions = [];

		/**
		 * @return void
		 */
		public function init() {
			parent::init();
			$this->current_hook = $this->profiler->call_collector( Hook::NAME, 'get_current_hook' );
		}

		/**
		 * @inheritDoc
		 */
		public function get( $data = null ) {

		}

		/**
		 * @return void
		 */
		public function enable() {
			parent::enable();
			if ( ! $this->profiler->is_collector_enabled( Hook::NAME ) ) {
				$this->profiler->disable_collector( self::NAME );
			}
		}

		/**
		 * @return mixed|void
		 */
		public function start() {
			// TODO: Implement start() method.
		}

		/**
		 * @return mixed|void
		 */
		public function stop() {
			// TODO: Implement stop() method.
		}

		/**
		 * @param $data
		 *
		 * @return array
		 */
		public function init_store( $data ) {
			if ( $data['hook'] ) {
				$data['functions'] = [];
			}

			return $data;
		}

		/**
		 * @param $parts
		 *
		 * @return array
		 */
		public function build_report_filename( $parts ) {

			$path = sanitize_title( $_SERVER['REQUEST_URI'] );
			if ( empty( $path ) ) {
				$path = 'root';
			}
			array_unshift( $parts, $path );

			return $parts;
		}

		/**
		 * @param array $function
		 *
		 * @throws \ReflectionException
		 */
		public function start_timer( $function ) {
			/** @var callable $function */
			$function = $function['function'];
			if ( is_array( $function ) && $function[0] === $this ) {
				return;
			}
			$data = $this->profiler->create_timer_store();
			if ( is_array( $function ) ) {
				$reflect = new ReflectionMethod( $function[0], $function[1] );
				if ( is_object( $function[0] ) ) {
					$function[0] = get_class( $function[0] );
				}
				$data ['file']     = $reflect->getFileName();
				$data ['line']     = $reflect->getStartLine();
				$data ['function'] = "{$function[0]}::$function[1]";
			}
			/** @noinspection CallableParameterUseCaseInTypeContextInspection */
			if ( is_string( $function ) || is_object( $function ) ) {
				$reflect           = new ReflectionFunction( $function );
				$data ['file']     = $reflect->getFileName();
				$data ['line']     = $reflect->getStartLine();
				$data ['function'] = $reflect->getName();
			}
			if ( empty( $data ['function'] ) ) {
				$data ['function'] = 'UNKNOWN';
			}

			$current_hook                  = $this->profiler->call_collector( Hook::NAME, 'get_current_hook' );
			$current_hook  ['functions'][] = $data;
			if ( $current_hook  ['parent'] ) {
				$current_hook  ['parent']['children'][ count( $current_hook  ['parent']['children'] ) - 1 ] = $current_hook;
			}
		}

		/**
		 *
		 */
		public function stop_timer() {
			$current_hook = $this->profiler->call_collector( Hook::NAME, 'get_current_hook' );
			end( $current_hook['functions'] );
			$function = $current_hook['functions'][ key( $current_hook['functions'] ) ];
			$this->profiler->call_collector( Hook::NAME, 'record_stop', $function );
		}

		/**
		 * @param $action
		 */
		public function maybe_inject_hook( $action ) {
			if ( ! ( $GLOBALS['wp_filter'][ $action ] instanceof Core\Hook ) ) {
				$GLOBALS['wp_filter'][ $action ] = new Core\Hook( $GLOBALS['wp_filter'][ $action ], $action, $this->profiler, $this );
			}
		}

		/**
		 * @param $action
		 */
		public function inject_timers( $action ) {
			/** @var \WPProfiler\Core\Hook $hook */
			$hook = $GLOBALS['wp_filter'][ $action ];
			$hook->maybe_inject_function_timer();
		}

		/**
		 * @param callable $function
		 */
		public function ignore_function( callable $function ) {
			$this->ignored_functions[ _wp_filter_build_unique_id( null, $function, null ) ] = true;
		}

		/**
		 * @param callable $function
		 *
		 * @return bool
		 */
		public function remove_ignored_function( callable $function ) {
			if ( $this->is_function_ignored( $function ) ) {
				unset( $this->ignored_functions[ _wp_filter_build_unique_id( null, $function, null ) ] );

				return true;
			}

			return false;
		}

		/**
		 * @param callable $function
		 *
		 * @return bool
		 */
		public function is_function_ignored( callable $function ) {
			return isset( $this->ignored_functions[ _wp_filter_build_unique_id( null, $function, null ) ] );
		}
	}

	/**
	 * Class FunctionTracer
	 *
	 * @package WPProfiler\Core\Collectors
	 */
	class FunctionTracer extends Core\CollectorAbstract {
		/**
		 *
		 */
		const NAME = 'function_tracer';

		/**
		 * @inheritDoc
		 */
		public function get( $data = null ) {
			return [];
		}

		/**
		 * @return void
		 */
		public function enable() {
			parent::enable();
			if ( ! $this->profiler->is_collector_enabled( Hook::NAME ) ) {
				$this->profiler->disable_collector( self::NAME );
			}
		}

		/**
		 * @return mixed|void
		 */
		public function disable() {
			// noop
		}

		/**
		 * @param array $data
		 * @param bool  $root
		 *
		 * @return mixed
		 */
		public function collect( $data, $root ) {
			$data['caller'] = null;
			if ( ! $root ) {
				$debug          = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 7 );
				$data['caller'] = end( $debug );
			}

			return $data;
		}

		/**
		 * @return mixed|void
		 */
		public function start() {
			// TODO: Implement start() method.
		}

		/**
		 * @return mixed|void
		 */
		public function stop() {
			// TODO: Implement stop() method.
		}
	}

	/**
	 * Class Query
	 *
	 * @package WPProfiler\Core\Collectors
	 */
	class Query extends Core\CollectorAbstract {

		/**
		 *
		 */
		const NAME = 'query';

		/**
		 * @inheritDoc
		 */
		public function get( $data = null ) {
			if ( ! did_action( 'parse_query' ) ) {
				return [];
			}

			$query   = $GLOBALS['wp_the_query'];
			$methods = get_class_methods( $query );
			$methods = array_filter( $methods, static function ( $method ) {
				return 0 === strpos( $method, 'is_' ) && $method !== 'is_comments_popup';
			} );
			$meta    = [];

			foreach ( $methods as $method ) {
				$meta[ $method ] = $query->{$method}();
			}

			return $meta;
		}

		/**
		 * @return mixed|void
		 */
		public function start() {
			// TODO: Implement start() method.
		}

		/**
		 * @return mixed|void
		 */
		public function stop() {
			// TODO: Implement stop() method.
		}
	}

	/**
	 * Class Request
	 *
	 * @package WPProfiler\Core\Collectors
	 */
	class Request extends Core\CollectorAbstract {

		/**
		 *
		 */
		const NAME = 'request';

		/**
		 * @inheritDoc
		 */
		public function get( $data = null ) {
			if ( ! did_action( 'parse_query' ) ) {
				return [];
			}

			return $GLOBALS['wp']->query_vars;

		}

		/**
		 * @return mixed|void
		 */
		public function start() {
			// TODO: Implement start() method.
		}

		/**
		 * @return mixed|void
		 */
		public function stop() {
			// TODO: Implement stop() method.
		}
	}

	/**
	 * Class Db
	 *
	 * @package WPProfiler\Core\Collectors
	 */
	class Db extends Core\CollectorAbstract {

		/**
		 *
		 */
		const NAME = 'db';

		/**
		 * @inheritDoc
		 */
		public function get( $data = null ) {

			if ( ! ( defined( 'SAVEQUERIES' ) && SAVEQUERIES ) ) {
				return [];
			}
			$queries   = &$GLOBALS['wpdb']->queries;
			$collected = [];

			foreach ( $queries as $query ) {
				$collected[] = [
					'sql'        => $query[0],
					'time'       => $query[1],
					'human_time' => sprintf( '%f', $query[1] ),
					'time_start' => $query[3],
					'stack'      => explode( ', ', $query[2] ),
					'data'       => $query[4],
				];
			}

			return $collected;
		}

		/**
		 * @return mixed|void
		 */
		public function start() {
			// TODO: Implement start() method.
		}

		/**
		 * @return mixed|void
		 */
		public function stop() {
			// TODO: Implement stop() method.
		}
	}
}

namespace WPProfiler\Core {

	use WPProfiler\Core;

	/**
	 * @return \WPProfiler\Core\Profiler
	 */
	function profiler() {
		static $instance;

		if ( ! $instance ) {
			$instance = new Core\Profiler();
			$instance->set_report_handler( new FileSystemReporter() );
			$collectors = [
				Collectors\Hook::class,
				Collectors\Function_::class,
				Collectors\FunctionTracer::class,
				Collectors\Query::class,
				Collectors\Request::class,
				Collectors\Db::class,
			];

			foreach ( $collectors as $collector ) {
				$name = constant( "{$collector}::NAME" );
				$instance->register_collector( constant( "{$collector}::NAME" ), new $collector( $instance ) );
				if ( $name === Collectors\Function_::NAME && 0 >= version_compare( PHP_VERSION, '7.0' ) ) {
					continue;
				}
				if ( $name === Collectors\FunctionTracer::NAME ) {
					continue;
				}
				$instance->enable_collector( $name );
			}
			$instance->init();
		}

		return $instance;
	}
}

namespace {

	use function WPProfiler\Core\profiler;

	if ( ! function_exists( 'wp_profiler' ) ) {

		/**
		 * @return \WPProfiler\Core\Profiler
		 */
		function wp_profiler() {
			return profiler();
		}

	}

	WPProfiler\Core\profiler();

}
