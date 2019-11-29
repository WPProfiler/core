# WordPress Profiler
A drop-in hook profiler (actions/filters) for WordPress in the form of a MU (Must Use) plugin

# Instructions

* Place `0_wordpress_profiler.php` in the WordPress installation's `mu-plugins` folder
* Access the troublesome parts of the site or just let it sit and collect reports
* Go to the `wp-content` (or where ever you have set the content folder to be)`/profiler` and review the reports

Tip: It is named `0_wordpress_profiler.php` so that the filesystem will ideally return it 1st in a list of files, so please avoid any other mu plugins siarting with `0` :). The same technique is used by OS configuration files in Linux.

# The following collectors are supported

## Hooks

The following data for each hook are recorded:

* The hook
* The time started
* the time stopped
* The computed time taken
* The computed time as a string without scientific notation taken
* The memory usage when starting
* The memory usage when stopping
* The computed memory use taken
* The functions called in the given instance of the hook run  if the function collector is enabled
* The caller function of the hook as provided by `debug_backtrace` if the function tracing collector is enabled
* The children hooks (nested hook calls) in the same structure as above

## Functions

Inside the hook data, a `function` key will exist with an array if functions. Eavh element has the following:

* The time started
* the time stopped
* The computed time taken
* The computed time as a string without scientific notation taken
* The memory usage when starting
* The memory usage when stopping
* The computed memory use taken

## Function tracing

Function tracing will provide a `caller` key in a hook with the output of `debug_backtrace`. It is is off by default due to the possible performance hit of using `debug_backtrace()` on every hooked-in function. If you wish to use it, call `wp_profiler()->enable_collector(\WPProfiler\Core\Collectors\FunctionTracer::NAME)`.

## Query data

This will scan and output the results of all `is_*` functions in `$the_wp_query`, the key being the method name, and the value the result.

## Request data

This simple makes a copy of `$wp->query_vars`

## Db

Even when this collector is enabled, it will only work when `SAVEQUERIES` WordPress constant is enabled. It relies on `$wpdb->queries` and the output is a customized format mapped from the resulting arrays of `wpdb::log_query`. See [example.json](example.json#L7035-L7050) for reference of the format.

# Example hook report

Here is sample of a report generated from this plugin: [example.json](example.json)

# Use a custom report handler

The profiler supports setting a custom handler for the report data via `set_report_handler` method. This requires a class implement against the `ReporterInterface` interface.

### Example custom report handler

```php
use WPProfiler\Core\ReporterInterface;

class MyCustomReporter implements ReporterInterface {
	public function execute( $filename, array $data ) {
		wp_remote_post( 'https://myreport.server', [ 'body' => [ 'filename' => $filename, 'data' => $data ] ] );
	}
}

wp_profiler()->set_report_handler( new MyCustomReporter() );
```

# Creating your own Collector

You can create a new collector at any time during the WordPress lifecycle, assuming it is after WPProfiler has loaded.

Simply implement the `\WPProfiler\Core\CollectorInterface` interface or extend the `\WPProfiler\Core\CollectorAbstract` class to use the base collector shared by all the core collectors.
 
Example:

```php
use WPProfiler\Core\CollectorInterface;
use WPProfiler\Core\Profiler;

class MyCollector implements CollectorInterface {


	/**
	 * @inheritDoc
	 */
	public function __construct( Profiler $profiler ) {
	}

	/**
	 * @inheritDoc
	 */
	public function init() {
		// TODO: Implement init() method.
	}

	/**
	 * @inheritDoc
	 */
	public function get( $data = null ) {

	}

	/**
	 * @inheritDoc
	 */
	public function enable() {
		// TODO: Implement enable() method.
	}

	/**
	 * @inheritDoc
	 */
	public function disable() {
		// TODO: Implement disable() method.
	}

	/**
	 * @inheritDoc
	 */
	public function start() {
		// TODO: Implement start() method.
	}

	/**
	 * @inheritDoc
	 */
	public function stop() {
		// TODO: Implement stop() method.
	}
}

wp_profiler()->register_collector('my_collector', new MyCollector(wp_profiler()));
wp_profiler()->enable_collector('my_collector');
```
