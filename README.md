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

Function tracing for hooks is off by default due to the possible performance hit of using `debug_backtrace()`. If you wish to use it, turn the `ENABLE_FUNCTION_TRACING` constant flag to `true` at the top.


# Example hook recording

Here is sample of a recorded object for a single hook

```json
            {
                "hook": "option_siteurl",
                "start": 1574938537.390849,
                "stop": 1574938537.390964,
                "time": 0.00011491775512695312,
                "human_time": "0.000115",
                "memory_start": 2315792,
                "memory_stop": 2319320,
                "memory": 3528,
                "functions": [
                    "_config_wp_siteurl"
                ],
                "caller": {
                    "file": "\/var\/www\/html\/wp-includes\/option.php",
                    "line": 152,
                    "function": "apply_filters"
                },
                "children": []
            }
```
