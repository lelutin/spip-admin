<?php
/**
 * Utility functions for various common tasks that PHP doesn't provide good
 * mechanisms for.
 **/

/**
 * Get array value or default
 *
 * Here is a simple function that gets a value from an array or returns the
 * default if key is not in the array. It prevents from crashing when accessing
 * an unset array key and provides for cleaner code.
 *
 * If not specified, the $default value is "null" but it can be specified to
 * whatever is needed.
 *
 * @return mixed: array value for $key or $default
 * @throws InvalidArgumentException: when $key is not a string
 * @author Gabriel Filion
 **/
function array_get($array, $key, $default=null) {
    if ( ! is_string($key) ) {
        $msg = "The \$key argument (second argument) must be a string";
        throw new InvalidArgumentException($msg);
    }
    if ( ! array_key_exists($key, $array) ) {
        return $default;
    }
    return $array[$key];
}

/**
 * Exit with an exit code and print a message to stdout.
 *
 * @return void
 * @author Gabriel Filion
 **/
function bail_out($message, $code) {
    $prog = basename($_SERVER['SCRIPT_FILENAME']);
    fprintf(STDERR, $prog.": Error: $message\n");
    exit($code);
}

?>
