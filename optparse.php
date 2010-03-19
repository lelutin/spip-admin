<?php
/**
 * Option parser.
 *
 * Easily parse command line arguments in PHP. This parser has the same
 * interface as the python "optparse" package.
 *
 * Example usage:
 *   $parser = new OptionParser();
 *   $parser->add_option(array("-f", "--foo", "dest"=>"bar"));
 *   $values = $parser->parse_args($argv);
 */
require_once("utils.php");

define("NO_SUCH_OPT_ERROR", 1);
define("WRONG_VALUE_COUNT_ERROR", 2);
define("OPTION_VALUE_ERROR", 3);

// Default value for an option can be Null. We need an explicit no_default value
define("NO_DEFAULT", "~~~NO~DEFAULT~~~");

/**
 * Utility class for parsing arguments from the Command Line Interface
 *
 * @author Gabriel Filion
 */
class OptionParser {

    function OptionParser($settings=array()) {
        $this->_positional = array();
        $this->option_list = array();

        // This must come first so that calls to add_option can succeed.
        $this->option_class = array_pop_elem(
            $settings,
            "option_class",
            "Option"
        );
        if ( ! is_string($this->option_class) ) {
            $msg = _translate("The setting \"option_class\" must be a string");
            throw new InvalidArgumentException($msg);
        }

        $default_usage = _translate("%prog [arguments ...]");
        $this->set_usage( array_pop_elem($settings, "usage", $default_usage) );

        $this->description = array_pop_elem($settings, "description", "");

        $this->defaults = array();

        $add_help_option = array_pop_elem($settings, "add_help_option", true);
        if ($add_help_option) {
            $this->add_option( array(
                "-h","--help",
                "action" => "help",
                "help" => _translate("show this help message and exit")
            ) );
        }

        $this->version = array_pop_elem($settings, "version", "");
        if ($this->version) {
            $this->add_option( array(
                "--version",
                "action" => "version",
                "help" => _translate("show program's version number and exit")
            ) );
        }

        $this->set_conflict_handler(array_pop_elem(
            $settings,
            "conflict_handler",
            "error"
        ) );

        $this->prog = array_pop_elem(
            $settings,
            "prog",
            get_prog_name()
        );

        // Still some settings left? we don't know about them. yell
        if ( ! empty($settings) ) {
            throw new OptionError($settings);
        }
    }

    /**
     * Add an option that the parser must recognize.
     *
     * The argument can be either an array with settings for the option class's
     * constructor, or an Option instance.
     *
     * @return Option object
     * @throws InvalidArgumentException: if argument isn't an array or an Option
     * @author Gabriel Filion
     **/
    public function add_option($settings) {
        if ( is_array($settings) ) {
            $option_class = $this->option_class;
            $new_option = new $option_class($settings);
        }
        else if ( is_a($settings, Option) ) {
            $new_option = $settings;
        }
        else {
            $vals = array("arg" => $settings);
            $msg = _translate("not an Option instance: %(arg)s", $vals);
            throw new InvalidArgumentException($msg);
        }

        // Resolve conflict with the right conflict handler
        foreach ( $new_option->option_strings as $name ) {
            $option = $this->get_option($name);

            if ( $option !== Null ) {
                if ( $this->conflict_handler == "resolve" ) {
                    $this->_resolve_option_conflict($option, $name, $this);
                }
                else {
                    throw new OptionConflictError($name);
                }
            }
        }

        $this->option_list[] = $new_option;

        // Option has a destination. we need a default value
        if ($new_option->dest !== Null) {
            if ($new_option->default !== NO_DEFAULT) {
                $this->defaults[$new_option->dest] = $new_option->default;
            }
            else if ( ! array_key_exists($new_option->dest, $this->defaults) ) {
                $this->defaults[$new_option->dest] = Null;
            }
        }

        return $new_option;
    }

    /**
     * Search for an option name in current options.
     *
     * Given an option string, search for the Option object that uses this
     * string. If the option cannot be found, return Null.
     *
     * @return Option object: when the option is found
     * @return Null: when the option is not found
     * @author Gabriel Filion
     **/
    public function get_option($text) {
        $found = Null;

        foreach ($this->option_list as $opt) {
            if ( in_array($text, $opt->option_strings) ) {
                $found = $opt;
                break;
            }
        }

        return $found;
    }

