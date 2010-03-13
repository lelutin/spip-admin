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
        $msg = _translate(
            "The \$key argument (second argument) must be a string"
        );
        throw new InvalidArgumentException($msg);
    }
    if ( ! array_key_exists($key, $array) ) {
        return $default;
    }
    return $array[$key];
}

/**
 * Retrieve an element and remove it from an array.
 *
 * Retrive the value for a given key in an array and remove it from that array.
 * If the key is not present in the array, return the default value, given in
 * the third argument. If the third argument is omitted, the default value is
 * Null.
 *
 * @return mixed: value from the array or default value if key is not in array.
 * @throws InvalidArgumentException: when $key is not a string
 * @author Gabriel Filion
 **/
function array_pop_elem(&$array, $key, $default=null) {
    $value = array_get($array, $key, $default);

    if ( array_key_exists($key, $array) ) {
        unset($array[$key]);
    }

    return $value;
}

/**
 * Append an array or a value to an array.
 *
 * Strangely, PHP has no function to simply append (not merge) an array to
 * another one. This provides for this lacking feature.
 *
 * The first array is modified in place, so nothing is returned.
 *
 * This function discards keys from the second array. To conserve the keys, use
 * array_merge.
 *
 * @return void
 * @author Gabriel Filion
 **/
function array_append(&$array, $appended) {
    if ( ! is_array($appended) ) {
        $array[] = $appended;
    }

    foreach ( $appended as $value ) {
        $array[] = $value;
    }
}

/**
 * Retrieve program name
 *
 * Get the file name, without the directory, of the script that was called.
 *
 * @return String
 * @author Gabriel Filion
 **/
function get_prog_name() {
    return basename($_SERVER['SCRIPT_FILENAME']);
}

/**
 * Exit with an exit code and print a message to stdout.
 *
 * @return void
 * @author Gabriel Filion
 **/
function bail_out($message, $code) {
    $prog = basename($_SERVER['SCRIPT_FILENAME']);

    $l10n_error = _translate("error");

    fprintf(STDERR, "$prog: $l10n_error: $message\n");
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
