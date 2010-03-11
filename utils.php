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

/**
 * Translate a string of text
 *
 * Try and find a translation corresponding to the given string of text. If no
 * translation program is available, fall back to the raw given text.
 *
 * @return String : the [hopefully] translated text
 * @author Gabriel Filion
 **/
function _translate($text, $variables=array() ) {
    $translator = _translate_find_method();

    $new_text = $translator($text);

    // Use values from $variables to replace pattends of the form %(name)s
    foreach ($variables as $name => $var) {
        $new_text = preg_replace("/%\($name\)s/", $var, $new_text);
    }

    return $new_text;
}

/**
 * Find what function should be used for translation.
 *
 * Try and detect the translation that should be used. If no translation is
 * possible, fall back to a function that simply sends back the text.
 *
 * @return String representation of the appropriate function name
 * @author Gabriel Filion
 **/
function _translate_find_method() {
    //TODO really detect something
    return "_translate_no_translation";
}

/**
 * Dummy translation function that does no translation
 *
 * This dummy translation function is used when no translation function was
 * detected. It simply returns the text with no modification.
 *
 * @return String of text
 * @author Gabriel Filion
 **/
function _translate_no_translation($text) {
    return $text;
}

?>
