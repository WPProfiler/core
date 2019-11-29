# WordPress Profiler
A drop-in hook profiler (actions/filters) for WordPress in the form of a MU (Must Use) plugin

# Instructions

* Place `wordpress_profiler.php` in the WordPress installation's `mu-plugins` folder
* Access the troublesome parts of the site or just let it sit and collect reports
* Go to the wp-content (or where ever you have set the content folder to be)/profiler and review the reports

# The following data is reported

* The hook
* The time started
* the time stopped
* The computed time taken
* The computed time as a string without scientific notation taken
* The memory usage when starting
* The memory usage when stopping
* The computed memory use taken
* The functions call in the given instance of the hook run
* The caller function of the hook as provided by `debug_backtrace` if function tracing is enabled
* The children hooks (nested hook calls) in the same structure as above

# Enabling function tracing

Function tracing for hooks is off by default due to the possible performance hit of using `debug_backtrace()`. If you wish to use it, call `profiler()->set_function_tracing(true)`.


# Example hook recording

Here is sample of a recorded object for a single hook

```json
{
    "hook": "option_siteurl",
    "start": 1574984682.006578,
    "stop": 1574984682.007091,
    "time": 0.0005130767822265625,
    "human_time": "0.000513",
    "memory_start": 2761664,
    "memory_stop": 2767440,
    "memory": 5776,
    "functions": [{
        "start": 1574984682.006713,
        "stop": 1574984682.006982,
        "time": 0.00026917457580566406,
        "human_time": "0.000269",
        "memory_start": 2766328,
        "memory_stop": 2767072,
        "memory": 744,
        "file": "\/var\/www\/html\/wp-includes\/functions.php",
        "line": 4017,
        "function": "_config_wp_siteurl"
    }],
    "caller": {
        "file": "\/var\/www\/html\/wp-includes\/option.php",
        "line": 152,
        "function": "apply_filters"
    },
    "children": []
}
```

# Use a custom report handler

The profiler supports setting a custom handler for the report data via `set_report_handler` method. This requires a class implement against the `ReporterInterface` interface.

### Example custom report handler

```php
use pcfreak30\WordPress_Profiler\ReporterInterface;
use function pcfreak30\WordPress_Profiler\profiler;

class MyCustomReporter implements ReporterInterface {
	public function execute( $filename, array $data ) {
		wp_remote_post( 'https://myreport.server', [ 'body' => [ 'filename' => $filename, 'data' => $data ] ] );
	}
}

profiler()->set_report_handler( new MyCustomReporter() );
```
 
