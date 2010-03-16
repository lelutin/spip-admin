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
                "callback" => "_optparse_display_help",
                "help" => _translate("show this help message and exit"),
                "dest" => null,
                "nargs" => 0
            ) );
        }

        $this->version = array_pop_elem($settings, "version", "");
        if ($this->version) {
            $this->add_option( array(
                "--version",
                "callback" => "_optparse_display_version",
                "help" => _translate("show program's version number and exit"),
                "dest" => null,
                "nargs" => 0
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
            fprintf($stream, "  ". $option->_str(). "\n" );
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
            $msg = _translate("Default values must be in an array");
            throw new InvalidArgumentException($msg);
        }

        if ($values === Null) {
            $values = $this->get_default_values();
        }
        else {
            // Get a copy of default values and update the array
            $values = array_merge($this->get_default_values(), $values);
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
            if ( substr($arg, 0, 1) != "-" ) {
                $positional[] = $arg;
                continue;
            }

            if ( substr($arg, 0, 2) == "--" ) {
                $this->_process_long_option($arg, $values);
            }
            else {
                // values will be removed from $rargs during this process
                $this->_process_short_option($arg, $rargs, $values);
            }
        }

        return new Values($values, $positional);
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
     * Long options are binary options, or they expect their value(s) to be
     * appended to them with = as a separator. If an option expects more than
     * one value, they should be a comma separated list.
     * Examples:
     *     program --enable-this
     *     program --option=value
     *     program --option=value1,value2
     *
     * @return void
     * @author Gabriel Filion
     **/
    private function _process_long_option($argument, &$values) {
        $key_value = explode("=", $argument, 2);
        $arg_text = $key_value[0];

        $option = $this->_get_known_option($arg_text);

        if ( count($key_value) > 1 ) {
            $opt_values = explode(",", $key_value[1]);
        }
        else {
            $opt_values = array();
        }

        if ( count($opt_values) < $option->nargs ) {
            if ($option->nargs == 1) {
                $vals = array("option" => $argument);
                $msg = _translate(
                    "%(option)s option takes a value.",
                    $vals
                );

                $this->error($msg, WRONG_VALUE_COUNT_ERROR);
            }
            else {
                $vals = array(
                    "option" => $argument,
                    "nbvals" => $option->nargs
                );
                $msg = _translate(
                    "%(option)s option takes %(nbvals)s values.",
                    $vals
                );

                $this->error($msg, WRONG_VALUE_COUNT_ERROR);
            }

        }

        if (count($opt_values) < 1) {
            $value = true;
        }
        else {
            if ($option->nargs < 1) {
                $vals = array("option" => $argument);
                $msg = _translate(
                    "%(option)s option does not take a value.",
                    $vals
                );

                $this->error($msg, WRONG_VALUE_COUNT_ERROR);
            }

            $value = $opt_values;
        }

        $option->process($value, $arg_text, $values, $this);
    }

    /**
     * Process a short option.
     *
     * Short options are binary options, or they expect their value(s) to be in
     * the following arguments.
     * Examples:
     *     program -q
     *     program -d something
     *
     * @return void
     * @author Gabriel Filion
     **/
    private function _process_short_option($argument,
                                           &$rargs,
                                           &$values)
    {
        $option = $this->_get_known_option($argument);

        $nbvals = $option->nargs;

        if ( $nbvals == 0 ) {
            $value = True;
        }
        else {
            $value = array();
        }

        if ( count($rargs) < $nbvals ) {
            if ( $nbvals == 1) {
                $vals = array("option" => $argument);
                $msg = _translate(
                    "%(option)s option takes an argument.",
                    $vals
                );
            }
            else {
                $vals = array("option" => $argument, "nbargs" => $nbvals);
                $msg = _translate(
                    "%(option)s option takes %(nbargs)s arguments.",
                    $vals
                );
            }

            $this->error($msg, WRONG_VALUE_COUNT_ERROR);
        }

        while ( $nbvals ) {
            $value[] = array_pop($rargs);
            $nbvals--;
        }

        // If only one value, set it directly as the value (not in an array)
        if ( $option->nargs == 1 ) {
            $value = $value[0];
        }

        $option->process($value, $argument, $values, $this);
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
 * Show a help message and exit
 *
 * This is the callback method for the automatic help option. It displays a
 * help message with a list of available options and exits with code 0.
 *
 * @return void
 * @author Gabriel Filion
 **/
function _optparse_display_help($dummy_option,
                                $dummy_opt_text,
                                $dummy_value,
                                $parser)
{
    $parser->print_help();

    exit(0);
}

/**
 * Show version information and exit
 *
 * This is the callback method for the automatic version option. It displays
 * the version tag and exits with code 0.
 *
 * @return void
 * @author Gabriel Filion
 **/
function _optparse_display_version($dummy_option,
                                   $dummy_opt_text,
                                   $dummy_value,
                                   $parser)
{
    $parser->print_version();

    exit(0);
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

        $this->help = array_pop_elem($settings, "help", "");
        $this->callback = array_pop_elem($settings, "callback", Null);
        // FIXME dest can be Null if action is "callback"
        $this->dest = array_pop_elem($settings, "dest", $longest_name);

        $this->nargs = array_pop_elem($settings, "nargs", 1);
        if ($this->nargs < 0) {
            $msg = _translate("nargs setting to Option cannot be negative");
            throw new InvalidArgumentException($msg);
        }

        // Using this can lead to results that are unexpected.
        // Use OptionParser.set_defaults instead
        $this->default = array_pop_elem($settings, "default", NO_DEFAULT);

        // Yell if any superfluous arguments are given.
        if ( ! empty($settings) ) {
            throw new OptionError($settings);
        }
    }

    /**
     * Process the option.
     *
     * When used, the option must be processed. Verify value type. Call the
     * callback, if needed.
     *
     * Callback functions should have the following signature:
     *     function x_callback(&$option, $opt_text, $value, &$parser) { }
     *
     * The name of the callback function is of no importance. The first and
     * last arguments should be passed by reference so that doing anything to
     * them is not done to a copy of the object only.
     *
     * @return void
     * @author Gabriel Filion
     **/
    public function process($value, $opt_text, &$values, &$parser) {
        // FIXME No type checking as of now.

        if ($this->callback !== Null) {
            $callback = $this->callback;
            try {
                $value = $callback($this, $opt_text, $value, $parser);
            }
            catch (OptionValueError $exc) {
                $this->error( $exc->get_message() );
            }
        }

        $values[$this->dest] = $value;
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
     * String representation of the option.
     *
     * Format a string with option name and description so that it can be used
     * for a help message.
     *
     * @return String: name and description of the option
     * @author Gabriel Filion
     **/
    public function _str() {
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
