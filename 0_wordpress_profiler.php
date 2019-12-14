<?php

namespace WPProfiler\Core {

	use ArrayAccess;
	use Iterator;
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
	}

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

		public function call_collector_by_ref( $name, $method, &...$args ) {
			if ( ! $this->is_collector_enabled( $name ) ) {
				if ( isset( $args[0] ) ) {
					return $args[0];
				}

				return false;
			}

			return $this->collectors[ $name ]->{$method}( ...$args );
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
		 * @param mixed  $value
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
				'user_agent'       => isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : null,
				'total_time'       => $time,
				'total_human_time' => sprintf( '%f', $time ),
				'memory_used'      => $memory,
				'peak_memory_used' => $peak_memory,
				'is_cron'          => wp_doing_cron() ? true : null,
				'is_ajax'          => wp_doing_ajax() ? true : null,
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

			if ( isset( $data['is_cli'] ) ) {
				$type = 'cli';
				if ( isset( $data['wp_cli_command'] ) ) {
					$type = 'wpcli';
				}
			}

			if ( isset( $data['is_cron'] ) ) {
				$type = 'cron';
			}

			if ( isset( $data['is_ajax'] ) ) {
				$type = 'ajax';
			}
			$dir      .= $type;
			$dir      = apply_filters( 'wp_profiler_report_storage_directory', $dir );
			$filename = apply_filters( 'wp_profiler_reporter_filesystem_reporter_filename', $filename );
			if ( ! @mkdir( $dir, 0777, true ) && ! @is_dir( $dir ) ) {
				error_log( sprintf( 'WP Profiler: Could not save report as Directory "%s" could not be created', $dir ) );

				return;
			}
			$path = $dir . DIRECTORY_SEPARATOR . $filename;
			file_put_contents( $path, wp_json_encode( $data, JSON_PRETTY_PRINT ) );

			do_action( 'wp_profiler_reporter_filesystem_reporter_report_saved', $path );
		}
	}

	/**
	 * Class Hook
	 *
	 * A modified copy of the WP_Hook Class
	 *
	 * @package WPProfiler
	 */
	class Hook implements Iterator, ArrayAccess {

		/**
		 * Hook callbacks.
		 *
		 * @var array
		 */
		public $callbacks = array();

		/**
		 * The priority keys of actively running iterations of a hook.
		 *
		 * @var array
		 */
		private $iterations = array();

		/**
		 * The current priority of actively running iterations of a hook.
		 *
		 * @var array
		 */
		private $current_priority = array();

		/**
		 * Number of levels this hook can be recursively called.
		 *
		 * @var int
		 */
		private $nesting_level = 0;

		/**
		 * Flag for if we're current doing an action, rather than a filter.
		 *
		 * @var bool
		 */
		private $doing_action = false;

		/**
		 * @var string
		 */
		private $hook_name;
		/**
		 * @var \WPProfiler\Core\Collectors\Hook
		 */
		private $collector;

		/**
		 * Hook constructor.
		 *
		 * @param \WP_Hook                              $hook
		 * @param string                                $hook_name
		 * @param \WPProfiler\Core\Profiler             $profiler
		 * @param \WPProfiler\Core\Collectors\Function_ $collector
		 */
		public function __construct( $hook_name, Function_ $collector ) {

			$this->hook_name = $hook_name;
			$this->collector = $collector;
		}

		/**
		 * Normalizes filters set up before WordPress has initialized to WP_Hook objects.
		 *
		 * @param array $filters Filters to normalize.
		 *
		 * @return WP_Hook[] Array of normalized filters.
		 */
		public static function build_preinitialized_hooks( $filters ) {
			/** @var \WPProfiler\Core\Hook[] $normalized */
			$normalized = [];
			$profiler   = wp_profiler();
			$collector  = $profiler->call_collector( Function_::NAME, 'get_self' );

			foreach ( $filters as $tag => $callback_groups ) {
				if ( is_object( $callback_groups ) && $callback_groups instanceof self ) {
					$normalized[ $tag ] = $callback_groups;
					continue;
				}
				$hook = new self( $tag, $collector );

				// Loop through callback groups.
				foreach ( $callback_groups as $priority => $callbacks ) {

					// Loop through callbacks.
					foreach ( $callbacks as $cb ) {
						$hook->add_filter( $tag, $cb['function'], $priority, $cb['accepted_args'] );
					}
				}
				$normalized[ $tag ] = $hook;
			}

			return $normalized;
		}

		/**
		 * Hooks a function or method to a specific filter action.
		 *
		 * @param string   $tag             The name of the filter to hook the $function_to_add callback to.
		 * @param callable $function_to_add The callback to be run when the filter is applied.
		 * @param int      $priority        The order in which the functions associated with a particular action
		 *                                  are executed. Lower numbers correspond with earlier execution,
		 *                                  and functions with the same priority are executed in the order
		 *                                  in which they were added to the action.
		 * @param int      $accepted_args   The number of arguments the function accepts.
		 */
		public function add_filter( $tag, $function_to_add, $priority, $accepted_args ) {
			$idx = _wp_filter_build_unique_id( $tag, $function_to_add, $priority );

			$priority_existed = isset( $this->callbacks[ $priority ] );

			$this->callbacks[ $priority ][ $idx ] = array(
				'function'      => $function_to_add,
				'accepted_args' => $accepted_args,
			);

			// if we're adding a new priority to the list, put them back in sorted order
			if ( ! $priority_existed && count( $this->callbacks ) > 1 ) {
				ksort( $this->callbacks, SORT_NUMERIC );
			}

			if ( $this->nesting_level > 0 ) {
				$this->resort_active_iterations( $priority, $priority_existed );
			}
		}

		/**
		 * Handles resetting callback priority keys mid-iteration.
		 *
		 * @param bool|int $new_priority     Optional. The priority of the new filter being added. Default false,
		 *                                   for no priority being added.
		 * @param bool     $priority_existed Optional. Flag for whether the priority already existed before the new
		 *                                   filter was added. Default false.
		 */
		private function resort_active_iterations( $new_priority = false, $priority_existed = false ) {
			$new_priorities = array_keys( $this->callbacks );

			// If there are no remaining hooks, clear out all running iterations.
			if ( ! $new_priorities ) {
				foreach ( $this->iterations as $index => $iteration ) {
					$this->iterations[ $index ] = $new_priorities;
				}

				return;
			}

			$min = min( $new_priorities );
			foreach ( $this->iterations as $index => &$iteration ) {
				$current = current( $iteration );
				// If we're already at the end of this iteration, just leave the array pointer where it is.
				if ( false === $current ) {
					continue;
				}

				$iteration = $new_priorities;

				if ( $current < $min ) {
					array_unshift( $iteration, $current );
					continue;
				}

				while ( current( $iteration ) < $current ) {
					if ( false === next( $iteration ) ) {
						break;
					}
				}

				// If we have a new priority that didn't exist, but ::apply_filters() or ::do_action() thinks it's the current priority...
				if ( $new_priority === $this->current_priority[ $index ] && ! $priority_existed ) {
					/*
					 * ... and the new priority is the same as what $this->iterations thinks is the previous
					 * priority, we need to move back to it.
					 */

					if ( false === current( $iteration ) ) {
						// If we've already moved off the end of the array, go back to the last element.
						$prev = end( $iteration );
					} else {
						// Otherwise, just go back to the previous element.
						$prev = prev( $iteration );
					}
					if ( false === $prev ) {
						// Start of the array. Reset, and go about our day.
						reset( $iteration );
					} elseif ( $new_priority !== $prev ) {
						// Previous wasn't the same. Move forward again.
						next( $iteration );
					}
				}
			}
			unset( $iteration );
		}

		/**
		 * Unhooks a function or method from a specific filter action.
		 *
		 * @param string   $tag                The filter hook to which the function to be removed is hooked.
		 * @param callable $function_to_remove The callback to be removed from running when the filter is applied.
		 * @param int      $priority           The exact priority used when adding the original filter callback.
		 *
		 * @return bool Whether the callback existed before it was removed.
		 */
		public function remove_filter( $tag, $function_to_remove, $priority ) {
			$function_key = _wp_filter_build_unique_id( $tag, $function_to_remove, $priority );

			$exists = isset( $this->callbacks[ $priority ][ $function_key ] );
			if ( $exists ) {
				unset( $this->callbacks[ $priority ][ $function_key ] );
				if ( ! $this->callbacks[ $priority ] ) {
					unset( $this->callbacks[ $priority ] );
					if ( $this->nesting_level > 0 ) {
						$this->resort_active_iterations();
					}
				}
			}

			return $exists;
		}

		/**
		 * Checks if a specific action has been registered for this hook.
		 *
		 * @param string        $tag               Optional. The name of the filter hook. Default empty.
		 * @param callable|bool $function_to_check Optional. The callback to check for. Default false.
		 *
		 * @return bool|int The priority of that hook is returned, or false if the function is not attached.
		 */
		public function has_filter( $tag = '', $function_to_check = false ) {
			if ( false === $function_to_check ) {
				return $this->has_filters();
			}

			$function_key = _wp_filter_build_unique_id( $tag, $function_to_check, false );
			if ( ! $function_key ) {
				return false;
			}

			foreach ( $this->callbacks as $priority => $callbacks ) {
				if ( isset( $callbacks[ $function_key ] ) ) {
					return $priority;
				}
			}

			return false;
		}

		/**
		 * Checks if any callbacks have been registered for this hook.
		 *
		 * @return bool True if callbacks have been registered for the current hook, otherwise false.
		 */
		public function has_filters() {
			foreach ( $this->callbacks as $callbacks ) {
				if ( $callbacks ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Removes all callbacks from the current filter.
		 *
		 * @param int|bool $priority Optional. The priority number to remove. Default false.
		 */
		public function remove_all_filters( $priority = false ) {
			if ( ! $this->callbacks ) {
				return;
			}

			if ( false === $priority ) {
				$this->callbacks = array();
			} elseif ( isset( $this->callbacks[ $priority ] ) ) {
				unset( $this->callbacks[ $priority ] );
			}

			if ( $this->nesting_level > 0 ) {
				$this->resort_active_iterations();
			}
		}

		/**
		 * Calls the callback functions that have been added to an action hook.
		 *
		 * @param array $args Parameters to pass to the callback functions.
		 */
		public function do_action( $args ) {
			$this->doing_action = true;
			$this->apply_filters( '', $args );

			// If there are recursive calls to the current action, we haven't finished it until we get to the last one.
			if ( ! $this->nesting_level ) {
				$this->doing_action = false;
			}
		}

		/**
		 * Calls the callback functions that have been added to a filter hook.
		 *
		 * @param mixed $value The value to filter.
		 * @param array $args  Additional parameters to pass to the callback functions.
		 *                     This array is expected to include $value at index 0.
		 *
		 * @return mixed The filtered value after all hooked functions are applied to it.
		 */
		public function apply_filters( $value, $args ) {
			if ( ! $this->callbacks ) {
				return $value;
			}

			$nesting_level = $this->nesting_level ++;

			$this->iterations[ $nesting_level ] = array_keys( $this->callbacks );
			$num_args                           = count( $args );

			do {
				$this->current_priority[ $nesting_level ] = current( $this->iterations[ $nesting_level ] );
				$priority                                 = $this->current_priority[ $nesting_level ];

				foreach ( $this->callbacks[ $priority ] as $the_ ) {
					if ( ! $this->doing_action ) {
						$args[0] = $value;
					}
					if ( ! $this->collector->is_function_ignored( $the_['function'] ) ) {
						$this->collector->start_timer( $the_['function'] );
					}
					// Avoid the array_slice if possible.
					if ( $the_['accepted_args'] == 0 ) {
						$value = call_user_func( $the_['function'] );
					} elseif ( $the_['accepted_args'] >= $num_args ) {
						$value = call_user_func_array( $the_['function'], $args );
					} else {
						$value = call_user_func_array( $the_['function'], array_slice( $args, 0, (int) $the_['accepted_args'] ) );
					}
					if ( ! $this->collector->is_function_ignored( $the_['function'] ) ) {
						$this->collector->stop_timer( $the_['function'] );
					}
				}
			} while ( false !== next( $this->iterations[ $nesting_level ] ) );

			unset( $this->iterations[ $nesting_level ] );
			unset( $this->current_priority[ $nesting_level ] );

			$this->nesting_level --;

			return $value;
		}

		/**
		 * Processes the functions hooked into the 'all' hook.
		 *
		 * @param array $args Arguments to pass to the hook callbacks. Passed by reference.
		 */
		public function do_all_hook( &$args ) {
			$nesting_level                      = $this->nesting_level ++;
			$this->iterations[ $nesting_level ] = array_keys( $this->callbacks );

			do {
				$priority = current( $this->iterations[ $nesting_level ] );
				foreach ( $this->callbacks[ $priority ] as $the_ ) {
					call_user_func_array( $the_['function'], $args );
				}
			} while ( false !== next( $this->iterations[ $nesting_level ] ) );

			unset( $this->iterations[ $nesting_level ] );
			$this->nesting_level --;
		}

		/**
		 * Return the current priority level of the currently running iteration of the hook.
		 *
		 * @return int|false If the hook is running, return the current priority level. If it isn't running, return false.
		 */
		public function current_priority() {
			if ( false === current( $this->iterations ) ) {
				return false;
			}

			return current( current( $this->iterations ) );
		}

		/**
		 * Determines whether an offset value exists.
		 *
		 * @link https://secure.php.net/manual/en/arrayaccess.offsetexists.php
		 *
		 * @param mixed $offset An offset to check for.
		 *
		 * @return bool True if the offset exists, false otherwise.
		 */
		public function offsetExists( $offset ) {
			return isset( $this->callbacks[ $offset ] );
		}

		/**
		 * Retrieves a value at a specified offset.
		 *
		 * @link https://secure.php.net/manual/en/arrayaccess.offsetget.php
		 *
		 * @param mixed $offset The offset to retrieve.
		 *
		 * @return mixed If set, the value at the specified offset, null otherwise.
		 */
		public function offsetGet( $offset ) {
			return isset( $this->callbacks[ $offset ] ) ? $this->callbacks[ $offset ] : null;
		}

		/**
		 * Sets a value at a specified offset.
		 *
		 * @link https://secure.php.net/manual/en/arrayaccess.offsetset.php
		 *
		 * @param mixed $offset The offset to assign the value to.
		 * @param mixed $value  The value to set.
		 */
		public function offsetSet( $offset, $value ) {
			if ( is_null( $offset ) ) {
				$this->callbacks[] = $value;
			} else {
				$this->callbacks[ $offset ] = $value;
			}
		}

		/**
		 * Unsets a specified offset.
		 *
		 * @link https://secure.php.net/manual/en/arrayaccess.offsetunset.php
		 *
		 * @param mixed $offset The offset to unset.
		 */
		public function offsetUnset( $offset ) {
			unset( $this->callbacks[ $offset ] );
		}

		/**
		 * Returns the current element.
		 *
		 * @link https://secure.php.net/manual/en/iterator.current.php
		 *
		 * @return array Of callbacks at current priority.
		 */
		public function current() {
			return current( $this->callbacks );
		}

		/**
		 * Moves forward to the next element.
		 *
		 * @link https://secure.php.net/manual/en/iterator.next.php
		 *
		 * @return array Of callbacks at next priority.
		 */
		public function next() {
			return next( $this->callbacks );
		}

		/**
		 * Returns the key of the current element.
		 *
		 * @link https://secure.php.net/manual/en/iterator.key.php
		 *
		 * @return mixed Returns current priority on success, or NULL on failure
		 */
		public function key() {
			return key( $this->callbacks );
		}

		/**
		 * Checks if current position is valid.
		 *
		 * @link https://secure.php.net/manual/en/iterator.valid.php
		 *
		 * @return boolean
		 */
		public function valid() {
			return key( $this->callbacks ) !== null;
		}

		/**
		 * Rewinds the Iterator to the first element.
		 *
		 * @link https://secure.php.net/manual/en/iterator.rewind.php
		 */
		public function rewind() {
			reset( $this->callbacks );
		}

	}
}

namespace WPProfiler\Core\Collectors {

	use ReflectionException;
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
		private function maybe_change_current_hook( $end = false ) {
			$count = count( $GLOBALS['wp_current_filter'] );
			if ( $this->level < $count ) {
				$this->move_down();
				$this->level ++;
			} else if ( ( $this->level > $count ) || ( $this->level === $count && $end ) ) {
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
			$this->maybe_change_current_hook( true );

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
		 * @return array
		 */
		public function get_current_hook() {
			return $this->current_hook;
		}

		public function update_current_hook_last_element( $name, $value ) {
			end( $this->current_hook[ $name ] );
			$this->current_hook[ $name ] [ key( $this->current_hook[ $name ] ) ] = $value;
		}

		public function get_current_hook_last_element( $name ) {
			end( $this->current_hook[ $name ] );

			return $this->current_hook[ $name ] [ key( $this->current_hook[ $name ] ) ];
		}

		public function append_current_hook( $name, $value ) {
			$this->current_hook[ $name ] [] = $value;
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


		private $skip = [];

		private $ignoring_enabled = false;

		/**
		 * @return void
		 */
		public function init() {
			parent::init();
			add_action( 'all', [ $this, 'maybe_inject_hook' ] );
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

				return;
			}

			$GLOBALS['wp_filter'] = Core\Hook::build_preinitialized_hooks( $GLOBALS['wp_filter'] );
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
			if ( is_array( $function ) && $function[0] instanceof Hook ) {
				return;
			}

			/** @var callable $function */
			$data = $this->profiler->create_timer_store();
			if ( is_string( $function ) && false !== strpos( $function, '::' ) ) {
				$function = explode( '::', $function );
			}
			if ( is_array( $function ) ) {
				try {
					$reflect = new ReflectionMethod( $function[0], $function[1] );
				} catch ( ReflectionException $e ) {
					$this->skip [] = true;

					return;
				}
				if ( is_object( $function[0] ) ) {
					$function[0] = get_class( $function[0] );
				}
				$data ['file']     = $reflect->getFileName();
				$data ['line']     = $reflect->getStartLine();
				$data ['function'] = "{$function[0]}::$function[1]";
			}
			/** @noinspection CallableParameterUseCaseInTypeContextInspection */
			if ( is_string( $function ) || is_object( $function ) ) {
				try {
					$reflect = new ReflectionFunction( $function );
				} catch ( ReflectionException $e ) {
					$this->skip [] = true;

					return;
				}
				$data ['file']     = $reflect->getFileName();
				$data ['line']     = $reflect->getStartLine();
				$data ['function'] = $reflect->getName();
				if ( $function instanceof \Closure ) {
					$data ['function'] .= '_' . md5( "{$data ['file']}:{$data ['line']}" );
				}
			}
			if ( empty( $data ['function'] ) ) {
				$data ['function'] = 'UNKNOWN';
			}

			$this->profiler->call_collector( Hook::NAME, 'append_current_hook', 'functions', $data );
			$this->skip [] = false;
		}

		/**
		 *
		 */
		public function stop_timer( $function ) {
			if ( is_array( $function ) && $function[0] instanceof Hook ) {
				return;
			}
			if ( array_pop( $this->skip ) ) {
				return;
			}
			$function = $this->profiler->call_collector( Hook::NAME, 'get_current_hook_last_element', 'functions' );
			$this->profiler->call_collector_by_ref( Hook::NAME, 'record_stop', $function );
			$this->profiler->call_collector( Hook::NAME, 'update_current_hook_last_element', 'functions', $function );
		}

		/**
		 * @param $action
		 */
		public function maybe_inject_hook() {
			$action = current_action();
			$exists = isset( $GLOBALS['wp_filter'][ $action ] );
			if ( ! $exists || ! ( $GLOBALS['wp_filter'][ $action ] instanceof Core\Hook ) ) {
				$hook = new Core\Hook( $action, $this );
				if ( $exists ) {
					// Loop through callback groups.
					foreach ( $GLOBALS['wp_filter'][ $action ] as $priority => $callbacks ) {
						// Loop through callbacks.
						foreach ( $callbacks as $cb ) {
							$hook->add_filter( $action, $cb['function'], $priority, $cb['accepted_args'] );
						}
					}
				}
				$GLOBALS['wp_filter'][ $action ] = $hook;
			}
		}

		/**
		 * @param callable $function
		 */
		public function ignore_function( $function ) {
			$this->ignored_functions[ _wp_filter_build_unique_id( null, $function, null ) ] = true;
		}

		/**
		 * @param callable $function
		 *
		 * @return bool
		 */
		public function remove_ignored_function( $function ) {
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
		public function is_function_ignored( $function ) {
			return $this->ignoring_enabled && isset( $this->ignored_functions[ _wp_filter_build_unique_id( null, $function, null ) ] );
		}

		public function get_self() {
			return $this;
		}

		/**
		 * @param bool $ignoring_enabled
		 */
		public function set_ignoring_enabled( $ignoring_enabled ) {
			$this->ignoring_enabled = $ignoring_enabled;
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