    /**
     * Verify if an option is present in the parser
     *
     * Given an option string, verify if one of the parser's options uses this
     * string. It is a convenient way to verify if an option was already added.
     *
     * @return boolean: true if option is present, false if not
     * @author Gabriel Filion
     **/
    public function has_option($text) {
        foreach ($this->option_list as $opt) {
            if ( in_array($text, $opt->option_strings) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove the option corresponding to a string
     *
     * Remove the option that uses the given string. If the option uses other
     * strings of text, those strings become invalid (unused). If the text does
     * not correspond to an option, a OutOfBoundsException is thrown.
     *
     * @return void
     * @author Gabriel Filion
     **/
    public function remove_option($text) {
        $found = false;

        foreach ($this->option_list as $key => $opt) {
            if ( in_array($text, $opt->option_strings) ) {
                $strings = $opt->option_strings;

                unset( $this->option_list[$key] );
                $found = true;

                $this->_reenable_option_strings($strings);
                break;
            }
        }

        if (! $found) {
            $vals = array("option" => $text);
            $msg = _translate(
                "Option \"%(option)s\" does not exist.",
                $vals
            );

            throw new OutOfBoundsException($msg);
        }
    }

    /**
     * Set the usage text
     *
     * @return void
     * @author Gabriel Filion
     **/
    public function set_usage($new_usage) {
        $this->usage = $new_usage;
    }

    /**
     * Retrieve usage string
     *
     * @return String
     * @author Gabriel Filion
     **/
    public function get_usage() {
        return $this->usage;
    }

    /**
     * Print usage
     *
     * Print usage message. Default output stream is stdout. To change it, pass
     * another stream as argument.
     *
     * @return void
     * @author Gabriel Filion
     **/
    public function print_usage($stream=STDOUT) {
        // Replace occurences of %prog to the program name
        $usage = preg_replace(
            "/\%prog/",
            $this->get_prog_name(),
            $this->get_usage()
        );

        fprintf($stream, "Usage: ". $usage. "\n\n" );
    }

    /**
     * Print the help text
     *
     * Print the whole help message as seen with option -h. Default output
     * stream is stdout. To change it, pass another stream as argument.
     *
     * @return void
     * @author Gabriel Filion
     **/
    public function print_help($stream=STDOUT) {
        // Print usage
        $this->print_usage($stream);

        if ($this->description) {
            fprintf($stream, $this->get_description(). "\n\n");
        }

        // List all available options
        $msg = _translate("Options:");
        fprintf($stream, $msg. "\n");
        foreach ($this->option_list as $option) {
            fprintf($stream, "  ". $option->__str__(). "\n" );
        }

        fprintf($stream, "\n");
    }

    /**
     * Print version
     *
     * Print version information message. Default output stream is stdout. To
     * change it, pass another stream as argument.
     *
     * @return void
     * @author Gabriel Filion
     **/
    public function print_version($stream=STDOUT) {
        // Replace occurences of %prog to the program name
        $version = preg_replace(
            "/\%prog/",
            $this->get_prog_name(),
            $this->get_version()
        );

        fprintf($stream, $version. "\n\n" );
    }

    /**
     * Retrieve the program name as shown by usage
     *
     * @return String
     * @author Gabriel Filion
     **/
    public function get_prog_name() {
        return $this->prog;
    }

    /**
     * Retrieve the description
     *
     * @return String
     * @author Gabriel Filion
     **/
    public function get_description() {
        return $this->description;
    }

    /**
     * Retrieve the version tag
     *
     * @return String
     * @author Gabriel Filion
     **/
    public function get_version() {
        return $this->version;
    }

    /**
     * Parse command line arguments
     *
     * Given an array of arguments, parse them and create an object containing
     * expected values and positional arguments.
     *
     * @author Gabriel Filion <gfilion@revolutionlinux.com>
     **/
    public function parse_args($argv, $values=Null){
        // Pop out the first argument, it is assumed to be the command name
        array_shift($argv);

        if ( $values !== Null && ! is_array($values) ) {
            $msg = _translate("Default values must be in an associative array");
            throw new InvalidArgumentException($msg);
        }

        $this->values = array();

        if ($values === Null) {
            $this->values = $this->get_default_values();
        }
        else {
            // Get a copy of default values and update the array
            $this->values = array_merge($this->get_default_values(), $values);
        }

        $rargs = $argv;

        $positional = array();
        while ( ! empty($rargs) ){
            $arg = array_shift($rargs);

            // Stop processing on a -- argument
            if ( $arg == "--" ) {
                // All remaining arguments are positional
                array_append($positional, $rargs);
                break;
            }

            // Options should begin with a dash. All else is positional
            // A single dash alone is also a positional argument
            if ( substr($arg, 0, 1) != "-" || strlen($arg) == 1) {
                $positional[] = $arg;
            }
            else if ( substr($arg, 0, 2) == "--" ) {
                $this->_process_long_option($arg, $rargs, $this->values);
            }
            else {
                // values will be removed from $rargs during this process
                $this->_process_short_options($arg, $rargs, $this->values);
            }
        }

        return new Values($this->values, $positional);
    }

    /**
     * Set the option conflict handler
     *
     * Conflict handler can be one of "error" or "resolve".
     *
     * @return void
     * @throws InvalidArgumentException on invalid handler name
     * @author Gabriel Filion
     **/
    public function set_conflict_handler($handler) {
        if ( ! in_array( $handler, array("error", "resolve") ) ) {
            $msg = _translate(
                "The conflict handler must be one of \"error\" or \"resolve\""
            );
            throw new InvalidArgumentException($msg);
        }

        $this->conflict_handler = $handler;
    }

    /**
     * Get the list of default values.
     *
     * @return array
     * @author Gabriel Filion
     **/
    public function get_default_values() {
        return $this->defaults;
    }

    /**
     * Set default value for only one option
     *
     * Default values must have a key that corresponds to the "dest" argument of
     * an option.
     *
     * @return void
     * @author Gabriel Filion
     **/
    public function set_default($dest, $value) {
        $this->defaults[$dest] = $value;
    }

    /**
     * Set default values for multiple destinations
     *
     * Default values must have a key that corresponds to the "dest" argument of
     * an option. Calling this function is the preferred way of setting default
     * values for options, since multiple options can share the same
     * destination.
     *
     * @return void
     * @author Gabriel Filion
     **/
    public function set_defaults($values) {
        $this->defaults = array_merge($this->defaults, $values);
    }

    /**
     * Exit program with an error message and code
     *
     * @return void
     * @author Gabriel Filion
     **/
    public function error($text, $code) {
        $this->print_usage(STDERR);
        bail_out($text, $code);
    }

    /**
     * Process a long option.
     *
     * Long options that expect value(s) will get them from the next arguments
     * given on the command line. The first value can also be appended to them
     * with = as a separator.
     *
     * Examples:
     *     program --enable-this
     *     program --option=value
     *     program --option=value1 value2
     *     program --option value1 value2
     *
     * @return void
     * @author Gabriel Filion
     **/
    private function _process_long_option($argument, &$rargs, &$values) {
        $key_value = explode("=", $argument, 2);
        $arg_text = $key_value[0];

        $option = $this->_get_known_option($arg_text);

        // Add the first value if it was appended to the arg with =
        if ( count($key_value) > 1 ) {
            // Option didn't expect this value
            if ($option->nargs < 1) {
                $vals = array("option" => $arg_text);
                $msg = _translate(
                    "%(option)s option does not take a value.",
                    $vals
                );

                $this->error($msg, WRONG_VALUE_COUNT_ERROR);
            }

            array_unshift($rargs, $key_value[1]);
        }

        $this->_process_option($option, $rargs, $arg_text, $values);
    }

    /**
     * Process a conglomerate of short options.
     *
     * Short options that expect value(s) will get them from the next
     * arguments. The first value can also be typed right after the option
     * without a space. Options can also be joined in conglomerates. Options
     * that expect a value should be at the end of a conglomerate, since the
     * rest of the argument will be evaluated as the option's value.
     *
     * Examples:
     *     program -q
     *     program -d something
     *     program -dsomething
     *     program -vvf arg_to_f
     *
     * @return void
     * @author Gabriel Filion
     **/
    private function _process_short_options($argument,
                                            &$rargs,
                                            &$values)
    {
        $characters = preg_split(
            '//', substr($argument, 1), -1, PREG_SPLIT_NO_EMPTY
        );
        $i = 1;
        $stop = false;

        foreach($characters as $ch) {
            $opt_string = "-". $ch;
            $i++; // an option was consumed

            $option = $this->_get_known_option($opt_string);

            if ( $option->nargs >= 1) {
                // The option expects values, insert the rest of $argument as
                // the value.
                if ( $i < strlen($argument) ) {
                    array_unshift($rargs, substr($argument, $i) );
                }
                // ... and stop iterating.
                $stop = true;
            }

            $this->_process_option($option, $rargs, $opt_string, $values);

            if ($stop) {
                break;
            }
        }
    }

    /**
     * Ask an option to process information
     *
     * Process an option. If it throws an OptionValueError, exit with an error
     * message.
     *
     * @return void
     * @author Gabriel Filion
     **/
    private function _process_option(&$option, &$rargs,
                                     $opt_string, &$values) {
        $nbvals = $option->nargs;

        if ( $nbvals < 1 ) {
            $value = $option->default;
        }
        else {
            $value = array();
        }

        // Not enough values given
        if ( count($rargs) < $nbvals ) {
            $vals = array("option" => $opt_string);
            if ( $nbvals == 1) {
                $what = "an argument";
            }
            else {
                $vals["nbargs"] = $nbvals;
                $what = "%(nbargs)s arguments";
            }
            $msg = _translate("%(option)s option takes $what.", $vals);

            $this->error($msg, WRONG_VALUE_COUNT_ERROR);
        }

        while ( $nbvals ) {
            $value[] = array_shift($rargs);
            $nbvals--;
        }

        // If only one value, set it directly as the value (not in an array)
        if ( $option->nargs == 1 ) {
            $value = $value[0];
        }

        try {
            $option->process($value, $opt_string, $values, $this);
        }
        catch (OptionValueError $exc) {
            $this->error(
                $exc->getMessage(),
                OPTION_VALUE_ERROR
            );
        }
    }

    /**
     * Find an option but exit if it is not known
     *
     * Find an option with the text from command line. If the option cannot be
     * found, exit with and error.
     *
     * @return Option object
     * @author Gabriel Filion
     **/
    private function _get_known_option($opt_text) {
        $option = $this->get_option($opt_text);

        // Unknown option. Exit with an error
        if ($option === Null) {
            $vals = array("option" => $opt_text);
            $msg = _translate("No such option: %(option)s", $vals);

            $this->error($msg, NO_SUCH_OPT_ERROR);
        }

        return $option;
    }

    /**
     * Resolve option conflicts intelligently
     *
     * This method is the resolver for option conflict_handler="resolve". It
     * tries to resolve conflicts automatically. It disables an option string
     * so that the last option added that uses this string has precedence.
     *
     * If an option sees its last string get disabled, it removes it entirely.
     * Options that get removed cannot be automatically re-enabled later.
     *
     * @return void
     * @author Gabriel Filion
     **/
    private function _resolve_option_conflict(&$old_option,
                                             $option_text,
                                             &$parser)
    {
        if ( count($old_option->option_strings) == 1 ) {
            $parser->remove_option($option_text);
            return;
        }

        $old_option->disable_string($option_text);
    }

    /**
     * Re-enable an option string
     *
     * When the conflict handler is set to "resolve", some strings may be
     * disabled. This method tries to reenable a string.
     *
     * @return void
     * @author Gabriel Filion
     **/
    private function _reenable_option_strings($option_strings) {
        $options = array_reverse($this->option_list);

        foreach ($option_strings as $option_text) {

            foreach ($options as $option) {
                $index = array_search($option_text, $option->disabled_strings);

                if ($index !== false) {
                    $option->option_strings[] = $option_text;
                    unset( $option->disabled_strings[$index] );
                    break;
                }
            }
        }
    }
}

/**
 * Object returned by parse_args.
 *
 * It contains two attributes: one for the options and one for the positional
 * arguments.
 **/
class Values {
    function Values($options, $positional) {
        $this->options = $options;
        $this->positional = $positional;
    }
}

/**
 * Class representing an option.
 *
 * The option parser uses this class to represent options that are added to it.
 **/
class Option {

    function Option($settings) {
        $option_strings = array();

        // Get all option strings. They should be added without key in settings
        $i = 0;
        $longest_name = "";
        while ( $option_name = array_pop_elem($settings, "$i") ) {
            $option_strings[] = $option_name;

            // Get the name without leading dashes
            if ($option_name[1] == "-") {
                $name = substr($option_name, 2);
            }
            else {
                $name = substr($option_name, 1);
            }

            // Keep only the longest name for default dest
            if ( strlen($name) > strlen($longest_name) ) {
                $longest_name = $name;
            }

            $i++;
        }

        if ( empty($option_strings) ) {
            $msg = _translate(
                "An option must have at least one string representation"
            );
            throw new InvalidArgumentException($msg);
        }

        $this->disabled_strings = array();
        $this->option_strings = $option_strings;

        // Default values that may be overridden by sensible action defaults or
        // by settings
        $this->dest = $longest_name;
        $this->nargs = 1;
        $this->default = NO_DEFAULT;

        // Set some sensible defaults depending on the chosen action
        $this->action = array_pop_elem($settings, "action", "store");
        $this->_set_defaults_by_action($this->action);

        // Get default value
        //
        // Using this can lead to results that are unexpected.
        // Use OptionParser.set_defaults instead
        $this->default = array_pop_elem($settings, "default", $this->default);

        // Other option settings
        $this->help = array_pop_elem($settings, "help", "");
        $this->callback = array_pop_elem($settings, "callback");
        $this->dest = array_pop_elem($settings, "dest", $this->dest);
        $this->const = array_pop_elem($settings, "const", Null);

        $this->nargs = array_pop_elem($settings, "nargs", $this->nargs);
        if ($this->nargs < 0) {
            $msg = _translate("nargs setting to Option cannot be negative");
            throw new InvalidArgumentException($msg);
        }

        // Yell if any superfluous arguments are given.
        if ( ! empty($settings) ) {
            throw new OptionError($settings);
        }
    }

    /**
     * Process the option.
     *
     * When used, the option must be processed. Convert value to the right
     * type. Call the callback, if needed.
     *
     * Callback functions should have the following signature:
     *     function x_callback(&$option, $opt_string, $value, &$parser) { }
     *
     * The name of the callback function is of no importance as long as it can
     * be called with a PHP dynamic evaluation (e.g. by doing $func="foo";
     * $func(...); ). The first and last arguments should be passed by
     * reference so that doing anything to them is not done to a copy of the
     * object only.
     *
     * @return void
     * @throws RuntimeException if an unknown action was requested
     * @author Gabriel Filion
     **/
    public function process($value, $opt_string, &$values, &$parser) {
        $this->convert_value($value, $opt_string);

        $this->take_action(
            $this->action, $this->dest,
            $value, $opt_string, $values, $parser
        );
    }

    /**
     * Convert value to the requested type
     *
     * @return void
     * @author Gabriel Filion
     **/
    public function convert_value($value, $opt_string) {
        // TODO implement type conversion
    }

    /**
     * Take an action
     *
     * Based on the requested action, do the right thing.
     *
     * @return void
     * @throws RuntimeException if an unknown action was requested
     * @author Gabriel Filion
     **/
    public function take_action($action, $dest,
                                $value, $opt_string, &$values, &$parser) {
        switch ($action) {
        case "store":
            $values[$dest] = $value;
            break;
        case "store_const":
            $values[$dest] = $this->const;
            break;
        case "store_true":
            $values[$dest] = true;
            break;
        case "store_false":
            $values[$dest] = false;
            break;
        case "append":
            if ( ! is_array($values[$dest]) ) {
                $values[$dest] = array();
            }
            array_push($values[$dest], $value);
            break;
        case "append_const":
            if ( ! is_array($values[$dest]) ) {
                $values[$dest] = array();
            }
            array_push($values[$dest], $this->const);
            break;
        case "count":
            if ( ! is_int($values[$dest]) ) {
                $values[$dest] = 0;
            }
            $values[$dest] += 1;
            break;
        case "callback":
            if ($this->callback !== Null) {
                $callback = $this->callback;
                $value = $callback($this, $opt_string, $value, $parser);
            }
            break;
        case "help":
            $parser->print_help();
            exit(0);
            break;
        case "version":
            $parser->print_version();
            exit(0);
            break;
        default:
            $vals = array("action" => $action);
            $msg = _translate("unknown action %(action)s", $vals);
            throw new RuntimeException($msg);
        }

    }

    /**
     * Disable an option string (e.g. --option) from the option
     *
     * @return void
     * @author Gabriel Filion
     **/
    public function disable_string($opt_text) {
        $index = array_search($opt_text, $this->option_strings);

        if ( $index === false ) {
            $vals = array("opt" => $opt_text);
            $msg = _translate(
                "String \"%(opt)s\" is not part of the Option.",
                $vals
            );

            throw new InvalidArgumentException($msg);
        }

        $this->disabled_strings[] = $this->option_strings[$index];
        unset( $this->option_strings[$index] );
    }

    /**
     * Set some sensible default values depending on the action that was chosen
     *
     * Some actions don't require one or another attribute. Set those to
     * sensible defaults in order to have everything behave correctly.
     *
     * Values set here can be overridden by settings passed to the Option's
     * constructor.
     *
     * @return void
     * @author Gabriel Filion
     **/
    private function _set_defaults_by_action($action) {
        switch ($action) {
        case "store_const":
            $this->nargs = 0;
            break;
        case "store_true":
            $this->nargs = 0;
            $this->default = false;
            break;
        case "store_false":
            $this->nargs = 0;
            $this->default = true;
            break;
        case "append":
            $this->default = array();
            break;
        case "append_const":
            $this->nargs = 0;
            $this->default = array();
            break;
        case "count":
            $this->nargs = 0;
            $this->default = 0;
            break;
        case "callback":
        case "help":
        case "version":
            $this->nargs = 0;
            $this->dest = null;
            break;
        }
    }

    /**
     * String representation for PHP5
     *
     * This is a wrapper for automatically displaying the option in PHP5 with
     * the option strings and the description when it is printed out.
     *
     * @return String: name and description of the option
     * @author Gabriel Filion
     **/
    public function __toString() {
        return $this->__str__();
    }

    /**
     * String representation of the option.
     *
     * Format a string with option name and description so that it can be used
     * for a help message.
     *
     * @return String: name and description of the option
     * @author Gabriel Filion
     **/
    public function __str__() {
        $call_method = "";
        foreach ($this->option_strings as $name) {
            //FIXME this is not correct. dest must be shown only when needed.
            $dest = _translate($this->dest);
            $call_method .= $name. " ". strtoupper($dest). " ";
        }

        return $call_method. " ". _translate($this->help);
    }
}

/**
 * Exception on duplicate options
 *
 * Exception raised when an option added tries to use a string representation
 * (e.g. "--option") that is already used by a previously added option.
 **/
class OptionConflictError extends Exception {
    function OptionConflictError($name) {
        $msg = _translate("Duplicate definition of option \"$name\"");
        parent::__construct($msg);
    }
}

/**
 * Exception on superfluous arguments
 *
 * Exception raised when unknown options are passed to Option's constructor.
 **/
class OptionError extends Exception {
    function OptionError($arguments) {
        $args_as_string = implode(", ", array_keys($arguments) );

        $msg = _translate(
            "The following settings are unknown: $args_as_string"
        );
        parent::__construct($msg);
    }
}

/**
 * Exception on incorrect value for an option
 *
 * This exception should be raised by callback functions if there is an error
 * with the value that was passed to the option. optparses catches this and
 * exits the program, after printing the message in the exception to stderr.
 **/
class OptionValueError extends Exception { }

?>
