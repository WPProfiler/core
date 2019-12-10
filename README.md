# WordPress Profiler
A drop-in profiler for WordPress in the form of a MU (Must Use) plugin

# Links

* [View](https://github.com/WPProfiler/core)
* [Downloads](https://raw.githubusercontent.com/WPProfiler/core/master/0_wordpress_profiler.php)

# Instructions

* Place `0_wordpress_profiler.php` in the WordPress installation's `mu-plugins` folder
* Access the troublesome parts of the site or just let it sit and collect reports. One of the project goals has been to make an unsupervised monitoring system/agent.
* Go to the `wp-content` (or where ever you have set the content folder to be)`/profiler` and review the reports

Tip: It is named `0_wordpress_profiler.php` so that the filesystem will ideally return it 1st in a list of files, so please avoid any other mu plugins starting with `0` :). OS configuration files in Linux use the same technique.

# The following collectors are supported

## Hooks

The following data for each hook are recorded:

* The hook
* The time started
* the time stopped
* The calculated time that was taken
* The calculated time as a string without scientific notation taken
* The memory usage when starting
* The memory usage when stopping
* The computed memory use that was taken
* The functions called in the given instance of the hook run  if the function collector is enabled
* The caller function of the hook as provided by `debug_backtrace` if the function tracing collector is enabled
* The children hooks (nested hook calls) in the same structure as above

## Functions

Inside the hook data, a `function` key will exist with an array of functions. Each element has the following:

* The time started
* the time stopped
* The calculated time that was taken
* The calculated time as a string without scientific notation taken
* The memory usage when starting
* The memory usage when stopping
* The calculated memory use that was taken

## Function tracing

Function tracing will provide a `caller` key in a hook with the output of `debug_backtrace`. It is is off by default due to the possible performance hit of using `debug_backtrace()` on every hooked-in function. If you wish to use it, call `wp_profiler()->enable_collector(\WPProfiler\Core\Collectors\FunctionTracer::NAME)`.

## Query data

This will scan and output the results of all `is_*` functions in `$the_wp_query`, the key being the method name, and the value the result.

## Request data

This makes a copy of `$wp->query_vars`.

## Db

Even when this collector is enabled, it will only work when `SAVEQUERIES` WordPress constant is enabled. It relies on `$wpdb->queries`, and the output is a customized format mapped from the resulting arrays of `wpdb::log_query`. See [example.json](https://github.com/WPProfiler/core/blob/master/example.json#L7035-L7050) for reference to the format.

# Example hook report

Here is a sample of a report generated from this plugin: [example.json](https://github.com/WPProfiler/core/blob/master/example.json)

# Use a custom report handler

The profiler supports setting a custom handler for the report data via the `set_report_handler` method. This requires a class implement against the `ReporterInterface` interface.

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

Implement the `\WPProfiler\Core\CollectorInterface` interface or extend the `\WPProfiler\Core\CollectorAbstract` class to use the base collector shared by all the core collectors.
 
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
}

wp_profiler()->register_collector('my_collector', new MyCollector(wp_profiler()));
wp_profiler()->enable_collector('my_collector');
```

# Adding custom meta to the report

 If you don't want to create a full-fleged collector and just want to send an extra piece of info with the report, the meta API (not post/user/term in WP core) will do the job. Example:
 
 ```php
wp_profiler()->add_meta('operating_system','linux');
```

# How to ignore a function from functional profiling

Use the ignore function API on the function collector. See example:

```php
use WPProfiler\Core\Collectors\Function_;

function my_function() {

}

$my_function = function () {

};

class MyTest {
	public static function my_static_function() {
	}

	public function my_function() {
	}
}

$my_object = new MyTest();
wp_profiler()->call_collector( Function_::NAME, 'set_ignoring_enabled', true );
wp_profiler()->call_collector( Function_::NAME, 'ignore_function', 'my_function' );
wp_profiler()->call_collector( Function_::NAME, 'ignore_function', $my_function );
wp_profiler()->call_collector( Function_::NAME, 'ignore_function', [ 'MyTest', 'my_static_function' ] );
wp_profiler()->call_collector( Function_::NAME, 'ignore_function', [ $my_object, 'my_function' ] );
```

# How to ignore a hook from profiling

 ```php
use WPProfiler\Core\Collectors\Hook;

wp_profiler()->call_collector( Hook::NAME, 'ignore_hook', 'my_custom_hook' );
```

# API Documentation

See generated docs at [apidocs.wpprofiler.org](https://apidocs.wpprofiler.org)
